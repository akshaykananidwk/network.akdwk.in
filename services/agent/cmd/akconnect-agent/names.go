package main

import (
	"context"
	"strings"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/dnsd"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/netcfg"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
)

// Names for the network, and pointing the operating system at them (§18).
//
// Two halves, and they fail independently on purpose. The resolver always
// runs: it costs a loopback socket and it is what a drill can query directly.
// Pointing the operating system at it is best-effort, because the mechanisms
// that give *split* DNS rather than a takeover — systemd-resolved on Linux,
// NRPT on Windows — are not present everywhere.
//
// Where the second half cannot be done, the agent says which mechanism was
// missing and carries on. Names do not resolve; addresses still work; the
// customer's own DNS is untouched. Writing ourselves into /etc/resolv.conf
// instead would make the feature work everywhere and make us the resolver for
// a customer's entire internet, which is not a trade this product gets to
// make.

// applyNames rebuilds the zone and, if it has moved, re-points the operating
// system at it.
func (s *session) applyNames(ctx context.Context, cfg *panel.Config) {
	zone := buildZone(cfg)

	if s.names == nil {
		if zone.Len() == 0 && zone.Suffix == "" {
			// Nothing to serve and no zone to serve it in. Not worth a socket.
			return
		}

		server, err := dnsd.New(s.logf)
		if err != nil {
			// Not fatal. A machine where every loopback address is taken still
			// has a working tunnel, and a tunnel is the product.
			s.logf("names: no resolver (%v); machines are reachable by address", err)

			return
		}

		s.names = server
		go server.Serve(ctx)
	}

	s.names.SetZone(zone)

	// The zone moving means re-pointing; the records moving means the hosts
	// file has to be rewritten, because that mechanism holds the answers
	// rather than an address to ask. A poll that changed neither does nothing.
	fingerprint := zoneFingerprint(cfg)
	if zone.Suffix == s.dnsZone && fingerprint == s.dnsRecords {
		return
	}
	s.dnsRecords = fingerprint

	if s.dnsZone != "" {
		netcfg.RemoveDNS(netcfg.DNSPlan{
			Interface: s.tun.Name(), Zone: s.dnsZone, Resolver: s.names.Addr(),
		})
	}
	s.dnsZone = zone.Suffix

	if zone.Suffix == "" {
		return
	}

	result, err := netcfg.ApplyDNS(netcfg.DNSPlan{
		Interface: s.tun.Name(),
		Zone:      zone.Suffix,
		Resolver:  s.names.Addr(),
		Entries:   hostEntries(cfg),
	})
	s.dnsRoutedBy = ""
	s.dnsNote = result.Mechanism

	switch {
	case err != nil:
		s.dnsNote = err.Error()
		s.logf("names: could not route %s to the resolver: %v", zone.Suffix, err)
	case result.Applied:
		s.dnsRoutedBy = result.Mechanism
		s.dnsNote = ""
		s.logf("names: %d name(s) under %s resolve through %s (%s); every other name is untouched",
			zone.Len(), zone.Suffix, s.names.Addr(), result.Mechanism)
	default:
		s.logf("names: %d name(s) under %s are served at %s, but nothing is pointing at it — %s",
			zone.Len(), zone.Suffix, s.names.Addr(), result.Mechanism)
	}
}

// stopNames takes the operating system's configuration back off.
//
// A machine left routing a zone to a resolver that is no longer listening
// would time out on those names rather than fail, which is the slower and more
// confusing of the two.
func (s *session) stopNames() {
	if s.names == nil {
		return
	}

	if s.dnsZone != "" && s.tun != nil {
		netcfg.RemoveDNS(netcfg.DNSPlan{
			Interface: s.tun.Name(),
			Zone:      s.dnsZone,
			Resolver:  s.names.Addr(),
		})
	}

	s.names.Close()
	s.names = nil
	s.dnsZone = ""
	s.dnsRoutedBy = ""
	s.dnsNote = ""
}

// buildZone compiles the panel's record list.
func buildZone(cfg *panel.Config) *dnsd.Zone {
	suffix := cfg.DNS.Zone
	if suffix == "" {
		suffix = cfg.DNS.SearchDomain
	}

	zone := dnsd.NewZone(suffix)
	for _, record := range cfg.DNS.Records {
		zone.Add(record.Name, record.Address)
	}

	return zone
}

// hostEntries is the zone as flat name/address pairs, for the mechanism that
// writes the answers down rather than pointing at a server.
func hostEntries(cfg *panel.Config) []netcfg.HostEntry {
	out := make([]netcfg.HostEntry, 0, len(cfg.DNS.Records))
	for _, record := range cfg.DNS.Records {
		out = append(out, netcfg.HostEntry{Name: record.Name, Address: record.Address})
	}

	return out
}

// zoneFingerprint is a cheap "has anything changed" over the record set.
//
// A device renamed in the panel has to reach the hosts file, and comparing the
// zone name alone would miss it. Comparing the records themselves is a handful
// of string appends on a poll that already parsed them out of JSON.
func zoneFingerprint(cfg *panel.Config) string {
	var sb strings.Builder
	sb.WriteString(cfg.DNS.Zone)

	for _, record := range cfg.DNS.Records {
		sb.WriteByte(0)
		sb.WriteString(record.Name)
		sb.WriteByte('=')
		sb.WriteString(record.Address)
	}

	return sb.String()
}
