package main

import (
	"context"
	"net/netip"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/fallback"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/state"
)

// When the agent gives up on UDP and opens the HTTPS path.
//
// Early. The old behaviour was to keep announcing on UDP for as long as the
// machine was switched on, which on one office network meant a laptop that
// announced itself every five seconds for an entire afternoon and was never
// once answered. Nothing was going to change by waiting, and nothing the
// customer could reasonably be asked to do would have changed it either.
//
// Two unanswered announcements over four seconds is enough evidence. The cost
// of being wrong is one TLS connection that carries nothing and is dropped
// again a few minutes later; the cost of being slow is a device that does not
// work.
const (
	fallbackAfterUnanswered = 2
	fallbackAfterSilence    = 4 * time.Second
)

// How long UDP has to be working, and the fallback carrying nothing, before
// the connection is dropped.
//
// Both conditions, because either alone is misleading: the coordinator
// answering says nothing about whether a peer's traffic is still relayed over
// HTTPS, and an idle tunnel on a network that blocks UDP is idle because
// nobody is using the device, not because the path is unnecessary.
const (
	fallbackSettled   = 2 * time.Minute
	fallbackUnusedFor = 5 * time.Minute
)

// startFallback prepares the HTTPS path and watches for the moment it is
// needed. It does not connect: a device on an ordinary network never opens
// this connection at all.
func (s *session) startFallback(ctx context.Context, cfg *panel.Config, coordinator netip.AddrPort, self [32]byte) {
	if cfg.Fallback.URL == "" {
		s.logf("fallback: the panel publishes no HTTPS address, so a network that blocks UDP " +
			"will leave this device unable to connect")

		return
	}

	client, err := fallback.New(fallback.Options{
		URL:         cfg.Fallback.URL,
		SelfKey:     self,
		Coordinator: coordinator,
		Router:      s.tun.Transport(),
		Logf:        s.logf,
	})
	if err != nil {
		s.logf("fallback: %v", err)

		return
	}

	s.fallback = client
	s.fallbackURL = cfg.Fallback.URL

	go s.watchFallback(ctx)
}

// watchFallback opens and closes the HTTPS path as the network demands.
func (s *session) watchFallback(ctx context.Context) {
	ticker := time.NewTicker(2 * time.Second)
	defer ticker.Stop()

	var stop context.CancelFunc
	defer func() {
		if stop != nil {
			stop()
		}
	}()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}

		// While the fallback is carrying control, an unanswered announcement
		// says nothing about the port it left from — so the port-move
		// detector is held. Reasserted on every tick rather than once,
		// because the connection comes and goes and the two must not drift.
		if s.discovery != nil {
			s.discovery.HoldPort(s.fallback != nil && s.fallback.Up())
		}

		switch {
		case stop == nil && s.udpIsHopeless():
			s.logf("fallback: UDP is not being answered on this network; trying HTTPS instead")

			running, cancel := context.WithCancel(ctx)
			stop = cancel

			go s.fallback.Run(running)
		case stop != nil && s.fallbackNoLongerNeeded():
			s.logf("fallback: UDP is working again and nothing is using the HTTPS path; closing it")
			stop()
			stop = nil
		}
	}
}

// udpIsHopeless reports whether the coordinator has stopped answering for long
// enough that waiting is not a plan.
func (s *session) udpIsHopeless() bool {
	if s.discovery == nil || s.fallback == nil {
		return false
	}

	if count, since := s.discovery.Unanswered(); count >= fallbackAfterUnanswered &&
		since >= fallbackAfterSilence {
		return true
	}

	// And the other shape of the same conclusion: the socket refusing rather
	// than the network swallowing. Windows Firewall blocking our program
	// outbound gives an error on every send, so nothing is ever left
	// unanswered — there is nothing out there to answer it — and a device
	// that watched only for silence would wait for ever.
	refused, failing := s.discovery.Unreachable()

	return refused >= fallbackAfterUnanswered && failing >= fallbackAfterSilence
}

// fallbackNoLongerNeeded reports whether the HTTPS path can be let go.
func (s *session) fallbackNoLongerNeeded() bool {
	if s.discovery == nil || s.fallback == nil {
		return false
	}

	return s.discovery.Answered(fallbackSettled) && s.fallback.Idle() > fallbackUnusedFor
}

// fallbackState is what the status file says about the HTTPS path.
//
// Nil on the overwhelming majority of devices, which never open it: a field
// that is present and empty reads like a path that failed, and this one has
// simply never been needed.
func (s *session) fallbackState() *state.RuntimeFallback {
	if s.fallback == nil || !s.fallback.Up() {
		return nil
	}

	return &state.RuntimeFallback{
		URL:      s.fallbackURL,
		Observed: s.fallback.Observed(),
		Since:    time.Now().Add(-s.fallback.Idle()).UTC(),
	}
}
