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
		if tk.noteHello("dev_ok", now) {
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
		if tk.noteHello("dev_64ae", now) {
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
		if tk.noteHello("dev_settled", now) {
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
		tk.noteHello("dev_64ae", now)
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

	tk.noteHello("dev_gone", now)
	tk.noteHello("dev_here", now.Add(time.Hour))

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
