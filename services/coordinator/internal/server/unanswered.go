package server

import (
	"sync"
	"time"
)

// Noticing, from this side, that a device is not hearing our replies.
//
// From the field: a laptop on an office Wi-Fi announced itself every few
// seconds for an afternoon. Every hello arrived here and was answered; not one
// answer arrived there. The coordinator even offered it a relay, which it
// never bound at, because it never saw the offer. Nothing in this process
// remarked on any of it — the logs read as a device saying hello a great deal,
// which is what they would read as if everything were fine but chatty.
//
// It is visible from here, and that matters because the alternative is asking
// a customer to run something on the machine that cannot hear us:
//
//	an agent that IS hearing us sends a hello, gets an ack, and then sends
//	PINGS — a hello only every five minutes or when the panel's configuration
//	moves. An agent that is NOT hearing us re-announces, because that is what
//	an unanswered agent does, and its hellos keep coming with no ping between
//	them.
//
// So: several hellos in a short window with no ping in between means the
// return path is broken, whatever the device itself believes. This is a
// statement about the path, not about the device, and it is the one fault that
// the device cannot report itself.

// unansweredAfter is how many hellos with no ping between them mean the
// replies are not arriving.
//
// Four. A restart, a configuration change and a relay handover can each
// produce a hello, and a device that is genuinely being answered will slip a
// ping in between them within twenty seconds.
const unansweredAfter = 4

// unansweredWindow is how long a run of hellos has to fall inside. Wider than
// four keepalives, so a device that says hello once every five minutes — which
// is what a settled agent does — never accumulates a run.
const unansweredWindow = 3 * time.Minute

// talkers tracks, per device, how one-sided the conversation is.
type talkers struct {
	mu   sync.Mutex
	seen map[string]*talker
}

type talker struct {
	hellos    int
	firstAt   time.Time
	lastAt    time.Time
	reported  bool
	lastReset time.Time
}

func newTalkers() *talkers { return &talkers{seen: map[string]*talker{}} }

// noteHello records an announcement and reports whether this device has now
// said hello enough times, without ever pinging, to call the return path
// broken. It reports true once per run, not on every hello after the
// threshold: the panel does not need the same sentence every five seconds.
func (t *talkers) noteHello(deviceUID string, now time.Time) bool {
	t.mu.Lock()
	defer t.mu.Unlock()

	entry := t.seen[deviceUID]
	if entry == nil || now.Sub(entry.firstAt) > unansweredWindow {
		t.seen[deviceUID] = &talker{hellos: 1, firstAt: now, lastAt: now}

		return false
	}

	entry.hellos++
	entry.lastAt = now

	if entry.hellos < unansweredAfter || entry.reported {
		return false
	}

	entry.reported = true

	return true
}

// notePing records that the device heard us. A ping is only ever sent by an
// agent that has been answered, so it is proof the return path works and it
// clears everything this file believes.
func (t *talkers) notePing(deviceUID string, now time.Time) bool {
	t.mu.Lock()
	defer t.mu.Unlock()

	entry := t.seen[deviceUID]
	if entry == nil {
		t.seen[deviceUID] = &talker{firstAt: now, lastAt: now, lastReset: now}

		return false
	}

	recovered := entry.reported

	entry.hellos = 0
	entry.firstAt = now
	entry.lastAt = now
	entry.reported = false
	entry.lastReset = now

	return recovered
}

// forget drops a device, so a revoked one does not sit in the map for ever.
func (t *talkers) forget(deviceUID string) {
	t.mu.Lock()
	delete(t.seen, deviceUID)
	t.mu.Unlock()
}

// sweep removes devices nothing has been heard from in a while. Called on the
// same timer that reports endpoints; without it this map is a slow leak on a
// coordinator that has seen every device a fleet ever had.
func (t *talkers) sweep(now time.Time, olderThan time.Duration) {
	t.mu.Lock()
	defer t.mu.Unlock()

	for uid, entry := range t.seen {
		if now.Sub(entry.lastAt) > olderThan {
			delete(t.seen, uid)
		}
	}
}
