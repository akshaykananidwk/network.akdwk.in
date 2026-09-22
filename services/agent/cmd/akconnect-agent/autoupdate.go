package main

import (
	"context"
	"os"
	"runtime"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
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
	// An administrator who pressed "Update now" is waiting, so the timer does
	// not apply — but the check is still the same one, through the same
	// signature verification. There is no path here that installs anything the
	// automatic check would refuse.
	requested := s.updateRequested
	s.updateRequested = false

	if !requested && !s.updateDue() {
		return
	}

	offer, err := s.client.CheckUpdate(ctx, runtime.GOOS, runtime.GOARCH)
	if err != nil {
		// Not worth a line on every poll: the heartbeat beside it already
		// reports when the panel is unreachable.
		s.noteUpdate("failed", "", "could not ask the panel: "+err.Error())

		return
	}
	if !offer.Available || offer.Version == "" {
		// Checked, nothing to do. Recorded, because "this device is up to
		// date" and "this device has never asked" are different answers and
		// the panel could not tell them apart.
		s.noteUpdate("idle", "", "")

		return
	}

	if s.controllerKey == "" {
		s.logf("update %s is offered but this panel publishes no controller key; not installing", offer.Version)
		s.noteUpdate("failed", offer.Version,
			"this panel publishes no controller key, so no release can be verified")

		return
	}

	s.logf("update %s is offered (running %s); downloading", offer.Version, version)
	s.noteUpdate("downloading", offer.Version, "")

	staged, err := download(ctx, s.client, offer)
	if err != nil {
		s.logf("update %s could not be downloaded: %v", offer.Version, err)
		s.noteUpdate("failed", offer.Version, "download failed: "+err.Error())

		return
	}
	defer func() { _ = os.Remove(staged) }()

	if err := selfupdate.Verify(staged, offer.SHA256, offer.Signature, s.controllerKey); err != nil {
		s.logf("update %s REFUSED: %v", offer.Version, err)
		s.noteUpdate("failed", offer.Version, "refused: "+err.Error())

		return
	}

	if _, err := selfupdate.Apply(staged); err != nil {
		s.logf("update %s could not be installed: %v", offer.Version, err)
		s.noteUpdate("failed", offer.Version, "could not be installed: "+err.Error())

		return
	}

	s.logf("update %s installed and verified; restarting to run it", offer.Version)
	s.noteUpdate("installed", offer.Version, "")

	if !winsvc.IsService() {
		s.logf("not running as a service, so nothing restarts this process; %s runs at the next start", offer.Version)

		return
	}

	if err := winsvc.RestartDetached(); err != nil {
		s.logf("update %s is on disk but the restart failed: %v", offer.Version, err)
		s.noteUpdate("failed", offer.Version,
			"installed on disk but the service would not restart: "+err.Error())
	}
}

// noteUpdate records where this agent got to, for the next heartbeat.
//
// Held rather than sent, because the heartbeat is the message the panel
// already trusts from this device and adding a second authenticated call for
// one field would be two things to keep working instead of one.
func (s *session) noteUpdate(state, version, detail string) {
	s.updateState = &panel.UpdateState{State: state, Version: version, Error: detail}
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
