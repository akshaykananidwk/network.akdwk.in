package server

import (
	"testing"
	"time"
)

// A device that is being answered sends a hello, hears back, and then pings.
// It must never be reported, however long it runs.
func TestAnAnsweredDeviceIsNeverReported(t *testing.T) {
	tk := newTalkers()
	now := time.Date(2026, 9, 22, 18, 0, 0, 0, time.UTC)

	for i := 0; i < 50; i++ {
		if first(tk.noteHello("dev_ok", "203.0.113.9:51820", now)) {
			t.Fatalf("reported a device that is being answered (hello %d)", i)
		}
		now = now.Add(2 * time.Second)

		// The ack arrives, so the next thing it sends is a ping.
		for p := 0; p < 3; p++ {
			tk.notePing("dev_ok", now)
			now = now.Add(20 * time.Second)
		}
	}
}

// The field case: hellos every five to ten seconds, no ping ever, because no
// ack ever arrives. Reported once, not on every hello after that.
func TestADeviceThatNeverHearsBackIsReportedOnce(t *testing.T) {
	tk := newTalkers()
	now := time.Date(2026, 9, 22, 18, 40, 12, 0, time.UTC)

	reports := 0
	for i := 0; i < 20; i++ {
		if first(tk.noteHello("dev_64ae", "150.129.167.50:53722", now)) {
			reports++
		}
		now = now.Add(7 * time.Second)
	}

	if reports != 1 {
		t.Fatalf("reported %d times; the panel needs to be told once, not every seven seconds", reports)
	}
}

// A settled agent says hello every five minutes. Twelve of those in an hour
// must not add up to a fault.
func TestAHelloEveryFiveMinutesIsNotAFault(t *testing.T) {
	tk := newTalkers()
	now := time.Date(2026, 9, 22, 9, 0, 0, 0, time.UTC)

	for i := 0; i < 12; i++ {
		if first(tk.noteHello("dev_settled", "203.0.113.9:51820", now)) {
			t.Fatalf("a hello every five minutes was reported as unanswered (hello %d)", i)
		}
		now = now.Add(5 * time.Minute)
	}
}

// And when it starts hearing us again — the customer fixes the firewall — that
// is worth knowing too, once.
func TestRecoveryIsReportedOnce(t *testing.T) {
	tk := newTalkers()
	now := time.Date(2026, 9, 22, 18, 40, 12, 0, time.UTC)

	for i := 0; i < 6; i++ {
		tk.noteHello("dev_64ae", "150.129.167.50:53722", now)
		now = now.Add(7 * time.Second)
	}

	if !tk.notePing("dev_64ae", now) {
		t.Fatal("the recovery was not reported")
	}
	if tk.notePing("dev_64ae", now.Add(20*time.Second)) {
		t.Fatal("the recovery was reported twice")
	}
}

// A device nothing has been heard from is dropped, or this map is a slow leak
// on a coordinator that has seen every device a fleet ever had.
func TestOldDevicesAreSweptOut(t *testing.T) {
	tk := newTalkers()
	now := time.Date(2026, 9, 22, 18, 0, 0, 0, time.UTC)

	tk.noteHello("dev_gone", "203.0.113.9:51820", now)
	tk.noteHello("dev_here", "203.0.113.9:51820", now.Add(time.Hour))

	tk.sweep(now.Add(time.Hour), 30*time.Minute)

	tk.mu.Lock()
	defer tk.mu.Unlock()

	if _, ok := tk.seen["dev_gone"]; ok {
		t.Error("a device last heard from an hour ago was kept")
	}
	if _, ok := tk.seen["dev_here"]; !ok {
		t.Error("a device heard from just now was swept out")
	}
}

// first is the "is it unanswered" half of noteHello, for the tests that do not
// care about the second.
func first(unanswered, _ bool) bool { return unanswered }

// Two faults that look identical from a customer's chair, and the coordinator
// is the only place that can tell them apart.
//
// A device whose observed address STAYS PUT is being answered at an address
// that exists, and something at that end is eating the replies — its own
// firewall, or another product on it. A device whose address MOVES between
// announcements is behind a router re-mapping the port every few seconds, so
// every reply is addressed to a mapping already thrown away. Nothing on the
// machine fixes the second one, and telling a customer to check their firewall
// for it wastes an evening.
func TestARemappingRouterIsToldApartFromABlockedMachine(t *testing.T) {
	now := time.Date(2026, 9, 22, 18, 40, 12, 0, time.UTC)

	steady := newTalkers()
	var steadyRemapping bool
	for i := 0; i < 8; i++ {
		if unanswered, remapping := steady.noteHello("dev_steady", "150.129.167.50:53722", now); unanswered {
			steadyRemapping = remapping
		}
		now = now.Add(7 * time.Second)
	}
	if steadyRemapping {
		t.Error("an address that never changed was blamed on the router")
	}

	now = time.Date(2026, 9, 22, 18, 40, 12, 0, time.UTC)
	moving := newTalkers()
	var movingRemapping, everReported bool
	for i := 0; i < 8; i++ {
		port := 50000 + i*37
		if unanswered, remapping := moving.noteHello("dev_moving",
			"150.129.167.50:"+itoa(port), now); unanswered {
			everReported = true
			movingRemapping = remapping
		}
		now = now.Add(7 * time.Second)
	}
	if !everReported {
		t.Fatal("a device that never pings was not reported at all")
	}
	if !movingRemapping {
		t.Error("an address changing on every announcement was not recognised as a re-mapping router")
	}
}

// A device that genuinely moves — a laptop carried to another network — does it
// once, and that must not be read as a router re-mapping every few seconds.
func TestOneMoveIsNotARemappingRouter(t *testing.T) {
	tk := newTalkers()
	now := time.Date(2026, 9, 22, 18, 40, 12, 0, time.UTC)

	var remapping bool
	for i := 0; i < 8; i++ {
		from := "150.129.167.50:53722"
		if i >= 4 {
			from = "49.36.14.2:53722"
		}
		if unanswered, r := tk.noteHello("dev_moved", from, now); unanswered {
			remapping = r
		}
		now = now.Add(7 * time.Second)
	}

	if remapping {
		t.Error("a laptop that changed network once was blamed on a re-mapping router")
	}
}

func itoa(n int) string {
	if n == 0 {
		return "0"
	}

	digits := ""
	for n > 0 {
		digits = string(rune('0'+n%10)) + digits
		n /= 10
	}

	return digits
}
