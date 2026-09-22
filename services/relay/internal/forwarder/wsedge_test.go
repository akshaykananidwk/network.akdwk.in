package forwarder

import (
	"context"
	"net"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
	"github.com/akshaykananidwk/network.akdwk.in/services/shared/fallback"
	"github.com/coder/websocket"
)

// These tests cover the path a device takes when the network it is on passes
// nothing but TCP 443. The office Wi-Fi that prompted them allowed outbound
// UDP and dropped the replies, so the laptop announced itself for hours and
// heard nothing — and the relay it was offered could not help, because
// reaching a relay also needs UDP.

// wsAgent is one end of a pair reached over the fallback.
type wsAgent struct {
	t      *testing.T
	conn   *websocket.Conn
	key    [32]byte
	ticket []byte
	peer   [32]byte
}

// dialFallback connects an agent to the edge and completes the handshake.
func dialFallback(t *testing.T, url string, self, peer [32]byte) *wsAgent {
	t.Helper()

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	conn, _, err := websocket.Dial(ctx, url, &websocket.DialOptions{
		Subprotocols: []string{fallback.Subprotocol},
	})
	if err != nil {
		t.Fatalf("dialling the fallback: %v", err)
	}
	t.Cleanup(func() { _ = conn.CloseNow() })

	conn.SetReadLimit(fallback.MaxFrame + 64)

	hello, err := fallback.EncodeHello(self)
	if err != nil {
		t.Fatal(err)
	}
	if err := conn.Write(ctx, websocket.MessageBinary, hello); err != nil {
		t.Fatalf("sending the hello: %v", err)
	}

	_, msg, err := conn.Read(ctx)
	if err != nil {
		t.Fatalf("reading the ready: %v", err)
	}

	kind, payload, err := fallback.Decode(msg)
	if err != nil || kind != fallback.KindReady {
		t.Fatalf("expected a ready frame, got %#x (%v)", byte(kind), err)
	}
	if observed, err := fallback.DecodeReady(payload); err != nil || observed == "" {
		t.Fatalf("the ready frame carried no observed address: %q (%v)", observed, err)
	}

	ticket := &disco.Ticket{
		TenantID:  7,
		ExpiresAt: time.Now().Add(time.Minute).Unix(),
		Self:      self,
		Peer:      peer,
	}
	ticket.Sign([]byte(testSecret))

	return &wsAgent{t: t, conn: conn, key: self, ticket: ticket.Encode(), peer: peer}
}

// bind presents the ticket over the fallback and waits for the acknowledgement.
func (w *wsAgent) bind() {
	w.t.Helper()

	pkt := make([]byte, disco.HeaderLen, disco.HeaderLen+len(w.ticket))
	disco.WriteHeader(pkt, disco.TypeRelayBind, w.key)
	pkt = append(pkt, w.ticket...)

	frame, err := fallback.Encode(fallback.KindBind, pkt)
	if err != nil {
		w.t.Fatal(err)
	}

	w.write(frame)

	if kind, payload := w.next(2 * time.Second); kind != fallback.KindBindAck {
		w.t.Fatalf("expected a bind acknowledgement, got %#x", byte(kind))
	} else if peer, err := fallback.DecodeBindAck(payload); err != nil || peer != w.peer {
		w.t.Fatalf("the acknowledgement named the wrong peer (%v)", err)
	}
}

func (w *wsAgent) write(msg []byte) {
	w.t.Helper()

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	if err := w.conn.Write(ctx, websocket.MessageBinary, msg); err != nil {
		w.t.Fatalf("writing a frame: %v", err)
	}
}

// send writes tunnel traffic for the peer.
func (w *wsAgent) send(payload string) {
	w.t.Helper()

	frame, err := fallback.EncodeData(w.peer, []byte(payload))
	if err != nil {
		w.t.Fatal(err)
	}

	w.write(frame)
}

// next reads one frame.
func (w *wsAgent) next(within time.Duration) (fallback.Kind, []byte) {
	w.t.Helper()

	ctx, cancel := context.WithTimeout(context.Background(), within)
	defer cancel()

	_, msg, err := w.conn.Read(ctx)
	if err != nil {
		w.t.Fatalf("reading a frame: %v", err)
	}

	kind, payload, err := fallback.Decode(msg)
	if err != nil {
		w.t.Fatalf("decoding a frame: %v", err)
	}

	return kind, payload
}

// expect reads until the wanted tunnel payload arrives, tolerating others.
func (w *wsAgent) expect(want string, within time.Duration) {
	w.t.Helper()

	deadline := time.Now().Add(within)

	for time.Now().Before(deadline) {
		kind, payload := w.next(time.Until(deadline))
		if kind != fallback.KindData {
			continue
		}

		from, tunnelBytes, err := fallback.DecodeData(payload)
		if err != nil {
			continue
		}
		if from != w.peer {
			w.t.Fatalf("a frame arrived attributed to %x…, not to the peer", from[:6])
		}
		if string(tunnelBytes) == want {
			return
		}
	}

	w.t.Fatalf("expected %q over the fallback, and it never arrived", want)
}

// startEdge puts the fallback in front of a relay and returns its URL.
func startEdge(t *testing.T, f *relayFixture, coordinator string) string {
	t.Helper()

	edge, err := f.relay.NewEdge(EdgeOptions{Coordinator: coordinator})
	if err != nil {
		t.Fatalf("building the edge: %v", err)
	}

	server := httptest.NewServer(edge.Handler("/ws"))
	t.Cleanup(server.Close)

	return server.URL + "/ws"
}

// The field case: one machine cannot use UDP at all, the other is fine. The
// pair must still carry traffic, and the UDP end must not be able to tell.
func TestAPairWorksWithOneEndOnTheFallback(t *testing.T) {
	f := startRelay(t)
	url := startEdge(t, f, "")

	alpha, beta := key(1), key(2)

	udp := newAgent(t, alpha, beta, f.control)
	udp.bind(t)

	ws := dialFallback(t, url, beta, alpha)
	ws.bind()

	// The UDP end's data address is learned from its first packet, exactly as
	// it is for an all-UDP pair.
	udp.send(t, "from-udp")
	ws.expect("from-udp", 3*time.Second)

	ws.send("from-https")
	udp.expect(t, "from-https", 3*time.Second)
}

// Both ends blocked is the hotel-Wi-Fi case, and it is the one that proves the
// path needs no UDP anywhere.
func TestAPairWorksWithBothEndsOnTheFallback(t *testing.T) {
	f := startRelay(t)
	url := startEdge(t, f, "")

	alpha, beta := key(3), key(4)

	one := dialFallback(t, url, alpha, beta)
	one.bind()

	two := dialFallback(t, url, beta, alpha)
	two.bind()

	one.send("one-to-two")
	two.expect("one-to-two", 3*time.Second)

	two.send("two-to-one")
	one.expect("two-to-one", 3*time.Second)
}

// A ticket issued to somebody else must not bind, however it arrives. The
// fallback is a way around the customer's firewall, not around the ticket.
func TestAFallbackBindWithSomebodyElsesTicketIsRefused(t *testing.T) {
	f := startRelay(t)
	url := startEdge(t, f, "")

	alpha, beta, impostor := key(5), key(6), key(7)

	ws := dialFallback(t, url, impostor, beta)

	// A ticket that verifies perfectly — it just names alpha, not the key this
	// connection announced.
	ticket := &disco.Ticket{
		TenantID:  7,
		ExpiresAt: time.Now().Add(time.Minute).Unix(),
		Self:      alpha,
		Peer:      beta,
	}
	ticket.Sign([]byte(testSecret))

	pkt := make([]byte, disco.HeaderLen)
	disco.WriteHeader(pkt, disco.TypeRelayBind, alpha)
	pkt = append(pkt, ticket.Encode()...)

	frame, err := fallback.Encode(fallback.KindBind, pkt)
	if err != nil {
		t.Fatal(err)
	}
	ws.write(frame)

	// No acknowledgement, and no session: the refusal is silent to the caller
	// on purpose, so a prober learns nothing from the difference.
	ctx, cancel := context.WithTimeout(context.Background(), 500*time.Millisecond)
	defer cancel()

	if _, _, err := ws.conn.Read(ctx); err == nil {
		t.Fatal("the relay answered a bind for a key the connection does not hold")
	}

	if got := f.relay.Sessions(); got != 0 {
		t.Fatalf("a refused bind created %d session(s)", got)
	}
}

// The coordinator is unchanged by any of this: it receives ordinary UDP from
// the relay and replies to the source.
func TestControlFramesReachTheCoordinatorAndComeBack(t *testing.T) {
	coordinator, err := net.ListenUDP("udp", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	defer coordinator.Close()

	go func() {
		buf := make([]byte, 1600)
		for {
			n, from, err := coordinator.ReadFromUDPAddrPort(buf)
			if err != nil {
				return
			}
			_, _ = coordinator.WriteToUDPAddrPort(append([]byte("ack:"), buf[:n]...), from)
		}
	}()

	f := startRelay(t)
	url := startEdge(t, f, coordinator.LocalAddr().String())

	ws := dialFallback(t, url, key(8), key(9))

	frame, err := fallback.Encode(fallback.KindControl, []byte("sealed-hello"))
	if err != nil {
		t.Fatal(err)
	}
	ws.write(frame)

	kind, payload := ws.next(3 * time.Second)
	if kind != fallback.KindControl {
		t.Fatalf("expected the coordinator's answer as a control frame, got %#x", byte(kind))
	}
	if string(payload) != "ack:sealed-hello" {
		t.Fatalf("the coordinator's answer came back as %q", payload)
	}
}

// When UDP starts working again the relay must stop paying for TLS. The
// evidence is a packet arriving on the side's own socket, not a claim.
func TestAnEndThatRecoversUDPLeavesTheFallback(t *testing.T) {
	f := startRelay(t)
	url := startEdge(t, f, "")

	alpha, beta := key(10), key(11)

	ws := dialFallback(t, url, alpha, beta)
	ws.bind()

	peer := newAgent(t, beta, alpha, f.control)
	peer.bind(t)

	// The UDP end speaks first so the relay learns which of its ports the
	// tunnel traffic comes from — under a symmetric NAT that is not the port
	// the bind arrived on, and until a packet arrives nothing knows it.
	peer.send(t, "from-udp")
	ws.expect("from-udp", 3*time.Second)

	ws.send("over-https")
	peer.expect(t, "over-https", 3*time.Second)

	// The same device now binds over UDP and sends from its data socket.
	recovered := newAgent(t, alpha, beta, f.control)
	recovered.bind(t)
	recovered.send(t, "over-udp")
	peer.expect(t, "over-udp", 3*time.Second)

	// Traffic for it must now go to the socket, not the connection.
	peer.send(t, "back-on-udp")
	recovered.expect(t, "back-on-udp", 3*time.Second)
}

// key builds a distinct public key for a test peer.
func key(n byte) [32]byte {
	var k [32]byte
	k[0] = n
	k[31] = n

	return k
}
