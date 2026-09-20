// Package discovery finds a direct path to each peer.
//
// The order of preference is R2's: a peer on the same LAN is reached across
// the switch, a peer elsewhere through whatever address NAT gives it, and
// only if neither works does anything else get considered. Nothing here
// touches a relay — that is Phase 3 — so a peer that cannot be reached
// directly simply stays unreachable, visibly, rather than silently degrading.
package discovery

import (
	"context"
	"encoding/base64"
	"fmt"
	"net/netip"
	"sync"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/disconn"
	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
)

// Transport is the socket discovery shares with WireGuard.
//
// SetHandler takes disconn.Handler rather than a bare func type so that the
// bind satisfies this interface directly, without an adapter whose only job
// would be to change a name.
type Transport interface {
	SendTo(pkt []byte, to netip.AddrPort) error
	SetHandler(disconn.Handler)
	Port() uint16
}

// PeerSetter applies a discovered path to the WireGuard device.
type PeerSetter interface {
	SetPeerEndpoint(publicKeyBase64, endpoint string) error
}

// Options configures a Client.
type Options struct {
	SelfPublic  [32]byte
	SelfPrivate [32]byte
	Coordinator netip.AddrPort
	CoordKey    [32]byte
	DeviceUID   string
	// Token is the device token, sealed into every announcement.
	Token string
	// LocalEndpoints are this machine's own addresses, offered to peers on the
	// same network so they can skip the public path entirely.
	LocalEndpoints []netip.AddrPort
	// Overlay is the tunnel's own prefix. No endpoint inside it is ever usable
	// as a path to a peer, whoever offers it.
	Overlay   netip.Prefix
	Transport Transport
	Peers     PeerSetter
	Logf      func(string, ...any)
}

// Client keeps this agent announced and its peers reachable.
type Client struct {
	opts Options

	mu        sync.Mutex
	reflexive netip.AddrPort
	// lastAck is when the coordinator last answered. It decides whether the
	// next announcement is a full Hello or a cheap Ping.
	lastAck time.Time
	// candidates is every address currently being tried for a peer, so a punch
	// reply can be matched back to the peer it proves.
	candidates map[[32]byte][]netip.AddrPort
	// established records the address that answered, so a later duplicate
	// reply does not churn the WireGuard configuration.
	established map[[32]byte]netip.AddrPort
}

// New builds a Client.
func New(opts Options) (*Client, error) {
	if opts.Logf == nil {
		return nil, fmt.Errorf("a logger is required")
	}
	if opts.Transport == nil || opts.Peers == nil {
		return nil, fmt.Errorf("a transport and a peer setter are required")
	}
	if !opts.Coordinator.IsValid() {
		return nil, fmt.Errorf("no coordinator address")
	}

	return &Client{
		opts:        opts,
		candidates:  make(map[[32]byte][]netip.AddrPort),
		established: make(map[[32]byte]netip.AddrPort),
	}, nil
}

// Run announces this agent and keeps doing so until the context ends.
//
// The interval is short enough to hold a NAT mapping open: a typical UDP
// mapping expires after 30 seconds of silence, and a mapping that has expired
// is a peer that can no longer reach us.
func (c *Client) Run(ctx context.Context) {
	c.opts.Transport.SetHandler(c.handle)

	const keepalive = 20 * time.Second

	c.announce(c.needsHello(keepalive))

	ticker := time.NewTicker(keepalive)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			c.announce(c.needsHello(keepalive))
		}
	}
}

// needsHello decides between a full announcement and a keepalive ping.
//
// A Ping only refreshes an entry the coordinator already has. If it has none —
// because the first Hello was lost, because the panel was briefly down when it
// arrived, or because the coordinator has restarted — pinging forever would
// leave the agent permanently undiscovered while looking healthy. So the
// agent re-introduces itself whenever it has not been acknowledged recently.
func (c *Client) needsHello(keepalive time.Duration) bool {
	c.mu.Lock()
	defer c.mu.Unlock()

	return c.lastAck.IsZero() || time.Since(c.lastAck) > 2*keepalive
}

// announce sends a Hello (first time, and periodically) or a lighter Ping.
func (c *Client) announce(full bool) {
	msgType := disco.TypePing
	var body []byte
	var err error

	if full {
		hello := &disco.Hello{
			DeviceUID:      c.opts.DeviceUID,
			Token:          c.opts.Token,
			LocalEndpoints: c.opts.LocalEndpoints,
		}
		body, err = hello.Encode()
		msgType = disco.TypeHello
	} else {
		// A ping carries nothing; it exists to refresh the mapping and the
		// coordinator's presence entry. It is still sealed, so it still proves
		// who sent it.
		body = []byte{}
	}

	if err != nil {
		c.opts.Logf("discovery: encoding announcement failed: %v", err)
		return
	}

	pkt, err := c.seal(msgType, c.opts.CoordKey, body)
	if err != nil {
		c.opts.Logf("discovery: sealing announcement failed: %v", err)
		return
	}

	if err := c.opts.Transport.SendTo(pkt, c.opts.Coordinator); err != nil {
		c.opts.Logf("discovery: announcing to the coordinator failed: %v", err)
	}
}

// Reflexive is this agent's public address as the coordinator sees it, or the
// zero value if it has not been told yet.
func (c *Client) Reflexive() netip.AddrPort {
	c.mu.Lock()
	defer c.mu.Unlock()

	return c.reflexive
}

func (c *Client) seal(t disco.MessageType, recipient [32]byte, body []byte) ([]byte, error) {
	sealed, err := disco.Seal(body, &recipient, &c.opts.SelfPrivate)
	if err != nil {
		return nil, err
	}

	pkt := make([]byte, disco.HeaderLen, disco.HeaderLen+len(sealed))
	disco.WriteHeader(pkt, t, c.opts.SelfPublic)

	return append(pkt, sealed...), nil
}

func base64Key(k [32]byte) string {
	return base64.StdEncoding.EncodeToString(k[:])
}
