//go:build linux

package netcfg

import "fmt"

// On Linux everything goes through iproute2, which is present on every
// distribution the agent targets.
//
// Note what is *not* here: no `ip route add default`, no routing-table
// manipulation beyond the overlay's own prefixes, and no fiddling with
// rt_tables or fwmark. A split-tunnel agent has no business touching the
// default route, and the absence of the code is part of the guarantee (R1).
func applyPlan(ifaceName string, plan *Plan) error {
	if err := run("ip", "link", "set", "dev", ifaceName, "mtu", fmt.Sprint(plan.MTU)); err != nil {
		return err
	}

	// Replace rather than add, so re-applying a configuration is idempotent
	// and a restart does not fail on "File exists".
	if err := run("ip", "address", "replace", plan.Address.String(), "dev", ifaceName); err != nil {
		return err
	}

	if err := run("ip", "link", "set", "dev", ifaceName, "up"); err != nil {
		return err
	}

	for _, route := range plan.Routes {
		if err := run("ip", "route", "replace", route.String(), "dev", ifaceName); err != nil {
			return err
		}
	}

	return nil
}

func removePlan(ifaceName string, plan *Plan) error {
	for _, route := range plan.Routes {
		runTolerant("ip", "route", "del", route.String(), "dev", ifaceName)
	}

	runTolerant("ip", "address", "del", plan.Address.String(), "dev", ifaceName)
	runTolerant("ip", "link", "set", "dev", ifaceName, "down")

	return nil
}
