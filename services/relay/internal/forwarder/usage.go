package forwarder

import (
	"net"
	"net/netip"

	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
)

// UsageReporter sends what this relay has carried to the coordinator.
//
// The relay reports to the coordinator rather than to the panel because the
// coordinator already holds a panel credential and the relay already shares a
// secret with the coordinator. Giving every relay its own panel login would
// mean one more credential on one more box for no gain — and a relay is the
// machine most exposed to customer traffic.
type UsageReporter struct {
	coordinator netip.AddrPort
	name        string
	secret      []byte
	logf        func(string, ...any)
}

// NewUsageReporter resolves the coordinator and prepares to report.
func NewUsageReporter(coordinator, name string, secret []byte, logf func(string, ...any)) (*UsageReporter, error) {
	addr, err := resolveUDP(coordinator)
	if err != nil {
		return nil, err
	}

	return &UsageReporter{coordinator: addr, name: name, secret: secret, logf: logf}, nil
}

// Report sends one cumulative snapshot.
//
// Fire and forget over UDP, and that is safe because the figures are
// cumulative: a lost report costs nothing, because the next one carries the
// whole total and the coordinator takes the difference. A delta that went
// missing would be revenue nobody could account for.
func (r *UsageReporter) Report(usage map[uint64]Usage) {
	if r == nil || len(usage) == 0 {
		return
	}

	report := &disco.RelayUsage{Relay: r.name}
	for tenant, u := range usage {
		report.Tenants = append(report.Tenants, disco.TenantUsage{TenantID: tenant, Bytes: uint64(u.Bytes)})
	}

	body, err := report.Encode(r.secret)
	if err != nil {
		return
	}

	pkt := make([]byte, disco.HeaderLen, disco.HeaderLen+len(body))
	disco.WriteHeader(pkt, disco.TypeRelayUsage, [32]byte{})
	pkt = append(pkt, body...)

	conn, err := net.Dial("udp", r.coordinator.String())
	if err != nil {
		r.logf("usage report failed: %v", err)

		return
	}
	defer func() { _ = conn.Close() }()

	if _, err := conn.Write(pkt); err != nil {
		r.logf("usage report failed: %v", err)
	}
}

func resolveUDP(addr string) (netip.AddrPort, error) {
	if parsed, err := netip.ParseAddrPort(addr); err == nil {
		return parsed, nil
	}

	resolved, err := net.ResolveUDPAddr("udp", addr)
	if err != nil {
		return netip.AddrPort{}, err
	}

	ip, ok := netip.AddrFromSlice(resolved.IP)
	if !ok {
		return netip.AddrPort{}, net.InvalidAddrError(addr)
	}

	return netip.AddrPortFrom(ip.Unmap(), uint16(resolved.Port)), nil
}
