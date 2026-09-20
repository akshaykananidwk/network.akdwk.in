package main

import (
	"context"
	"errors"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/tunnel"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/wgkey"
)

// wgPrivate is an alias so up.go reads without importing the key package for
// one type name.
type wgPrivate = wgkey.Private

// defaultPoll is used when the panel does not say how long to wait.
const defaultPoll = 10 * time.Second

// revokedGracePeriod bounds how long a revoked device keeps passing traffic.
// The panel refuses its next call; this is the longest that call can be away.
const revokedGracePeriod = 10 * time.Second

// loop keeps the tunnel matching the panel until the context is cancelled.
//
// Two things happen on each pass: the agent reports that it is alive, and it
// asks whether its configuration has changed. The heartbeat is also how
// revocation arrives — the panel answers 401 or 403, and the agent tears the
// tunnel down rather than carrying on with a credential it has been told is no
// longer valid (R4).
func (s *session) loop(ctx context.Context, priv wgPrivate, pollAfter int) error {
	interval := pollInterval(pollAfter)

	for {
		select {
		case <-ctx.Done():
			s.logf("shutting down; taking the interface back down")
			return nil
		case <-time.After(interval):
		}

		if err := s.heartbeat(ctx); err != nil {
			if revoked(err) {
				s.logf("this device has been revoked; disconnecting")
				return nil
			}
			// A transient failure is not a reason to drop a working tunnel.
			// R6: the data plane outlives the control plane.
			s.logf("heartbeat failed, tunnel left up: %v", err)
			continue
		}

		next, err := s.refresh(ctx, priv)
		if err != nil {
			if revoked(err) {
				s.logf("this device has been revoked; disconnecting")
				return nil
			}
			s.logf("configuration refresh failed, tunnel left up: %v", err)
			continue
		}

		interval = pollInterval(next)
	}
}

// heartbeat reports liveness and the device's own view of its traffic.
func (s *session) heartbeat(ctx context.Context) error {
	hb := panel.Heartbeat{Revision: s.st.Revision}

	if s.tun != nil {
		if peers, err := s.tun.Status(); err == nil {
			for _, p := range peers {
				hb.RXBytes += p.RXBytes
				hb.TXBytes += p.TXBytes
			}
			hb.ConnectionType = connectionType(peers)
		}
	}

	_, err := s.client.SendHeartbeat(ctx, hb)

	return err
}

// refresh asks for configuration and re-applies it if the revision moved.
func (s *session) refresh(ctx context.Context, priv wgPrivate) (int, error) {
	cfg, pollAfter, err := s.client.FetchConfig(ctx, s.st.Revision)
	if err != nil {
		return pollAfter, err
	}

	if cfg.Unchanged() {
		return pollAfter, nil
	}

	if err := s.applyConfig(priv, cfg); err != nil {
		return pollAfter, err
	}

	s.logf("configuration updated to revision %d (%d peer(s))", cfg.Revision, len(cfg.Peers))

	return pollAfter, nil
}

// connectionType reports how the tunnel is carrying traffic, which the panel
// shows in the device list.
//
// A completed handshake is the only evidence that counts: a peer can be
// configured, reachable on paper and still not passing anything. "direct"
// therefore means at least one peer has handshaked recently, not that one is
// listed.
func connectionType(peers []tunnel.PeerStatus) string {
	if len(peers) == 0 {
		return "none"
	}

	cutoff := time.Now().Add(-3 * time.Minute).Unix()
	for _, p := range peers {
		if p.LastHandshake > cutoff {
			return "direct"
		}
	}

	return "pending"
}

func revoked(err error) bool {
	var apiErr *panel.APIError

	return errors.As(err, &apiErr) && apiErr.Unauthorized()
}

func pollInterval(seconds int) time.Duration {
	if seconds <= 0 {
		return defaultPoll
	}

	// Never longer than the revocation grace period: a device that has been
	// revoked must stop within a bounded time, and the poll is what finds out.
	d := time.Duration(seconds) * time.Second
	if d > revokedGracePeriod {
		return revokedGracePeriod
	}

	return d
}
