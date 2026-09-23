package discovery

import (
	"net/netip"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
)

// handle processes one discovery packet lifted off the shared socket.
func (c *Client) handle(pkt []byte, from netip.AddrPort) {
	header, rest, err := disco.ParseHeader(pkt)
	if err != nil {
		return
	}

	switch header.Type {
	case disco.TypeHelloAck:
		c.handleHelloAck(header, rest)
	case disco.TypePeers:
		c.handlePeers(header, rest)
	case disco.TypePunch:
		c.handlePunch(header, from)
	case disco.TypePunchAck:
		c.handlePunchAck(header, from)
	case disco.TypeRelayOffer:
		c.handleRelayOffer(header, rest)
	case disco.TypeRelayBindRefused:
		c.handleRelayBindRefused(from, rest)
	case disco.TypeRelayBindAck:
		c.handleRelayBindAck(from, rest)
	case disco.TypeRelayProbeAck:
		c.handleProbeAck(rest)
	}
}

// handleHelloAck records the address the coordinator saw us from. It is the
// only way an agent behind NAT can learn its own public address.
func (c *Client) handleHelloAck(header disco.Header, sealed []byte) {
	if header.Sender != c.opts.CoordKey {
		return
	}

	body, err := disco.Open(sealed, &header.Sender, &c.opts.SelfPrivate)
	if err != nil {
		return
	}

	ack, err := disco.DecodeHelloAck(body)
	if err != nil {
		return
	}

	c.mu.Lock()
	changed := c.reflexive != ack.Reflexive
	c.reflexive = ack.Reflexive
	c.lastAck = time.Now()
	c.mu.Unlock()

	c.noteAnswered()

	if changed {
		c.opts.Logf("discovery: our public address is %s", ack.Reflexive)
	}
}

// handlePeers starts trying to reach everyone the coordinator introduced.
func (c *Client) handlePeers(header disco.Header, sealed []byte) {
	if header.Sender != c.opts.CoordKey {
		return
	}

	body, err := disco.Open(sealed, &header.Sender, &c.opts.SelfPrivate)
	if err != nil {
		return
	}

	peers, err := disco.DecodePeers(body)
	if err != nil {
		return
	}

	for _, peer := range peers.Peers {
		c.probe(prefer(peer, c.opts.LocalEndpoints))
	}
}

// prefer puts a candidate on one of our own subnets first.
//
// Two PCs in one shop are on one switch. Reaching each other across it is
// faster, does not depend on the router hairpinning traffic back to itself —
// which many consumer routers simply will not do — and does not consume one of
// the external mappings they are already fighting over (defect 23).
//
// The coordinator already sends LAN addresses ahead of the public one, and
// that is not enough: it does not know which of a peer's addresses is on the
// same network as us. Only we know that.
//
// Ordering only. Every candidate is still tried, because a peer that looks
// same-subnet may be a different site using the same private range, and that
// is the case that would otherwise never reach anybody.
func prefer(peer disco.PeerInfo, ours []netip.AddrPort) disco.PeerInfo {
	if len(peer.Candidates) < 2 || len(ours) == 0 {
		return peer
	}

	near := make([]netip.AddrPort, 0, len(peer.Candidates))
	far := make([]netip.AddrPort, 0, len(peer.Candidates))

	for _, candidate := range peer.Candidates {
		if sameSubnet(candidate.Addr(), ours) {
			near = append(near, candidate)
			continue
		}
		far = append(far, candidate)
	}

	peer.Candidates = append(near, far...)

	return peer
}

// sameSubnet reports whether an address looks like it is on one of ours.
//
// A /24 for IPv4 rather than the real prefix length, because the agent is not
// told its own mask here and /24 is what a home or shop network is. Getting it
// wrong costs an ordering preference, not a connection: every candidate is
// still tried.
func sameSubnet(addr netip.Addr, ours []netip.AddrPort) bool {
	if !addr.Is4() {
		return false
	}

	for _, mine := range ours {
		self := mine.Addr()
		if !self.Is4() {
			continue
		}

		a, b := addr.As4(), self.As4()
		if a[0] == b[0] && a[1] == b[1] && a[2] == b[2] {
			return true
		}
	}

	return false
}

// probe sends a punch to every candidate address for a peer.
//
// All candidates are tried at once rather than in sequence. The point is not
// to find the best one by measurement but to open a NAT mapping towards each,
// so that when the peer punches back — which it is doing at the same moment,
// having been told about us — at least one direction finds a hole already
// open. Serialising the attempts would mean the two ends rarely punch
// simultaneously, which is the only thing that makes it work.
func (c *Client) probe(peer disco.PeerInfo) {
	c.mu.Lock()
	if _, seen := c.firstSeen[peer.PublicKey]; !seen {
		c.firstSeen[peer.PublicKey] = time.Now()
	}
	c.candidates[peer.PublicKey] = peer.Candidates
	current := c.pathOf(peer.PublicKey)
	c.mu.Unlock()

	// A peer already reached directly is left alone: there is nothing to gain
	// and a working tunnel to disturb.
	if current == pathDirect {
		return
	}

	// A relayed peer is punched at again, here, because this message is the
	// one moment both ends are known to act together.
	//
	// Hole punching needs the two sides to punch at roughly the same instant;
	// that is the whole technique. Independent retry timers on each agent
	// almost never line up, so a relayed pair would stay relayed even after
	// the networks in front of them improved. The coordinator sends this
	// message to both ends at once — when a peer appears, and when it moves —
	// so acting on it is what makes the upgrade actually happen.

	pkt, err := c.punchPacket(disco.TypePunch)
	if err != nil {
		return
	}

	var tried []netip.AddrPort
	for _, candidate := range peer.Candidates {
		if !c.usableCandidate(candidate) {
			continue
		}
		if err := c.opts.Transport.SendTo(pkt, candidate); err != nil {
			c.opts.Logf("discovery: punch to %s failed: %v", candidate, err)

			continue
		}
		tried = append(tried, candidate)
	}

	// Logged because this is the line that matters when diagnosing a customer
	// site that will not connect: it shows exactly which addresses were tried
	// and what the path was at the time.
	if len(tried) > 0 {
		c.opts.Logf("discovery: punched %s… at %v (currently %s)",
			base64Key(peer.PublicKey)[:12], tried, current)
	}
}

// handlePunch answers a peer's probe, which both confirms the path and opens
// our side of it.
func (c *Client) handlePunch(header disco.Header, from netip.AddrPort) {
	if !c.known(header.Sender) {
		// An unsolicited punch from a key the coordinator never introduced is
		// either a scan or a peer we are not allowed to talk to. Either way it
		// gets no reply, which is also what stops this being an amplifier.
		return
	}

	pkt, err := c.punchPacket(disco.TypePunchAck)
	if err != nil {
		return
	}

	_ = c.opts.Transport.SendTo(pkt, from)

	// Receiving a punch is itself proof the path works in this direction, so
	// the endpoint is adopted without waiting for our own probe to be answered.
	c.adopt(header.Sender, from)
}

// handlePunchAck adopts the address that answered.
func (c *Client) handlePunchAck(header disco.Header, from netip.AddrPort) {
	if !c.known(header.Sender) {
		return
	}

	c.adopt(header.Sender, from)
}

// usableCandidate rejects addresses that cannot be a path to a peer.
//
// An address inside the overlay is the dangerous case: pointing WireGuard at
// one asks it to send a peer's encrypted traffic through the very tunnel that
// traffic is establishing. It is refused here rather than trusted not to be
// offered, because the offer can come from a peer or from a coordinator, and
// neither is something the data plane should take on faith.
func (c *Client) usableCandidate(at netip.AddrPort) bool {
	if !at.IsValid() || at.Port() == 0 {
		return false
	}

	if c.opts.Overlay.IsValid() && c.opts.Overlay.Contains(at.Addr()) {
		return false
	}

	return true
}

// adopt points WireGuard at an address that has demonstrably worked.
func (c *Client) adopt(peer [32]byte, at netip.AddrPort) {
	if !c.usableCandidate(at) {
		c.opts.Logf("discovery: refusing %s as a path to a peer; it is inside the overlay", at)
		return
	}

	c.mu.Lock()
	if current, ok := c.established[peer]; ok && current == at {
		c.mu.Unlock()
		return
	}
	wasRelayed := c.pathOf(peer) == pathRelay
	c.established[peer] = at
	c.paths[peer] = pathDirect
	c.mu.Unlock()

	key := base64Key(peer)
	if err := c.opts.Peers.SetPeerEndpoint(key, at.String()); err != nil {
		c.opts.Logf("discovery: pointing peer at %s failed: %v", at, err)
		return
	}

	if wasRelayed {
		// The silent upgrade: the WireGuard session is untouched, only its
		// destination changed, so nothing reconnects and no packet is lost.
		c.opts.Logf("discovery: upgraded peer %s… from relay to direct via %s", key[:12], at)

		return
	}

	c.opts.Logf("discovery: direct path to peer %s… via %s", key[:12], at)
}

func (c *Client) known(peer [32]byte) bool {
	c.mu.Lock()
	defer c.mu.Unlock()

	_, ok := c.candidates[peer]

	return ok
}

// punchPacket builds an unsealed probe.
//
// It is not sealed because it carries nothing: its only content is the
// sender's public key in the header, and its only purpose is to make a packet
// traverse the path. Sealing it would cost a key agreement per probe and
// protect nothing. What it cannot do is act on behalf of a peer, because
// adopting an endpoint requires that peer to have been introduced first.
func (c *Client) punchPacket(t disco.MessageType) ([]byte, error) {
	pkt := make([]byte, disco.HeaderLen)
	disco.WriteHeader(pkt, t, c.opts.SelfPublic)

	return pkt, nil
}
