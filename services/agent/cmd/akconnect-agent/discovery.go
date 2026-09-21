package main

import (
	"context"
	"fmt"
	"net"
	"net/netip"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/discovery"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/wgkey"
)

// startDiscovery brings up peer rendezvous once the tunnel exists.
//
// It is best-effort by design. Without it the tunnel still works between peers
// that already know each other's addresses, and with it they find each other
// automatically — but a coordinator that is down, misconfigured or not yet
// deployed must never be the reason an agent refuses to run (R6).
func (s *session) startDiscovery(ctx context.Context, cfg *panel.Config, priv wgPrivate) {
	if cfg.Coordinator.Host == "" || cfg.Coordinator.Port == 0 {
		s.logf("discovery: no coordinator configured; peers must have static endpoints")
		return
	}

	if cfg.Coordinator.PublicKey == "" {
		// Announcing without it would mean putting the device token on the
		// wire in the clear. Not announcing is the better failure.
		s.logf("discovery: the panel published no coordinator public key; not announcing")
		return
	}

	coordKey, err := wgkey.ParsePublic(cfg.Coordinator.PublicKey)
	if err != nil {
		s.logf("discovery: the coordinator public key is unusable: %v", err)
		return
	}

	addr, err := resolveCoordinator(cfg.Coordinator.Host, cfg.Coordinator.Port)
	if err != nil {
		s.logf("discovery: %v", err)
		return
	}

	pub, err := priv.Public()
	if err != nil {
		s.logf("discovery: %v", err)
		return
	}

	client, err := discovery.New(discovery.Options{
		SelfPublic:     [32]byte(pub),
		SelfPrivate:    [32]byte(priv),
		Coordinator:    addr,
		CoordKey:       [32]byte(coordKey),
		DeviceUID:      s.st.DeviceUID,
		Token:          s.st.DeviceToken,
		LocalEndpoints: localEndpoints(uint16(s.port), s.tun.Name()),
		Overlay:        overlayPrefix(cfg),
		Transport:      s.tun.Transport(),
		Peers:          s.tun,
		Logf:           s.logf,
	})
	if err != nil {
		s.logf("discovery: %v", err)
		return
	}

	client.SetRelays(relayFleet(cfg, s.logf))

	s.discovery = client

	go client.Run(ctx)

	s.logf("discovery: announcing to the coordinator at %s", addr)
}

// relayFleet turns the panel's relay list into addresses the agent can probe.
//
// A relay whose host will not resolve is dropped with a line in the log rather
// than kept as a nil address: a fleet entry that can never be measured would
// otherwise sit there looking like a relay that is simply slow.
func relayFleet(cfg *panel.Config, logf func(string, ...any)) []discovery.RelayTarget {
	out := make([]discovery.RelayTarget, 0, len(cfg.Relays))

	for _, relay := range cfg.Relays {
		addr, err := resolveCoordinator(relay.Host, relay.Port)
		if err != nil {
			logf("discovery: ignoring relay %s: %v", relay.Name, err)
			continue
		}

		out = append(out, discovery.RelayTarget{Name: relay.Name, Addr: addr})
	}

	return out
}

// overlayPrefix is the tunnel's own network, used to refuse any endpoint
// inside it. A malformed CIDR yields the zero prefix, which disables the check
// rather than blocking every candidate.
func overlayPrefix(cfg *panel.Config) netip.Prefix {
	prefix, err := netip.ParsePrefix(cfg.Network.CIDR)
	if err != nil {
		return netip.Prefix{}
	}

	return prefix.Masked()
}

func resolveCoordinator(host string, port int) (netip.AddrPort, error) {
	if addr, err := netip.ParseAddr(host); err == nil {
		return netip.AddrPortFrom(addr, uint16(port)), nil
	}

	ips, err := net.LookupIP(host)
	if err != nil || len(ips) == 0 {
		return netip.AddrPort{}, fmt.Errorf("cannot resolve the coordinator at %s: %w", host, err)
	}

	addr, ok := netip.AddrFromSlice(ips[0])
	if !ok {
		return netip.AddrPort{}, fmt.Errorf("the coordinator address for %s is unusable", host)
	}

	return netip.AddrPortFrom(addr.Unmap(), uint16(port)), nil
}

// localEndpoints lists this machine's own addresses, so a peer on the same
// network can reach us across it instead of going out to the internet and
// back (R2).
//
// Loopback is excluded because it is never reachable from anywhere else,
// link-local because an address that needs a zone index is not one a peer can
// simply be handed, and the tunnel interface because routing the underlay
// through the overlay is a loop.
func localEndpoints(port uint16, tunnelIface string) []netip.AddrPort {
	ifaces, err := net.Interfaces()
	if err != nil {
		return nil
	}

	var out []netip.AddrPort

	for _, iface := range ifaces {
		if iface.Flags&net.FlagUp == 0 || iface.Flags&net.FlagLoopback != 0 {
			continue
		}
		// Never offer the overlay's own address as a way to reach us. A peer
		// that took it would be asking WireGuard to carry its own encrypted
		// packets through the tunnel they are meant to establish.
		if iface.Name == tunnelIface {
			continue
		}

		addrs, err := iface.Addrs()
		if err != nil {
			continue
		}

		for _, a := range addrs {
			ipNet, ok := a.(*net.IPNet)
			if !ok {
				continue
			}

			addr, ok := netip.AddrFromSlice(ipNet.IP)
			if !ok {
				continue
			}
			addr = addr.Unmap()

			if addr.IsLoopback() || addr.IsLinkLocalUnicast() || addr.IsUnspecified() {
				continue
			}

			out = append(out, netip.AddrPortFrom(addr, port))
		}
	}

	return out
}
