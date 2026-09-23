package server

import (
	"fmt"
	"net/netip"
	"sort"
	"strings"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/coordinator/internal/registry"
	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
)

// ticketLifetime bounds how long an agent may present a ticket to a relay.
//
// Short, because a ticket is the relay's entire authorisation model and a
// leaked one should stop being useful quickly. Long enough that an agent does
// not have to round-trip the coordinator for every reconnection.
const ticketLifetime = 10 * time.Minute

// ticketLife is the lifetime actually used, so the lab can make it short
// enough to watch a renewal happen.
//
// Ten minutes is right in production and useless in a drill: proving that a
// pair survives its ticket expiring would take an hour of wall clock per run.
// AKCONNECT_TICKET_SECONDS shortens it, and is read once at start.
//
// Environment rather than a flag because the relay and the coordinator have
// to agree about nothing here — the expiry travels inside the ticket — so
// there is no configuration to keep in step, only a number the coordinator
// stamps.
func (s *Server) ticketLife() time.Duration {
	if s.ticketLifeOverride > 0 {
		return s.ticketLifeOverride
	}

	return ticketLifetime
}

// handleRelayRequest answers an agent that cannot reach a peer directly.
//
// The coordinator decides, not the agent: the agent says "I cannot reach this
// peer", and the coordinator checks that the pair is allowed to talk at all
// before issuing anything. An agent that could mint its own relay session
// would be able to use the relay against peers its ACL forbids.
func (s *Server) handleRelayRequest(header disco.Header, sealed []byte, from netip.AddrPort) {
	body, err := disco.Open(sealed, &header.Sender, &s.opts.PrivateKey)
	if err != nil {
		return
	}

	request, err := disco.DecodeRelayRequest(body)
	if err != nil {
		return
	}

	self, ok := s.reg.Get(header.Sender)
	if !ok {
		// Never said hello, so nothing is known about who it may talk to.
		return
	}

	if _, allowed := self.Peers[request.Peer]; !allowed {
		s.opts.Logf("refused a relay request from %s: that peer is not permitted", self.DeviceUID)

		return
	}

	// The agent names the relay it could not use. Two devices agreeing takes
	// that relay out of rotation for everyone, which is what turns one pair's
	// failure into a fleet-wide failover.
	if request.Avoid != "" && s.health.complain(request.Avoid, header.Sender) {
		s.opts.Logf("relay %s taken out of rotation: enough devices report it unusable", request.Avoid)
	}

	peer, _ := s.reg.Get(request.Peer)

	relay := s.pickRelayAvoiding(self, peer, request.Avoid)
	if relay == nil {
		s.opts.Logf("no relay available for %s", self.DeviceUID)

		return
	}

	ticket := &disco.Ticket{
		TenantID:  uint64(self.TenantID),
		ExpiresAt: time.Now().Add(s.ticketLife()).Unix(),
		Self:      header.Sender,
		Peer:      request.Peer,
	}
	ticket.Sign(relay.Secret)

	offer := &disco.RelayOffer{
		Peer:     request.Peer,
		Endpoint: relay.Endpoint,
		Ticket:   ticket.Encode(),
		Name:     relay.Name,
	}

	encoded, err := offer.Encode()
	if err != nil {
		return
	}

	pkt, err := s.sealTo(disco.TypeRelayOffer, header.Sender, encoded)
	if err != nil {
		return
	}

	s.send(pkt, from)

	s.opts.Logf("offered %s the relay %s for peer %x…", self.DeviceUID, relay.Name, request.Peer[:6])

	// The other end is offered the same relay, unprompted. Both ends must
	// bind for anything to flow, and waiting for the peer to independently
	// give up on hole punching would double the time to first packet.
	s.offerToPeer(request.Peer, header.Sender, relay)
}

// offerToPeer sends the matching offer to the other end of the pair.
func (s *Server) offerToPeer(peerKey, selfKey [32]byte, relay *RelayTarget) {
	peer, ok := s.reg.Get(peerKey)
	if !ok || !peer.Reflexive.IsValid() {
		// Said out loud, because this is a pair half-formed.
		//
		// One end was offered a relay and bound to it; the other was never
		// told, so nothing crossed and neither machine could explain why. It
		// happened after a panel outage dropped the peer out of the registry:
		// the offer went to one end, this returned in silence, and both
		// devices sat on "connecting" until a service was restarted.
		//
		// The agent that asked will ask again — a relay it cannot use goes
		// unanswered and that is a failover it retries — so the recovery is
		// already there. What was missing was any record that half a pair had
		// been served.
		s.opts.Logf("offered %x… a relay but could not offer the other end %x…: "+
			"it is not registered right now, so nothing will flow until it announces again",
			selfKey[:6], peerKey[:6])

		return
	}

	ticket := &disco.Ticket{
		TenantID:  uint64(peer.TenantID),
		ExpiresAt: time.Now().Add(s.ticketLife()).Unix(),
		Self:      peerKey,
		Peer:      selfKey,
	}
	ticket.Sign(relay.Secret)

	offer := &disco.RelayOffer{
		Peer:     selfKey,
		Endpoint: relay.Endpoint,
		Ticket:   ticket.Encode(),
		Name:     relay.Name,
	}

	encoded, err := offer.Encode()
	if err != nil {
		return
	}

	pkt, err := s.sealTo(disco.TypeRelayOffer, peerKey, encoded)
	if err != nil {
		return
	}

	s.send(pkt, peer.Reflexive)
}

// RelayTarget is a relay the coordinator may hand out.
type RelayTarget struct {
	Name     string
	Region   string
	Endpoint string
	Secret   []byte
}

// pickRelay chooses which relay a pair should use.
//
// Both ends must land on the same relay, so this is one decision made for two
// devices, not two decisions that happen to agree. The measure is the worse of
// the two round trips: a relay 5 ms from one end and 300 ms from the other is
// a 300 ms relay for this conversation, and picking it because one end likes
// it would be optimising for the wrong device.
//
// The numbers come from the agents, because they are the only things that can
// measure them. A coordinator in Mumbai cannot tell how far a shop in
// Ahmedabad is from a relay in Chennai, and a device behind CGNAT does not
// even have an address we could guess from.
//
// Region is a tiebreak and nothing more. It is routinely empty, and selection
// has to work perfectly well when it is — an operator who has not labelled
// anything should still get the nearest relay.
// pickRelayAvoiding is pickRelay with one relay excluded, which is what a
// failover needs: the relay the agent just failed on must not be the answer to
// "give me another one", even while it is still in rotation for everyone else.
func (s *Server) pickRelayAvoiding(self, peer *registry.Entry, avoid string) *RelayTarget {
	if relay := s.pickRelay(self, peer, avoid); relay != nil {
		return relay
	}

	// Nothing else in the fleet. Offering the failed relay again is the only
	// thing left, and a relay that has since come back beats refusing to
	// answer at all.
	if relay := s.pickRelay(self, peer, ""); relay != nil {
		return relay
	}

	// And if the only relay there is has been marked down by complaints,
	// offer it anyway.
	//
	// This is the difference between a degraded pair and a dead one. A
	// one-relay deployment — which is every deployment until somebody adds a
	// second — had its single relay taken out of rotation by an agent whose
	// TICKET had expired, complaining every twenty seconds that the relay was
	// unusable. It was not: it was refusing an expired ticket, correctly.
	// With the relay marked down, both the avoiding pick and the fallback
	// above skipped it, no offer was ever sent, and the pair stayed dead
	// until somebody restarted the agent.
	//
	// A relay that some devices cannot use is still the only thing that might
	// work. Refusing to answer guarantees it will not.
	return s.pickRelayIgnoringHealth(self, peer)
}

// pickRelayIgnoringHealth is the last resort: the best relay in the fleet
// even if health has taken it out of rotation, because having no relay to
// offer is worse than offering one that may be unwell.
//
// The relay itself, secret and all. This used to build a new RelayTarget from
// the name, endpoint and region and leave the Secret out, so every ticket it
// produced was signed with an empty key and every bind was dropped by the
// relay without a word. The complaints that followed kept the relay marked
// down, the next offer came from here again, and on a one-relay install a
// relay outage of twenty seconds could leave relayed pairs dead for good.
func (s *Server) pickRelayIgnoringHealth(self, peer *registry.Entry) *RelayTarget {
	for _, relay := range s.opts.Relays {
		if relay != nil {
			return relay
		}
	}

	return nil
}

// avoid names a relay to exclude from this decision — the one an agent just
// failed on. It is a parameter rather than a filtered copy of the fleet
// because every packet is handled in its own goroutine, so a selection that
// edited s.opts.Relays even briefly would be two concurrent requests
// corrupting each other's view of the fleet.
func (s *Server) pickRelay(self, peer *registry.Entry, avoid string) *RelayTarget {
	if len(s.opts.Relays) == 0 {
		return nil
	}

	var best *RelayTarget
	var bestScore int

	for _, relay := range s.opts.Relays {
		if relay.Name == avoid || s.relayIsDown(relay.Name) {
			continue
		}

		score, known := pairScore(relay.Name, self, peer)
		if !known {
			continue
		}
		if regionMatches(relay, self, peer) {
			// Worth a little, not worth overriding a measurement: a relay in
			// the right region that is demonstrably further away is still
			// further away.
			score -= regionBonusMs
		}

		if best == nil || score < bestScore {
			best, bestScore = relay, score
		}
	}

	if best != nil {
		return best
	}

	// Nobody has reported a usable measurement for any relay — a brand new
	// device, or one whose probes are being dropped. Any relay that is not
	// known to be down beats no connectivity at all.
	for _, relay := range s.opts.Relays {
		if relay.Name != avoid && !s.relayIsDown(relay.Name) {
			return relay
		}
	}

	return nil
}

// regionBonusMs is how much a region match is worth, in milliseconds of
// measured latency. Small on purpose: it settles ties between relays that are
// genuinely close, and loses to any real difference.
const regionBonusMs = 5

// pairScore is the worse of the two ends' round trips to one relay.
//
// An end that has reported the relay as unreachable rules it out for the pair:
// a relay only one side can reach cannot carry a conversation between them.
// An end that has not reported at all is not an objection — a device that has
// only just started has measured nothing yet, and refusing to relay it until
// it does would mean refusing to connect it.
func pairScore(name string, self, peer *registry.Entry) (int, bool) {
	worst := 0
	any := false

	for _, entry := range []*registry.Entry{self, peer} {
		if entry == nil || entry.RelayRTT == nil {
			continue
		}

		rtt, ok := entry.RelayRTT[name]
		if !ok {
			continue
		}
		if rtt == disco.RTTUnreachable {
			return 0, false
		}

		any = true
		if int(rtt) > worst {
			worst = int(rtt)
		}
	}

	return worst, any
}

// regionMatches reports whether a relay is labelled with either end's region.
func regionMatches(relay *RelayTarget, self, peer *registry.Entry) bool {
	if relay.Region == "" {
		return false
	}

	for _, entry := range []*registry.Entry{self, peer} {
		if entry != nil && entry.Region != "" && entry.Region == relay.Region {
			return true
		}
	}

	return false
}

// handleRelayRTT records what a device measured against the relay fleet.
//
// Sealed, so the numbers can only come from the device whose key is on the
// packet. That matters: relay choice is made from these, and an attacker who
// could report on someone else's behalf could steer a tenant's traffic onto a
// relay of their choosing.
func (s *Server) handleRelayRTT(header disco.Header, sealed []byte) {
	body, err := disco.Open(sealed, &header.Sender, &s.opts.PrivateKey)
	if err != nil {
		return
	}

	report, err := disco.DecodeRelayRTT(body)
	if err != nil {
		return
	}

	// Only relays this coordinator actually knows about. A device is free to
	// report whatever it likes; believing a name that is not in the fleet
	// would let it invent relays.
	known := make(map[string]bool, len(s.opts.Relays))
	for _, relay := range s.opts.Relays {
		known[relay.Name] = true
	}

	samples := make(map[string]uint16, len(report.Samples))
	for _, sample := range report.Samples {
		if known[sample.Name] {
			samples[sample.Name] = sample.RTT
		}
	}

	if len(samples) == 0 {
		return
	}

	if !s.reg.SetRelayRTT(header.Sender, samples) {
		// Never said hello. Measurements from a device we know nothing about
		// are not something to hold on to.
		return
	}

	// Logged because relay choice is otherwise invisible: an operator asking
	// why a site was put on a particular relay has nothing else to read.
	if entry, ok := s.reg.Get(header.Sender); ok {
		s.opts.Logf("relay latency from %s: %s", entry.DeviceUID, formatSamples(samples))
	}
}

// formatSamples renders a measurement table for the log, nearest first.
func formatSamples(samples map[string]uint16) string {
	names := make([]string, 0, len(samples))
	for name := range samples {
		names = append(names, name)
	}
	sort.Slice(names, func(i, j int) bool { return samples[names[i]] < samples[names[j]] })

	parts := make([]string, 0, len(names))
	for _, name := range names {
		if samples[name] == disco.RTTUnreachable {
			parts = append(parts, name+"=unreachable")
			continue
		}
		parts = append(parts, fmt.Sprintf("%s=%dms", name, samples[name]))
	}

	return strings.Join(parts, " ")
}
