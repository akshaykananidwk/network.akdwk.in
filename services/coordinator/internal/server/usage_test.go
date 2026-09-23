package server

import "testing"

// Usage the panel would not take is billed on the next report.
//
// delta() advances this coordinator's mark whether or not the report is
// delivered, so a dropped report loses those bytes for ever: the next delta
// starts from the new figure. A panel answering 503 for ninety seconds during
// a deployment silently lost every byte relayed in that window, and the
// comment in the code said that was safe because the relay's cumulative
// counter would carry them — it does carry them, past a mark that has already
// moved.
func TestUsageHeldWhileThePanelIsDownIsBilledLater(t *testing.T) {
	ledger := newUsageLedger()

	// A first report: 1000 bytes for tenant 7.
	first := map[uint64]uint64{}
	if d := ledger.delta("mumbai-1", 7, 1000); d > 0 {
		first[7] = d
	}
	ledger.addHeld("mumbai-1", first)

	if first[7] != 1000 {
		t.Fatalf("first delta is %d, want 1000", first[7])
	}

	// The panel refuses it.
	ledger.hold("mumbai-1", first)

	// A second report: the relay's cumulative total has reached 1500, so the
	// new delta alone is only 500 — the 1000 would be lost without the hold.
	second := map[uint64]uint64{}
	if d := ledger.delta("mumbai-1", 7, 1500); d > 0 {
		second[7] = d
	}
	ledger.addHeld("mumbai-1", second)

	if second[7] != 1500 {
		t.Fatalf("second report carries %d, want 1500 (500 new plus 1000 held)", second[7])
	}

	// The panel takes it, and the debt is cleared.
	ledger.delivered("mumbai-1")

	third := map[uint64]uint64{}
	if d := ledger.delta("mumbai-1", 7, 1700); d > 0 {
		third[7] = d
	}
	ledger.addHeld("mumbai-1", third)

	if third[7] != 200 {
		t.Fatalf("third report carries %d, want 200 — delivered usage must not be billed twice", third[7])
	}
}
