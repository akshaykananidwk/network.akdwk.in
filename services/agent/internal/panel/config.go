package panel

import (
	"context"
	"fmt"
	"net/http"
)

// Config is the payload DeviceService::buildAgentConfig() produces. The field
// names mirror it exactly; where the panel can send null, the Go type is a
// pointer or a slice so that "absent" and "empty" stay distinguishable.
type Config struct {
	Changed  *bool `json:"changed"`
	Revision int   `json:"revision"`

	Device struct {
		UID       string `json:"uid"`
		Name      string `json:"name"`
		VirtualIP string `json:"virtual_ip"`
		Status    string `json:"status"`
		// IsGateway says this device routes for machines that cannot run an
		// agent — an NVR, a printer, a DVR.
		IsGateway bool `json:"is_gateway"`
		// Advertises are the LAN prefixes it routes for, as the panel
		// approved them. Empty unless IsGateway.
		Advertises []string `json:"advertises"`
	} `json:"device"`

	Network struct {
		UID          string `json:"uid"`
		Name         string `json:"name"`
		CIDR         string `json:"cidr"`
		MTU          int    `json:"mtu"`
		Keepalive    int    `json:"keepalive"`
		SearchDomain string `json:"search_domain"`
	} `json:"network"`

	DNS struct {
		Servers      []string `json:"servers"`
		SearchDomain string   `json:"search_domain"`
		SplitOnly    bool     `json:"split_only"`
	} `json:"dns"`

	Peers  []Peer  `json:"peers"`
	Routes []Route `json:"routes"`
	Relays []Relay `json:"relays"`

	Coordinator struct {
		Host string `json:"host"`
		Port int    `json:"port"`
		// PublicKey is what the agent seals its announcements to. Without it
		// the agent does not announce at all, rather than sending its device
		// token across the internet in the clear.
		PublicKey string `json:"public_key"`
	} `json:"coordinator"`

	Policy struct {
		SplitTunnelOnly   bool   `json:"split_tunnel_only"`
		AllowDefaultRoute bool   `json:"allow_default_route"`
		ACLDefaultAction  string `json:"acl_default_action"`
	} `json:"policy"`

	IssuedAt string `json:"issued_at"`
}

// Peer is one device this device may talk to.
type Peer struct {
	UID         string   `json:"uid"`
	Name        string   `json:"name"`
	PublicKey   string   `json:"public_key"`
	VirtualIP   string   `json:"virtual_ip"`
	Endpoint    string   `json:"endpoint"`
	LANEndpoint string   `json:"lan_endpoint"`
	AllowedIPs  []string `json:"allowed_ips"`
	IsGateway   bool     `json:"is_gateway"`
	LastSeenAt  string   `json:"last_seen_at"`
	// Filters are the port and protocol rules the agent applies above the peer
	// link. The panel has already decided *whether* these two devices may
	// talk; these decide which packets between them may pass.
	Filters []Filter `json:"filters"`
	// Routes are the prefixes *this* device is the gateway for, each with the
	// rules governing what this peer may reach inside them. Present only on a
	// gateway's own configuration, and only there because it is the device
	// that forwards the traffic.
	Routes []PeerRoute `json:"routes"`
}

// PeerRoute is what one peer may reach inside one LAN we route for.
type PeerRoute struct {
	Destination string   `json:"destination"`
	Filters     []Filter `json:"filters"`
}

// Filter is one compiled ACL rule, as AclService::toFilter() emits it.
//
// PortFrom and PortTo are pointers because the panel sends null for a rule
// that is about a protocol and not about any port, and "no port" has to stay
// distinguishable from "port 0".
type Filter struct {
	Action   string `json:"action"`
	Protocol string `json:"protocol"`
	PortFrom *int   `json:"port_from"`
	PortTo   *int   `json:"port_to"`
	RuleID   int    `json:"rule_id"`
}

// Route is a subnet reachable through a gateway device.
type Route struct {
	Destination string `json:"destination"`
	Via         string `json:"via"`
	Metric      int    `json:"metric"`
	// Filters govern traffic to machines inside this prefix. They are
	// separate from the gateway peer's own filters: a rule about the NVR at
	// 192.168.1.50 is about the NVR, not about the reception PC that happens
	// to route for it.
	Filters []Filter `json:"filters"`
}

// Relay is a fallback path for peers that cannot connect directly.
type Relay struct {
	Name      string `json:"name"`
	Region    string `json:"region"`
	Host      string `json:"host"`
	Port      int    `json:"port"`
	TCPPort   int    `json:"tcp_port"`
	PublicKey string `json:"public_key"`
}

// Unchanged reports whether the panel answered "nothing new since that
// revision" rather than sending a whole config.
func (c *Config) Unchanged() bool {
	return c.Changed != nil && !*c.Changed
}

// FetchConfig retrieves the device's configuration.
//
// knownRevision is the revision the agent already holds; passing it lets the
// panel answer "unchanged" without building a peer list. Pass 0 on first fetch.
func (c *Client) FetchConfig(ctx context.Context, knownRevision int) (*Config, int, error) {
	if !c.HasToken() {
		return nil, 0, fmt.Errorf("no device token: the device is not approved yet")
	}

	path := fmt.Sprintf("/api/v1/agent/config?revision=%d", knownRevision)

	var out Config
	pollAfter, err := c.do(ctx, http.MethodGet, path, nil, &out)
	if err != nil {
		return nil, pollAfter, err
	}

	return &out, pollAfter, nil
}

// Heartbeat is what the agent reports on each cycle.
//
// The byte fields are deltas since the last heartbeat, not totals. The panel
// accumulates them into usage counters, so sending totals would make every
// heartbeat re-add the whole session and the numbers would be nonsense.
type Heartbeat struct {
	Endpoint    string `json:"endpoint,omitempty"`
	LANEndpoint string `json:"lan_endpoint,omitempty"`
	// ConnectionType is "direct", "relay" or "offline" — the panel accepts
	// nothing else, and anything else becomes "offline".
	ConnectionType string `json:"connection_type,omitempty"`
	LatencyMS      int    `json:"latency_ms,omitempty"`
	RXDelta        int64  `json:"rx_delta,omitempty"`
	TXDelta        int64  `json:"tx_delta,omitempty"`
	Revision       int    `json:"revision,omitempty"`
}

// SendHeartbeat reports liveness and counters, and is how the panel learns the
// device is still there. Its failure with 401 or 403 is how a revocation
// reaches an agent that is otherwise happily running.
func (c *Client) SendHeartbeat(ctx context.Context, hb Heartbeat) (int, error) {
	if !c.HasToken() {
		return 0, fmt.Errorf("no device token")
	}

	return c.do(ctx, http.MethodPost, "/api/v1/agent/heartbeat", hb, nil)
}
