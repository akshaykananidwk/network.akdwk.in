package disconn

import (
	"net"
	"net/netip"
	"time"

	"golang.zx2c4.com/wireguard/conn"

	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
)

// The HTTPS fallback, from the socket's point of view.
//
// A device on a network that passes nothing but TCP 443 still has to look
// ordinary to everything above this file: WireGuard sends to an endpoint and
// receives from one, discovery sends to the coordinator and hears back. What
// changes is only which door those packets leave by, and that is decided here.
//
// The endpoints a relayed peer is given while the fallback carries it are not
// real addresses. They are loopback addresses in a range nothing routes,
// handed out so wireguard-go has something to name the peer by; Send
// recognises them and diverts. A packet addressed to one can never reach a
// socket, which is the property that makes them safe to invent.

// injectQueue is how many inbound fallback packets may wait for wireguard-go.
//
// Deep enough to absorb a burst of a handshake plus the traffic behind it,
// shallow enough that a stalled device drops packets — which is what a
// datagram path is supposed to do — rather than growing without limit.
const injectQueue = 128

// injected is one packet that arrived over the fallback.
type injected struct {
	data []byte
	ep   conn.Endpoint
}

// Tunnel is the fallback connection a bind may divert traffic through.
type Tunnel interface {
	// SendControl carries a discovery packet addressed to a coordinator or a
	// relay's control port.
	SendControl(pkt []byte, to netip.AddrPort) error
	// SendTunnel carries one WireGuard packet to a peer.
	SendTunnel(peer [32]byte, pkt []byte) error
}

// routing is the diversion table, replaced whole on every change.
type routing struct {
	tunnel  Tunnel
	control map[netip.AddrPort]bool
	peers   map[netip.AddrPort][32]byte
}

// diverts decides whether one discovery packet should also go through the
// fallback.
//
// Two things must: anything for the coordinator, which is registered by
// address, and a relay bind, which is addressed to a relay this table has
// never heard of — the coordinator names it in an offer the agent alone can
// read. Deciding a bind by its type rather than its destination is what keeps
// that private.
//
// A hole punch is deliberately not diverted. It is addressed to a peer's own
// NAT mapping, which no relay can reach and no proxy can help with, and
// sending it anyway would only put a packet on the wire that must be dropped
// at the other end.
func (r *routing) diverts(pkt []byte, to netip.AddrPort) bool {
	if r == nil || r.tunnel == nil {
		return false
	}

	if r.control[normalise(to)] {
		return true
	}

	header, _, err := disco.ParseHeader(pkt)

	return err == nil && header.Type == disco.TypeRelayBind
}

func (r *routing) sendTunnel(peer [32]byte, bufs [][]byte) error {
	for _, buf := range bufs {
		if err := r.tunnel.SendTunnel(peer, buf); err != nil {
			return err
		}
	}

	return nil
}

// clone copies the table so a change never mutates one a sender is reading.
func (r *routing) clone(t Tunnel) *routing {
	next := &routing{
		tunnel:  t,
		control: make(map[netip.AddrPort]bool, len(r.control)+1),
		peers:   make(map[netip.AddrPort][32]byte, len(r.peers)+1),
	}

	for addr := range r.control {
		next.control[addr] = true
	}
	for addr, peer := range r.peers {
		next.peers[addr] = peer
	}

	return next
}

// current returns the table to build the next one from.
func (b *Bind) current() *routing {
	if route := b.route.Load(); route != nil {
		return route
	}

	return &routing{}
}

// UseTunnel attaches or, with nil, detaches the fallback.
//
// Detaching clears the peer routes with it: their endpoints named a connection
// that no longer exists, and leaving them behind would divert traffic into a
// tunnel that cannot carry it. The control addresses stay, because they are
// real addresses that UDP can still reach.
func (b *Bind) UseTunnel(t Tunnel) {
	next := b.current().clone(t)

	if t == nil {
		next.peers = map[netip.AddrPort][32]byte{}
	}

	b.route.Store(next)
}

// DivertControl marks an address whose discovery traffic should also go
// through the fallback — the coordinator, and every relay this device is
// offered.
func (b *Bind) DivertControl(to netip.AddrPort) {
	to = normalise(to)

	if route := b.route.Load(); route != nil && route.control[to] {
		return
	}

	next := b.current().clone(b.current().tunnel)
	next.control[to] = true
	b.route.Store(next)
}

// normalise puts an address in the one form these tables are keyed by.
//
// An IPv4 address can be held either plainly or mapped into IPv6, and the two
// are different map keys while being the same address. Everything read back
// out of a wireguard-go endpoint is unmapped, so everything put in has to be
// too — otherwise a lookup misses, the packet is not diverted, and it is sent
// for real to an address that exists nowhere. A black hole with no error.
func normalise(at netip.AddrPort) netip.AddrPort {
	return netip.AddrPortFrom(at.Addr().Unmap(), at.Port())
}

// RoutePeer sends everything addressed to at through the fallback, as traffic
// for peer.
//
// One entry per peer: a peer given a new pseudo endpoint has left the old one
// behind, and an entry nothing addresses any more would still divert traffic
// if that address were ever handed out again.
func (b *Bind) RoutePeer(at netip.AddrPort, peer [32]byte) {
	at = normalise(at)

	next := b.current().clone(b.current().tunnel)

	for addr, existing := range next.peers {
		if existing == peer && addr != at {
			delete(next.peers, addr)
		}
	}

	next.peers[at] = peer
	b.route.Store(next)
}

// ForgetPeer stops diverting an endpoint, which is what happens when a peer's
// path is upgraded back to UDP.
func (b *Bind) ForgetPeer(at netip.AddrPort) {
	if route := b.route.Load(); route == nil || len(route.peers) == 0 {
		return
	}

	next := b.current().clone(b.current().tunnel)
	delete(next.peers, normalise(at))
	b.route.Store(next)
}

// UDPAlive reports whether a discovery packet has arrived on the real socket
// within the given time.
//
// The one honest answer to "has UDP started working again". A device on the
// fallback is being answered by the coordinator the whole time, so every other
// measure of health says yes while the underlying network still carries
// nothing.
func (b *Bind) UDPAlive(within time.Duration) bool {
	last := b.lastUDP.Load()

	return last != 0 && time.Since(time.Unix(last, 0)) <= within
}

// UDPAliveSince reports whether a real UDP packet has arrived since a moment.
//
// The freshness window UDPAlive allows is forty-five seconds, and it has to
// be: the keepalive that produces those packets is twenty seconds and one lost
// datagram must not read as the network failing. But a device that was working
// on UDP a moment ago and has just been carried onto a network that drops it
// has a stamp that is both fresh and worthless — it is evidence about the
// network it left.
//
// So once the fallback is carrying, the question stops being "how old is the
// stamp" and becomes "is there a stamp from after we gave up on UDP". Nothing
// before that moment says anything about now.
func (b *Bind) UDPAliveSince(t time.Time) bool {
	last := b.lastUDP.Load()

	return last != 0 && time.Unix(last, 0).After(t)
}

// TunnelActive reports whether the fallback is currently attached.
func (b *Bind) TunnelActive() bool {
	route := b.route.Load()

	return route != nil && route.tunnel != nil
}

// Inject hands a discovery packet that arrived over the fallback to the
// discovery handler, exactly as if it had arrived on the socket from the
// address given.
func (b *Bind) Inject(pkt []byte, from netip.AddrPort) { b.dispatch(pkt, from) }

// InjectTunnel hands a WireGuard packet that arrived over the fallback to
// wireguard-go, attributed to the pseudo-endpoint the peer is routed by.
//
// Attributing it to that endpoint rather than to anything real is what stops
// wireguard-go's roaming logic moving the peer: it sees traffic arriving from
// the address it is already sending to, and leaves the configuration alone.
func (b *Bind) InjectTunnel(pkt []byte, from netip.AddrPort) {
	ep, err := b.inner.ParseEndpoint(from.String())
	if err != nil {
		return
	}

	owned := make([]byte, len(pkt))
	copy(owned, pkt)

	select {
	case b.injected <- injected{data: owned, ep: ep}:
	default:
		// The queue is full, so the device is not keeping up. Dropping is what
		// a socket would do.
	}
}

// receiveInjected is the receive function wireguard-go reads the fallback
// through.
func (b *Bind) receiveInjected(packets [][]byte, sizes []int, eps []conn.Endpoint) (int, error) {
	b.mu.RLock()
	closed := b.closed
	b.mu.RUnlock()

	if closed == nil {
		return 0, net.ErrClosed
	}

	select {
	case <-closed:
		return 0, net.ErrClosed
	case p := <-b.injected:
		if len(packets) == 0 || len(p.data) > len(packets[0]) {
			return 0, nil
		}

		sizes[0] = copy(packets[0], p.data)
		eps[0] = p.ep

		return 1, nil
	}
}
