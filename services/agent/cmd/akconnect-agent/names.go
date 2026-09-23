package main

import (
	"context"
	"fmt"
	"strings"
	"time"

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
	s.dnsProblem = result.Problem

	switch {
	case err != nil:
		s.dnsNote = err.Error()
		s.dnsProblem = err.Error()
		s.logf("names: could not route %s to the resolver: %v", zone.Suffix, err)
	case result.Applied:
		s.dnsRoutedBy = result.Mechanism
		s.dnsNote = ""
		s.dnsProblem = ""
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
	s.dnsProblem = ""
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

// problems is what this device cannot do and cannot fix by itself, for the
// heartbeat.
//
// Repeated on every heartbeat rather than sent once: a problem reported at the
// moment it happened and never again is one that disappears from the panel the
// first time a row is updated, and these are exactly the problems somebody
// only looks for a week later when a customer complains.
// unansweredProblemAfter is how long without an answer is worth telling an
// administrator about.
//
// A minute. Shorter would report a device that is merely starting up — the
// first announcement is unanswered by definition until its reply arrives — and
// a support call is not worth generating for that. Longer and a customer
// standing in front of the machine has already telephoned.
const unansweredProblemAfter = time.Minute

func (s *session) problems() []panel.Problem {
	var out []panel.Problem

	if s.dnsProblem != "" {
		out = append(out, panel.Problem{Code: "dns.not_routed", Detail: s.dnsProblem})
	}

	if s.fwProblem != "" {
		out = append(out, panel.Problem{
			Code: "firewall.inbound",
			Detail: "Inbound traffic from the overlay may be blocked on this machine, so peers " +
				"can reach it but get no answer — a ping will fail even though the tunnel is up. " +
				s.fwProblem,
		})
	}

	// A socket that cannot send is the one fault that makes every other
	// signal lie: the device heartbeats, the panel shows it Online, and no
	// peer can reach it because nothing it announces ever leaves the machine.
	if s.discovery != nil {
		if detail := s.discovery.TransportProblem(); detail != "" {
			out = append(out, panel.Problem{
				Code: "discovery.unreachable",
				Detail: "This device cannot send to the coordinator, so its peers are not being " +
					"told where it is and it cannot be introduced to theirs. The agent is " +
					"reopening its socket; if this persists, restart the AKConnect service. " +
					detail,
			})
		}
	}

	// The opposite fault, and the one a customer's firewall or router actually
	// produces: every send succeeds and nothing ever comes back.
	//
	// From the outside this is indistinguishable from "still connecting", so
	// a device sat on it silently for as long as anybody watched — which is
	// exactly what happened on an office Wi-Fi where the same laptop worked
	// on a phone hotspot an hour earlier. It says so now, and names the port,
	// because the port is what a firewall rule has to mention.
	if s.discovery != nil && s.discovery.TransportProblem() == "" {
		// Judged on how long it has been, not on the count.
		//
		// The count is reset every time the agent moves to a different port,
		// which is precisely what it does when nothing is answering — so a
		// threshold on the count would have reported this fault, cleared it,
		// reported it and cleared it again, on exactly the machines that have
		// it. Time since the last answer only goes one way.
		if count, since := s.discovery.Unanswered(); since >= unansweredProblemAfter {
			out = append(out, panel.Problem{
				Code: "discovery.no_reply",
				Detail: fmt.Sprintf(
					"This device is announcing itself and the coordinator never answers: %d "+
						"announcement(s) unanswered over %s. Its traffic is leaving the machine, so "+
						"nothing here is broken — something between it and the coordinator is "+
						"dropping the replies. Check that inbound UDP %d is allowed on this "+
						"network's firewall profile, and that the router is not blocking it. "+
						"Until this clears the device cannot be introduced to any peer.",
					count, since.Round(time.Second), s.port),
			})
		}
	}

	for _, prefix := range s.refused {
		code := "route.clash"
		detail := fmt.Sprintf(
			"This machine is already on %s, so the route for it was not installed and nothing in "+
				"that range is reachable from here. Change the network's mapping pool to a range "+
				"this site does not use.", prefix)

		if s.plan != nil && prefix == s.plan.Overlay {
			code = "overlay.clash"
			detail = fmt.Sprintf(
				"This machine is already on %s, which is the network's own range, so the overlay "+
					"route was not installed and no peer is reachable from here. Change the "+
					"network's CIDR to a range this site does not use.", prefix)
		}

		out = append(out, panel.Problem{Code: code, Detail: detail})
	}

	return out
}
