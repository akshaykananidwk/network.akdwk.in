package server

import (
	"testing"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/coordinator/internal/registry"
	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
)

// fleet builds a server with relays and nothing else. None of these tests send
// a packet: the question is which relay comes out, and that is a pure decision.
func fleet(names ...string) *Server {
	relays := make([]*RelayTarget, 0, len(names))
	for _, name := range names {
		relays = append(relays, &RelayTarget{Name: name, Endpoint: name + ":9000", Secret: []byte("s")})
	}

	return &Server{opts: Options{Relays: relays}, health: newRelayHealth()}
}

func device(rtt map[string]uint16) *registry.Entry {
	return &registry.Entry{RelayRTT: rtt, RTTReportedAt: time.Now()}
}

func TestPicksTheRelayWithTheLowestMeasuredRTT(t *testing.T) {
	s := fleet("far", "near", "middling")

	self := device(map[string]uint16{"far": 200, "near": 10, "middling": 80})
	peer := device(map[string]uint16{"far": 210, "near": 12, "middling": 90})

	got := s.pickRelay(self, peer, "")
	if got == nil || got.Name != "near" {
		t.Fatalf("want the nearest relay, got %v", nameOf(got))
	}
}

// The relay must suit the pair, not whichever end happened to ask. A relay
// next door to one device and across the country from the other is a bad relay
// for the conversation between them.
func TestPicksForTheWorseEndNotTheAsker(t *testing.T) {
	s := fleet("alpha-side", "between")

	self := device(map[string]uint16{"alpha-side": 5, "between": 60})
	peer := device(map[string]uint16{"alpha-side": 400, "between": 65})

	got := s.pickRelay(self, peer, "")
	if got == nil || got.Name != "between" {
		t.Fatalf("want the relay that suits both ends, got %v", nameOf(got))
	}
}

// Region is the requirement's explicit edge case: it must be possible to leave
// it empty and still get the nearest relay.
func TestSelectionWorksWithNoRegionsSetAnywhere(t *testing.T) {
	s := fleet("one", "two")

	self := device(map[string]uint16{"one": 120, "two": 30})
	peer := device(map[string]uint16{"one": 130, "two": 35})

	if self.Region != "" || peer.Region != "" {
		t.Fatal("this test is only meaningful with regions unset")
	}

	got := s.pickRelay(self, peer, "")
	if got == nil || got.Name != "two" {
		t.Fatalf("want the measured-nearest relay, got %v", nameOf(got))
	}
}

// And a region must not be able to override a measurement that contradicts it.
func TestRegionDoesNotBeatAClearlyBetterMeasurement(t *testing.T) {
	s := fleet("in-region", "measured-closer")
	s.opts.Relays[0].Region = "in"

	self := device(map[string]uint16{"in-region": 90, "measured-closer": 20})
	self.Region = "in"
	peer := device(map[string]uint16{"in-region": 95, "measured-closer": 25})

	got := s.pickRelay(self, peer, "")
	if got == nil || got.Name != "measured-closer" {
		t.Fatalf("a region label beat a 70ms difference; got %v", nameOf(got))
	}
}

// A relay one end cannot reach cannot carry the pair, however good the other
// end's number is.
func TestRefusesARelayOneEndCannotReach(t *testing.T) {
	s := fleet("unreachable-for-peer", "works")

	self := device(map[string]uint16{"unreachable-for-peer": 5, "works": 150})
	peer := device(map[string]uint16{"unreachable-for-peer": disco.RTTUnreachable, "works": 160})

	got := s.pickRelay(self, peer, "")
	if got == nil || got.Name != "works" {
		t.Fatalf("want the relay both ends can reach, got %v", nameOf(got))
	}
}

// A device that has only just started has measured nothing. Refusing to relay
// it until it has would mean refusing to connect it.
func TestFallsBackWhenNobodyHasMeasuredAnything(t *testing.T) {
	s := fleet("only-one")

	got := s.pickRelay(device(nil), device(nil), "")
	if got == nil || got.Name != "only-one" {
		t.Fatalf("want any relay rather than none, got %v", nameOf(got))
	}
}

// Failover: the relay that just failed must not be the answer to "give me
// another one".
func TestFailoverDoesNotReturnTheRelayThatFailed(t *testing.T) {
	s := fleet("failed", "backup")

	self := device(map[string]uint16{"failed": 10, "backup": 90})
	peer := device(map[string]uint16{"failed": 12, "backup": 95})

	// Without avoidance the failed relay wins on latency, which is exactly the
	// trap: it is the nearest and it is not working.
	if got := s.pickRelay(self, peer, ""); got == nil || got.Name != "failed" {
		t.Fatalf("precondition: expected the nearest relay to be chosen, got %v", nameOf(got))
	}

	got := s.pickRelayAvoiding(self, peer, "failed")
	if got == nil || got.Name != "backup" {
		t.Fatalf("failover returned %v", nameOf(got))
	}

	// The fleet must be left as it was found.
	if len(s.opts.Relays) != 2 {
		t.Fatalf("selection mutated the fleet: %d relays remain", len(s.opts.Relays))
	}
}

// One relay in the fleet and it is the one that failed: offering it again is
// the only thing left, and a relay that has come back beats refusing to answer.
func TestFailoverWithASingleRelayStillOffersIt(t *testing.T) {
	s := fleet("only")

	got := s.pickRelayAvoiding(device(map[string]uint16{"only": 10}), nil, "only")
	if got == nil || got.Name != "only" {
		t.Fatalf("want the single relay offered again, got %v", nameOf(got))
	}
}

// One device's complaint is a fact about that device's network. Taking a
// working relay out of service on one report would be a denial of service
// anyone could trigger.
func TestOneComplaintDoesNotTakeARelayOutOfRotation(t *testing.T) {
	health := newRelayHealth()

	if health.complain("mumbai", [32]byte{1}) {
		t.Fatal("a single complaint took the relay down")
	}
	if health.isDown("mumbai") {
		t.Fatal("relay reported down after one complaint")
	}

	if !health.complain("mumbai", [32]byte{2}) {
		t.Fatal("a second device's complaint did not take the relay down")
	}
	if !health.isDown("mumbai") {
		t.Fatal("relay not down after two devices agreed")
	}
}

// The same device complaining twice is still one device.
func TestTheSameDeviceComplainingTwiceIsStillOneDevice(t *testing.T) {
	health := newRelayHealth()

	health.complain("mumbai", [32]byte{1})
	if health.complain("mumbai", [32]byte{1}) {
		t.Fatal("one device took a relay down by complaining twice")
	}
}

// A relay that is down must be skipped even when it is the nearest.
func TestARelayThatIsDownIsNotChosen(t *testing.T) {
	s := fleet("down", "up")
	s.health.complain("down", [32]byte{1})
	s.health.complain("down", [32]byte{2})

	self := device(map[string]uint16{"down": 5, "up": 200})
	peer := device(map[string]uint16{"down": 6, "up": 210})

	got := s.pickRelay(self, peer, "")
	if got == nil || got.Name != "up" {
		t.Fatalf("a relay known to be down was chosen: %v", nameOf(got))
	}
}

func nameOf(r *RelayTarget) string {
	if r == nil {
		return "<nil>"
	}

	return r.Name
}
