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
		// Advertises are the LANs it routes for, as the panel approved them.
		// Each carries both prefixes: the one the overlay uses and the real
		// one on the site's own network. Empty unless IsGateway.
		Advertises []Advertised `json:"advertises"`
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
		// Zone is the only domain this device answers for. Everything outside
		// it is refused, never forwarded: the agent must not be capable of
		// becoming the customer's resolver, however it is pointed at.
		Zone string `json:"zone"`
		// Records are the names in that zone and the addresses the overlay
		// uses for them — a device's virtual IP, and for a machine behind a
		// gateway its *mapped* address, because the real one would send a
		// technician to whatever sits at it on their own LAN.
		Records []DNSRecord `json:"records"`
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

	// Fallback is the HTTPS path, used when UDP does not work at all.
	Fallback Fallback `json:"fallback"`

	// Controller is the panel's own signing identity. A release is only
	// installed if its digest carries a signature from this key, so a panel
	// that publishes no key can offer no updates.
	Controller struct {
		PublicKey string `json:"public_key"`
	} `json:"controller"`

	Policy struct {
		SplitTunnelOnly   bool   `json:"split_tunnel_only"`
		AllowDefaultRoute bool   `json:"allow_default_route"`
		ACLDefaultAction  string `json:"acl_default_action"`
	} `json:"policy"`

	// UpdateRequested is an administrator having pressed "Update now" on this
	// device's page. The agent otherwise checks every six hours, which is
	// right for a rollout and useless for somebody standing in front of a
	// machine trying to fix it.
	UpdateRequested bool `json:"update_requested,omitempty"`

	IssuedAt string `json:"issued_at"`
}

// DNSRecord is one name this device answers.
type DNSRecord struct {
	Name    string `json:"name"`
	Address string `json:"address"`
	// Kind is "device" or "lan", for the status output. A technician looking
	// at a list of names wants to know which of them are machines with no
	// agent on them.
	Kind string `json:"kind"`
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

// Advertised is one LAN a gateway routes for, in both address spaces.
type Advertised struct {
	// Destination is the prefix the overlay uses for this LAN.
	Destination string `json:"destination"`
	// RealDestination is the prefix on the site's own network. The two differ
	// because two customers both on 192.168.1.0/24 is the normal case, and one
	// routing table cannot hold both.
	RealDestination string `json:"real_destination"`
}

// Route is a subnet reachable through a gateway device.
type Route struct {
	// Destination is the prefix the overlay uses — the mapped one. This is
	// what a client routes on and what its rules are about.
	Destination string `json:"destination"`
	// RealDestination is the address range on the site's LAN, carried for the
	// gateway that has to NAT between the two and for anything showing a human
	// which machine a rule names. A client never routes on it.
	RealDestination string `json:"real_destination"`
	Via             string `json:"via"`
	Metric          int    `json:"metric"`
	// Filters govern traffic to machines inside this prefix. They are
	// separate from the gateway peer's own filters: a rule about the NVR at
	// 192.168.1.50 is about the NVR, not about the reception PC that happens
	// to route for it.
	Filters []Filter `json:"filters"`
}

// Fallback is where the agent goes when UDP cannot leave the network.
type Fallback struct {
	// URL is the wss:// address the relay's HTTPS endpoint is served on.
	// Empty on a panel that has not configured one, which disables the path.
	URL string `json:"url"`
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
	// Problems are things this device could not do and cannot fix by itself —
	// an NRPT rule Windows refused, a prefix that clashed with a network the
	// machine is already on. They go here because the alternative is a line in
	// a log file on the customer's machine, which nobody reads until they
	// telephone.
	Problems []Problem `json:"problems,omitempty"`
	// UptimeSeconds is how long this agent process has been running. The panel
	// turns it into "started at", which is how an administrator answers "has
	// it restarted?" without telephoning a shopkeeper. Sent rather than a
	// timestamp because a machine with a wrong clock is common and a duration
	// does not care what the clock says.
	UptimeSeconds int `json:"uptime_seconds,omitempty"`
	// Update is this agent's own account of what it did about the last
	// release it was offered.
	//
	// Nil until it has checked once. It is here because everything the agent
	// does about an update it does silently, and every failure is a line in a
	// log on the customer's machine: a machine that never checked and one
	// that refused a bad signature look identical from the panel, both still
	// on the old version. One did nothing wrong and one needs attention.
	Update *UpdateState `json:"update,omitempty"`
}

// UpdateState is where a device got to with a release.
type UpdateState struct {
	// State is one of idle, offered, downloading, installed, failed.
	State string `json:"state"`
	// Version is the release this is about, empty when nothing was offered.
	Version string `json:"version,omitempty"`
	// Error is why it stopped, when it stopped badly.
	Error string `json:"error,omitempty"`
}

// Problem is one thing that needs a person.
type Problem struct {
	// Code is a stable identifier the panel can key messages and help off.
	Code string `json:"code"`
	// Detail is a sentence written for whoever has to act on it, naming what
	// clashed or what refused.
	Detail string `json:"detail"`
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
