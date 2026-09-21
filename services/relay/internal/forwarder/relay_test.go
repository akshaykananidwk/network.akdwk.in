package forwarder

import (
	"context"
	"net"
	"net/netip"
	"testing"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
)

// These tests reproduce two faults found by running the relay against a lab
// with symmetric NAT. Both were invisible to inspection and both made the
// relay forward traffic into a black hole while reporting success.
//
// The shape of a symmetric NAT is reproduced with ordinary sockets: an agent
// reaches the relay's *control* port from one source port and its *data* port
// from another, exactly as a carrier NAT would translate them. That difference
// is the whole difficulty, so the tests build it in rather than around it.

const testSecret = "relay and coordinator share this"

// relayFixture is a running relay plus the sockets a test drives it with.
type relayFixture struct {
	t       *testing.T
	relay   *Relay
	control netip.AddrPort
	cancel  context.CancelFunc
}

func startRelay(t *testing.T) *relayFixture {
	t.Helper()

	relay, err := New(Options{
		Control:     "127.0.0.1:0",
		ListenIP:    "127.0.0.1",
		Secret:      []byte(testSecret),
		IdleTimeout: time.Minute,
		Logf:        func(string, ...any) {},
	})
	if err != nil {
		t.Fatalf("building the relay: %v", err)
	}

	ctx, cancel := context.WithCancel(context.Background())
	go func() { _ = relay.Run(ctx) }()

	// Run binds asynchronously; wait for the address rather than sleeping a
	// guessed amount.
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		if addr := relay.ControlAddr(); addr.IsValid() && addr.Port() != 0 {
			t.Cleanup(cancel)

			return &relayFixture{t: t, relay: relay, control: addr, cancel: cancel}
		}
		time.Sleep(5 * time.Millisecond)
	}

	cancel()
	t.Fatal("the relay did not bind within two seconds")

	return nil
}

// agent is one end of a pair, with two sockets: one it binds from and one it
// sends tunnel traffic from. Two sockets is what makes this a symmetric NAT.
type agent struct {
	key        [32]byte
	bindSock   *net.UDPConn
	dataSock   *net.UDPConn
	relayPort  uint16
	relayAddr  netip.AddrPort
	controlTo  netip.AddrPort
	ticketBlob []byte
}

func newAgent(t *testing.T, self, peer [32]byte, control netip.AddrPort) *agent {
	t.Helper()

	bindSock, err := net.ListenUDP("udp", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatalf("opening the bind socket: %v", err)
	}
	t.Cleanup(func() { _ = bindSock.Close() })

	dataSock, err := net.ListenUDP("udp", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatalf("opening the data socket: %v", err)
	}
	t.Cleanup(func() { _ = dataSock.Close() })

	ticket := &disco.Ticket{
		TenantID:  7,
		ExpiresAt: time.Now().Add(time.Minute).Unix(),
		Self:      self,
		Peer:      peer,
	}
	ticket.Sign([]byte(testSecret))

	return &agent{
		key:        self,
		bindSock:   bindSock,
		dataSock:   dataSock,
		controlTo:  control,
		ticketBlob: ticket.Encode(),
	}
}

// bind presents the ticket from the bind socket and reads back the data port.
func (a *agent) bind(t *testing.T) {
	t.Helper()

	pkt := make([]byte, disco.HeaderLen, disco.HeaderLen+len(a.ticketBlob))
	disco.WriteHeader(pkt, disco.TypeRelayBind, a.key)
	pkt = append(pkt, a.ticketBlob...)

	if _, err := a.bindSock.WriteToUDPAddrPort(pkt, a.controlTo); err != nil {
		t.Fatalf("sending the bind: %v", err)
	}

	// Read until the acknowledgement, skipping anything else that lands here.
	//
	// Before either side's data address is learned the relay can forward
	// tunnel traffic to the bind address, so this socket does see payloads
	// that are not acknowledgements. A real agent demuxes the shared socket by
	// packet type for the same reason.
	buf := make([]byte, 1600)
	deadline := time.Now().Add(2 * time.Second)

	for time.Now().Before(deadline) {
		_ = a.bindSock.SetReadDeadline(deadline)

		n, _, err := a.bindSock.ReadFromUDPAddrPort(buf)
		if err != nil {
			break
		}

		header, rest, err := disco.ParseHeader(buf[:n])
		if err != nil || header.Type != disco.TypeRelayBindAck || len(rest) < 2 {
			continue
		}

		a.relayPort = uint16(rest[0])<<8 | uint16(rest[1])
		a.relayAddr = netip.AddrPortFrom(a.controlTo.Addr(), a.relayPort)

		return
	}

	t.Fatal("no bind acknowledgement arrived")
}

// send writes tunnel traffic from the data socket, which under a symmetric NAT
// is a different source port from the one that bound.
func (a *agent) send(t *testing.T, payload string) {
	t.Helper()

	if _, err := a.dataSock.WriteToUDPAddrPort([]byte(payload), a.relayAddr); err != nil {
		t.Fatalf("sending data: %v", err)
	}
}

// expect reads forwarded datagrams until the wanted one arrives, or fails.
//
// It tolerates other payloads rather than demanding the next one match,
// because a real pair has keepalives and handshake retries in flight and a
// test that assumed a quiet channel would be flaky for no useful reason.
func (a *agent) expect(t *testing.T, want string, within time.Duration) {
	t.Helper()

	deadline := time.Now().Add(within)
	buf := make([]byte, 1600)

	for time.Now().Before(deadline) {
		_ = a.dataSock.SetReadDeadline(deadline)

		n, _, err := a.dataSock.ReadFromUDPAddrPort(buf)
		if err != nil {
			break
		}
		if string(buf[:n]) == want {
			return
		}
	}

	t.Fatalf("expected %q to be forwarded to the data socket, and it never arrived", want)
}

// openPath gets both sides' data addresses learned by the relay.
//
// Until a side has sent from its data socket the relay only knows where its
// bind came from, so the very first packet in either direction can be
// delivered to the bind address and lost. A real pair sends handshake
// initiations every few seconds and converges; this does the same thing
// deliberately so the tests that follow are about the fault under test rather
// than about who spoke first.
func openPath(t *testing.T, a, b *agent) {
	t.Helper()

	for i := 0; i < 5; i++ {
		a.send(t, "open")
		b.send(t, "open")
		time.Sleep(50 * time.Millisecond)
	}

	drain(a)
	drain(b)
}

// drain empties a data socket without blocking.
func drain(a *agent) {
	buf := make([]byte, 1600)

	for {
		_ = a.dataSock.SetReadDeadline(time.Now().Add(20 * time.Millisecond))
		if _, _, err := a.dataSock.ReadFromUDPAddrPort(buf); err != nil {
			return
		}
	}
}

// Defect 11: the relay forwarded to the address the bind arrived from. Under a
// symmetric NAT that is a different mapping from the data port's, so every
// forwarded packet went to a mapping the NAT would not deliver, and the
// handshake never completed while the byte counters happily climbed.
func TestForwardsToTheDataAddressNotTheBindAddress(t *testing.T) {
	fixture := startRelay(t)

	alice := newAgent(t, [32]byte{1}, [32]byte{2}, fixture.control)
	bob := newAgent(t, [32]byte{2}, [32]byte{1}, fixture.control)

	alice.bind(t)
	bob.bind(t)

	if alice.relayPort == bob.relayPort {
		t.Fatalf("both sides were given port %d; each side needs its own", alice.relayPort)
	}

	openPath(t, alice, bob)

	// The payloads cross. Before the fix neither ever arrived at a data
	// socket, however many times they were sent, because the relay was
	// writing to the address the bind came from.
	alice.send(t, "from-alice")
	bob.expect(t, "from-alice", 2*time.Second)

	bob.send(t, "from-bob")
	alice.expect(t, "from-bob", 2*time.Second)
}

// Defect 12: a periodic re-bind arrives on the control flow, and the relay
// stored that address over the data-learned one — breaking the path every
// twenty seconds, forever, on exactly the schedule meant to keep it alive.
func TestRebindDoesNotClobberTheLearnedAddress(t *testing.T) {
	fixture := startRelay(t)

	alice := newAgent(t, [32]byte{3}, [32]byte{4}, fixture.control)
	bob := newAgent(t, [32]byte{4}, [32]byte{3}, fixture.control)

	alice.bind(t)
	bob.bind(t)

	openPath(t, alice, bob)

	alice.send(t, "first")
	bob.expect(t, "first", 2*time.Second)

	// The agents re-bind, as they do every keepalive.
	alice.bind(t)
	bob.bind(t)

	// And traffic must still cross. Before the fix this is where it stopped.
	alice.send(t, "after-rebind")
	bob.expect(t, "after-rebind", 2*time.Second)

	bob.send(t, "after-rebind-b")
	alice.expect(t, "after-rebind-b", 2*time.Second)
}

// A relay that forwarded without a valid ticket would be an open reflector,
// so the refusal matters as much as the forwarding.
func TestRefusesABindWithoutAValidTicket(t *testing.T) {
	fixture := startRelay(t)

	sock, err := net.ListenUDP("udp", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	defer sock.Close()

	forged := &disco.Ticket{
		ExpiresAt: time.Now().Add(time.Minute).Unix(),
		Self:      [32]byte{9},
		Peer:      [32]byte{8},
	}
	forged.Sign([]byte("the wrong secret entirely!!!!!!!"))

	blob := forged.Encode()
	pkt := make([]byte, disco.HeaderLen, disco.HeaderLen+len(blob))
	disco.WriteHeader(pkt, disco.TypeRelayBind, [32]byte{9})
	pkt = append(pkt, blob...)

	if _, err := sock.WriteToUDPAddrPort(pkt, fixture.control); err != nil {
		t.Fatal(err)
	}

	buf := make([]byte, 256)
	_ = sock.SetReadDeadline(time.Now().Add(500 * time.Millisecond))

	if _, _, err := sock.ReadFromUDPAddrPort(buf); err == nil {
		t.Fatal("the relay answered a bind signed with the wrong secret")
	}

	if fixture.relay.Sessions() != 0 {
		t.Fatalf("a session was created for an unverifiable ticket")
	}
}

// The sender named in the packet must be the one the ticket was issued to, or
// a leaked ticket would let anyone bind as the other end of the pair.
func TestRefusesABindWhoseSenderDoesNotMatchTheTicket(t *testing.T) {
	fixture := startRelay(t)

	sock, err := net.ListenUDP("udp", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	defer sock.Close()

	ticket := &disco.Ticket{
		ExpiresAt: time.Now().Add(time.Minute).Unix(),
		Self:      [32]byte{5},
		Peer:      [32]byte{6},
	}
	ticket.Sign([]byte(testSecret))

	blob := ticket.Encode()
	pkt := make([]byte, disco.HeaderLen, disco.HeaderLen+len(blob))
	// A valid ticket, presented by somebody else.
	disco.WriteHeader(pkt, disco.TypeRelayBind, [32]byte{99})
	pkt = append(pkt, blob...)

	if _, err := sock.WriteToUDPAddrPort(pkt, fixture.control); err != nil {
		t.Fatal(err)
	}

	buf := make([]byte, 256)
	_ = sock.SetReadDeadline(time.Now().Add(500 * time.Millisecond))

	if _, _, err := sock.ReadFromUDPAddrPort(buf); err == nil {
		t.Fatal("the relay accepted a ticket presented by a different key")
	}
}
