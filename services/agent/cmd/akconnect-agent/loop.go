package main

import (
	"context"
	"errors"
	"net/netip"
	"sort"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/probe"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/state"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/tunnel"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/wgkey"
)

// wgPrivate is an alias so up.go reads without importing the key package for
// one type name.
type wgPrivate = wgkey.Private

// defaultPoll is used when the panel does not say how long to wait.
const defaultPoll = 10 * time.Second

// coordinatorSilentAfter is how long without an answer means the coordinator
// is not reachable FROM HERE, whatever it was doing earlier.
//
// Ninety seconds: announcements are twenty seconds apart when everything is
// working and five apart when they are not, so this cannot be tripped by one
// lost packet or a coordinator restart.
const coordinatorSilentAfter = 90 * time.Second

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
			s.stateSt.ClearRuntime()

			return nil
		case <-time.After(interval):
		}

		s.publishRuntime(true)

		if err := s.heartbeat(ctx); err != nil {
			if s.confirmRevoked(err) {
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
			if s.confirmRevoked(err) {
				s.logf("this device has been revoked; disconnecting")
				return nil
			}
			s.logf("configuration refresh failed, tunnel left up: %v", err)
			continue
		}

		interval = pollInterval(next)

		// Last, and only after a healthy pass: an agent that cannot reach its
		// panel or apply its configuration has no business replacing its own
		// binary.
		s.maybeUpdate(ctx)
	}
}

// heartbeat reports liveness and the device's own view of its traffic.
func (s *session) heartbeat(ctx context.Context) error {
	hb := panel.Heartbeat{
		Revision: s.st.Revision,
		Problems: s.problems(),
		// What this agent last did about a release. Sent on every heartbeat
		// rather than once, because the panel is the thing that has to notice
		// a fleet that has stopped updating, and a report that arrives once
		// is a report that is lost the first time a heartbeat fails.
		Update: s.updateState,
		// Rounded to whole seconds and always at least one, so a heartbeat
		// sent in the first second of a restart still says "just started"
		// rather than saying nothing, which the panel reads as unknown.
		UptimeSeconds: max(1, int(time.Since(s.startedAt).Seconds())),
	}

	if s.tun != nil {
		if peers, err := s.tun.Status(); err == nil {
			var rx, tx int64
			for _, p := range peers {
				rx += p.RXBytes
				tx += p.TXBytes
			}

			// Deltas, because the panel accumulates them. Counters only go up
			// while the device runs, so a restart shows as a drop; clamping at
			// zero reports nothing rather than a negative.
			hb.RXDelta = max64(0, rx-s.lastRX)
			hb.TXDelta = max64(0, tx-s.lastTX)
			s.lastRX, s.lastTX = rx, tx

			hb.ConnectionType = s.connectionType(peers)
			hb.Peers = s.peerLinks(peers)
		}
	}

	if endpoint := s.reflexive(); endpoint != "" {
		hb.Endpoint = endpoint
	}

	hb.Probes = s.runProbes(ctx)

	_, err := s.client.SendHeartbeat(ctx, hb)

	return err
}

// runProbes answers the reachability tests the panel asked for.
//
// Bounded per heartbeat rather than run all at once: a device that was
// offline while somebody clicked Test a dozen times comes back to a dozen
// requests, and running them together would put a burst of traffic on a
// customer's network at the moment it is least expected.
func (s *session) runProbes(ctx context.Context) []panel.ProbeAnswer {
	if len(s.pendingProbes) == 0 {
		return nil
	}

	const perHeartbeat = 4

	take := s.pendingProbes
	if len(take) > perHeartbeat {
		take = take[:perHeartbeat]
		s.pendingProbes = s.pendingProbes[perHeartbeat:]
	} else {
		s.pendingProbes = nil
	}

	answers := make([]panel.ProbeAnswer, 0, len(take))
	for _, request := range take {
		// On the gateway for this range, test the REAL address.
		//
		// The mapping is applied to traffic arriving from the overlay, not to
		// traffic this machine originates, so the gateway has no route for
		// 10.128.5.1 at all — it sent the probe nowhere and reported the
		// router unreachable while a browser on the same machine was showing
		// that router's login page. A false "no answer" on a customer's
		// router sends somebody to a site to fix nothing.
		target := request.Target
		onLAN := ""

		if s.mappings != nil {
			if asked, err := netip.ParseAddr(request.Target); err == nil {
				if real, ok := s.mappings.Real(asked); ok {
					target = real.String()
					onLAN = real.String()
				}
			}
		}

		result := probe.Do(ctx, target)

		// Say which address was actually tested, so a result nobody expected
		// can be explained without reading the code.
		if onLAN != "" && result.Method != "" {
			result.Method += " lan"
		}

		if result.OK {
			s.logf("probe: %s answered in %dms over %s", target, result.LatencyMS, result.Method)
		} else {
			s.logf("probe: %s did not answer: %s", target, result.Error)
		}

		answers = append(answers, panel.ProbeAnswer{
			ID:        request.ID,
			OK:        result.OK,
			LatencyMS: result.LatencyMS,
			Method:    result.Method,
			Error:     result.Error,
		})
	}

	return answers
}

func (s *session) reflexive() string {
	if s.discovery == nil {
		return ""
	}

	if addr := s.discovery.Reflexive(); addr.IsValid() {
		return addr.String()
	}

	return ""
}

func max64(a, b int64) int64 {
	if a > b {
		return a
	}

	return b
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

	if err := s.applyConfig(ctx, priv, cfg); err != nil {
		return pollAfter, err
	}

	s.logf("configuration updated to revision %d (%d peer(s))", cfg.Revision, len(cfg.Peers))

	return pollAfter, nil
}

// connectionType reports how the tunnel is carrying traffic, which is what the
// panel's green, amber and red indicator shows.
//
// Two things must both be true to claim "direct": a peer has handshaked
// recently, and discovery says that peer is reached directly rather than
// through a relay. A handshake alone proves traffic flows, not how — and the
// difference is what a customer is billed for.
//
// The panel accepts "direct", "relay", "relay_https" and "connecting". It does
// not accept "offline" from an agent any more, and should not: a heartbeat
// arriving is proof the device is not offline, and the panel decides that from
// when it last heard rather than from what it was told.
// peerLinks is this device's account of every peer it was given.
//
// Every peer, including the ones it cannot reach — that is the point. A peer
// with no path is reported with an empty path rather than left out, because
// "I have three peers and can reach two" and "I have two peers" are different
// sentences and only one of them is true.
func (s *session) peerLinks(peers []tunnel.PeerStatus) []panel.PeerLink {
	if len(s.peerMeta) == 0 {
		return nil
	}

	cutoff := time.Now().Add(-3 * time.Minute).Unix()

	// Indexed by the hex key WireGuard reports, so a peer the device has no
	// session for at all still gets a row.
	live := make(map[string]tunnel.PeerStatus, len(peers))
	for _, p := range peers {
		live[p.PublicKeyHex] = p
	}

	links := make([]panel.PeerLink, 0, len(s.peerMeta))
	for hex, meta := range s.peerMeta {
		if meta.uid == "" {
			continue
		}

		link := panel.PeerLink{UID: meta.uid}

		if p, ok := live[hex]; ok && p.LastHandshake > cutoff {
			link.Path = "direct"

			if s.discovery != nil && s.discovery.Path(meta.publicKey) == "relay" {
				link.Path = "relay-udp"
				if s.onFallback(meta.publicKey, p.Endpoint) {
					link.Path = "relay-https"
				}
			}
		}

		links = append(links, link)
	}

	// Stable, so a heartbeat that says the same thing twice looks the same
	// twice — map order would make every one of them a change.
	sort.Slice(links, func(i, j int) bool { return links[i].UID < links[j].UID })

	return links
}

func (s *session) connectionType(peers []tunnel.PeerStatus) string {
	cutoff := time.Now().Add(-3 * time.Minute).Unix()
	live := false
	relayed := false
	overHTTPS := false

	for _, p := range peers {
		if p.LastHandshake <= cutoff {
			continue
		}
		live = true

		meta, ok := s.peerMeta[p.PublicKeyHex]
		if !ok || s.discovery == nil {
			continue
		}

		if s.discovery.Path(meta.publicKey) == "relay" {
			relayed = true

			if s.onFallback(meta.publicKey, p.Endpoint) {
				overHTTPS = true
			}
		}
	}

	switch {
	case !live:
		// "connecting", not "offline": this process is running and talking to
		// the panel, so the device is plainly not offline. What has not
		// happened yet is a path to a peer, and calling that offline is what
		// made two running machines show as red dots in the panel for an
		// evening.
		return "connecting"
	case relayed && overHTTPS:
		// Distinct from an ordinary relay, because it says something an
		// operator can act on that "relay" does not: this network will not
		// carry UDP at all, so the device will never reach a peer directly
		// from here however long it waits.
		return "relay_https"
	case relayed:
		// Amber if any peer is relayed: the operator needs to know some of
		// this device's traffic is going through our servers, not that all of
		// it is.
		return "relay"
	default:
		return "direct"
	}
}

// publishRuntime writes what a support script needs to collect, so the
// question "is this device connected, and how" has an answer that does not
// depend on reading a log or being the process that holds the tunnel.
func (s *session) publishRuntime(controlPlaneUp bool) {
	rt := &state.Runtime{
		VirtualIP:      s.st.VirtualIP,
		Revision:       s.st.Revision,
		ControlPlaneUp: controlPlaneUp,
	}

	if s.tun != nil {
		rt.Interface = s.tun.Name()
		rt.ListenPort = s.tun.ListenPort()
	}
	if s.names != nil {
		_, _, refused, _ := s.names.Stats()
		zone := s.names.Zone()
		rt.Names = &state.RuntimeNames{
			Zone:     zone.Suffix,
			Resolver: s.names.Addr().String(),
			Records:  zone.Len(),
			RoutedBy: s.dnsRoutedBy,
			Note:     s.dnsNote,
			Refused:  refused,
		}
	}

	if s.gateway != nil {
		for i, lan := range s.gateway.Advertised {
			if i >= len(s.gateway.Mapped) {
				break
			}
			rt.Mappings = append(rt.Mappings, state.RuntimeMapping{
				Overlay: s.gateway.Mapped[i].String(),
				LAN:     lan.String(),
			})
		}
		rt.Gateway = &state.RuntimeGateway{Mode: "system", Problem: s.gatewayProblem}
		if s.tun != nil {
			if c := s.tun.GatewayCounters(); c != nil {
				rt.Gateway.Mode = "agent"
				rt.Gateway.Diverted = c.Diverted.Load()
				rt.Gateway.TCPOpened = c.TCPOpened.Load()
				rt.Gateway.TCPRefused = c.TCPRefused.Load()
				rt.Gateway.UDPOpened = c.UDPOpened.Load()
				rt.Gateway.PingsOK = c.PingsOK.Load()
				rt.Gateway.PingsFailed = c.PingsFailed.Load()
				rt.Gateway.Dropped = c.Dropped.Load()
			}
		}
	}

	if s.plan != nil && len(s.plan.Routes) > 0 {
		rt.OverlayCIDR = s.plan.Routes[0].String()
	}
	if s.discovery != nil {
		if reflexive := s.discovery.Reflexive(); reflexive.IsValid() {
			rt.Reflexive = reflexive.String()
		}

		// Recently answered, not ever answered.
		//
		// This used to be set from the reflexive address alone, which is
		// written once and never cleared — so a device answered at nine in the
		// morning reported a reachable coordinator all day, including while it
		// sat unable to receive a thing. The status file is what the tray and
		// `status` read, and both were repeating that.
		rt.CoordinatorUp = s.discovery.Answered(coordinatorSilentAfter)
		rt.Unanswered, rt.UnansweredFor = s.discovery.Unanswered()
	}

	rt.Coordinator = s.coordinator
	rt.Fallback = s.fallbackState()

	rt.Peers = s.peerStatus()

	if err := s.stateSt.SaveRuntime(rt); err != nil {
		s.logf("could not write the status file: %v", err)
	}
}

// peerStatus turns the device's own view of its peers into something a person
// can read, including whether each path is actually carrying traffic.
func (s *session) peerStatus() []state.RuntimePeer {
	if s.tun == nil {
		return nil
	}

	peers, err := s.tun.Status()
	if err != nil {
		return nil
	}

	out := make([]state.RuntimePeer, 0, len(peers))

	for _, p := range peers {
		entry := state.RuntimePeer{
			PublicKey: p.PublicKeyHex,
			Endpoint:  p.Endpoint,
			RXBytes:   p.RXBytes,
			TXBytes:   p.TXBytes,
			Path:      "connecting",
		}

		if p.LastHandshake > 0 {
			ago := time.Since(time.Unix(p.LastHandshake, 0))
			entry.LastHandshakeAgo = ago.Truncate(time.Second).String()
			// A handshake within the rekey window means this path is live.
			// Anything older is a path that worked once and may not now.
			if ago < 3*time.Minute {
				entry.Path = "direct"
			} else {
				entry.Path = "stale"
			}
		}

		// Names and overlay addresses come from the configuration, which the
		// device does not keep, so they are filled in from the last config.
		if meta, ok := s.peerMeta[p.PublicKeyHex]; ok {
			entry.Name = meta.name
			entry.VirtualIP = meta.virtualIP

			// Discovery knows whether the path is direct or relayed; the
			// device only knows that a handshake happened. Prefer discovery's
			// answer, because "connected" and "connected the expensive way"
			// are different facts to a customer billed for relayed bytes.
			if s.discovery != nil {
				if path := s.discovery.Path(meta.publicKey); path != "connecting" {
					entry.Path = path
				}
				entry.Relay = s.discovery.RelayName(meta.publicKey)
			}

			// "relay" is two different situations to whoever is looking at it:
			// a pair that could not punch through, and a device on a network
			// that carries no UDP at all. Only the second one will still be
			// relayed tomorrow.
			if entry.Path == "relay" {
				entry.Path = "relay-udp"
				if s.onFallback(meta.publicKey, p.Endpoint) {
					entry.Path = "relay-https"
				}
			}
		}

		out = append(out, entry)
	}

	return out
}

// onFallback reports whether a peer's traffic is on the HTTPS path right now.
//
// Decided by comparing the endpoint WireGuard is actually using against the
// one the fallback handed out, rather than by remembering that it once did.
// The two stop matching the instant discovery adopts a real relay port, which
// is exactly when this should stop being true.
// divertedTo reports the endpoint the fallback is carrying this peer on.
//
// Read from the fallback's own table rather than remembered, so it stops
// answering the moment releasePeers hands the peers back to UDP.
func (s *session) divertedTo(peer [32]byte) (netip.AddrPort, bool) {
	if s.fallback == nil || !s.fallback.Up() {
		return netip.AddrPort{}, false
	}

	return s.fallback.Endpoint(peer)
}

func (s *session) onFallback(peer [32]byte, endpoint string) bool {
	if s.fallback == nil || !s.fallback.Up() || endpoint == "" {
		return false
	}

	at, ok := s.fallback.Endpoint(peer)

	return ok && at.String() == endpoint
}

// revokeConfirmations is how many times in a row the panel must say a device
// is unauthorized before the agent believes it.
//
// One is not enough even when the code is right. A panel mid-deploy, a
// database that came back with an empty table for a few seconds, a botched
// migration — any of these can answer correctly-shaped nonsense briefly, and
// the cost of believing it is every device disconnecting at once with no way
// back but a site visit. The cost of waiting is that a genuinely revoked
// device keeps passing traffic for another minute, which is a bounded and much
// smaller harm.
const revokeConfirmations = 3

// revokeConfirmWindow is how long those answers must span.
//
// Both conditions, so three fast polls in the same second do not count as
// confirmation.
const revokeConfirmWindow = 60 * time.Second

// confirmRevoked decides whether to believe the panel.
//
// Returns true only when the panel has said "this device is not authorized" —
// its own structured code, not merely a 401 — enough times, over long enough,
// that a transient fault has been ruled out.
func (s *session) confirmRevoked(err error) bool {
	if !revoked(err) {
		// Anything else resets the count, including a success: the evidence
		// has to be consecutive to mean anything.
		s.revokedSince = time.Time{}
		s.revokedSeen = 0

		return false
	}

	if s.revokedSeen == 0 {
		s.revokedSince = time.Now()
	}
	s.revokedSeen++

	if s.revokedSeen < revokeConfirmations || time.Since(s.revokedSince) < revokeConfirmWindow {
		s.logf("the panel says this device is not authorized (%d of %d checks); "+
			"leaving the tunnel up until it is confirmed",
			s.revokedSeen, revokeConfirmations)

		return false
	}

	return true
}

// revoked reports the panel's own answer that this device is not authorized.
//
// The structured code, not the status. See APIError.Revoked: a 401 or 403
// arrives from Apache dropping a header, a WAF, maintenance mode, a rate
// limiter or a panel with a sick database, and treating those as revocation
// disconnects an entire fleet over somebody else's outage.
func revoked(err error) bool {
	var apiErr *panel.APIError

	return errors.As(err, &apiErr) && apiErr.Revoked()
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
