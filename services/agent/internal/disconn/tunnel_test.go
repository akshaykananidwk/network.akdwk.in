package disconn

import (
	"net/netip"
	"testing"
	"time"

	"golang.zx2c4.com/wireguard/conn"

	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
)

// The diversion table decides, for every packet, whether it leaves by the
// socket or by the HTTPS fallback. Getting it wrong is silent in both
// directions: a packet that should have been diverted is sent for real to an
// address that exists nowhere, and one that should not have been disappears
// into a tunnel the other end is not listening on.

type fakeTunnel struct {
	control []netip.AddrPort
	peers   [][32]byte
}

func (f *fakeTunnel) SendControl(_ []byte, to netip.AddrPort) error {
	f.control = append(f.control, to)

	return nil
}

func (f *fakeTunnel) SendTunnel(peer [32]byte, _ []byte) error {
	f.peers = append(f.peers, peer)

	return nil
}

// An IPv4 address can be held plainly or mapped into IPv6, and the two are
// different map keys while being the same address. Everything read back out of
// a wireguard-go endpoint is unmapped, so a table keyed on the mapped form
// would miss every lookup — and a miss here is a packet sent for real to a
// pseudo-address, which is a black hole with no error anywhere.
func TestTheDiversionTableDoesNotCareHowAnAddressIsSpelled(t *testing.T) {
	plain := netip.MustParseAddrPort("198.51.100.7:9000")
	mapped := netip.AddrPortFrom(netip.AddrFrom16(plain.Addr().As16()), plain.Port())

	if plain == mapped {
		t.Fatal("this test needs the two spellings to be different values")
	}

	bind := &Bind{}
	tunnel := &fakeTunnel{}
	bind.UseTunnel(tunnel)

	// Registered one way, asked the other.
	bind.DivertControl(mapped)

	bind.RoutePeer(mapped, testPeer(1))

	route := bind.route.Load()
	if _, ok := route.peers[plain]; !ok {
		t.Error("a peer registered by its mapped address is not found by its plain one")
	}
	if !route.control[plain] {
		t.Error("a control address registered mapped is not found plain")
	}

	bind.ForgetPeer(plain)
	if len(bind.route.Load().peers) != 0 {
		t.Error("forgetting by the plain address did not remove the mapped entry")
	}
}

// A bind is diverted by destination for the coordinator, and by packet type
// for a relay bind — because the relay's address is named in an offer only the
// agent can read, and the socket has never heard of it.
//
// A hole punch must never be diverted. It is addressed to a peer's own NAT
// mapping, which no relay can reach.
func TestOnlyTheRightPacketsAreDiverted(t *testing.T) {
	coordinator := netip.MustParseAddrPort("10.0.0.1:8443")
	relay := netip.MustParseAddrPort("198.51.100.7:9000")
	peer := netip.MustParseAddrPort("203.0.113.4:41234")

	bind := &Bind{}
	bind.UseTunnel(&fakeTunnel{})
	bind.DivertControl(coordinator)

	route := bind.route.Load()

	packet := func(t disco.MessageType) []byte {
		pkt := make([]byte, disco.HeaderLen)
		disco.WriteHeader(pkt, t, [32]byte{})

		return pkt
	}

	cases := []struct {
		name string
		pkt  []byte
		to   netip.AddrPort
		want bool
	}{
		{"a hello to the coordinator", packet(disco.TypeHello), coordinator, true},
		{"a relay request to the coordinator", packet(disco.TypeRelayRequest), coordinator, true},
		{"a bind to a relay nobody registered", packet(disco.TypeRelayBind), relay, true},
		{"a punch to a peer", packet(disco.TypePunch), peer, false},
		{"a probe to a relay", packet(disco.TypeRelayProbe), relay, false},
	}

	for _, c := range cases {
		if got := route.diverts(c.pkt, c.to); got != c.want {
			t.Errorf("%s: diverted = %v, want %v", c.name, got, c.want)
		}
	}

	// And with no tunnel attached, nothing is diverted at all.
	bind.UseTunnel(nil)
	if bind.route.Load().diverts(packet(disco.TypeHello), coordinator) {
		t.Error("traffic was diverted with no fallback attached")
	}
}

// Detaching the fallback has to take the peer routes with it. Their endpoints
// name a connection that no longer exists, and leaving them behind would
// divert traffic into a tunnel that cannot carry it.
func TestDetachingTheFallbackDropsThePeerRoutes(t *testing.T) {
	bind := &Bind{}
	bind.UseTunnel(&fakeTunnel{})
	bind.DivertControl(netip.MustParseAddrPort("10.0.0.1:8443"))
	bind.RoutePeer(netip.MustParseAddrPort("198.51.100.7:65000"), testPeer(1))

	bind.UseTunnel(nil)

	route := bind.route.Load()
	if len(route.peers) != 0 {
		t.Error("a peer is still routed through a fallback that has gone away")
	}

	// The control addresses stay: they are real addresses that UDP can reach,
	// and the fallback reconnects within seconds.
	if len(route.control) != 1 {
		t.Error("the control addresses were dropped with the connection")
	}
}

// Only packets that arrive on the real socket may say UDP is working. An
// answer that came over the fallback is handed to discovery exactly as a UDP
// one is, so every other measure of health reads fine while the network
// underneath carries nothing.
func TestOnlyTheSocketSaysUDPIsAlive(t *testing.T) {
	bind := &Bind{}

	if bind.UDPAlive(time.Minute) {
		t.Error("a bind that has received nothing claims UDP is working")
	}

	// What Inject does — the fallback's path — must not count.
	bind.Inject([]byte("not a real packet"), netip.MustParseAddrPort("10.0.0.1:8443"))

	if bind.UDPAlive(time.Minute) {
		t.Error("a packet injected from the fallback was counted as proof UDP works")
	}

	// What the receive path does.
	bind.lastUDP.Store(time.Now().Unix())

	if !bind.UDPAlive(time.Minute) {
		t.Error("a packet from the socket was not counted")
	}
	if bind.UDPAlive(time.Nanosecond) {
		t.Error("an old packet still counts as proof UDP works now")
	}
}

func testPeer(n byte) [32]byte {
	var k [32]byte
	k[0] = n

	return k
}

// A discovery handler that blocks — as one does when it waits for the tunnel's
// lock while a rebind holds it — must never stop the socket being read: the
// rebind is itself waiting for the reader to finish, and that was a deadlock
// that froze all inbound traffic.
func TestABlockedDiscoveryHandlerNeverStopsTheSocketReader(t *testing.T) {
	bind := New()
	release := make(chan struct{})
	got := make(chan string, 4)
	bind.SetHandler(func(pkt []byte, _ netip.AddrPort) {
		<-release
		got <- string(pkt[len(pkt)-1:])
	})

	disc := func(tag byte) []byte {
		pkt := make([]byte, 64)
		copy(pkt, disco.Magic[:])
		pkt[len(pkt)-1] = tag
		return pkt
	}
	queue := [][]byte{disc('a'), disc('b'), []byte("wireguard"), disc('c')}
	recv := bind.intercept(func(packets [][]byte, sizes []int, eps []conn.Endpoint) (int, error) {
		for i, p := range queue {
			packets[i] = append(packets[i][:0], p...)
			sizes[i] = len(p)
			eps[i] = &conn.StdNetEndpoint{AddrPort: netip.MustParseAddrPort("192.0.2.1:8443")}
		}
		return len(queue), nil
	})

	done := make(chan int)
	go func() {
		packets := make([][]byte, 4)
		for i := range packets {
			packets[i] = make([]byte, 128)
		}
		sizes := make([]int, 4)
		n, _ := recv(packets, sizes, make([]conn.Endpoint, 4))
		kept := 0
		for _, sz := range sizes[:n] {
			if sz > 0 {
				kept++
			}
		}
		if kept != 1 {
			n = -kept
		}
		done <- n
	}()

	select {
	case n := <-done:
		if n != 4 {
			t.Fatalf("the batch came back with %d entries, want all 4 (discovery hidden by size)", n)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("the socket reader waited for a blocked discovery handler")
	}

	close(release)
	order := ""
	for i := 0; i < 3; i++ {
		select {
		case tag := <-got:
			order += tag
		case <-time.After(2 * time.Second):
			t.Fatalf("only %q of the discovery packets reached the handler", order)
		}
	}
	if order != "abc" {
		t.Fatalf("discovery packets reached the handler as %q, not in arrival order", order)
	}
}

// wireguard-go reads each received packet from its OWN buffer at that index
// and skips entries shorter than a WireGuard message; it never reads back the
// slice headers a ReceiveFunc was given. So discovery packets must be hidden
// by size, in place — compacting the batch by swapping headers left it reading
// a discovery packet's buffer where a WireGuard packet should have been.
func TestAMixedBatchReachesWireGuardIntactAtEveryIndex(t *testing.T) {
	bind := New()
	bind.SetHandler(func([]byte, netip.AddrPort) {})

	const batch = 128
	// What wireguard-go owns: one array per index. The ReceiveFunc is handed
	// views of them, exactly as device/receive.go does.
	arrays := make([][]byte, batch)
	views := make([][]byte, batch)
	for i := range arrays {
		arrays[i] = make([]byte, 2048)
		views[i] = arrays[i][:]
	}

	wg := func(i int) []byte {
		p := make([]byte, 148)
		p[0] = 4 // a WireGuard transport message
		p[len(p)-1] = byte(i)
		return p
	}
	discoPkt := func() []byte {
		p := make([]byte, 64)
		copy(p, disco.Magic[:])
		return p
	}

	isDisco := func(i int) bool { return i%3 == 1 }
	recv := bind.intercept(func(packets [][]byte, sizes []int, eps []conn.Endpoint) (int, error) {
		for i := 0; i < batch; i++ {
			p := wg(i)
			if isDisco(i) {
				p = discoPkt()
			}
			sizes[i] = copy(packets[i], p)
			eps[i] = &conn.StdNetEndpoint{AddrPort: netip.MustParseAddrPort("192.0.2.1:8443")}
		}
		return batch, nil
	})

	sizes := make([]int, batch)
	n, err := recv(views, sizes, make([]conn.Endpoint, batch))
	if err != nil || n != batch {
		t.Fatalf("n=%d err=%v", n, err)
	}

	// Now read the batch the way wireguard-go does.
	for i := 0; i < n; i++ {
		if sizes[i] < 32 {
			if !isDisco(i) {
				t.Fatalf("the WireGuard packet at %d was hidden", i)
			}
			continue
		}
		if isDisco(i) {
			t.Fatalf("a discovery packet at %d reached WireGuard", i)
		}
		got := arrays[i][:sizes[i]]
		if got[0] != 4 || got[len(got)-1] != byte(i) {
			t.Fatalf("WireGuard read the wrong bytes at index %d", i)
		}
	}
}
