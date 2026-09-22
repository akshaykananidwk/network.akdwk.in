// Package fallback carries this agent's traffic over TCP 443 when UDP cannot
// leave the network.
//
// Some networks pass nothing but what a web browser uses. A hotel, a guest
// VLAN, a corporate firewall that allows outbound UDP and drops the replies —
// on those the entire UDP design is unavailable, coordinator included, so
// there is nobody left to ask for help. The agent therefore opens an ordinary
// TLS connection to port 443 and carries both its control messages and its
// relayed tunnel traffic over it.
//
// It is a fallback and it stays one. UDP is retried the whole time it is up,
// and the moment a peer can be reached the ordinary way the traffic moves
// back without the tunnel being torn down and without WireGuard noticing.
package fallback

import (
	"context"
	"fmt"
	"net/netip"
	"sync"
	"sync/atomic"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/disconn"
	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
	wire "github.com/akshaykananidwk/network.akdwk.in/services/shared/fallback"
)

// Router is the part of the shared bind this package drives.
//
// An interface rather than *disconn.Bind so the tests can drive it without a
// WireGuard device, and so it is obvious from here exactly how much of the
// socket this package touches.
type Router interface {
	UseTunnel(disconn.Tunnel)
	DivertControl(netip.AddrPort)
	RoutePeer(at netip.AddrPort, peer [32]byte)
	Inject(pkt []byte, from netip.AddrPort)
	InjectTunnel(pkt []byte, from netip.AddrPort)
}

// Options configures a Client.
type Options struct {
	// URL is where the fallback is served, e.g. wss://net.akdwk.in/fallback.
	URL string
	// SelfKey is this device's public key, announced so the relay can file
	// its sessions. Nothing is authorised by it — every bind still carries a
	// coordinator-signed ticket.
	SelfKey [32]byte
	// Coordinator is the address whose control traffic is diverted.
	Coordinator netip.AddrPort
	// Router is the shared socket.
	Router Router
	Logf   func(string, ...any)
}

// Client is one agent's fallback connection.
type Client struct {
	opts Options

	up atomic.Bool
	// everUp records that the last attempt got as far as a working
	// connection, so the retry after it starts from the beginning rather than
	// from however long a previous unreachable stretch had grown the wait to.
	everUp   atomic.Bool
	observed atomic.Pointer[string]
	lastData atomic.Int64
	since    atomic.Int64

	mu sync.Mutex
	// relayFor is the control address of the relay each peer was offered, kept
	// so a bind acknowledgement can be handed back to discovery attributed to
	// the address it is expecting an answer from.
	relayFor map[[32]byte]netip.AddrPort
	// pseudo is the endpoint each relayed peer is addressed by while the
	// fallback carries it.
	pseudo     map[[32]byte]netip.AddrPort
	nextPseudo uint16

	// conn is the live connection, nil while reconnecting. Writes go through
	// send() so a caller never touches it directly.
	conn wsConn

	// dialer replaces the real websocket dial. Only a test sets it; the
	// alternative is a test that proves a websocket library works rather than
	// that this agent reconnects, re-binds and re-routes when a connection
	// drops, which is the part that has to be right.
	dialer func(context.Context) (wsConn, error)
}

// New builds a Client.
func New(opts Options) (*Client, error) {
	if opts.URL == "" {
		return nil, fmt.Errorf("no fallback URL is configured")
	}
	if opts.Router == nil {
		return nil, fmt.Errorf("the fallback needs the shared socket")
	}
	if opts.Logf == nil {
		opts.Logf = func(string, ...any) {}
	}

	return &Client{
		opts:       opts,
		relayFor:   make(map[[32]byte]netip.AddrPort),
		pseudo:     make(map[[32]byte]netip.AddrPort),
		nextPseudo: firstPseudoPort,
	}, nil
}

// Up reports whether the fallback is currently connected.
func (c *Client) Up() bool { return c.up.Load() }

// Observed is how the relay sees this device's address, empty until connected.
func (c *Client) Observed() string {
	if s := c.observed.Load(); s != nil {
		return *s
	}

	return ""
}

// Idle is how long since the fallback carried any tunnel traffic. It is the
// measure the supervisor uses to decide the path is no longer needed.
func (c *Client) Idle() time.Duration {
	last := c.lastData.Load()
	if last == 0 {
		return time.Since(time.Unix(c.since.Load(), 0))
	}

	return time.Since(time.Unix(last, 0))
}

// SendControl carries one discovery packet. It satisfies disconn.Tunnel.
func (c *Client) SendControl(pkt []byte, to netip.AddrPort) error {
	header, rest, err := disco.ParseHeader(pkt)
	if err != nil {
		return err
	}

	if header.Type != disco.TypeRelayBind {
		return c.send(wire.KindControl, pkt)
	}

	// A bind names the pair it is for, which is how the acknowledgement that
	// comes back can be attributed to the right peer and to the relay address
	// discovery is waiting to hear from.
	if ticket, err := disco.DecodeTicket(rest); err == nil {
		c.mu.Lock()
		c.relayFor[ticket.Peer] = to
		c.mu.Unlock()
	}

	return c.send(wire.KindBind, pkt)
}

// SendTunnel carries one WireGuard packet. It satisfies disconn.Tunnel.
func (c *Client) SendTunnel(peer [32]byte, pkt []byte) error {
	c.lastData.Store(time.Now().Unix())

	msg, err := wire.EncodeData(peer, pkt)
	if err != nil {
		return err
	}

	return c.write(msg)
}

// firstPseudoPort starts the range of ports used to name a relayed peer while
// the fallback carries it.
//
// Above the ephemeral range every operating system we run on hands out, so a
// pseudo endpoint cannot collide with a port a relay might really allocate.
// A collision would not break anything — the traffic would take the fallback
// instead of UDP, which is where it already is — but a range nothing else
// uses makes the endpoints recognisable in a packet capture.
const firstPseudoPort uint16 = 65000

// pseudoFor names a peer's fallback endpoint, allocating one on first use.
//
// The address is the relay's own, so that the acknowledgement discovery
// receives matches the relay it asked; only the port is invented.
func (c *Client) pseudoFor(peer [32]byte, relay netip.AddrPort) (netip.AddrPort, bool) {
	c.mu.Lock()
	defer c.mu.Unlock()

	if existing, ok := c.pseudo[peer]; ok {
		return existing, false
	}

	if c.nextPseudo == 0 {
		return netip.AddrPort{}, false
	}

	at := netip.AddrPortFrom(relay.Addr(), c.nextPseudo)
	c.nextPseudo++
	c.pseudo[peer] = at

	return at, true
}

// Endpoint is the address a peer is reached by while the fallback carries it.
//
// The caller compares it against the endpoint WireGuard is actually using,
// which is the only exact way to say whether this peer's traffic is on the
// HTTPS path right now: the agent hands the endpoint out here and discovery
// replaces it the moment UDP works, without telling anybody.
func (c *Client) Endpoint(peer [32]byte) (netip.AddrPort, bool) { return c.endpointOf(peer) }

// endpointOf returns a peer's fallback endpoint, if it has one.
func (c *Client) endpointOf(peer [32]byte) (netip.AddrPort, bool) {
	c.mu.Lock()
	defer c.mu.Unlock()

	at, ok := c.pseudo[peer]

	return at, ok
}

// relayOf returns the relay address a peer's bind went to.
func (c *Client) relayOf(peer [32]byte) (netip.AddrPort, bool) {
	c.mu.Lock()
	defer c.mu.Unlock()

	at, ok := c.relayFor[peer]

	return at, ok
}
