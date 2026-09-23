package netcfg

import (
	"errors"
	"testing"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
)

// base returns a configuration that should be accepted, for tests to spoil.
func base() *panel.Config {
	cfg := &panel.Config{}
	cfg.Device.VirtualIP = "10.50.0.7"
	cfg.Network.CIDR = "10.50.0.0/16"
	cfg.Network.MTU = 1380
	cfg.Peers = []panel.Peer{{
		UID:        "peer-1",
		PublicKey:  "aGVsbG8gd29ybGQgaGVsbG8gd29ybGQgaGVsbG8x",
		VirtualIP:  "10.50.0.8",
		AllowedIPs: []string{"10.50.0.8/32"},
	}}

	return cfg
}

func TestAcceptsASplitTunnelConfig(t *testing.T) {
	plan, err := Build(base())
	if err != nil {
		t.Fatalf("a valid split-tunnel config was rejected: %v", err)
	}

	if plan.Address.String() != "10.50.0.7/32" {
		t.Errorf("address = %s, want 10.50.0.7/32", plan.Address)
	}
	if plan.MTU != 1380 {
		t.Errorf("MTU = %d, want 1380", plan.MTU)
	}
	if len(plan.Routes) != 1 || plan.Routes[0].String() != "10.50.0.0/16" {
		t.Errorf("routes = %v, want [10.50.0.0/16]", plan.Routes)
	}
}

// The whole point of the package: every route into the plan must be refused if
// it would carry traffic the customer did not put on the overlay.
func TestRefusesADefaultRouteFromEverySource(t *testing.T) {
	cases := []struct {
		name   string
		spoil  func(*panel.Config)
		source string
	}{
		{
			name:   "in the routes list",
			spoil:  func(c *panel.Config) { c.Routes = []panel.Route{{Destination: "0.0.0.0/0"}} },
			source: "the routes list",
		},
		{
			name:   "in a peer's allowed_ips",
			spoil:  func(c *panel.Config) { c.Peers[0].AllowedIPs = []string{"0.0.0.0/0"} },
			source: "peer peer-1 allowed_ips",
		},
		{
			name:   "as the network CIDR itself",
			spoil:  func(c *panel.Config) { c.Network.CIDR = "0.0.0.0/0" },
			source: "the network CIDR",
		},
		{
			name:   "as an IPv6 default route",
			spoil:  func(c *panel.Config) { c.Peers[0].AllowedIPs = []string{"::/0"} },
			source: "peer peer-1 allowed_ips",
		},
		{
			// The classic smuggle: two /1 routes cover everything a /0 does,
			// and slip past any check that only looks for "/0".
			name: "as a pair of /1 routes",
			spoil: func(c *panel.Config) {
				c.Routes = []panel.Route{{Destination: "0.0.0.0/1"}, {Destination: "128.0.0.0/1"}}
			},
			source: "the routes list",
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			cfg := base()
			tc.spoil(cfg)

			_, err := Build(cfg)
			if err == nil {
				t.Fatal("the configuration was accepted; R1 would be broken")
			}

			var defaultRoute *ErrDefaultRoute
			if !errors.As(err, &defaultRoute) {
				t.Fatalf("got %v, want an ErrDefaultRoute", err)
			}
			if defaultRoute.Source != tc.source {
				t.Errorf("blamed %q, want %q", defaultRoute.Source, tc.source)
			}
		})
	}
}

func TestRejectsNonsense(t *testing.T) {
	cases := map[string]func(*panel.Config){
		"no virtual IP":     func(c *panel.Config) { c.Device.VirtualIP = "" },
		"unparseable IP":    func(c *panel.Config) { c.Device.VirtualIP = "not-an-ip" },
		"unparseable CIDR":  func(c *panel.Config) { c.Network.CIDR = "10.50.0.0" },
		"unparseable route": func(c *panel.Config) { c.Routes = []panel.Route{{Destination: "banana"}} },
		"unparseable peer":  func(c *panel.Config) { c.Peers[0].AllowedIPs = []string{"banana"} },
	}

	for name, spoil := range cases {
		t.Run(name, func(t *testing.T) {
			cfg := base()
			spoil(cfg)

			if _, err := Build(cfg); err == nil {
				t.Fatal("accepted a configuration it cannot make sense of")
			}
		})
	}
}

func TestDefaultsMTUWhenThePanelDoesNotSay(t *testing.T) {
	cfg := base()
	cfg.Network.MTU = 0

	plan, err := Build(cfg)
	if err != nil {
		t.Fatal(err)
	}
	if plan.MTU != 1420 {
		t.Errorf("MTU = %d, want the 1420 fallback", plan.MTU)
	}
}

// A gateway must not route its own LAN down the tunnel.
//
// This is the defect that made subnet-router mode look implemented and route
// nothing: the panel sends every gateway's prefixes in the routes list, the
// agent installed all of them, and `ip route replace 192.168.77.0/24 dev akc0`
// on the gateway itself both destroyed the kernel's interface route for the
// LAN and pointed forwarded packets back at the tunnel they arrived on. The
// tunnel was up, the iptables rules were right, and the NVR was unreachable.
func TestBuildSkipsPrefixesThisDeviceServes(t *testing.T) {
	cfg := &panel.Config{}
	cfg.Device.VirtualIP = "10.99.0.3"
	cfg.Device.IsGateway = true
	cfg.Device.Advertises = []panel.Advertised{{Destination: "10.128.0.0/24", RealDestination: "192.168.77.0/24"}}
	cfg.Network.CIDR = "10.99.0.0/24"
	cfg.Routes = []panel.Route{
		{Destination: "10.128.0.0/24", RealDestination: "192.168.77.0/24", Via: "10.99.0.3"},
		{Destination: "10.128.1.0/24", RealDestination: "192.168.88.0/24", Via: "10.99.0.9"},
	}

	plan, err := Build(cfg)
	if err != nil {
		t.Fatalf("Build: %v", err)
	}

	for _, route := range plan.Routes {
		if route.String() == "10.128.0.0/24" {
			t.Fatalf("plan routes this device's own LAN through the tunnel: %v", plan.Routes)
		}
	}

	var sawOther bool
	for _, route := range plan.Routes {
		if route.String() == "10.128.1.0/24" {
			sawOther = true
		}
	}
	if !sawOther {
		t.Fatalf("another gateway's prefix was dropped too: %v", plan.Routes)
	}
}

// The same, when the panel names the prefix only in the device block — an
// older panel, or a route whose via address has not been filled in yet.
func TestBuildSkipsAdvertisedPrefixWithoutVia(t *testing.T) {
	cfg := &panel.Config{}
	cfg.Device.VirtualIP = "10.99.0.3"
	cfg.Device.IsGateway = true
	cfg.Device.Advertises = []panel.Advertised{{Destination: "10.128.0.0/24", RealDestination: "192.168.77.0/24"}}
	cfg.Network.CIDR = "10.99.0.0/24"
	cfg.Routes = []panel.Route{{Destination: "10.128.0.0/24", RealDestination: "192.168.77.0/24"}}

	plan, err := Build(cfg)
	if err != nil {
		t.Fatalf("Build: %v", err)
	}

	if len(plan.Routes) != 1 || plan.Routes[0].String() != "10.99.0.0/24" {
		t.Fatalf("expected the overlay alone, got %v", plan.Routes)
	}
}

// Overlay must be filled in, because applyPlan uses it to decide which route
// is not allowed to lose to a local network.
func TestBuildRecordsTheOverlay(t *testing.T) {
	cfg := &panel.Config{}
	cfg.Device.VirtualIP = "10.99.0.2"
	cfg.Network.CIDR = "10.99.0.0/24"

	plan, err := Build(cfg)
	if err != nil {
		t.Fatalf("Build: %v", err)
	}

	if plan.Overlay.String() != "10.99.0.0/24" {
		t.Fatalf("Overlay = %q", plan.Overlay)
	}
}
