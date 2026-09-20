package discovery

import (
	"net/netip"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
)

// punchDeadline is how long direct connection is attempted before asking for a
// relay.
//
// Short on purpose. A pair behind symmetric NAT will never punch through, and
// every second spent proving that again is a second the customer's traffic is
// not flowing. Punching continues afterwards regardless — see repunch.
const punchDeadline = 5 * time.Second

// repunchInterval is how often a relayed pair retries a direct path.
//
// It decays: networks change — a laptop moves from 4G to the shop's wifi, a
// carrier reassigns, someone fixes their router — so it is worth retrying
// forever, but not worth retrying often once it has failed for an hour.
var repunchSchedule = []time.Duration{
	30 * time.Second,
	60 * time.Second,
	2 * time.Minute,
	5 * time.Minute,
}

// requestRelay asks the coordinator for a relay to one peer.
func (c *Client) requestRelay(peer [32]byte) {
	request := &disco.RelayRequest{Peer: peer}

	pkt, err := c.seal(disco.TypeRelayRequest, c.opts.CoordKey, request.Encode())
	if err != nil {
		return
	}

	if err := c.opts.Transport.SendTo(pkt, c.opts.Coordinator); err != nil {
		c.opts.Logf("discovery: relay request failed: %v", err)
	}
}

// handleRelayOffer binds to the relay the coordinator chose.
func (c *Client) handleRelayOffer(header disco.Header, sealed []byte) {
	if header.Sender != c.opts.CoordKey {
		return
	}

	body, err := disco.Open(sealed, &header.Sender, &c.opts.SelfPrivate)
	if err != nil {
		return
	}

	offer, err := disco.DecodeRelayOffer(body)
	if err != nil {
		return
	}

	control, err := netip.ParseAddrPort(offer.Endpoint)
	if err != nil {
		c.opts.Logf("discovery: relay endpoint %q is unusable: %v", offer.Endpoint, err)

		return
	}

	c.mu.Lock()
	c.relayControl[offer.Peer] = control
	c.relayTicket[offer.Peer] = offer.Ticket
	c.mu.Unlock()

	c.sendRelayBind(offer.Peer, control, offer.Ticket)
}

// sendRelayBind presents the ticket, which is how the relay learns where this
// agent is and which pair it belongs to.
func (c *Client) sendRelayBind(peer [32]byte, control netip.AddrPort, ticket []byte) {
	pkt := make([]byte, disco.HeaderLen, disco.HeaderLen+len(ticket))
	disco.WriteHeader(pkt, disco.TypeRelayBind, c.opts.SelfPublic)
	pkt = append(pkt, ticket...)

	if err := c.opts.Transport.SendTo(pkt, control); err != nil {
		c.opts.Logf("discovery: relay bind to %s failed: %v", control, err)
	}
}

// handleRelayBindAck points the tunnel at the port the relay allocated.
func (c *Client) handleRelayBindAck(from netip.AddrPort, body []byte) {
	if len(body) < 2 {
		return
	}

	port := uint16(body[0])<<8 | uint16(body[1])

	// Match the acknowledgement back to the peer whose relay control address
	// this is. Without the match, an unsolicited ack could repoint an
	// unrelated peer at an attacker's port.
	c.mu.Lock()
	var peer [32]byte
	var found bool
	for candidate, control := range c.relayControl {
		if control.Addr() == from.Addr() && control.Port() == from.Port() {
			peer, found = candidate, true

			break
		}
	}
	c.mu.Unlock()

	if !found {
		return
	}

	endpoint := netip.AddrPortFrom(from.Addr(), port)
	c.adoptRelay(peer, endpoint)
}

// adoptRelay routes a peer's traffic through the relay.
//
// It uses the same call as a direct path, so the WireGuard session is not
// disturbed: switching between relayed and direct is a change of destination,
// not a reconnection. That is what makes the later upgrade silent.
func (c *Client) adoptRelay(peer [32]byte, at netip.AddrPort) {
	c.mu.Lock()
	if c.pathOf(peer) == pathDirect {
		// A direct path won while the relay was being arranged. Keep it.
		c.mu.Unlock()

		return
	}
	if current, ok := c.established[peer]; ok && current == at {
		c.mu.Unlock()

		return
	}
	c.established[peer] = at
	c.paths[peer] = pathRelay
	c.mu.Unlock()

	key := base64Key(peer)
	if err := c.opts.Peers.SetPeerEndpoint(key, at.String()); err != nil {
		c.opts.Logf("discovery: pointing peer at the relay failed: %v", err)

		return
	}

	c.opts.Logf("discovery: relaying to peer %s… via %s (still trying for a direct path)", key[:12], at)
}

// repunch retries a direct path for every relayed peer.
//
// This is the "silent upgrade" half of the design. A pair that starts relayed
// can become direct later without dropping a packet, because adopting the
// direct endpoint is the same operation that adopted the relay's.
func (c *Client) repunch() {
	c.mu.Lock()

	type attempt struct {
		peer       [32]byte
		candidates []netip.AddrPort
	}

	var due []attempt
	now := time.Now()

	for peer, path := range c.paths {
		if path != pathRelay {
			continue
		}

		next, ok := c.nextRepunch[peer]
		if ok && now.Before(next) {
			continue
		}

		tries := c.repunchCount[peer]
		delay := repunchSchedule[min(tries, len(repunchSchedule)-1)]
		c.repunchCount[peer] = tries + 1
		c.nextRepunch[peer] = now.Add(delay)

		due = append(due, attempt{peer: peer, candidates: c.candidates[peer]})
	}
	c.mu.Unlock()

	for _, a := range due {
		pkt, err := c.punchPacket(disco.TypePunch)
		if err != nil {
			continue
		}

		for _, candidate := range a.candidates {
			if c.usableCandidate(candidate) {
				_ = c.opts.Transport.SendTo(pkt, candidate)
			}
		}
	}
}

// rebindRelays re-presents tickets, which keeps the relay's NAT mapping for
// this agent alive and refreshes its idea of where we are.
func (c *Client) rebindRelays() {
	c.mu.Lock()

	type binding struct {
		peer    [32]byte
		control netip.AddrPort
		ticket  []byte
	}

	var bindings []binding
	for peer, control := range c.relayControl {
		if c.paths[peer] != pathRelay {
			continue
		}
		bindings = append(bindings, binding{peer: peer, control: control, ticket: c.relayTicket[peer]})
	}
	c.mu.Unlock()

	for _, b := range bindings {
		c.sendRelayBind(b.peer, b.control, b.ticket)
	}
}

func min(a, b int) int {
	if a < b {
		return a
	}

	return b
}
