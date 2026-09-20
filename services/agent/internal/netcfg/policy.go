// Package netcfg turns a panel configuration into the addresses and routes the
// operating system will actually be asked to install, and refuses anything
// that would break R1.
//
// The server already strips default routes before a config leaves it. This
// package checks again, on the device, because the two guarantees fail
// independently: a compromised or simply buggy panel must not be able to route
// a customer's entire internet through the overlay, and "the server promised"
// is not something the machine whose traffic it is should have to take on
// trust.
package netcfg

import (
	"fmt"
	"net/netip"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
)

// ErrDefaultRoute is returned when a configuration would capture all traffic.
type ErrDefaultRoute struct {
	// Source says where the offending prefix appeared: a peer's allowed_ips,
	// or the routes list.
	Source string
	Prefix string
}

func (e *ErrDefaultRoute) Error() string {
	return fmt.Sprintf(
		"refusing configuration: %s contains %s, which would capture the default route (R1: split tunnel only)",
		e.Source, e.Prefix)
}

// Plan is the vetted result: what to put on the interface and in the table.
type Plan struct {
	// Address is this device's overlay address, as a host prefix.
	Address netip.Prefix
	// Routes are the overlay prefixes to send through the tunnel. The overlay
	// CIDR itself is included; nothing wider ever is.
	Routes []netip.Prefix
	// MTU for the tunnel interface.
	MTU int
}

// Build validates a configuration and returns the plan to apply.
func Build(cfg *panel.Config) (*Plan, error) {
	if cfg.Device.VirtualIP == "" {
		return nil, fmt.Errorf("configuration has no virtual IP for this device")
	}

	addr, err := netip.ParseAddr(cfg.Device.VirtualIP)
	if err != nil {
		return nil, fmt.Errorf("virtual IP %q is not an address: %w", cfg.Device.VirtualIP, err)
	}

	overlay, err := netip.ParsePrefix(cfg.Network.CIDR)
	if err != nil {
		return nil, fmt.Errorf("network CIDR %q is not a prefix: %w", cfg.Network.CIDR, err)
	}
	if err := reject(overlay, "the network CIDR"); err != nil {
		return nil, err
	}

	routes := []netip.Prefix{overlay.Masked()}

	for _, r := range cfg.Routes {
		prefix, err := netip.ParsePrefix(r.Destination)
		if err != nil {
			return nil, fmt.Errorf("route %q is not a prefix: %w", r.Destination, err)
		}
		if err := reject(prefix, "the routes list"); err != nil {
			return nil, err
		}
		routes = append(routes, prefix.Masked())
	}

	// Peers are checked too. A peer's allowed_ips is what the WireGuard layer
	// will decrypt and accept from that peer, so 0.0.0.0/0 there is every bit
	// as total as a default route in the routing table.
	for _, p := range cfg.Peers {
		for _, raw := range p.AllowedIPs {
			prefix, err := netip.ParsePrefix(raw)
			if err != nil {
				return nil, fmt.Errorf("peer %s has allowed_ip %q that is not a prefix: %w", p.UID, raw, err)
			}
			if err := reject(prefix, fmt.Sprintf("peer %s allowed_ips", p.UID)); err != nil {
				return nil, err
			}
		}
	}

	mtu := cfg.Network.MTU
	if mtu <= 0 {
		// The WireGuard default: 1500 less 60 bytes of IPv6+UDP+WireGuard
		// overhead, which is the safe figure when the panel does not say.
		mtu = 1420
	}

	return &Plan{
		Address: netip.PrefixFrom(addr, addr.BitLen()),
		Routes:  dedupe(routes),
		MTU:     mtu,
	}, nil
}

// reject refuses a prefix that is, or contains, a default route.
func reject(p netip.Prefix, source string) error {
	if p.Bits() == 0 {
		return &ErrDefaultRoute{Source: source, Prefix: p.String()}
	}

	// A /1 pair covers the whole address space just as a /0 does, and is the
	// usual way a "full tunnel" is smuggled past a naive /0 check.
	if p.Bits() == 1 {
		return &ErrDefaultRoute{Source: source, Prefix: p.String()}
	}

	return nil
}

func dedupe(in []netip.Prefix) []netip.Prefix {
	seen := make(map[netip.Prefix]struct{}, len(in))
	out := make([]netip.Prefix, 0, len(in))

	for _, p := range in {
		if _, ok := seen[p]; ok {
			continue
		}
		seen[p] = struct{}{}
		out = append(out, p)
	}

	return out
}
