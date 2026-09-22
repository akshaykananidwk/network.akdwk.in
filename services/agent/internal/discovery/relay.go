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
//
// avoid names a relay this agent was given and could not use; empty on a
// first request. Naming it is the whole of failover from the agent's side: the
// coordinator cannot see that a relay stopped forwarding, and only the agents
// on it can.
func (c *Client) requestRelay(peer [32]byte, avoid string) {
	request := &disco.RelayRequest{Peer: peer, Avoid: avoid}

	encoded, err := request.Encode()
	if err != nil {
		return
	}

	pkt, err := c.seal(disco.TypeRelayRequest, c.opts.CoordKey, encoded)
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

	control, ok := c.relayEndpoint(offer)
	if !ok {
		return
	}

	c.mu.Lock()
	c.relayControl[offer.Peer] = control
	c.relayTicket[offer.Peer] = offer.Ticket
	c.relayName[offer.Peer] = offer.Name
	// A fresh offer starts the liveness count over. Without this a peer moved
	// to a new relay would inherit the dead one's missed acknowledgements and
	// be failed over again immediately.
	c.relayMissed[offer.Peer] = 0
	c.mu.Unlock()

	c.sendRelayBind(offer.Peer, control, offer.Ticket)
}

// relayEndpoint works out where to send the bind.
//
// The offer names the relay and gives its endpoint as a string, and that
// string is whatever the coordinator was configured with. On a real deployment
// that is a hostname — DEPLOY.md tells the operator to use the VPS's public
// hostname, and AKCONNECT_RELAYS carries it verbatim — so parsing it as a
// literal address fails for every offer ever made. That is exactly what
// happened in the field: the coordinator logged that it had offered the relay
// to both devices, and a twenty-second capture on the relay's own ports saw
// not one packet from either of them. Every lab drill passed because the lab
// configures its relays by IP.
//
// The fleet the panel published is tried first, by name, because those
// addresses were resolved at start-up and are already known good. Resolution
// is the fallback, and it is deliberately not done on this goroutine: this
// runs in the packet handler, and a DNS lookup that takes two seconds would
// stall every other message for two seconds.
func (c *Client) relayEndpoint(offer *disco.RelayOffer) (netip.AddrPort, bool) {
	// A literal address is the easy case, and the one the lab exercises.
	if addr, err := netip.ParseAddrPort(offer.Endpoint); err == nil {
		return addr, true
	}

	if offer.Name != "" {
		c.mu.Lock()
		for _, relay := range c.relays {
			if relay.Name == offer.Name && relay.Addr.IsValid() {
				addr := relay.Addr
				c.mu.Unlock()

				return addr, true
			}
		}
		c.mu.Unlock()
	}

	// Neither a literal address nor a relay this device has measured. Resolve
	// it, off this goroutine, and bind when the answer comes back.
	go c.bindAfterResolving(offer)

	return netip.AddrPort{}, false
}

// bindAfterResolving looks a relay's hostname up and then binds to it.
//
// Separate goroutine because DNS blocks. One attempt: the coordinator re-offers
// a relay whenever an agent asks again, which it does every second while a
// peer is stalled, so a name that resolves a moment later is picked up without
// a retry loop here.
func (c *Client) bindAfterResolving(offer *disco.RelayOffer) {
	addr, err := resolveHostPort(offer.Endpoint)
	if err != nil {
		c.opts.Logf("discovery: relay %s at %q cannot be reached: %v", offer.Name, offer.Endpoint, err)

		return
	}

	c.mu.Lock()
	c.relayControl[offer.Peer] = addr
	c.relayTicket[offer.Peer] = offer.Ticket
	c.relayName[offer.Peer] = offer.Name
	c.relayMissed[offer.Peer] = 0
	// Remembered under its name, so the next offer for this relay takes the
	// fast path above instead of resolving again.
	c.rememberRelayAddrLocked(offer.Name, addr)
	c.mu.Unlock()

	c.opts.Logf("discovery: relay %s resolved to %s", offer.Name, addr)

	c.sendRelayBind(offer.Peer, addr, offer.Ticket)
}

// rememberRelayAddrLocked adds a resolved relay to the fleet this device
// knows. Caller holds c.mu.
func (c *Client) rememberRelayAddrLocked(name string, addr netip.AddrPort) {
	if name == "" {
		return
	}

	for i, relay := range c.relays {
		if relay.Name == name {
			c.relays[i].Addr = addr

			return
		}
	}

	c.relays = append(c.relays, RelayTarget{Name: name, Addr: addr})
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

	// The relay answered, so it is alive. This is the only thing that clears
	// the miss counter, which is what makes the counter mean "consecutive
	// unanswered rebinds" rather than "rebinds since the last reset".
	c.mu.Lock()
	c.relayMissed[peer] = 0
	c.mu.Unlock()

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

// relayRebindInterval is how often a relayed peer's ticket is re-presented.
//
// It does two jobs: it keeps the NAT mapping between this agent and the relay
// alive, and — because every bind is acknowledged — it is the heartbeat that
// tells the agent the relay is still there.
const relayRebindInterval = 5 * time.Second

// relayMissesBeforeFailover is how many unanswered rebinds mean the relay is
// gone.
//
// Three, so a single dropped packet on a lossy link does not move a working
// session, and so the decision takes about fifteen seconds rather than the
// twenty-plus it took when rebinds rode on the coordinator keepalive.
const relayMissesBeforeFailover = 3

// relayFailoverNeedsCoordinator is how long the coordinator may have been
// silent before relay failover stands down.
//
// Asking for another relay is a UDP packet to the coordinator, and the answer
// comes back the same way. On a network that has stopped carrying UDP, that
// request cannot be sent usefully and cannot be answered — so a relay that
// "stopped answering" because the network died takes the agent through a
// relay change that can only fail, before anything concludes the network is
// the problem. It cost eighteen seconds of a thirty-second budget in the lab,
// on the one scenario written for a laptop carried from a working network
// onto an office that drops UDP.
//
// Four seconds, matching the silence the fallback supervisor acts on, so the
// two agree about when UDP has stopped working rather than each deciding
// separately.
const relayFailoverNeedsCoordinator = 4 * time.Second

// relayFailoverNeedsAnswers is how many announcements may be outstanding
// before relay failover stands down, for the same reason.
const relayFailoverNeedsAnswers = 2

// relayMissesBeforeAsking is how many unanswered rebinds prompt an early
// announcement to the coordinator.
//
// A settled agent talks to the coordinator once every twenty seconds, so when
// the network dies under it nothing notices until the next keepalive is due —
// which is most of the time it then takes to reach the fallback. But relay
// rebinds go out every five seconds and are acknowledged, so on a relayed pair
// the silence is visible three times sooner. Two misses is ten seconds and one
// lost packet is not enough.
//
// This asks a question rather than deciding anything: it makes the
// announcement go out now, so the count of unanswered ones starts growing when
// the evidence appeared instead of when the clock came round. Everything that
// reads that count — the fallback supervisor, the stand-down above — is
// unchanged. On a network that is working the rebind is answered and this
// never fires.
const relayMissesBeforeAsking = 2

// rebindRelays re-presents tickets and notices when a relay stops answering.
func (c *Client) rebindRelays() {
	c.mu.Lock()

	type binding struct {
		peer    [32]byte
		control netip.AddrPort
		ticket  []byte
		name    string
		dead    bool
	}

	var bindings []binding
	askCoordinator := false
	for peer, control := range c.relayControl {
		// Any peer with a relay assigned, not only one whose relay has already
		// worked.
		//
		// Keying this on paths[peer] == pathRelay was a real defect: the path
		// only becomes "relay" when a bind is acknowledged, so a relay that was
		// already dead when the coordinator offered it was never rebound,
		// never counted as missing, and never failed over. The pair sat in
		// "connecting" indefinitely. One dead relay stranded every new pair it
		// was handed to, which is worse than the case this was written for.
		if c.paths[peer] == pathDirect {
			continue
		}
		// A zero address means a relay was asked for and none has been offered
		// yet. That is the coordinator's silence, not a relay's, and there is
		// nothing to bind to.
		if !control.IsValid() {
			continue
		}

		// Count this rebind as missed up front. handleRelayBindAck clears it
		// when the relay answers, so the counter only grows while nothing
		// comes back.
		c.relayMissed[peer]++
		dead := c.relayMissed[peer] > relayMissesBeforeFailover

		// But a relay that went quiet at the same moment the coordinator did
		// is not a relay fault. A device that cannot reach one relay over UDP
		// cannot reach another one either, and the request for another relay
		// is itself a UDP packet to a coordinator that is not answering. Keep
		// rebinding — it costs one packet every five seconds and it is how the
		// relay is found again the moment UDP comes back — and let the
		// fallback take it.
		if dead && c.udpLooksDeadLocked() {
			c.relayMissed[peer] = relayMissesBeforeFailover
			dead = false
		}

		// A relay that has gone quiet is a reason to talk to the coordinator
		// now rather than at the next keepalive, whether the answer turns out
		// to be "that relay is gone" or "so has the network".
		if c.relayMissed[peer] >= relayMissesBeforeAsking {
			askCoordinator = true
		}

		bindings = append(bindings, binding{
			peer:    peer,
			control: control,
			ticket:  c.relayTicket[peer],
			name:    c.relayName[peer],
			dead:    dead,
		})

		if dead {
			// Reset so the next relay gets a full count of its own rather than
			// inheriting this one's, and so a coordinator that is also down
			// does not produce a request every five seconds.
			c.relayMissed[peer] = 0
		}
	}
	c.mu.Unlock()

	// One announcement for the whole tick, however many peers are stalled.
	if askCoordinator {
		c.announce(false)
	}

	for _, b := range bindings {
		if b.dead {
			c.opts.Logf("discovery: relay %s stopped answering for %x…; asking for another",
				orUnnamed(b.name), b.peer[:6])
			c.requestRelay(b.peer, b.name)

			continue
		}

		c.sendRelayBind(b.peer, b.control, b.ticket)
	}
}

// udpLooksDeadLocked reports whether the coordinator has stopped answering.
//
// Called with c.mu held, from the rebind loop.
//
// Two shapes, and both mean the same thing here. Announcements that leave and
// are never answered is a network dropping the return path; announcements the
// socket refuses is a local firewall blocking this program, where no count of
// unanswered ones ever grows because nothing left the machine. Relay failover
// is useless in either.
func (c *Client) udpLooksDeadLocked() bool {
	if c.sendFailures > 0 {
		return true
	}

	if c.unacked < relayFailoverNeedsAnswers {
		return false
	}

	from := c.lastAck
	if from.IsZero() {
		from = c.firstSend
	}
	if from.IsZero() {
		return false
	}

	return time.Since(from) >= relayFailoverNeedsCoordinator
}

func orUnnamed(name string) string {
	if name == "" {
		return "(unnamed)"
	}

	return name
}

func min(a, b int) int {
	if a < b {
		return a
	}

	return b
}

// RelayName is the fleet name of the relay a peer is currently reached
// through, or empty when the path is direct.
//
// Surfaced because "this device is relayed" is not actionable on its own: an
// operator looking at a slow site needs to know which relay it is on before
// they can do anything about it.
func (c *Client) RelayName(peer [32]byte) string {
	c.mu.Lock()
	defer c.mu.Unlock()

	if c.paths[peer] != pathRelay {
		return ""
	}

	return c.relayName[peer]
}
