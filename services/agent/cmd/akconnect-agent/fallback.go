package main

import (
	"context"
	"net/netip"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/fallback"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
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

	count, since := s.discovery.Unanswered()

	return count >= fallbackAfterUnanswered && since >= fallbackAfterSilence
}

// fallbackNoLongerNeeded reports whether the HTTPS path can be let go.
func (s *session) fallbackNoLongerNeeded() bool {
	if s.discovery == nil || s.fallback == nil {
		return false
	}

	return s.discovery.Answered(fallbackSettled) && s.fallback.Idle() > fallbackUnusedFor
}

// fallbackPath describes how this device is reaching the control plane, for
// the status file and the tray.
func (s *session) fallbackPath() string {
	if s.fallback == nil || !s.fallback.Up() {
		return ""
	}

	return s.fallback.Observed()
}
