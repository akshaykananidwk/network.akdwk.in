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
	// Token is the device token this device announced with, kept so the peer
	// set can be re-confirmed with the panel without waiting for the device to
	// say hello again.
	//
	// It is held in memory only, never logged and never written anywhere, and
	// it is dropped the moment the entry is. The coordinator already receives
	// it on every hello and forwards it to the panel; keeping it is the same
	// trust, held for less time than the device's own copy.
	Token string
	// VerifiedAt is when the panel last confirmed Peers.
	//
	// This exists because it was missing. The peer set used to be written only
	// by a hello, and in steady state an agent only pings — so a device that
	// said hello when it had no peers was never told about the ones added
	// afterwards, and nothing short of restarting its service fixed it. Every
	// existing device at a customer site broke the moment a new one was added.
	VerifiedAt time.Time
}

// NeedsVerify reports whether the peer set is old enough to be re-confirmed.
func (e *Entry) NeedsVerify(ttl time.Duration) bool {
	return e.VerifiedAt.IsZero() || time.Since(e.VerifiedAt) > ttl
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
	// A hello is a verification: the panel was just asked and just answered.
	e.VerifiedAt = e.LastSeen
	r.byKey[e.PublicKey] = e

	return e
}

// SetPeers replaces what the panel says this device may reach.
//
// Separate from Upsert because it is the answer to a re-verification rather
// than to an announcement: the endpoint, the local addresses and the measured
// latencies all stay as they are.
//
// It reports whether the set actually changed, so a caller can tell the
// difference between "confirmed" and "news", and only push peers when there is
// news.
func (r *Registry) SetPeers(key [32]byte, peers map[[32]byte]struct{}, networkID, tenantID int, region string) (changed bool, ok bool) {
	r.mu.Lock()
	defer r.mu.Unlock()

	entry, ok := r.byKey[key]
	if !ok {
		return false, false
	}

	changed = !samePeerSet(entry.Peers, peers)

	entry.Peers = peers
	entry.NetworkID = networkID
	entry.TenantID = tenantID
	entry.Region = region
	entry.VerifiedAt = time.Now()

	return changed, true
}

// Invalidate marks a device's peer set as needing re-confirmation.
//
// Used when something else has happened that probably changed it — a peer
// coming online, the panel saying a set has moved — so the next keepalive
// re-verifies instead of waiting out the full TTL.
func (r *Registry) Invalidate(keys ...[32]byte) {
	r.mu.Lock()
	defer r.mu.Unlock()

	for _, key := range keys {
		if entry, ok := r.byKey[key]; ok {
			entry.VerifiedAt = time.Time{}
		}
	}
}

// InvalidateNetwork marks every device on one network as needing
// re-confirmation, and reports how many.
func (r *Registry) InvalidateNetwork(networkID int) int {
	r.mu.Lock()
	defer r.mu.Unlock()

	n := 0
	for _, entry := range r.byKey {
		if entry.NetworkID == networkID {
			entry.VerifiedAt = time.Time{}
			n++
		}
	}

	return n
}

// Credentials returns what re-verification needs, without exposing the entry.
func (r *Registry) Credentials(key [32]byte) (deviceUID, token string, ok bool) {
	r.mu.RLock()
	defer r.mu.RUnlock()

	entry, ok := r.byKey[key]
	if !ok {
		return "", "", false
	}

	return entry.DeviceUID, entry.Token, entry.Token != ""
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

func samePeerSet(a, b map[[32]byte]struct{}) bool {
	if len(a) != len(b) {
		return false
	}

	for key := range a {
		if _, ok := b[key]; !ok {
			return false
		}
	}

	return true
}

// Len is the number of devices currently known.
func (r *Registry) Len() int {
	r.mu.RLock()
	defer r.mu.RUnlock()

	return len(r.byKey)
}
