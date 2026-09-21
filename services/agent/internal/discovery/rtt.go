package discovery

import (
	"net/netip"
	"sort"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
)

// RelayTarget is one relay from the panel's fleet list, as the agent sees it.
type RelayTarget struct {
	Name string
	Addr netip.AddrPort
}

// probeInterval is how often the fleet is re-measured.
//
// Relay latency changes with routing, not with the second, so this is slow on
// purpose: a device on a metered 4G connection should not spend its data
// allowance telling us what it already told us.
const probeInterval = 2 * time.Minute

// probeTimeout is how long a relay has to answer before it is recorded as
// unreachable. Generous, because the alternative to a slow relay is usually no
// connectivity at all.
const probeTimeout = 3 * time.Second

// pendingProbe is one probe in flight.
type pendingProbe struct {
	name string
	sent time.Time
}

// probeRelays sends one probe to every relay in the fleet.
//
// The probes go out on the same socket WireGuard uses, like everything else in
// discovery, so the measurement includes the NAT the real traffic will cross.
// Measuring from a second socket would give a number that is accurate about
// the network and wrong about the product.
func (c *Client) probeRelays() {
	c.mu.Lock()
	fleet := append([]RelayTarget(nil), c.relays...)
	c.mu.Unlock()

	for _, relay := range fleet {
		nonce, err := disco.NewProbeNonce()
		if err != nil {
			continue
		}

		pkt := make([]byte, disco.HeaderLen+disco.ProbeNonceLen)
		disco.WriteHeader(pkt, disco.TypeRelayProbe, c.opts.SelfPublic)
		copy(pkt[disco.HeaderLen:], nonce[:])

		c.mu.Lock()
		c.probesInFlight[nonce] = pendingProbe{name: relay.Name, sent: time.Now()}
		c.mu.Unlock()

		if err := c.opts.Transport.SendTo(pkt, relay.Addr); err != nil {
			c.mu.Lock()
			delete(c.probesInFlight, nonce)
			c.mu.Unlock()
		}
	}
}

// handleProbeAck records a round trip.
func (c *Client) handleProbeAck(body []byte) {
	nonce, err := disco.DecodeProbeNonce(body)
	if err != nil {
		return
	}

	c.mu.Lock()
	defer c.mu.Unlock()

	probe, ok := c.probesInFlight[nonce]
	if !ok {
		// A reply to a probe we already gave up on, or one we never sent.
		// Either way timing it would produce a number that means nothing.
		return
	}
	delete(c.probesInFlight, nonce)

	rtt := time.Since(probe.sent)
	c.relayRTT[probe.name] = clampRTT(rtt)
	c.rttDirty = true
}

// expireProbes marks relays that did not answer.
func (c *Client) expireProbes() {
	c.mu.Lock()
	defer c.mu.Unlock()

	cutoff := time.Now().Add(-probeTimeout)
	for nonce, probe := range c.probesInFlight {
		if probe.sent.After(cutoff) {
			continue
		}
		delete(c.probesInFlight, nonce)
		c.relayRTT[probe.name] = disco.RTTUnreachable
		c.rttDirty = true
	}
}

// clampRTT converts a duration to the wire's milliseconds.
//
// Zero becomes one: a relay on the same host really can answer in under a
// millisecond, and reporting 0 would be indistinguishable from an unset value
// at the far end.
func clampRTT(d time.Duration) uint16 {
	ms := d.Milliseconds()
	if ms < 1 {
		return 1
	}
	if ms >= int64(disco.RTTUnreachable) {
		return disco.RTTUnreachable - 1
	}

	return uint16(ms)
}

// relaySamples renders the current measurements, best first.
//
// Sorted so that a coordinator reading a truncated report still sees the
// relays that matter, and so the ordering is stable for tests.
func (c *Client) relaySamples() []disco.RelaySample {
	c.mu.Lock()
	defer c.mu.Unlock()

	out := make([]disco.RelaySample, 0, len(c.relayRTT))
	for name, rtt := range c.relayRTT {
		out = append(out, disco.RelaySample{Name: name, RTT: rtt})
	}

	sort.Slice(out, func(i, j int) bool {
		if out[i].RTT != out[j].RTT {
			return out[i].RTT < out[j].RTT
		}

		return out[i].Name < out[j].Name
	})

	return out
}

// reportRelayRTT tells the coordinator what this device measured.
func (c *Client) reportRelayRTT() {
	samples := c.relaySamples()
	if len(samples) == 0 {
		return
	}

	report := &disco.RelayRTT{Samples: samples}
	body, err := report.Encode()
	if err != nil {
		return
	}

	pkt, err := c.seal(disco.TypeRelayRTT, c.opts.CoordKey, body)
	if err != nil {
		return
	}

	_ = c.opts.Transport.SendTo(pkt, c.opts.Coordinator)
}

// SetRelays replaces the fleet the agent measures against.
//
// Called whenever the panel's config changes, because a relay added to the
// fleet is no use to a device that will not learn about it until it restarts.
func (c *Client) SetRelays(fleet []RelayTarget) {
	c.mu.Lock()
	defer c.mu.Unlock()

	c.relays = fleet

	// Drop measurements for relays that are no longer in the fleet, so a
	// decommissioned relay cannot keep winning on an old number.
	live := make(map[string]bool, len(fleet))
	for _, relay := range fleet {
		live[relay.Name] = true
	}
	for name := range c.relayRTT {
		if !live[name] {
			delete(c.relayRTT, name)
		}
	}
}

// maybeProbeRelays re-measures the fleet when the interval has elapsed.
func (c *Client) maybeProbeRelays() {
	c.mu.Lock()
	due := time.Since(c.lastProbe) >= probeInterval && len(c.relays) > 0
	if due {
		c.lastProbe = time.Now()
	}
	c.mu.Unlock()

	if due {
		c.probeRelays()
	}
}

// reportInterval bounds how often measurements are sent, once there is
// something new to send.
const reportInterval = 5 * time.Second

// maybeReportRelayRTT sends a fresh measurement without waiting for the
// keepalive.
//
// The keepalive is twenty seconds and the deadline that sends a peer to a
// relay is five, so an agent that only reported on the keepalive had never
// reported anything by the time it first needed a relay. Its first assignment
// was therefore always made with no measurements at all, and fell back to
// whichever relay happened to be first in the fleet — which made latency-based
// selection something that only applied from the second assignment onwards.
//
// Reporting as soon as the first probe round answers closes that, and the
// interval stops a flapping relay turning into a stream of reports.
func (c *Client) maybeReportRelayRTT() {
	c.mu.Lock()
	due := c.rttDirty && time.Since(c.lastReport) >= reportInterval
	if due {
		c.rttDirty = false
		c.lastReport = time.Now()
	}
	c.mu.Unlock()

	if due {
		c.reportRelayRTT()
	}
}
