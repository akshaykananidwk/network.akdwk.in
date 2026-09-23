package server

import (
	"sync"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/coordinator/internal/panelapi"
)

// lastKnownTTL is how long a verification survives a panel that cannot answer.
//
// Twenty-four hours. Long enough that no plausible maintenance window, deploy,
// database restore or certificate renewal reaches the end of it; short enough
// that a coordinator cut off from its panel for a day stops introducing
// devices rather than running on its own memory for ever.
const lastKnownTTL = 24 * time.Hour

// lastKnown remembers the panel's most recent ANSWER about each device.
//
// The coordinator asks the panel who may reach whom, on every hello and every
// minute after. The code that did so treated "the panel did not answer" the
// same as "the panel has not said yes": it returned, the device was never
// refreshed in the registry, its entry expired after the presence TTL, and
// the pair it was half of stopped being introduced to anybody.
//
// That is what a five-minute panel deployment did to a working relayed pair
// carrying seventy megabytes. The panel answered 503 for ninety seconds; both
// devices fell out of the registry; when the panel came back only one end
// asked for a relay again, and the offer to the other end found nothing to
// send to. Neither machine had moved, neither network had changed, and no
// packet crossed between them again until somebody restarted a service.
//
// R6 says the data plane outlives the control plane. A panel that is down
// must be invisible to traffic, and that means the coordinator has to keep
// deciding — with the last thing the panel actually told it.
//
// An explicit refusal is not an error and is never cached: a revoked device
// is forgotten immediately, which is the property the old comment was
// protecting when it said "the panel decides, every time".
type lastKnown struct {
	mu      sync.Mutex
	entries map[[32]byte]lastKnownEntry
}

type lastKnownEntry struct {
	result *panelapi.VerifyResult
	at     time.Time
}

func newLastKnown() *lastKnown {
	return &lastKnown{entries: make(map[[32]byte]lastKnownEntry)}
}

// remember stores an authorised answer.
func (l *lastKnown) remember(key [32]byte, result *panelapi.VerifyResult) {
	// Nil-safe on the receiver as well as the argument. A Server can be
	// assembled field by field — the tests do — and a cache that panicked
	// when it had not been created would turn an optional optimisation into
	// a required construction step.
	if l == nil || result == nil || !result.Authorized {
		return
	}

	l.mu.Lock()
	defer l.mu.Unlock()

	if l.entries == nil {
		l.entries = make(map[[32]byte]lastKnownEntry)
	}

	l.entries[key] = lastKnownEntry{result: result, at: time.Now()}
}

// recall returns the last authorised answer, if it is recent enough to act on.
func (l *lastKnown) recall(key [32]byte) (*panelapi.VerifyResult, bool) {
	if l == nil {
		return nil, false
	}

	l.mu.Lock()
	defer l.mu.Unlock()

	entry, ok := l.entries[key]
	if !ok || time.Since(entry.at) > lastKnownTTL {
		return nil, false
	}

	return entry.result, true
}

// forget drops a device, for an explicit revocation.
func (l *lastKnown) forget(key [32]byte) {
	if l == nil {
		return
	}

	l.mu.Lock()
	defer l.mu.Unlock()

	delete(l.entries, key)
}

// sweep removes entries nobody has refreshed within the TTL, so a coordinator
// that has run for months does not hold every device it has ever seen.
func (l *lastKnown) sweep() {
	if l == nil {
		return
	}

	l.mu.Lock()
	defer l.mu.Unlock()

	for key, entry := range l.entries {
		if time.Since(entry.at) > lastKnownTTL {
			delete(l.entries, key)
		}
	}
}
