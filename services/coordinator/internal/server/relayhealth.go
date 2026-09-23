package server

import (
	"sync"
	"time"
)

// relayDownFor is how long a relay stays out of the rotation after agents
// report they cannot use it.
//
// Long enough that a relay being restarted is not immediately handed back out
// and long enough to cover a short outage; short enough that a relay which
// recovers is used again without an operator having to do anything.
const relayDownFor = 2 * time.Minute

// relayComplaintsNeeded is how many separate devices must report a relay
// unusable before it is taken out of the rotation.
//
// More than one, because a single device that cannot reach a relay is usually
// a fact about that device's network rather than about the relay, and taking a
// working relay out of service on one device's say-so would be a denial of
// service anyone could trigger.
const relayComplaintsNeeded = 2

// relayHealth tracks which relays agents say they cannot use.
//
// This is deliberately separate from whether the relay is running: the
// coordinator does not forward traffic and cannot tell a relay that is down
// from one that is merely unreachable from where the customers are. What it
// can do is believe several customers who agree.
type relayHealth struct {
	mu sync.Mutex
	// complaints is, per relay, the devices that reported it unusable and when.
	complaints map[string]map[[32]byte]time.Time
	// downUntil is when each relay may be offered again.
	downUntil map[string]time.Time
}

func newRelayHealth() *relayHealth {
	return &relayHealth{
		complaints: make(map[string]map[[32]byte]time.Time),
		downUntil:  make(map[string]time.Time),
	}
}

// complain records one device's report that a relay is not working for it,
// and reports whether that was enough to take the relay out of rotation.
func (h *relayHealth) complain(name string, device [32]byte) bool {
	if name == "" {
		return false
	}

	h.mu.Lock()
	defer h.mu.Unlock()

	now := time.Now()
	byDevice, ok := h.complaints[name]
	if !ok {
		byDevice = make(map[[32]byte]time.Time)
		h.complaints[name] = byDevice
	}
	byDevice[device] = now

	// Only recent complaints count. Two devices that failed an hour apart are
	// two unrelated incidents, not evidence of a broken relay.
	fresh := 0
	cutoff := now.Add(-relayDownFor)
	for key, at := range byDevice {
		if at.Before(cutoff) {
			delete(byDevice, key)
			continue
		}
		fresh++
	}

	if fresh < relayComplaintsNeeded {
		return false
	}

	h.downUntil[name] = now.Add(relayDownFor)
	delete(h.complaints, name)

	return true
}

// isDown reports whether a relay is currently out of rotation.
func (h *relayHealth) isDown(name string) bool {
	h.mu.Lock()
	defer h.mu.Unlock()

	until, ok := h.downUntil[name]
	if !ok {
		return false
	}
	if time.Now().After(until) {
		delete(h.downUntil, name)

		return false
	}

	return true
}

// relayIsDown is the server's view of one relay's health.
func (s *Server) relayIsDown(name string) bool {
	if s.health == nil {
		return false
	}

	return s.health.isDown(name)
}
