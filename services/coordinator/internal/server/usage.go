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
	// held is usage the panel could not be told about yet, carried into the
	// next report. delta() advances the mark whether or not the report is
	// delivered, so without this a failed report loses those bytes for good —
	// which a panel deployment did, for every byte relayed while it answered
	// 503.
	held map[usageKey]uint64
}

type usageKey struct {
	relay  string
	tenant uint64
}

func newUsageLedger() *usageLedger {
	return &usageLedger{
		seen: make(map[usageKey]uint64),
		held: make(map[usageKey]uint64),
	}
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

	// Anything a previous attempt could not deliver goes with this one.
	s.usage.addHeld(report.Relay, deltas)

	if len(deltas) == 0 {
		return
	}

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()

	if err := s.opts.Panel.ReportRelayUsage(ctx, report.Relay, deltas); err != nil {
		// Held, not dropped.
		//
		// The comment here used to say dropping was safe because the relay's
		// cumulative counter would carry the bytes in the next report. It
		// does carry them — but s.usage.delta has already advanced this
		// coordinator's mark past them, so the next delta starts from the new
		// figure and those bytes are never billed to anybody. A panel
		// answering 503 for ninety seconds during a deployment silently lost
		// every byte relayed in that window.
		//
		// They are added to the next attempt instead, and only cleared once
		// the panel has actually taken them.
		s.usage.hold(report.Relay, deltas)
		s.opts.Logf("could not report usage for relay %s: %v (holding %d tenant(s) for the next report)",
			report.Relay, err, len(deltas))

		return
	}

	s.usage.delivered(report.Relay)

	s.opts.Logf("reported usage for relay %s: %d tenant(s)", report.Relay, len(deltas))
}

// heldCap bounds what one relay and tenant may accumulate while the panel is
// unreachable.
//
// A terabyte. Far more than any plausible outage carries, and a ceiling so
// that a coordinator cut off for a week does not grow without limit or send a
// number that overflows whatever the panel stores it in.
const heldCap uint64 = 1 << 40

// hold keeps usage the panel would not take: exactly what was sent, which is
// the whole debt.
//
// It replaces what was held rather than adding to it. The deltas that failed
// already carry everything held before them — addHeld folded it in — so adding
// counted the old debt a second time, and it doubled on every consecutive
// failure: ten failed reports of 100 new bytes each held 102,300 bytes for
// 1,000 relayed, and billed them when the panel came back.
func (l *usageLedger) hold(relay string, deltas map[uint64]uint64) {
	l.mu.Lock()
	defer l.mu.Unlock()

	if l.held == nil {
		l.held = make(map[usageKey]uint64)
	}

	for tenant, bytes := range deltas {
		key := usageKey{relay: relay, tenant: tenant}

		if bytes > heldCap {
			bytes = heldCap
		}

		l.held[key] = bytes
	}
}

// addHeld folds anything held for this relay into the deltas about to be sent.
func (l *usageLedger) addHeld(relay string, deltas map[uint64]uint64) {
	l.mu.Lock()
	defer l.mu.Unlock()

	for key, bytes := range l.held {
		if key.relay != relay || bytes == 0 {
			continue
		}

		deltas[key.tenant] += bytes
	}
}

// delivered clears what the panel has now accepted for this relay.
//
// Only after a successful report, which is the whole point: the bytes stay
// owed until somebody has actually taken them.
func (l *usageLedger) delivered(relay string) {
	l.mu.Lock()
	defer l.mu.Unlock()

	for key := range l.held {
		if key.relay == relay {
			delete(l.held, key)
		}
	}
}
