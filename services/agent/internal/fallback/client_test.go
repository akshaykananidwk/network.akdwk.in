package fallback

import (
	"context"
	"errors"
	"net/netip"
	"sync"
	"testing"
	"time"

	"github.com/coder/websocket"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/disconn"
	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
	wire "github.com/akshaykananidwk/network.akdwk.in/services/shared/fallback"
)

// These drive the whole client — handshake, control, binds, tunnel traffic and
// reconnection — against a connection that is not a websocket. That is
// deliberate: what has to be right here is what the agent does with the frames,
// not that somebody else's library speaks the protocol.

// fakeConn is a websocket connection a test writes both ends of.
type fakeConn struct {
	mu     sync.Mutex
	sent   [][]byte
	inbox  chan []byte
	closed chan struct{}
	once   sync.Once
}

func newFakeConn() *fakeConn {
	return &fakeConn{inbox: make(chan []byte, 32), closed: make(chan struct{})}
}

func (f *fakeConn) Read(ctx context.Context) (websocket.MessageType, []byte, error) {
	select {
	case <-ctx.Done():
		return 0, nil, ctx.Err()
	case <-f.closed:
		return 0, nil, errors.New("closed")
	case msg := <-f.inbox:
		return websocket.MessageBinary, msg, nil
	}
}

func (f *fakeConn) Write(_ context.Context, _ websocket.MessageType, p []byte) error {
	select {
	case <-f.closed:
		return errors.New("closed")
	default:
	}

	owned := make([]byte, len(p))
	copy(owned, p)

	f.mu.Lock()
	f.sent = append(f.sent, owned)
	f.mu.Unlock()

	return nil
}

func (f *fakeConn) CloseNow() error {
	f.once.Do(func() { close(f.closed) })

	return nil
}

// deliver hands an already-encoded frame to the client as though the relay had
// sent it.
func (f *fakeConn) deliver(t *testing.T, msg []byte) {
	t.Helper()

	select {
	case f.inbox <- msg:
	case <-time.After(2 * time.Second):
		t.Fatal("the client never read the frame")
	}
}

// waitSent blocks until a frame of the given kind has been written, and
// returns its payload.
func (f *fakeConn) waitSent(t *testing.T, kind wire.Kind) []byte {
	t.Helper()

	deadline := time.Now().Add(3 * time.Second)

	for time.Now().Before(deadline) {
		f.mu.Lock()
		frames := make([][]byte, len(f.sent))
		copy(frames, f.sent)
		f.mu.Unlock()

		for _, msg := range frames {
			got, payload, err := wire.Decode(msg)
			if err == nil && got == kind {
				return payload
			}
		}

		time.Sleep(10 * time.Millisecond)
	}

	t.Fatalf("no %#x frame was ever sent", byte(kind))

	return nil
}

// fakeRouter records what the client asked the shared socket to do.
type fakeRouter struct {
	mu       sync.Mutex
	tunnel   disconn.Tunnel
	diverted []netip.AddrPort
	routes   map[netip.AddrPort][32]byte
	injected []injectedControl
	tunnels  []injectedTunnel
}

type injectedControl struct {
	pkt  []byte
	from netip.AddrPort
}

type injectedTunnel struct {
	pkt  []byte
	from netip.AddrPort
}

func newFakeRouter() *fakeRouter {
	return &fakeRouter{routes: make(map[netip.AddrPort][32]byte)}
}

func (r *fakeRouter) UseTunnel(t disconn.Tunnel) {
	r.mu.Lock()
	defer r.mu.Unlock()

	r.tunnel = t
}

func (r *fakeRouter) DivertControl(to netip.AddrPort) {
	r.mu.Lock()
	defer r.mu.Unlock()

	r.diverted = append(r.diverted, to)
}

func (r *fakeRouter) RoutePeer(at netip.AddrPort, peer [32]byte) {
	r.mu.Lock()
	defer r.mu.Unlock()

	r.routes[at] = peer
}

func (r *fakeRouter) ForgetPeer(at netip.AddrPort) {
	r.mu.Lock()
	defer r.mu.Unlock()

	delete(r.routes, at)
}

func (r *fakeRouter) Inject(pkt []byte, from netip.AddrPort) {
	owned := make([]byte, len(pkt))
	copy(owned, pkt)

	r.mu.Lock()
	defer r.mu.Unlock()

	r.injected = append(r.injected, injectedControl{pkt: owned, from: from})
}

func (r *fakeRouter) InjectTunnel(pkt []byte, from netip.AddrPort) {
	owned := make([]byte, len(pkt))
	copy(owned, pkt)

	r.mu.Lock()
	defer r.mu.Unlock()

	r.tunnels = append(r.tunnels, injectedTunnel{pkt: owned, from: from})
}

func (r *fakeRouter) attached() bool {
	r.mu.Lock()
	defer r.mu.Unlock()

	return r.tunnel != nil
}

func (r *fakeRouter) routeFor(peer [32]byte) (netip.AddrPort, bool) {
	r.mu.Lock()
	defer r.mu.Unlock()

	for at, key := range r.routes {
		if key == peer {
			return at, true
		}
	}

	return netip.AddrPort{}, false
}

func (r *fakeRouter) lastInjected() (injectedControl, bool) {
	r.mu.Lock()
	defer r.mu.Unlock()

	if len(r.injected) == 0 {
		return injectedControl{}, false
	}

	return r.injected[len(r.injected)-1], true
}

func (r *fakeRouter) tunnelCount() int {
	r.mu.Lock()
	defer r.mu.Unlock()

	return len(r.tunnels)
}

// fixture starts a client against a connection the test controls.
func fixture(t *testing.T) (*Client, *fakeConn, *fakeRouter, netip.AddrPort) {
	t.Helper()

	coordinator := netip.MustParseAddrPort("10.0.0.1:8443")
	router := newFakeRouter()
	conn := newFakeConn()

	client, err := New(Options{
		URL:         "wss://example.invalid/fallback",
		SelfKey:     testKey(1),
		Coordinator: coordinator,
		Router:      router,
		Logf:        func(string, ...any) {},
	})
	if err != nil {
		t.Fatal(err)
	}

	client.dialer = func(context.Context) (wsConn, error) { return conn, nil }

	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(func() {
		cancel()
		_ = conn.CloseNow()
	})

	go client.Run(ctx)

	// The relay's side of the handshake.
	hello := conn.waitSent(t, wire.KindHello)
	if key, err := wire.DecodeHello(hello); err != nil || key != testKey(1) {
		t.Fatalf("the hello did not carry this device's key (%v)", err)
	}

	ready, err := wire.EncodeReady("203.0.113.9")
	if err != nil {
		t.Fatal(err)
	}
	conn.deliver(t, ready)

	waitFor(t, func() bool { return router.attached() }, "the tunnel was never attached")

	if client.Observed() != "203.0.113.9" {
		t.Fatalf("the observed address came back as %q", client.Observed())
	}

	return client, conn, router, coordinator
}

func waitFor(t *testing.T, cond func() bool, complaint string) {
	t.Helper()

	deadline := time.Now().Add(3 * time.Second)
	for time.Now().Before(deadline) {
		if cond() {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}

	t.Fatal(complaint)
}

func testKey(n byte) [32]byte {
	var k [32]byte
	k[0] = n
	k[31] = n

	return k
}

// The coordinator's address is diverted the moment the fallback starts, so an
// announcement goes out over both paths from the first one.
func TestTheCoordinatorIsDivertedImmediately(t *testing.T) {
	_, _, router, coordinator := fixture(t)

	router.mu.Lock()
	defer router.mu.Unlock()

	for _, at := range router.diverted {
		if at == coordinator {
			return
		}
	}

	t.Fatal("the coordinator's address was never diverted through the fallback")
}

// Control traffic goes out as a control frame and comes back attributed to the
// coordinator, which is where discovery believes it came from.
func TestControlTravelsBothWays(t *testing.T) {
	client, conn, router, coordinator := fixture(t)

	hello := make([]byte, disco.HeaderLen)
	disco.WriteHeader(hello, disco.TypeHello, testKey(1))

	if err := client.SendControl(hello, coordinator); err != nil {
		t.Fatalf("sending control: %v", err)
	}

	if got := conn.waitSent(t, wire.KindControl); len(got) != len(hello) {
		t.Fatalf("the control frame carried %d bytes, not %d", len(got), len(hello))
	}

	answer := make([]byte, disco.HeaderLen)
	disco.WriteHeader(answer, disco.TypeHelloAck, testKey(9))

	frame, err := wire.Encode(wire.KindControl, answer)
	if err != nil {
		t.Fatal(err)
	}
	conn.deliver(t, frame)

	waitFor(t, func() bool {
		last, ok := router.lastInjected()

		return ok && last.from == coordinator
	}, "the coordinator's answer was never handed to discovery")
}

// A relay bind goes out as a bind frame, and the acknowledgement is turned into
// the one discovery is waiting for: from the relay's own address, carrying a
// port that routes through the tunnel.
func TestABindIsAnsweredAsDiscoveryExpects(t *testing.T) {
	client, conn, router, _ := fixture(t)

	relay := netip.MustParseAddrPort("198.51.100.7:9000")
	peer := testKey(2)

	ticket := &disco.Ticket{
		TenantID:  3,
		ExpiresAt: time.Now().Add(time.Minute).Unix(),
		Self:      testKey(1),
		Peer:      peer,
	}
	ticket.Sign([]byte("not checked on this side"))

	bind := make([]byte, disco.HeaderLen)
	disco.WriteHeader(bind, disco.TypeRelayBind, testKey(1))
	bind = append(bind, ticket.Encode()...)

	if err := client.SendControl(bind, relay); err != nil {
		t.Fatalf("sending the bind: %v", err)
	}

	conn.waitSent(t, wire.KindBind)

	ack, err := wire.EncodeBindAck(peer)
	if err != nil {
		t.Fatal(err)
	}
	conn.deliver(t, ack)

	waitFor(t, func() bool {
		_, ok := router.routeFor(peer)

		return ok
	}, "the peer was never routed through the fallback")

	at, _ := router.routeFor(peer)
	if at.Addr() != relay.Addr() {
		t.Fatalf("the peer's endpoint is %s, not on the relay's own address", at)
	}
	if at.Port() < firstPseudoPort {
		t.Fatalf("the peer's endpoint port %d is not in the reserved range", at.Port())
	}

	// And the packet discovery receives has to be a real bind acknowledgement
	// from that relay, naming that port. Anything else and discovery ignores
	// it and the peer stays unreachable.
	last, ok := router.lastInjected()
	if !ok || last.from != relay {
		t.Fatalf("the acknowledgement was not attributed to the relay")
	}

	header, rest, err := disco.ParseHeader(last.pkt)
	if err != nil || header.Type != disco.TypeRelayBindAck {
		t.Fatalf("discovery was handed %v, not a bind acknowledgement (%v)", header.Type, err)
	}
	if len(rest) < 2 || uint16(rest[0])<<8|uint16(rest[1]) != at.Port() {
		t.Fatal("the acknowledgement did not carry the endpoint's port")
	}

	if _, ok := client.Endpoint(peer); !ok {
		t.Fatal("the client does not report an endpoint for a peer it just bound")
	}
}

// Tunnel traffic is tagged with the peer on the way out and attributed to the
// peer's own endpoint on the way in, so wireguard-go never sees it move.
func TestTunnelTrafficIsTaggedAndAttributed(t *testing.T) {
	client, conn, router, _ := fixture(t)

	relay := netip.MustParseAddrPort("198.51.100.7:9000")
	peer := testKey(2)

	client.mu.Lock()
	client.relayFor[peer] = relay
	client.mu.Unlock()

	ack, err := wire.EncodeBindAck(peer)
	if err != nil {
		t.Fatal(err)
	}
	conn.deliver(t, ack)

	waitFor(t, func() bool {
		_, ok := client.Endpoint(peer)

		return ok
	}, "the peer never got an endpoint")

	at, _ := client.Endpoint(peer)

	if err := client.SendTunnel(peer, []byte("encrypted")); err != nil {
		t.Fatalf("sending tunnel traffic: %v", err)
	}

	payload := conn.waitSent(t, wire.KindData)
	gotPeer, tunnelBytes, err := wire.DecodeData(payload)
	if err != nil || gotPeer != peer || string(tunnelBytes) != "encrypted" {
		t.Fatalf("the data frame was wrong: peer %x…, bytes %q (%v)", gotPeer[:4], tunnelBytes, err)
	}

	inbound, err := wire.EncodeData(peer, []byte("from-the-peer"))
	if err != nil {
		t.Fatal(err)
	}
	conn.deliver(t, inbound)

	waitFor(t, func() bool { return router.tunnelCount() > 0 }, "nothing was injected into wireguard-go")

	router.mu.Lock()
	got := router.tunnels[0]
	router.mu.Unlock()

	if got.from != at {
		t.Fatalf("the packet was attributed to %s, not to the peer's endpoint %s", got.from, at)
	}
	if string(got.pkt) != "from-the-peer" {
		t.Fatalf("the injected packet was %q", got.pkt)
	}
}

// Traffic for a peer this end has not bound has nowhere to go, and must be
// dropped rather than attributed to whatever endpoint is to hand.
func TestTrafficForAnUnboundPeerIsDropped(t *testing.T) {
	_, conn, router, _ := fixture(t)

	inbound, err := wire.EncodeData(testKey(8), []byte("for-a-stranger"))
	if err != nil {
		t.Fatal(err)
	}
	conn.deliver(t, inbound)

	// Given a moment to do the wrong thing.
	time.Sleep(200 * time.Millisecond)

	if router.tunnelCount() != 0 {
		t.Fatal("a packet for an unbound peer was injected into the device")
	}
}

// A dropped connection takes the diversion with it, because the endpoints it
// handed out name a connection that no longer exists.
func TestADroppedConnectionStopsDiverting(t *testing.T) {
	_, conn, router, _ := fixture(t)

	_ = conn.CloseNow()

	waitFor(t, func() bool { return !router.attached() },
		"the tunnel was still attached after the connection went away")
}

// Once the socket is carrying discovery traffic again, the fallback stops
// answering binds — which is what hands the peers back to UDP.
//
// Both ends answering the same bind is the failure this prevents, and it is a
// stable one rather than a race: while the traffic goes through the tunnel the
// relay never sees a packet on the device's own socket, so it keeps forwarding
// through the tunnel and the pair stays on the expensive path indefinitely
// with everything apparently healthy.
func TestUDPComingBackTakesThePeersOffTheFallback(t *testing.T) {
	client, conn, router, _ := fixture(t)

	relay := netip.MustParseAddrPort("198.51.100.7:9000")
	peer := testKey(2)

	client.mu.Lock()
	client.relayFor[peer] = relay
	client.mu.Unlock()

	ack, err := wire.EncodeBindAck(peer)
	if err != nil {
		t.Fatal(err)
	}
	conn.deliver(t, ack)

	waitFor(t, func() bool {
		_, ok := router.routeFor(peer)

		return ok
	}, "the peer was never routed through the fallback")

	client.PreferUDP(true)

	if _, ok := router.routeFor(peer); ok {
		t.Fatal("the peer is still diverted through the tunnel after UDP came back")
	}
	if _, ok := client.Endpoint(peer); ok {
		t.Fatal("the client still reports a fallback endpoint for the peer")
	}

	// And a further acknowledgement must not put it back, or the two paths
	// take turns for as long as both are up.
	conn.deliver(t, ack)
	time.Sleep(200 * time.Millisecond)

	if _, ok := router.routeFor(peer); ok {
		t.Fatal("a later acknowledgement diverted the peer again while UDP was working")
	}

	// When UDP stops working the fallback takes the peer back.
	client.PreferUDP(false)
	conn.deliver(t, ack)

	waitFor(t, func() bool {
		_, ok := router.routeFor(peer)

		return ok
	}, "the fallback did not take the peer back when UDP failed again")
}
