package main

import (
	"sync/atomic"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/acl"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/netmap"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
)

// buildMappings compiles the LANs this device is the gateway for into the
// address translation it applies.
//
// Only a gateway maps anything. A client uses the mapped addresses end to end
// and never learns the site's real range, which is the entire point: two
// customers on 192.168.1.0/24 are two different prefixes as far as it is
// concerned.
func buildMappings(cfg *panel.Config) *netmap.Table {
	table := netmap.NewTable()

	if !cfg.Device.IsGateway {
		return table
	}

	for _, advertised := range cfg.Device.Advertises {
		real := advertised.RealDestination
		if real == "" {
			continue
		}
		table.Add(advertised.Destination, real)
	}

	return table
}

// buildFilterTable compiles the panel's peer list into the table the tunnel
// enforces.
//
// Every peer in the configuration is recorded, whether or not it carries port
// rules: the table is also the list of addresses this device may exchange
// traffic with at all, and a peer absent from it is refused.
func buildFilterTable(cfg *panel.Config) *acl.Table {
	table := acl.NewTable()
	table.AddSelf(cfg.Device.VirtualIP)

	for _, peer := range cfg.Peers {
		table.Add(peer.VirtualIP, localFilters(peer.Filters))

		// On a gateway, each peer also carries what it may reach inside the
		// LANs this device routes for. Absent everywhere else, because every
		// other device forwards nothing.
		for _, served := range peer.Routes {
			table.AddServed(peer.VirtualIP, served.Destination, convertFilters(served.Filters))
		}
	}

	// Prefixes reachable through a gateway. The machines inside them have no
	// overlay address and are not peers, so without this the filter refuses
	// them as unknown — the right answer to the wrong question.
	for _, route := range cfg.Routes {
		if route.Via == "" {
			// A route with no gateway is one nothing can deliver, and
			// accepting traffic for it would be accepting traffic that goes
			// nowhere.
			continue
		}
		table.AddRoute(route.Destination, route.Via, localFilters(route.Filters))
	}

	return table
}

func convertFilters(in []panel.Filter) []acl.Filter {
	if len(in) == 0 {
		return nil
	}

	out := make([]acl.Filter, 0, len(in))
	for _, f := range in {
		out = append(out, acl.Filter{
			Action:   f.Action,
			Protocol: acl.Protocol(f.Protocol),
			PortFrom: f.PortFrom,
			PortTo:   f.PortTo,
			RuleID:   f.RuleID,
		})
	}

	return out
}

// dropReporter logs refused packets at a bounded rate.
//
// A single misconfigured rule can drop thousands of packets a second. Logging
// each one turns a connectivity problem into a disk-space problem and buries
// the first line, which is the one that says what happened.
type dropReporter struct {
	logf  func(string, ...any)
	last  atomic.Int64
	since atomic.Uint64
}

const dropReportInterval = 30 * time.Second

func newDropReporter(logf func(string, ...any)) *dropReporter {
	return &dropReporter{logf: logf}
}

func (r *dropReporter) report(dir acl.Direction, verdict acl.Verdict) {
	suppressed := r.since.Add(1)

	now := time.Now().UnixNano()
	last := r.last.Load()
	if last != 0 && now-last < int64(dropReportInterval) {
		return
	}
	if !r.last.CompareAndSwap(last, now) {
		return
	}
	r.since.Store(0)

	rule := "no rule"
	if verdict.RuleID != 0 {
		r.logf("acl: dropped %sbound packet — %s (rule %d); %d since the last report",
			dir, verdict.Reason, verdict.RuleID, suppressed)

		return
	}

	r.logf("acl: dropped %sbound packet — %s (%s); %d since the last report",
		dir, verdict.Reason, rule, suppressed)
}
