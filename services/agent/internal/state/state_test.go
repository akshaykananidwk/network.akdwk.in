package state

import "testing"

// Defect 23: a fixed 51820 for every device meant that at a site with several
// PCs behind one router only one of them could hold the external mapping, and
// the others announced for ever unanswered.
func TestEachDeviceGetsItsOwnPortAndKeepsIt(t *testing.T) {
	first := ChooseListenPort(0)

	if first == 51820 {
		t.Fatal("51820 is what every agent used and is exactly the collision; it must not be chosen")
	}
	if first < 1024 || first > 65535 {
		t.Fatalf("port %d is not usable", first)
	}

	// Kept, not re-rolled: a port that changes on every start is a NAT mapping
	// and a firewall rule that change with it.
	if again := ChooseListenPort(first); again != first {
		t.Fatalf("a stored port was replaced: %d became %d", first, again)
	}

	// Two machines imaged from the same disk and switched on together are the
	// pair that must not draw the same number.
	seen := map[int]int{}
	for i := 0; i < 200; i++ {
		seen[ChooseListenPort(0)]++
	}
	if len(seen) < 150 {
		t.Fatalf("200 fresh devices produced only %d distinct ports", len(seen))
	}

	// A stored value outside the usable range is replaced rather than trusted.
	// Below 1024 is privileged, which this has no business binding; an
	// operator who genuinely wants a particular port passes --port, which does
	// not come through here at all.
	for _, stored := range []int{0, -1, 80, 70000} {
		got := ChooseListenPort(stored)
		if got < 1024 || got > 65535 {
			t.Fatalf("a stored port of %d produced %d, which is not usable", stored, got)
		}
		if stored == 80 && got == 80 {
			t.Fatal("a privileged port was kept")
		}
	}
}
