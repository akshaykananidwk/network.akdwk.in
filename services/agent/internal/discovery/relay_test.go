package discovery

import (
	"net/netip"
	"testing"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
)

// Relay offers, failover and latency reporting.
//
// Split from discovery_test.go, which covers punching and candidates, so that
// neither file outgrows what somebody can read in one sitting. The harness
// both use lives next door.
// deliverRelayOffer hands the client an offer as if the coordinator sent it.
func (h *harness) deliverRelayOffer(t *testing.T, peer [32]byte, endpoint, name string) {
	t.Helper()

	offer := &disco.RelayOffer{
		Peer:     peer,
		Endpoint: endpoint,
		Ticket:   make([]byte, disco.TicketLen),
		Name:     name,
	}

	body, err := offer.Encode()
	if err != nil {
		t.Fatal(err)
	}

	sealed, err := disco.Seal(body, &h.client.opts.SelfPublic, &h.coordPriv)
	if err != nil {
		t.Fatal(err)
	}

	pkt := make([]byte, disco.HeaderLen)
	disco.WriteHeader(pkt, disco.TypeRelayOffer, h.coordPub)
	h.client.handle(append(pkt, sealed...), netip.MustParseAddrPort("10.0.0.1:8443"))
}

// An offer is the first thing that writes to the relay bookkeeping, so it is
// the first thing that finds out whether that bookkeeping exists. It did not:
// the maps were declared and never made, and the agent panicked with
// "assignment to entry in nil map" the moment a coordinator offered it a
// relay — which is to say, for every device behind a symmetric NAT.
func TestAcceptsARelayOfferWithoutCrashing(t *testing.T) {
	h := newHarness(t)

	var peer [32]byte
	peer[0] = 7

	h.deliverRelayOffer(t, peer, "10.0.0.1:9000", "lab-a")

	// A bind must have gone to the relay's control address, which is the only
	// externally visible sign that the offer was accepted.
	var bound bool
	h.transport.mu.Lock()
	for _, p := range h.transport.sent {
		if p.kind == disco.TypeRelayBind && p.to == netip.MustParseAddrPort("10.0.0.1:9000") {
			bound = true
		}
	}
	h.transport.mu.Unlock()

	if !bound {
		t.Fatal("the agent did not bind to the relay it was offered")
	}
}

// The relay's name has to survive the offer, because it is what the agent
// gives back when it asks for a different relay. Without it a failover asks
// the same question and gets the same answer.
func TestRemembersWhichRelayItWasPutOn(t *testing.T) {
	h := newHarness(t)

	var peer [32]byte
	peer[0] = 7

	h.deliverRelayOffer(t, peer, "10.0.0.1:9000", "lab-a")

	// adoptRelay is what marks the path relayed, and it runs on the bind ack.
	h.client.adoptRelay(peer, netip.MustParseAddrPort("10.0.0.1:41000"))

	if got := h.client.RelayName(peer); got != "lab-a" {
		t.Fatalf("relay name after an offer = %q, want %q", got, "lab-a")
	}
}

// A relay that stops acknowledging rebinds is gone, and the agent must ask for
// another one by name rather than re-presenting its ticket forever.
func TestAsksForAnotherRelayWhenRebindsGoUnanswered(t *testing.T) {
	h := newHarness(t)

	var peer [32]byte
	peer[0] = 7

	h.deliverRelayOffer(t, peer, "10.0.0.1:9000", "lab-a")
	h.client.adoptRelay(peer, netip.MustParseAddrPort("10.0.0.1:41000"))

	// Rebind more times than the failover threshold, never acknowledging.
	for i := 0; i <= relayMissesBeforeFailover; i++ {
		h.client.rebindRelays()
	}

	var asked bool
	h.transport.mu.Lock()
	for _, p := range h.transport.sent {
		if p.kind == disco.TypeRelayRequest && p.to == h.client.opts.Coordinator {
			asked = true
		}
	}
	h.transport.mu.Unlock()

	if !asked {
		t.Fatalf("after %d unanswered rebinds the agent never asked for another relay",
			relayMissesBeforeFailover+1)
	}
}

// And it must not fail over while the relay is answering, however long the
// session lasts.
func TestDoesNotFailOverWhileTheRelayIsAnswering(t *testing.T) {
	h := newHarness(t)

	var peer [32]byte
	peer[0] = 7

	h.deliverRelayOffer(t, peer, "10.0.0.1:9000", "lab-a")
	h.client.adoptRelay(peer, netip.MustParseAddrPort("10.0.0.1:41000"))

	for i := 0; i < relayMissesBeforeFailover*4; i++ {
		h.client.rebindRelays()
		// The relay answers, as a live one does.
		h.client.handleRelayBindAck(
			netip.MustParseAddrPort("10.0.0.1:9000"),
			[]byte{0xA0, 0x08},
		)
	}

	var requests int
	h.transport.mu.Lock()
	for _, p := range h.transport.sent {
		if p.kind == disco.TypeRelayRequest {
			requests++
		}
	}
	h.transport.mu.Unlock()

	if requests != 0 {
		t.Fatalf("failed over %d time(s) while the relay was answering", requests)
	}
}

// The relay that was dead before it was ever used.
//
// Failover used to key on the peer's path already being "relay", which only
// becomes true once a bind has been acknowledged. A relay that had already
// died when the coordinator offered it therefore never got rebound, never
// accumulated a missed count, and never triggered a request for another one —
// the pair sat in "connecting" for as long as anyone cared to watch. One dead
// relay stranded every new pair it was handed to, which is a worse failure
// than the one failover was written for.
func TestFailsOverFromARelayThatNeverWorked(t *testing.T) {
	h := newHarness(t)

	var peer [32]byte
	peer[0] = 7

	// An offer arrives and is bound to, but nothing ever answers: this is what
	// a relay that is already down looks like from the agent's side.
	h.deliverRelayOffer(t, peer, "10.0.0.1:9000", "lab-a")

	if got := h.client.Path(peer); got == "relay" {
		t.Fatal("precondition: the path must not be relayed until a bind is acknowledged")
	}

	for i := 0; i <= relayMissesBeforeFailover; i++ {
		h.client.rebindRelays()
	}

	var asked bool
	h.transport.mu.Lock()
	for _, p := range h.transport.sent {
		if p.kind == disco.TypeRelayRequest && p.to == h.client.opts.Coordinator {
			asked = true
		}
	}
	h.transport.mu.Unlock()

	if !asked {
		t.Fatal("the agent never asked for another relay; it is stranded on a dead one")
	}
}

// A peer that has asked for a relay and not yet been offered one must not be
// mistaken for a relay failure. The silence there is the coordinator's, and
// asking it again every five seconds would be shouting at the wrong party.
func TestDoesNotFailOverBeforeARelayHasBeenOffered(t *testing.T) {
	h := newHarness(t)

	var peer [32]byte
	peer[0] = 7

	// escalateStalledPeers marks the peer as asked with a zero address.
	h.client.mu.Lock()
	h.client.relayControl[peer] = netip.AddrPort{}
	h.client.paths[peer] = pathConnecting
	h.client.mu.Unlock()

	for i := 0; i <= relayMissesBeforeFailover*2; i++ {
		h.client.rebindRelays()
	}

	var requests int
	h.transport.mu.Lock()
	for _, p := range h.transport.sent {
		if p.kind == disco.TypeRelayRequest {
			requests++
		}
	}
	h.transport.mu.Unlock()

	if requests != 0 {
		t.Fatalf("asked for a relay %d time(s) while still waiting for the first offer", requests)
	}
}

// Measurements have to reach the coordinator before the agent first needs a
// relay, or latency-based selection never applies to a first assignment.
//
// The keepalive that used to carry them is twenty seconds; the deadline that
// sends a stalled peer to a relay is five. An agent reporting only on the
// keepalive had therefore said nothing at all by the time the coordinator had
// to choose, and the choice fell back to whichever relay was first in the
// fleet — so the feature applied from the second assignment onwards, which is
// not what it says on the tin.
func TestReportsMeasurementsWithoutWaitingForTheKeepalive(t *testing.T) {
	h := newHarness(t)

	h.client.SetRelays([]RelayTarget{
		{Name: "lab-a", Addr: netip.MustParseAddrPort("10.0.0.1:9000")},
	})

	// A probe goes out and the relay answers, as a live one does.
	h.client.probeRelays()

	h.client.mu.Lock()
	var nonce [disco.ProbeNonceLen]byte
	for n := range h.client.probesInFlight {
		nonce = n
	}
	h.client.mu.Unlock()

	h.client.handleProbeAck(nonce[:])
	h.client.maybeReportRelayRTT()

	var reported bool
	h.transport.mu.Lock()
	for _, p := range h.transport.sent {
		if p.kind == disco.TypeRelayRTT && p.to == h.client.opts.Coordinator {
			reported = true
		}
	}
	h.transport.mu.Unlock()

	if !reported {
		t.Fatal("the agent measured a relay and told nobody until the next keepalive")
	}
}

// And it must not send the same measurement over and over: a device on a
// metered connection should not pay to repeat itself.
func TestDoesNotRepeatAnUnchangedMeasurement(t *testing.T) {
	h := newHarness(t)

	h.client.SetRelays([]RelayTarget{
		{Name: "lab-a", Addr: netip.MustParseAddrPort("10.0.0.1:9000")},
	})
	h.client.probeRelays()

	h.client.mu.Lock()
	var nonce [disco.ProbeNonceLen]byte
	for n := range h.client.probesInFlight {
		nonce = n
	}
	h.client.mu.Unlock()

	h.client.handleProbeAck(nonce[:])

	for i := 0; i < 10; i++ {
		h.client.maybeReportRelayRTT()
	}

	var reports int
	h.transport.mu.Lock()
	for _, p := range h.transport.sent {
		if p.kind == disco.TypeRelayRTT {
			reports++
		}
	}
	h.transport.mu.Unlock()

	if reports != 1 {
		t.Fatalf("sent %d reports for one measurement, want 1", reports)
	}
}

// Defect 16, from the first two-PC test on the real internet.
//
// The coordinator logged that it had offered the relay to both devices:
//
//	08:33:56 offered dev_1eb85… the relay mumbai-1 for peer 174f…
//	08:33:57 offered dev_d66b… the relay mumbai-1 for peer 4992…
//
// A twenty-second capture on the relay's control and data ports saw not one
// packet from either device. The offer carries the endpoint as the string the
// coordinator was configured with — AKCONNECT_RELAYS=mumbai-1:in:edge.akdwk.in:9000,
// a hostname, because that is what DEPLOY.md tells the operator to use — and
// the agent parsed it as a literal address, which never succeeds. Every relay
// drill in the lab passed because the lab configures its relays by IP.
func TestARelayOfferedByHostnameIsStillUsed(t *testing.T) {
	// The fleet the panel published, resolved at start-up. This is the path
	// that must be taken: the address is already known, so the offer needs no
	// DNS at all.
	fleet := []RelayTarget{{
		Name: "mumbai-1",
		Addr: netip.MustParseAddrPort("203.0.113.9:9000"),
	}}

	c := &Client{relays: fleet, relayControl: map[[32]byte]netip.AddrPort{}}

	offer := &disco.RelayOffer{
		Name:     "mumbai-1",
		Endpoint: "edge.akdwk.in:9000",
	}

	got, ok := c.relayEndpoint(offer)
	if !ok {
		t.Fatal("a relay offered by hostname was discarded, so nothing would ever reach it")
	}
	if got != netip.MustParseAddrPort("203.0.113.9:9000") {
		t.Fatalf("bound to %s, not the address the panel published", got)
	}

	// A literal address still works, which is what the lab exercises.
	literal, ok := c.relayEndpoint(&disco.RelayOffer{Name: "lab-a", Endpoint: "10.0.0.1:9000"})
	if !ok || literal != netip.MustParseAddrPort("10.0.0.1:9000") {
		t.Fatalf("a literal endpoint broke: %s ok=%v", literal, ok)
	}
}

// The resolver itself, including the parts that decide what "usable" means.
func TestResolvingARelayEndpoint(t *testing.T) {
	cases := []struct {
		in   string
		want string
		ok   bool
	}{
		{"10.0.0.1:9000", "10.0.0.1:9000", true},
		{"[::1]:9000", "[::1]:9000", true},
		{"localhost:9000", "127.0.0.1:9000", true},
		// The shape that produced the field failure, and the ones next to it.
		{"edge.example.invalid:9000", "", false},
		{"edge.akdwk.in", "", false},
		{"edge.akdwk.in:0", "", false},
		{"edge.akdwk.in:notaport", "", false},
		{"", "", false},
	}

	for _, tc := range cases {
		got, err := resolveHostPort(tc.in)
		if tc.ok && err != nil {
			t.Fatalf("resolveHostPort(%q) failed: %v", tc.in, err)
		}
		if !tc.ok {
			if err == nil {
				t.Fatalf("resolveHostPort(%q) accepted an endpoint it cannot use: %s", tc.in, got)
			}

			continue
		}
		if got.String() != tc.want {
			t.Fatalf("resolveHostPort(%q) = %s, want %s", tc.in, got, tc.want)
		}
	}
}

// A relay that went quiet because the network stopped carrying UDP is not a
// relay fault, and asking the coordinator for another one is a UDP packet to a
// party that is not answering either.
//
// This is the laptop carried from a working network into an office that drops
// UDP. The pair was on a relay, so when the network died the relay stopped
// answering first, and the agent spent eighteen seconds of a thirty-second
// budget on a relay change that could not possibly work before anything
// concluded the network was the problem. A device that cannot reach one relay
// over UDP cannot reach another.
func TestDoesNotChangeRelayWhileTheCoordinatorIsAlsoSilent(t *testing.T) {
	h := newHarness(t)

	var peer [32]byte
	peer[0] = 9

	h.client.mu.Lock()
	h.client.relayControl[peer] = netip.MustParseAddrPort("10.0.0.9:9000")
	h.client.relayTicket[peer] = []byte("ticket")
	h.client.relayName[peer] = "lab-b"
	h.client.paths[peer] = pathRelay
	h.client.mu.Unlock()

	// The network dies: announcements go out and nothing comes back. This is
	// what the fallback supervisor acts on too, and the two must agree.
	h.client.mu.Lock()
	h.client.unacked = relayFailoverNeedsAnswers
	h.client.firstSend = time.Now().Add(-2 * relayFailoverNeedsCoordinator)
	h.client.lastAck = time.Time{}
	h.client.mu.Unlock()

	for i := 0; i <= relayMissesBeforeFailover*3; i++ {
		h.client.rebindRelays()
	}

	if got := h.transport.count(disco.TypeRelayRequest); got != 0 {
		t.Fatalf("asked for another relay %d time(s) on a network that carries no UDP", got)
	}

	// And it keeps re-presenting the ticket, which costs one packet every five
	// seconds and is how the relay is found again the moment UDP comes back.
	if got := h.transport.count(disco.TypeRelayBind); got == 0 {
		t.Fatal("stopped rebinding as well, so nothing would notice UDP returning")
	}
}

// The other half: relay failover still has to work when UDP to the
// coordinator is fine and only the relay has gone. That is the case it was
// written for, and standing down whenever a relay goes quiet would strand
// every pair on a dead relay.
func TestStillChangesRelayWhenTheCoordinatorIsAnswering(t *testing.T) {
	h := newHarness(t)

	var peer [32]byte
	peer[0] = 11

	h.client.mu.Lock()
	h.client.relayControl[peer] = netip.MustParseAddrPort("10.0.0.9:9000")
	h.client.relayTicket[peer] = []byte("ticket")
	h.client.relayName[peer] = "lab-a"
	h.client.paths[peer] = pathRelay
	// The coordinator answered a moment ago, so UDP is working.
	h.client.unacked = 0
	h.client.lastAck = time.Now()
	h.client.mu.Unlock()

	for i := 0; i <= relayMissesBeforeFailover*2; i++ {
		h.client.rebindRelays()
	}

	if got := h.transport.count(disco.TypeRelayRequest); got == 0 {
		t.Fatal("never asked for another relay although the coordinator was answering")
	}
}

// A relay that has gone quiet is a reason to ask the coordinator now.
//
// A settled agent talks to the coordinator once every twenty seconds, so when
// the network dies under it nothing notices until the next keepalive — which
// was most of the time it then took to reach the fallback. Relay rebinds go
// out every five seconds and are acknowledged, so on a relayed pair the same
// silence is visible three times sooner.
func TestAsksTheCoordinatorWhenRebindsGoQuiet(t *testing.T) {
	h := newHarness(t)

	var peer [32]byte
	peer[0] = 13

	h.client.mu.Lock()
	h.client.relayControl[peer] = netip.MustParseAddrPort("10.0.0.9:9000")
	h.client.relayTicket[peer] = []byte("ticket")
	h.client.paths[peer] = pathRelay
	h.client.lastAck = time.Now()
	h.client.mu.Unlock()

	before := h.transport.count(disco.TypeHello) + h.transport.count(disco.TypePing)

	// One miss is a lost packet and must not produce anything.
	h.client.rebindRelays()
	if got := h.transport.count(disco.TypeHello) + h.transport.count(disco.TypePing); got != before {
		t.Fatal("one unanswered rebind asked the coordinator; a single lost packet must not")
	}

	for i := 1; i < relayMissesBeforeAsking; i++ {
		h.client.rebindRelays()
	}

	if got := h.transport.count(disco.TypeHello) + h.transport.count(disco.TypePing); got <= before {
		t.Fatalf("%d unanswered rebinds and the coordinator was never asked", relayMissesBeforeAsking)
	}
}

// And nothing extra goes out while the relay is answering, or every healthy
// relayed pair would announce every five seconds for ever.
func TestDoesNotAskTheCoordinatorWhileTheRelayAnswers(t *testing.T) {
	h := newHarness(t)

	var peer [32]byte
	peer[0] = 17

	h.client.mu.Lock()
	h.client.relayControl[peer] = netip.MustParseAddrPort("10.0.0.9:9000")
	h.client.relayTicket[peer] = []byte("ticket")
	h.client.paths[peer] = pathRelay
	h.client.lastAck = time.Now()
	h.client.mu.Unlock()

	before := h.transport.count(disco.TypeHello) + h.transport.count(disco.TypePing)

	for i := 0; i < 10; i++ {
		h.client.rebindRelays()
		// The relay answers every time, which is what clears the counter.
		h.client.mu.Lock()
		h.client.relayMissed[peer] = 0
		h.client.mu.Unlock()
	}

	if got := h.transport.count(disco.TypeHello) + h.transport.count(disco.TypePing); got != before {
		t.Fatalf("announced %d time(s) while the relay was answering normally", got-before)
	}
}

// While the fallback is carrying a peer, the RELAY's answer must not steal
// it — and the FALLBACK's own answer must still be applied.
//
// Both arrive here while the fallback is up. A relay bind is answered twice on
// a network with no UDP: once by the relay naming a real port, once by the
// fallback naming a tunnelled one, and the relay's answer arrives THROUGH the
// fallback, so it arrives even though the port it names is unreachable.
// Applying it made the endpoint flap, and while it was flapped the device
// reported relay-udp: the wrong answer, on the release whose point is telling
// those two apart.
//
// The first attempt at this refused on "the fallback is carrying this peer",
// which refused the fallback's own answer too and left WireGuard pointed at
// the relay it had been using before the network died. Four of five lab runs
// still reported relay-udp, for the opposite reason. The address is the only
// thing that tells the two answers apart.
func TestARelayOfferDoesNotStealAPeerTheFallbackIsCarrying(t *testing.T) {
	h := newHarness(t)

	var peer [32]byte
	peer[0] = 19

	tunnelled := netip.MustParseAddrPort("10.0.0.1:65000")
	real_ := netip.MustParseAddrPort("10.0.0.1:52386")

	carrying := true
	h.client.opts.DivertedTo = func([32]byte) (netip.AddrPort, bool) {
		return tunnelled, carrying
	}

	// The fallback's own answer is applied: without it nothing ever points
	// WireGuard at the tunnel.
	h.client.adoptRelay(peer, tunnelled)
	if got := h.peers.endpointOf(base64Key(peer)); got != tunnelled.String() {
		t.Fatalf("endpoint is %q after the fallback answered, want %s", got, tunnelled)
	}

	// The relay's own answer, arriving through that same tunnel, is not.
	h.client.adoptRelay(peer, real_)
	if got := h.peers.endpointOf(base64Key(peer)); got != tunnelled.String() {
		t.Fatalf("a relay offer moved the endpoint to %q while the fallback was carrying the peer", got)
	}

	// The path is still relayed, because it is — the panel has to say so.
	if h.client.Path(peer) != "relay" {
		t.Fatalf("path is %q, so the device would not report a relayed path at all", h.client.Path(peer))
	}

	// And once the fallback hands the peer back, the next offer is applied.
	carrying = false
	h.client.adoptRelay(peer, real_)
	if got := h.peers.endpointOf(base64Key(peer)); got != real_.String() {
		t.Fatalf("endpoint is %q after the fallback released the peer, want %s", got, real_)
	}
}

// Standing down while the coordinator is silent must not last for ever.
//
// A home PC on its own ISP went from "Online · relay-udp" to
// "Online · connecting" by itself, minutes after traffic had been flowing.
// The relay had stopped answering and the coordinator had stopped answering
// at the same moment — they share one socket, so whatever broke one broke
// both — and failover stood down waiting for a takeover that was never
// coming: that panel's Apache had not been configured yet, so the fallback
// could not connect at all. The agent sat pinned on a dead relay until its
// WireGuard handshake went stale and the panel called it "connecting".
//
// Past the bound, asking costs one packet and is the only move left.
func TestFailoverStandDownIsBounded(t *testing.T) {
	h := newHarness(t)

	var peer [32]byte
	peer[0] = 23

	h.client.mu.Lock()
	h.client.relayControl[peer] = netip.MustParseAddrPort("10.0.0.9:9000")
	h.client.relayTicket[peer] = []byte("ticket")
	h.client.paths[peer] = pathRelay
	// The network stopped carrying UDP and has not started again.
	h.client.unacked = relayFailoverNeedsAnswers
	h.client.firstSend = time.Now().Add(-2 * relayFailoverNeedsCoordinator)
	h.client.lastAck = time.Time{}
	h.client.mu.Unlock()

	for i := 0; i <= relayMissesBeforeFailover*3; i++ {
		h.client.rebindRelays()
	}

	if got := h.transport.count(disco.TypeRelayRequest); got != 0 {
		t.Fatalf("asked for another relay %d time(s) while still inside the stand-down", got)
	}

	// Wind the clock past the bound. Nothing else changes: the coordinator is
	// still silent and the fallback still has not taken over.
	h.client.mu.Lock()
	h.client.standDownSince = time.Now().Add(-2 * relayFailoverStandDownMax)
	h.client.mu.Unlock()

	for i := 0; i <= relayMissesBeforeFailover; i++ {
		h.client.rebindRelays()
	}

	if got := h.transport.count(disco.TypeRelayRequest); got == 0 {
		t.Fatalf("never asked for another relay, %s after the coordinator went quiet",
			relayFailoverStandDownMax)
	}
}

// And the clock is about the coordinator, not about one peer: it restarts
// once the coordinator is answering again, so a later silence gets its own
// full stand-down instead of inheriting a spent one.
func TestTheStandDownClockRestartsWhenTheCoordinatorAnswers(t *testing.T) {
	h := newHarness(t)

	var peer [32]byte
	peer[0] = 29

	h.client.mu.Lock()
	h.client.relayControl[peer] = netip.MustParseAddrPort("10.0.0.9:9000")
	h.client.relayTicket[peer] = []byte("ticket")
	h.client.paths[peer] = pathRelay
	h.client.standDownSince = time.Now().Add(-2 * relayFailoverStandDownMax)
	// The coordinator is answering.
	h.client.unacked = 0
	h.client.lastAck = time.Now()
	h.client.mu.Unlock()

	h.client.rebindRelays()

	h.client.mu.Lock()
	spent := h.client.standDownSince
	h.client.mu.Unlock()

	if !spent.IsZero() {
		t.Fatal("the stand-down clock kept running while the coordinator was answering")
	}
}

// A relay offer that never arrives must be asked for again.
//
// This was "asked once, never again": the entry that records the request is
// written before it is sent, rebindRelays skips a peer whose relay address is
// still zero, and nothing else revisited it. One lost datagram — the request
// going out or the offer coming back — stranded that pair until somebody
// restarted the service.
//
// A home laptop on its own ISP did exactly that: one "asking for a relay" in
// the log, then ten minutes of punching into a network that could not carry
// it, with the coordinator answering the whole time. The panel said
// "connecting" and the machine had been moving traffic over a relay minutes
// before.
func TestARelayThatIsNeverOfferedIsAskedForAgain(t *testing.T) {
	h := newHarness(t)

	var peer [32]byte
	peer[0] = 31

	// A peer that has been stalled long enough to want a relay.
	h.client.mu.Lock()
	h.client.paths[peer] = pathConnecting
	h.client.firstSeen[peer] = time.Now().Add(-2 * punchDeadline)
	h.client.mu.Unlock()

	h.client.escalateStalledPeers()

	if got := h.transport.count(disco.TypeRelayRequest); got != 1 {
		t.Fatalf("asked %d time(s) on the first escalation, want 1", got)
	}

	// Nothing answers. Before the timeout it must not ask again, or a busy
	// coordinator would be asked once a second.
	h.client.escalateStalledPeers()

	if got := h.transport.count(disco.TypeRelayRequest); got != 1 {
		t.Fatalf("asked %d time(s) while still inside the offer timeout, want 1", got)
	}

	// Past it, ask again: the answer is not coming and waiting is not a plan.
	h.client.mu.Lock()
	h.client.relayAskedAt[peer] = time.Now().Add(-2 * relayOfferTimeout)
	h.client.mu.Unlock()

	h.client.escalateStalledPeers()

	if got := h.transport.count(disco.TypeRelayRequest); got != 2 {
		t.Fatalf("asked %d time(s) after the offer timeout, want 2 — the pair is stranded", got)
	}

	// And once an offer does arrive, the asking stops.
	h.client.mu.Lock()
	h.client.relayControl[peer] = netip.MustParseAddrPort("10.0.0.9:9000")
	h.client.mu.Unlock()

	h.client.escalateStalledPeers()

	if got := h.transport.count(disco.TypeRelayRequest); got != 2 {
		t.Fatalf("asked %d time(s) after a relay was offered, want 2", got)
	}
}
