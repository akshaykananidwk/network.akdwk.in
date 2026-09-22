package main

import (
	"context"
	"os"
	"runtime"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/selfupdate"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/winsvc"
)

// updateCheckInterval is how often a running agent asks whether it is stale.
//
// Six hours, not six minutes: the panel decides who is offered a release and
// in what order (rollout_percent), so the agent asking more often would not
// make a rollout faster — it would only add load and make a bad release reach
// every machine before anybody could withdraw it.
const updateCheckInterval = 6 * time.Hour

// updateGrace is how long after start-up the first check waits. A machine that
// has just booted has a tunnel to bring up first.
const updateGrace = 5 * time.Minute

// maybeUpdate is the automatic half of §14.
//
// It is deliberately quiet about the ordinary case — no offer, nothing logged
// — and loud about every step of an actual update, because if an agent
// replaces its own binary the log is the only account of why the version
// changed.
//
// Nothing here can install an unsigned binary: it goes through the same
// selfupdate.Verify as the manual command, and a missing controller key or a
// failed signature stops it.
func (s *session) maybeUpdate(ctx context.Context) {
	if os.Getenv("AKCONNECT_NO_AUTO_UPDATE") != "" {
		return
	}
	if !s.updateDue() {
		return
	}

	offer, err := s.client.CheckUpdate(ctx, runtime.GOOS, runtime.GOARCH)
	if err != nil {
		// Not worth a line on every poll: the heartbeat beside it already
		// reports when the panel is unreachable.
		return
	}
	if !offer.Available || offer.Version == "" {
		return
	}

	if s.controllerKey == "" {
		s.logf("update %s is offered but this panel publishes no controller key; not installing", offer.Version)

		return
	}

	s.logf("update %s is offered (running %s); downloading", offer.Version, version)

	staged, err := download(ctx, s.client, offer)
	if err != nil {
		s.logf("update %s could not be downloaded: %v", offer.Version, err)

		return
	}
	defer func() { _ = os.Remove(staged) }()

	if err := selfupdate.Verify(staged, offer.SHA256, offer.Signature, s.controllerKey); err != nil {
		s.logf("update %s REFUSED: %v", offer.Version, err)

		return
	}

	if _, err := selfupdate.Apply(staged); err != nil {
		s.logf("update %s could not be installed: %v", offer.Version, err)

		return
	}

	s.logf("update %s installed and verified; restarting to run it", offer.Version)

	if !winsvc.IsService() {
		s.logf("not running as a service, so nothing restarts this process; %s runs at the next start", offer.Version)

		return
	}

	if err := winsvc.RestartDetached(); err != nil {
		s.logf("update %s is on disk but the restart failed: %v", offer.Version, err)
	}
}

// updateDue answers whether enough time has passed, and records the check.
func (s *session) updateDue() bool {
	now := time.Now()

	if s.lastUpdateCheck.IsZero() {
		if now.Sub(s.startedAt) < updateGrace {
			return false
		}

		s.lastUpdateCheck = now

		return true
	}

	if now.Sub(s.lastUpdateCheck) < updateCheckInterval {
		return false
	}

	s.lastUpdateCheck = now

	return true
}
