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
		// The overlay is not negotiable: without it the device is not on the
		// network. An advertised LAN is, and loses to a LAN the machine is
		// already sitting on.
		if route != plan.Overlay && occupiedElsewhere(ifaceName, route) {
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
		// If the routing table cannot be read, the safe answer is to leave it
		// alone. Guessing "nothing is there" is how a support laptop loses
		// the LAN it is sitting on.
		return true
	}

	for _, line := range strings.Split(strings.TrimSpace(string(out)), "\n") {
		if line == "" {
			continue
		}
		if dev := fieldAfter(line, "dev"); dev != "" && dev != ifaceName {
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
