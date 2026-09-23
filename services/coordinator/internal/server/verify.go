package server

import (
	"context"
	"encoding/base64"
	"sync"
	"time"
)

// verifyTTL is how long a peer set is trusted without re-asking the panel.
//
// This exists because the peer set used to be written once, by a hello, and in
// steady state an agent only pings. A device that said hello before its peer
// existed was never told about it: the coordinator held an empty allowed set,
// refused to introduce anything, and the agent logged "no known endpoint for
// peer" every five seconds until somebody restarted its service. Every
// existing device at a customer site broke the moment a new one was added.
//
// A minute is a compromise. It is one panel request per device per minute in
// the worst case — nothing, next to the cost of the failure — and it bounds
// how long a stale set can survive even for an agent too old to re-announce
// when its configuration changes.
const verifyTTL = time.Minute

// reverifier re-confirms peer sets with the panel, one request per device at a
// time.
//
// Single-flight per device, because a keepalive arrives every twenty seconds
// and a slow panel must not turn that into a queue of identical questions.
type reverifier struct {
	mu       sync.Mutex
	inFlight map[[32]byte]struct{}
}

func newReverifier() *reverifier {
	return &reverifier{inFlight: make(map[[32]byte]struct{})}
}

func (r *reverifier) begin(key [32]byte) bool {
	r.mu.Lock()
	defer r.mu.Unlock()

	if _, busy := r.inFlight[key]; busy {
		return false
	}

	r.inFlight[key] = struct{}{}

	return true
}

func (r *reverifier) done(key [32]byte) {
	r.mu.Lock()
	defer r.mu.Unlock()

	delete(r.inFlight, key)
}

// reverify asks the panel again who this device may reach, and acts on the
// answer.
//
// Runs in its own goroutine: it makes an HTTP request, and the packet loop
// must not wait for the panel to answer before handling the next datagram.
func (s *Server) reverify(key [32]byte) {
	if !s.verifying.begin(key) {
		return
	}

	go func() {
		defer s.verifying.done(key)

		deviceUID, token, ok := s.reg.Credentials(key)
		if !ok {
			// No token held for this device — it announced under an older
			// agent that did not carry one, or the entry has gone. Nothing to
			// ask with; the next hello will bring one.
			return
		}

		ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()

		publicKey := base64.StdEncoding.EncodeToString(key[:])

		result, err := s.opts.Panel.VerifyDevice(ctx, deviceUID, token, publicKey)
		if err != nil {
			// Leave the set as it is and try again at the next keepalive. A
			// panel that cannot be reached is not a revocation.
			s.opts.Logf("re-verifying %s failed: %v", deviceUID, err)

			return
		}

		if !result.Authorized {
			s.opts.Logf("%s is no longer authorised (%s); forgetting it", deviceUID, result.Reason)
			peers := s.reg.PeersOf(key)
			s.reg.Forget(key)

			// The peers it was introduced to have to be told, or they keep
			// punching at a device that is no longer allowed to answer.
			for _, peer := range peers {
				s.sendPeers(peer.PublicKey, peer.Reflexive)
			}

			return
		}

		changed, ok := s.reg.SetPeers(key, peerSet(result), result.NetworkID, result.TenantID, result.Region)
		if !ok || !changed {
			return
		}

		entry, ok := s.reg.Get(key)
		if !ok {
			return
		}

		s.opts.Logf("%s may now reach %d peer(s)", deviceUID, len(entry.Peers))

		// Both directions, immediately. This is the moment a device that was
		// alone learns it is not, and waiting for its next keepalive would add
		// twenty seconds to something the customer is watching.
		s.sendPeers(key, entry.Reflexive)
		s.notifyPeersOf(key)
	}()
}

// invalidatePeersOf marks everyone this device may reach as needing
// re-confirmation.
//
// Called when a device says hello: the set that changed is almost never only
// its own. If A has just been approved into a network with B, it is B's stored
// set that is wrong, and B is the one that will otherwise never hear about A.
func (s *Server) invalidatePeersOf(key [32]byte) {
	entry, ok := s.reg.Get(key)
	if !ok {
		return
	}

	keys := make([][32]byte, 0, len(entry.Peers))
	for peer := range entry.Peers {
		keys = append(keys, peer)
	}

	s.reg.Invalidate(keys...)

	// And re-verify them now rather than at their next keepalive, so a pair
	// converges in one round trip instead of two.
	for _, peer := range keys {
		s.reverify(peer)
	}
}
