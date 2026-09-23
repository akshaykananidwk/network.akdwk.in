package server

import (
	"context"
	"sync"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
)

// Relay usage, on its way from the relay that measured it to the panel that
// bills for it.
//
// The relay sends cumulative totals; the panel wants deltas. Converting here
// rather than at either end is deliberate: the relay must be able to restart
// without losing count, and the panel must never see a figure that goes
// backwards.

// usageLedger remembers the last cumulative total seen per relay and tenant.
type usageLedger struct {
	mu   sync.Mutex
	seen map[usageKey]uint64
}

type usageKey struct {
	relay  string
	tenant uint64
}

func newUsageLedger() *usageLedger {
	return &usageLedger{seen: make(map[usageKey]uint64)}
}

// delta returns how much to add to the panel's counter for one report.
//
// A total lower than the last one means the relay restarted, so everything it
// reports is new since that restart — the whole figure is the delta. Treating
// it as zero would silently stop billing a relay that had been restarted,
// which is the failure that costs money rather than the one that annoys a
// customer.
func (l *usageLedger) delta(relay string, tenant, total uint64) uint64 {
	key := usageKey{relay: relay, tenant: tenant}

	l.mu.Lock()
	defer l.mu.Unlock()

	previous, ok := l.seen[key]
	l.seen[key] = total

	if !ok || total < previous {
		return total
	}

	return total - previous
}

// handleRelayUsage accepts a usage report from a relay.
//
// Authenticated by a MAC the relay computes with the secret it already shares
// with this coordinator. The relay names itself in the report, and that name
// selects the secret — so a report naming a relay we do not know, or carrying
// a MAC that does not verify under that relay's secret, is discarded without
// being counted. Billing data from an unauthenticated source is worse than no
// billing data.
func (s *Server) handleRelayUsage(body []byte) {
	report, err := disco.DecodeRelayUsage(body)
	if err != nil {
		return
	}

	var secret []byte
	for _, relay := range s.opts.Relays {
		if relay.Name == report.Relay {
			secret = relay.Secret

			break
		}
	}
	if secret == nil {
		s.opts.Logf("discarded a usage report from unknown relay %q", report.Relay)

		return
	}

	if !disco.VerifyUsage(body, secret) {
		s.opts.Logf("discarded a usage report for relay %q: signature does not verify", report.Relay)

		return
	}

	deltas := make(map[uint64]uint64, len(report.Tenants))
	for _, tenant := range report.Tenants {
		if d := s.usage.delta(report.Relay, tenant.TenantID, tenant.Bytes); d > 0 {
			deltas[tenant.TenantID] = d
		}
	}

	if len(deltas) == 0 {
		return
	}

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()

	if err := s.opts.Panel.ReportRelayUsage(ctx, report.Relay, deltas); err != nil {
		// Logged and dropped. Retrying would mean holding the deltas, and a
		// queue of unbilled bytes on a coordinator that might itself restart
		// is a worse place for them than the relay's own cumulative counter,
		// which the next report carries anyway.
		s.opts.Logf("could not report usage for relay %s: %v", report.Relay, err)

		return
	}

	s.opts.Logf("reported usage for relay %s: %d tenant(s)", report.Relay, len(deltas))
}
