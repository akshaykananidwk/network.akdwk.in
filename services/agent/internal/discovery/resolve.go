package discovery

import (
	"fmt"
	"net"
	"net/netip"
	"strconv"
)

// resolveHostPort turns "host:port" into an address, whether host is a literal
// or a name.
//
// It lives here rather than in the command package because the discovery
// client needs it at run time, not only at start-up: a relay is offered by the
// coordinator as whatever string the operator configured, and on a real
// deployment that is a hostname.
//
// IPv4 is preferred when both are offered. The overlay is IPv4, WireGuard's
// endpoint is a single address, and a relay reachable over v6 from here may
// not be reachable over v6 from the peer — picking the address family that
// definitely works at both ends is worth more than picking the newer one.
func resolveHostPort(endpoint string) (netip.AddrPort, error) {
	if addr, err := netip.ParseAddrPort(endpoint); err == nil {
		return addr, nil
	}

	host, portText, err := net.SplitHostPort(endpoint)
	if err != nil {
		return netip.AddrPort{}, fmt.Errorf("%q is not host:port: %w", endpoint, err)
	}

	port, err := strconv.ParseUint(portText, 10, 16)
	if err != nil || port == 0 {
		return netip.AddrPort{}, fmt.Errorf("%q has no usable port", endpoint)
	}

	if addr, err := netip.ParseAddr(host); err == nil {
		return netip.AddrPortFrom(addr.Unmap(), uint16(port)), nil
	}

	ips, err := net.LookupIP(host)
	if err != nil {
		return netip.AddrPort{}, fmt.Errorf("cannot resolve %s: %w", host, err)
	}

	var fallback netip.Addr

	for _, ip := range ips {
		addr, ok := netip.AddrFromSlice(ip)
		if !ok {
			continue
		}
		addr = addr.Unmap()

		if addr.Is4() {
			return netip.AddrPortFrom(addr, uint16(port)), nil
		}
		if !fallback.IsValid() {
			fallback = addr
		}
	}

	if fallback.IsValid() {
		return netip.AddrPortFrom(fallback, uint16(port)), nil
	}

	return netip.AddrPort{}, fmt.Errorf("%s resolved to nothing usable", host)
}
