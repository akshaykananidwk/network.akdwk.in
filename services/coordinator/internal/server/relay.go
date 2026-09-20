package server

import (
	"net/netip"
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

	relay := s.pickRelay(self)
	if relay == nil {
		s.opts.Logf("no relay available for %s", self.DeviceUID)

		return
	}

	ticket := &disco.Ticket{
		TenantID:  uint64(self.TenantID),
		ExpiresAt: time.Now().Add(ticketLifetime).Unix(),
		Self:      header.Sender,
		Peer:      request.Peer,
	}
	ticket.Sign(relay.Secret)

	offer := &disco.RelayOffer{
		Peer:     request.Peer,
		Endpoint: relay.Endpoint,
		Ticket:   ticket.Encode(),
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

	s.opts.Logf("offered %s the relay %s for peer %x…",
		self.DeviceUID, relay.Name, request.Peer[:6])

	// The other end is offered the same relay, unprompted. Both ends must
	// bind for anything to flow, and waiting for the peer to independently
	// give up on hole punching would double the time to first packet.
	s.offerToPeer(request.Peer, header.Sender, relay)
}

// offerToPeer sends the matching offer to the other end of the pair.
func (s *Server) offerToPeer(peerKey, selfKey [32]byte, relay *RelayTarget) {
	peer, ok := s.reg.Get(peerKey)
	if !ok || !peer.Reflexive.IsValid() {
		return
	}

	ticket := &disco.Ticket{
		TenantID:  uint64(peer.TenantID),
		ExpiresAt: time.Now().Add(ticketLifetime).Unix(),
		Self:      peerKey,
		Peer:      selfKey,
	}
	ticket.Sign(relay.Secret)

	offer := &disco.RelayOffer{
		Peer:     selfKey,
		Endpoint: relay.Endpoint,
		Ticket:   ticket.Encode(),
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

// pickRelay chooses which relay to use.
//
// Region first: a shop in Ahmedabad relaying through Frankfurt would work and
// would be unusable. Within a region the choice is round-robin, because the
// coordinator cannot measure latency from where the agents are — the agents
// can, and reporting that back is the refinement this leaves room for.
func (s *Server) pickRelay(entry *registry.Entry) *RelayTarget {
	if len(s.opts.Relays) == 0 {
		return nil
	}

	for _, relay := range s.opts.Relays {
		if relay.Region != "" && relay.Region == entry.Region {
			return relay
		}
	}

	// No regional match: any relay beats no connectivity.
	return s.opts.Relays[0]
}
