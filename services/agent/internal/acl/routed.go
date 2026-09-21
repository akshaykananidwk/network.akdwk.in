package acl

import (
	"net/netip"
	"sort"
	"strings"
)

// Routed destinations — subnet-router mode.
//
// A gateway device advertises a LAN prefix, and traffic for that prefix goes
// through it to machines that cannot run an agent: an NVR, a printer, a DVR.
// Those machines have no overlay address and are not peers, so the peer table
// has nothing to say about them — and without this, the filter would refuse
// every packet to a routed destination as "not a permitted peer", which is the
// correct answer to the wrong question.
//
// Rules for routed traffic name the destination by LAN address, because that
// is how an operator thinks about it: "AK Support may reach 192.168.1.50 on
// tcp/554" is about the NVR, not about the PC that happens to be routing for
// it. So a route carries its own filter set, and the most specific prefix
// covering a destination decides.

// Route is one advertised prefix and the rules that govern traffic to it.
type Route struct {
	Prefix netip.Prefix
	// Via is the gateway peer's overlay address, so the filter can confirm
	// that routed traffic is actually going through the device that
	// advertised the route rather than being sprayed at the overlay.
	Via     netip.Addr
	Filters []Filter
}

// AddRoute records an advertised prefix.
//
// Prefixes are kept sorted longest-first, so a specific rule for one machine
// beats a general one for the subnet it sits in. An operator who writes "the
// LAN is reachable, except the NVR" means the exception.
func (t *Table) AddRoute(cidr, via string, filters []Filter) {
	prefix, err := netip.ParsePrefix(strings.TrimSpace(cidr))
	if err != nil {
		return
	}

	gateway, err := netip.ParseAddr(strings.TrimSpace(via))
	if err != nil {
		// A route with no usable gateway cannot be honoured, and recording it
		// would mean accepting traffic for a prefix nothing can deliver.
		return
	}

	sorted := append([]Filter(nil), filters...)
	sort.SliceStable(sorted, func(i, j int) bool { return sorted[i].RuleID < sorted[j].RuleID })

	t.routes = append(t.routes, Route{
		Prefix:  prefix.Masked(),
		Via:     gateway.Unmap(),
		Filters: sorted,
	})

	sort.SliceStable(t.routes, func(i, j int) bool {
		return t.routes[i].Prefix.Bits() > t.routes[j].Prefix.Bits()
	})
}

// routeFor returns the most specific advertised prefix covering an address.
func (t *Table) routeFor(addr netip.Addr) (Route, bool) {
	for _, r := range t.routes {
		if r.Prefix.Contains(addr) {
			return r, true
		}
	}

	return Route{}, false
}

// Routes is how many advertised prefixes this device knows about.
func (t *Table) Routes() int { return len(t.routes) }

// GatewayFor reports which peer routes for an address, if any. Used by the
// gateway itself to decide whether a packet it is forwarding is one it
// advertised a route for.
func (t *Table) GatewayFor(addr netip.Addr) (netip.Addr, bool) {
	route, ok := t.routeFor(addr.Unmap())
	if !ok {
		return netip.Addr{}, false
	}

	return route.Via, true
}
