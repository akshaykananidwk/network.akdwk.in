//go:build linux

package netcfg

import (
	"fmt"
	"net/netip"
	"os/exec"
	"strings"
)

// On Linux everything goes through iproute2, which is present on every
// distribution the agent targets.
//
// Note what is *not* here: no `ip route add default`, no routing-table
// manipulation beyond the overlay's own prefixes, and no fiddling with
// rt_tables or fwmark. A split-tunnel agent has no business touching the
// default route, and the absence of the code is part of the guarantee (R1).
func applyPlan(ifaceName string, plan *Plan) ([]netip.Prefix, error) {
	if err := run("ip", "link", "set", "dev", ifaceName, "mtu", fmt.Sprint(plan.MTU)); err != nil {
		return nil, err
	}

	// Replace rather than add, so re-applying a configuration is idempotent
	// and a restart does not fail on "File exists".
	if err := run("ip", "address", "replace", plan.Address.String(), "dev", ifaceName); err != nil {
		return nil, err
	}

	if err := run("ip", "link", "set", "dev", ifaceName, "up"); err != nil {
		return nil, err
	}

	var refused []netip.Prefix
	installed := make([]netip.Prefix, 0, len(plan.Routes))

	for _, route := range plan.Routes {
		// Every prefix, the overlay included.
		//
		// An earlier version exempted the overlay as "not negotiable: without
		// it the device is not on the network". That is true and it is the
		// wrong conclusion. A customer whose office already runs 10.50.0.0/16
		// would have had the agent take that range over, cutting the machine
		// off from its own file server to join an overlay — and the
		// alternative, not joining, is the one a person would choose every
		// time. Refusing is visible and recoverable; taking over a working
		// network is neither.
		if occupiedElsewhere(ifaceName, route) {
			refused = append(refused, route)
			continue
		}

		if err := run("ip", "route", "replace", route.String(), "dev", ifaceName); err != nil {
			return nil, err
		}
		installed = append(installed, route)
	}

	plan.installed = installed

	return refused, nil
}

func removePlan(ifaceName string, plan *Plan) error {
	for _, route := range routesToRemove(plan) {
		runTolerant("ip", "route", "del", route.String(), "dev", ifaceName)
	}

	runTolerant("ip", "address", "del", plan.Address.String(), "dev", ifaceName)
	runTolerant("ip", "link", "set", "dev", ifaceName, "down")

	return nil
}

// occupiedElsewhere reports whether this exact prefix is already routed out of
// some other interface.
//
// `show exact` matches the prefix and nothing wider, which is what we want: a
// machine whose default route covers the address is not "already on" that
// network, and refusing the route on that basis would refuse every route.
func occupiedElsewhere(ifaceName string, prefix netip.Prefix) bool {
	out, err := exec.Command("ip", "-o", "route", "show", "exact", prefix.String()).Output()
	if err != nil {
		// A prefix is occupied only when a route for it was positively
		// observed somewhere else. This used to answer "occupied" on any
		// failure, on the reasoning that a routing table we cannot read is one
		// we do not overwrite — and the consequence of that is worse than the
		// risk: refusing the overlay leaves a device that reaches nothing,
		// with a message blaming the customer's network for a fault that is
		// ours. A machine genuinely on the range keeps working and can be
		// fixed by changing the network's CIDR.
		return false
	}

	for _, line := range strings.Split(strings.TrimSpace(string(out)), "\n") {
		if line == "" {
			continue
		}
		// Our own interface is not somebody else's. Compared case-insensitively
		// and against the whole line's device field, because the adapter we
		// just configured carries a route for its own address and counting
		// that as a clash is what blocked every peer on Windows.
		if dev := fieldAfter(line, "dev"); dev != "" && !strings.EqualFold(dev, ifaceName) {
			return true
		}
	}

	return false
}

// fieldAfter returns the token following key in an `ip -o` line.
func fieldAfter(line, key string) string {
	fields := strings.Fields(line)
	for i, f := range fields {
		if f == key && i+1 < len(fields) {
			return fields[i+1]
		}
	}

	return ""
}
