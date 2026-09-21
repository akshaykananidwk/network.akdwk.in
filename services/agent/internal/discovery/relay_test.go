package discovery

import (
	"net/netip"
	"testing"

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
