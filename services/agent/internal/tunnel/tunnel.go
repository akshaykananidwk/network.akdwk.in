package tunnel

import (
	"fmt"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/acl"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/netmap"
	"net/netip"
	"strconv"
	"strings"
	"sync"

	"golang.zx2c4.com/wireguard/device"
	"golang.zx2c4.com/wireguard/tun"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/disconn"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/netcfg"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/wgkey"
)

// DefaultListenPort is the UDP port the agent binds by default. Zero would let
// the kernel choose, but a stable port makes a router's port forwarding and an
// admin's packet capture both possible.
const DefaultListenPort = 51820

// Tunnel is a running WireGuard interface.
type Tunnel struct {
	mu     sync.Mutex
	dev    *device.Device
	tunDev tun.Device
	bind   *disconn.Bind
	name   string
	port   int
	closed bool
	// filter sits between wireguard-go and the operating system and drops
	// packets the panel's ACL forbids, in both directions.
	filter *acl.Device
	mapper *netmap.Device
}

// Options configures Open.
type Options struct {
	// InterfaceName is the OS interface to create, e.g. "akc0" or "AKConnect".
	InterfaceName string
	// ListenPort is the UDP port to bind. Zero uses DefaultListenPort.
	ListenPort int
	// Verbose turns on wireguard-go's handshake logging.
	Verbose bool
	// Logf receives log lines. Required.
	Logf func(format string, args ...any)
	// OnFilterDrop is called for every packet the ACL refuses. Optional; the
	// caller owns any rate limiting, because a misconfigured rule can drop
	// thousands of packets a second and the log is the wrong place to discover
	// that.
	OnFilterDrop func(acl.Direction, acl.Verdict)
}

// Open creates the interface and starts the device, but configures no peers:
// call Apply for that. Splitting the two means a configuration that turns out
// to be unusable never leaves a half-built interface behind.
func Open(opts Options) (*Tunnel, error) {
	if opts.Logf == nil {
		return nil, fmt.Errorf("tunnel: a logger is required")
	}

	name := opts.InterfaceName
	if name == "" {
		name = defaultInterfaceName
	}

	port := opts.ListenPort
	if port == 0 {
		port = DefaultListenPort
	}

	// MTU is set properly by Apply once the plan is known; this is a floor that
	// lets the interface exist.
	tunDev, err := tun.CreateTUN(name, 1420)
	if err != nil {
		return nil, fmt.Errorf("creating the %s interface: %w (this needs root on Linux and Administrator on Windows)", name, err)
	}

	actualName, err := tunDev.Name()
	if err != nil {
		actualName = name
	}

	// Built by hand rather than via device.NewLogger so that both streams go
	// through the agent's own logger and inherit its destination and prefix.
	logger := &device.Logger{
		Verbosef: func(format string, args ...any) {
			if opts.Verbose {
				opts.Logf("wg: "+format, args...)
			}
		},
		Errorf: func(format string, args ...any) { opts.Logf("wg error: "+format, args...) },
	}

	// The bind is ours rather than wireguard-go's default, so discovery can
	// share this exact socket. Anything else would get its own NAT mapping and
	// teach peers an address that does not work.
	bind := disconn.New()

	// Two wrappers around the interface, and the order is the point.
	//
	// Address translation goes innermost, so everything above it — the ACL
	// filter, wireguard-go, the panel's rules, the other end of the tunnel —
	// works in one address space. Only the operating system below it and the
	// LAN beyond it see the customer's real range. Translating above the
	// filter would have the gateway judging packets by addresses no other
	// device in the network uses.
	mapper := netmap.Wrap(tunDev)

	// The ACL filter wraps that, so wireguard-go reads and writes through both
	// without knowing either is there. This is the only point on the machine
	// where packets are both plaintext and attributable to a peer.
	filter := acl.Wrap(mapper, opts.OnFilterDrop)

	dev := device.NewDevice(filter, bind, logger)

	return &Tunnel{
		dev:    dev,
		tunDev: tunDev,
		bind:   bind,
		name:   actualName,
		port:   port,
		filter: filter,
		mapper: mapper,
	}, nil
}

// SetMappings replaces the subnet mappings a gateway applies. Empty on every
// device that is not a subnet router.
func (t *Tunnel) SetMappings(table *netmap.Table) {
	if t.mapper != nil {
		t.mapper.SetTable(table)
	}
}

// Mapped is how many advertised LANs this device translates for.
func (t *Tunnel) Mapped() int {
	if t.mapper == nil {
		return 0
	}

	return t.mapper.Active()
}

// SetFilters replaces the ACL table. Safe while traffic is flowing, and it
// takes effect on the next packet rather than the next reconnection.
func (t *Tunnel) SetFilters(table *acl.Table) {
	if t.filter != nil {
		t.filter.SetTable(table)
	}
}

// FilterDrops is how many packets the ACL has refused since startup.
func (t *Tunnel) FilterDrops() uint64 {
	if t.filter == nil {
		return 0
	}

	return t.filter.Dropped()
}

// Name is the interface name the OS actually gave us.
func (t *Tunnel) Name() string { return t.name }

// Transport exposes the shared socket to the discovery client.
func (t *Tunnel) Transport() *disconn.Bind { return t.bind }

// SetPeerEndpointAddr is the PeerSetter shape discovery expects.
func (t *Tunnel) SetPeerEndpointAddr(publicKeyBase64 string, at netip.AddrPort) error {
	return t.SetPeerEndpoint(publicKeyBase64, at.String())
}

// ListenPort is the UDP port in use.
func (t *Tunnel) ListenPort() int { return t.port }

// Rebind reopens the UDP socket the tunnel and discovery share.
//
// This exists because of what a closed socket looked like in the field. The
// agent's log read
//
//	discovery: announcing to the coordinator failed: use of closed network connection
//
// every twenty seconds for as long as anyone watched. Something below us —
// wireguard-go's bind being closed on a network change, an adapter torn down
// and rebuilt — had left the socket dead, and nothing in the agent noticed:
// the announcement failed, the error was logged, and the next tick tried the
// same dead socket again. The device was reachable by nobody, showed no fault,
// and only a service restart fixed it.
//
// BindUpdate closes the current bind and opens a new one on the same port,
// re-attaching wireguard-go's receive routines to it. The discovery client
// holds the *Bind wrapper rather than the socket, so it needs no notification:
// its next send goes out of the new one.
func (t *Tunnel) Rebind() error {
	t.mu.Lock()
	defer t.mu.Unlock()

	if t.closed {
		return fmt.Errorf("tunnel is closed")
	}

	return t.dev.BindUpdate()
}

// Apply pushes a vetted configuration into the device and brings it up.
func (t *Tunnel) Apply(priv wgkey.Private, cfg *panel.Config, plan *netcfg.Plan) error {
	t.mu.Lock()
	defer t.mu.Unlock()

	if t.closed {
		return fmt.Errorf("tunnel is closed")
	}

	uapi, err := buildUAPI(priv, t.port, cfg, plan)
	if err != nil {
		return err
	}

	if err := t.dev.IpcSet(uapi); err != nil {
		return fmt.Errorf("configuring the WireGuard device: %w", err)
	}

	if err := t.dev.Up(); err != nil {
		return fmt.Errorf("bringing the WireGuard device up: %w", err)
	}

	return nil
}

// SetPeerEndpoint repoints one peer without disturbing the rest, which is what
// happens when NAT traversal finds a direct path.
func (t *Tunnel) SetPeerEndpoint(publicKey, endpoint string) error {
	t.mu.Lock()
	defer t.mu.Unlock()

	if t.closed {
		return fmt.Errorf("tunnel is closed")
	}

	uapi, err := buildEndpointUpdate(publicKey, endpoint)
	if err != nil {
		return err
	}

	return t.dev.IpcSet(uapi)
}

// PeerStatus is what the device reports back about one peer.
type PeerStatus struct {
	PublicKeyHex  string
	Endpoint      string
	LastHandshake int64
	RXBytes       int64
	TXBytes       int64
}

// Status reads the device's own view of its peers, which is the only
// authoritative answer to "is this tunnel actually carrying traffic".
func (t *Tunnel) Status() ([]PeerStatus, error) {
	t.mu.Lock()
	defer t.mu.Unlock()

	if t.closed {
		return nil, fmt.Errorf("tunnel is closed")
	}

	raw, err := t.dev.IpcGet()
	if err != nil {
		return nil, fmt.Errorf("reading device state: %w", err)
	}

	return parseStatus(raw), nil
}

// parseStatus reads the UAPI get format: flat key=value lines, where each
// public_key line starts a new peer.
func parseStatus(raw string) []PeerStatus {
	var out []PeerStatus
	var current *PeerStatus

	for _, line := range strings.Split(raw, "\n") {
		key, value, found := strings.Cut(strings.TrimSpace(line), "=")
		if !found {
			continue
		}

		switch key {
		case "public_key":
			if current != nil {
				out = append(out, *current)
			}
			current = &PeerStatus{PublicKeyHex: value}
		case "endpoint":
			if current != nil {
				current.Endpoint = value
			}
		case "last_handshake_time_sec":
			if current != nil {
				current.LastHandshake, _ = strconv.ParseInt(value, 10, 64)
			}
		case "rx_bytes":
			if current != nil {
				current.RXBytes, _ = strconv.ParseInt(value, 10, 64)
			}
		case "tx_bytes":
			if current != nil {
				current.TXBytes, _ = strconv.ParseInt(value, 10, 64)
			}
		}
	}

	if current != nil {
		out = append(out, *current)
	}

	return out
}

// Close tears the interface down. Safe to call more than once.
func (t *Tunnel) Close() error {
	t.mu.Lock()
	defer t.mu.Unlock()

	if t.closed {
		return nil
	}
	t.closed = true

	// Closing the device closes the TUN with it.
	t.dev.Close()

	return nil
}
