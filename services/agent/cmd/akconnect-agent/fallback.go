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

// How long a device whose UDP was working seconds ago waits before it decides
// the network has stopped carrying UDP.
//
// Four seconds is right for a device that has never had UDP — somebody
// standing in a hotel lobby must not wait — and wrong for one that was
// carrying traffic a moment ago, because the likeliest explanation by far is
// not a network that has changed but a server that is restarting. An edge
// upgrade restarts the coordinator and the relay back to back, which is three
// to six seconds of nothing answering, and both ends of every relayed pair
// concluded their network blocked UDP and opened the fallback. Measured on the
// drill: eighteen seconds of lost traffic and a pair that did not settle for
// minutes, every time anybody pressed Update Now.
//
// Fifteen seconds, and the number is a count of retries rather than a guess.
// An unanswered announcement is now retried every five seconds with a full
// hello, so fifteen seconds is three attempts that went nowhere — by which
// point a restart has long since finished, because with the rest of this
// release a coordinator and relay restart costs about three seconds end to
// end.
//
// It was thirty at first, and thirty broke the drill this exists to protect:
// a device whose network genuinely stops carrying UDP has a thirty-second
// budget to get back, and thirty seconds of waiting plus twenty of connecting
// does not fit in it. The cost of this number falls on a real customer either
// way — too low and an edge upgrade drops everybody onto HTTPS, too high and
// somebody carrying a laptop onto a bad network waits. Three failed retries
// is the smallest amount of evidence that distinguishes them.
const (
	fallbackWarmSilence = 15 * time.Second
	udpWorkedRecently   = 60 * time.Second
)

// How long UDP has to be working, and the fallback carrying nothing, before
// the connection is dropped.
//
// Both conditions, because either alone is misleading: the coordinator
// answering says nothing about whether a peer's traffic is still relayed over
// HTTPS, and an idle tunnel on a network that blocks UDP is idle because
// nobody is using the device, not because the path is unnecessary.
//
// Five minutes was the old value for fallbackUnusedFor, and while the two
// paths both announced it was five minutes of a relayed pair being told its
// peer had moved twice every twenty seconds. That duplicate is gone, so this
// is no longer the dangerous window it was — but a connection nothing is
// using, on a device whose UDP is demonstrably working, is still just a
// socket held open through somebody's proxy. Forty-five seconds is long
// enough not to flap it open and shut on a marginal link.
const (
	fallbackSettled   = 2 * time.Minute
	fallbackUnusedFor = 45 * time.Second
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
			carrying := s.fallback != nil && s.fallback.Up()
			s.discovery.HoldPort(carrying)
			// And while it is carrying, discovery announces down the tunnel
			// only, and tests the real socket with a probe instead. See
			// DivertedControl.
			s.discovery.DivertedControl(carrying)
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

	silence := fallbackAfterSilence
	if s.tun != nil && s.tun.Transport().UDPAlive(udpWorkedRecently) {
		// This device was carrying UDP within the last minute, so a few
		// seconds of silence is far more likely to be our own servers
		// restarting than the network changing under it.
		silence = fallbackWarmSilence
	}

	if count, since := s.discovery.Unanswered(); count >= fallbackAfterUnanswered &&
		since >= silence {
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
