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

// udpProvenWithin is how recently a discovery packet must have arrived on the
// real socket for UDP to count as working.
//
// Measured at the socket, not from the coordinator answering: a device on the
// fallback is answered continuously, over the fallback, so every other measure
// of health reads fine while the network underneath carries nothing.
//
// Comfortably longer than the keepalive that produces those packets, so one
// lost datagram does not read as the network failing.
const udpProvenWithin = 45 * time.Second

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

	// The moment this device gave up on UDP. Everything the socket recorded
	// before it is evidence about a different network.
	var gaveUp time.Time

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

		// And the other half of the same question: once the socket is
		// carrying discovery traffic again, the fallback stops answering
		// binds so the peers go back to UDP by themselves.
		if s.fallback != nil {
			s.fallback.PreferUDP(s.udpIsWorking(gaveUp))
		}

		switch {
		case stop == nil && s.udpIsHopeless():
			s.logf("fallback: UDP is not being answered on this network; trying HTTPS instead")

			gaveUp = time.Now()

			running, cancel := context.WithCancel(ctx)
			stop = cancel

			go s.fallback.Run(running)
		case stop != nil && s.fallbackNoLongerNeeded(gaveUp):
			s.logf("fallback: UDP is working again and nothing is using the HTTPS path; closing it")
			stop()
			stop = nil
			gaveUp = time.Time{}
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

// udpIsWorking reports whether the ordinary path is carrying this device's
// traffic.
//
// Two conditions, and the second one exists because of the case the lab did
// not have: a machine that was working on UDP and is then carried to a network
// that is not.
//
// The proof that UDP works is a discovery packet arriving on the socket, and
// that proof is allowed to be forty-five seconds old — it has to be, because
// the keepalive that produces those packets is twenty seconds and one lost
// datagram must not read as the network failing. But the agent decides UDP is
// hopeless after four seconds of unanswered announcements, which is much
// sooner. In between, a device that had just moved would have opened the
// fallback and then refused to use it, because a stamp from the network it had
// left still said UDP was fine. Up to forty seconds of a thirty-second budget,
// on exactly the machine this release is for.
//
// Announcements going unanswered while the fallback is not yet carrying them
// is therefore enough on its own to stop preferring UDP. Once the fallback is
// up the coordinator answers through it, nothing goes unanswered, and the
// socket is the only thing left deciding — which is what it should be.
// Since is the moment this device gave up on UDP, or the zero time when it
// has not.
func (s *session) udpIsWorking(since time.Time) bool {
	if s.tun == nil || s.udpIsHopeless() {
		return false
	}

	// Once we have given up, only a packet that arrived AFTER that counts.
	//
	// The forty-five second window is right for "is this socket still alive"
	// and wrong for "has this network started carrying UDP again", and on a
	// machine carried from a working network onto one that drops UDP the two
	// give opposite answers: the stamp is four seconds old and describes the
	// office car park. The fallback would come up, hand the peers straight
	// back to a network that carries nothing, and the device would report
	// relay-udp while its traffic went over HTTPS — which is the one thing
	// this release exists to be able to tell apart.
	if !since.IsZero() {
		return s.tun.Transport().UDPAliveSince(since)
	}

	return s.tun.Transport().UDPAlive(udpProvenWithin)
}

// fallbackNoLongerNeeded reports whether the HTTPS path can be let go.
//
// Both conditions, and both measured at the socket. "The coordinator is
// answering" is not one of them: on the fallback it always is.
func (s *session) fallbackNoLongerNeeded(since time.Time) bool {
	if s.fallback == nil {
		return false
	}

	return s.udpIsWorking(since) && s.fallback.Idle() > fallbackUnusedFor
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
