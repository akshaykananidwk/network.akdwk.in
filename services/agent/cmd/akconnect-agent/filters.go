package main

import (
	"sync/atomic"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/acl"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
)

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
		table.Add(peer.VirtualIP, convertFilters(peer.Filters))
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
