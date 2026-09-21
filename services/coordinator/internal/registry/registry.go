// Package registry remembers where each device was last seen.
//
// This is deliberately in-memory and deliberately short-lived. A NAT mapping
// is worth nothing once it expires, so a coordinator that restarts and forgets
// everything is not in a worse position than one that remembers an address no
// longer valid — agents re-announce within one keepalive either way.
package registry

import (
	"net/netip"
	"sync"
	"time"
)

// Entry is one device's current presence.
type Entry struct {
	PublicKey [32]byte
	DeviceUID string
	// Reflexive is the address the coordinator saw the agent from, after any
	// NAT between them. It is the address other peers should try.
	Reflexive netip.AddrPort
	// Local are the addresses the agent reports for itself. A peer on the same
	// LAN uses one of these and never leaves the switch (R2).
	Local []netip.AddrPort
	// Peers is the set this device is allowed to be introduced to, as the
	// panel's ACL decided. Held here so a hello does not need a panel round
	// trip on every keepalive.
	Peers     map[[32]byte]struct{}
	LastSeen  time.Time
	NetworkID int
	TenantID  int
	// Region is where the panel says this device is. A hint only: it narrows
	// nothing on its own and is routinely empty.
	Region string
	// RelayRTT is what this device measured, by relay name. This is the real
	// input to relay selection — the device is the only thing that knows how
	// far it is from anything, and behind CGNAT its address tells us nothing.
	RelayRTT map[string]uint16
	// RTTReportedAt is when that measurement arrived, so a stale one can be
	// recognised as stale rather than trusted forever.
	RTTReportedAt time.Time
}

// Online reports whether the entry is fresh enough to introduce to others.
func (e *Entry) Online(ttl time.Duration) bool {
	return time.Since(e.LastSeen) < ttl
}

// Registry is a concurrent map of device presence.
type Registry struct {
	mu sync.RWMutex
	// byKey is the primary index: a device is identified by its public key,
	// which is also what authenticates its packets.
	byKey map[[32]byte]*Entry
	ttl   time.Duration
}

// New builds a registry whose entries expire after ttl without contact.
func New(ttl time.Duration) *Registry {
	return &Registry{byKey: make(map[[32]byte]*Entry), ttl: ttl}
}

// Upsert records a device's presence, returning the stored entry.
func (r *Registry) Upsert(e *Entry) *Entry {
	r.mu.Lock()
	defer r.mu.Unlock()

	// A re-announcement must not throw away what the device measured. The
	// hello carries presence, not latency, and re-gathering the fleet on every
	// keepalive would cost a device on 4G real money.
	if previous, ok := r.byKey[e.PublicKey]; ok && e.RelayRTT == nil {
		e.RelayRTT = previous.RelayRTT
		e.RTTReportedAt = previous.RTTReportedAt
	}

	e.LastSeen = time.Now()
	r.byKey[e.PublicKey] = e

	return e
}

// Touch refreshes a device's last-seen time and endpoint without replacing its
// peer set, which is what a keepalive needs.
//
// It reports false when the device is unknown, so the caller can ask it to say
// hello properly rather than silently accepting an unauthenticated ping.
func (r *Registry) Touch(key [32]byte, reflexive netip.AddrPort) bool {
	r.mu.Lock()
	defer r.mu.Unlock()

	entry, ok := r.byKey[key]
	if !ok {
		return false
	}

	entry.LastSeen = time.Now()
	entry.Reflexive = reflexive

	return true
}

// SetRelayRTT records a device's view of the relay fleet.
//
// Kept separate from Upsert because a hello replaces an entry wholesale, and
// measurements that survive a re-announcement are more useful than ones that
// have to be gathered again every twenty seconds.
func (r *Registry) SetRelayRTT(key [32]byte, samples map[string]uint16) bool {
	r.mu.Lock()
	defer r.mu.Unlock()

	entry, ok := r.byKey[key]
	if !ok {
		return false
	}

	entry.RelayRTT = samples
	entry.RTTReportedAt = time.Now()

	return true
}

// Get returns one device's entry.
func (r *Registry) Get(key [32]byte) (*Entry, bool) {
	r.mu.RLock()
	defer r.mu.RUnlock()

	entry, ok := r.byKey[key]

	return entry, ok
}

// PeersOf returns the online peers a device is allowed to learn about.
//
// The ACL is applied in both directions: this device must be allowed to see
// the peer, and the peer must be allowed to see it. A one-sided introduction
// would hand out an address that the other end will refuse to answer.
func (r *Registry) PeersOf(key [32]byte) []*Entry {
	r.mu.RLock()
	defer r.mu.RUnlock()

	self, ok := r.byKey[key]
	if !ok {
		return nil
	}

	out := make([]*Entry, 0, len(self.Peers))

	for peerKey := range self.Peers {
		peer, ok := r.byKey[peerKey]
		if !ok || !peer.Online(r.ttl) {
			continue
		}
		if _, mutual := peer.Peers[key]; !mutual {
			continue
		}
		out = append(out, peer)
	}

	return out
}

// Forget removes a device, which is how a revocation takes effect here.
func (r *Registry) Forget(key [32]byte) {
	r.mu.Lock()
	defer r.mu.Unlock()

	delete(r.byKey, key)
}

// Sweep drops entries that have gone quiet, and reports how many went.
func (r *Registry) Sweep() int {
	r.mu.Lock()
	defer r.mu.Unlock()

	removed := 0
	for key, entry := range r.byKey {
		if !entry.Online(r.ttl) {
			delete(r.byKey, key)
			removed++
		}
	}

	return removed
}

// Len is the number of devices currently known.
func (r *Registry) Len() int {
	r.mu.RLock()
	defer r.mu.RUnlock()

	return len(r.byKey)
}
