package main

import (
	"strings"
	"testing"
	"time"
)

// A resume produces a power event and then several network-binding events as
// adapters come back. Acting on each would rebind the socket four times while
// the machine is still settling, and each rebind costs every peer a punch.
func TestABurstOfWakeEventsIsOneRecovery(t *testing.T) {
	wakeMu.Lock()
	lastWake = time.Time{}
	wakeMu.Unlock()

	calls := 0
	s := &session{logf: func(string, ...any) { calls++ }}

	for i := 0; i < 5; i++ {
		s.wake("the network changed")
	}

	if calls != 1 {
		t.Fatalf("%d recoveries for one resume; a burst must collapse into one", calls)
	}
}

// And a later one, after things have settled, is a real event again: a laptop
// carried from a shop to a hotel wakes twice.
func TestAWakeAfterTheBurstIsActedOn(t *testing.T) {
	wakeMu.Lock()
	lastWake = time.Time{}
	wakeMu.Unlock()

	calls := 0
	s := &session{logf: func(string, ...any) { calls++ }}

	s.wake("the computer woke up")

	wakeMu.Lock()
	lastWake = time.Now().Add(-2 * wakeDebounce)
	wakeMu.Unlock()

	s.wake("the computer woke up")

	if calls != 2 {
		t.Fatalf("%d recoveries; the second wake was swallowed", calls)
	}
}

// With no tunnel and no discovery — the agent is still starting, or is in the
// waiting room — a wake must do nothing rather than panic. The service control
// handler calls this whenever Windows says so, including three seconds after
// the service started.
func TestAWakeBeforeTheTunnelExistsIsSafe(t *testing.T) {
	wakeMu.Lock()
	lastWake = time.Time{}
	wakeMu.Unlock()

	s := &session{logf: func(string, ...any) {}}
	s.wake("the computer woke up")
}

// The tunnel's own address must not count as a network change. Without the
// exclusion, bringing the tunnel up looks like the network moving and triggers
// a recovery from the thing that has just succeeded — on every start.
func TestTheTunnelsOwnAddressIsNotANetworkChange(t *testing.T) {
	all := localAddressSet("")
	if all == "" {
		t.Skip("this machine reports no routable addresses")
	}

	// Exclude whatever the first interface in the set is, and the reading must
	// change — which is the mechanism the tunnel exclusion relies on.
	name := all[:strings.Index(all, "=")]

	if localAddressSet(name) == all {
		t.Fatalf("excluding %q changed nothing; the tunnel would look like a network change", name)
	}
}

// An empty reading is a machine mid-change, not a network worth reacting to:
// an adapter down, a cable out. Reacting would mean rebinding a socket at the
// moment there is nowhere to bind it.
func TestTheReadingIsStableAcrossCalls(t *testing.T) {
	first := localAddressSet("")
	second := localAddressSet("")

	if first != second {
		t.Fatalf("two readings a moment apart differ:\n  %s\n  %s", first, second)
	}
}
