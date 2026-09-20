//go:build windows

package netcfg

import "fmt"

// On Windows the address and routes go on through netsh, which is present on
// every supported version and needs no extra install.
//
// As on Linux, there is deliberately no code here that could install a default
// route: no `0.0.0.0/0`, and no lowering of the interface metric to make the
// tunnel win against the physical adapter (R1).
func applyPlan(ifaceName string, plan *Plan) error {
	addr := plan.Address.Addr().String()

	// store=active keeps the configuration out of the persistent store, so a
	// crashed agent cannot leave an overlay address stranded on the adapter
	// across a reboot.
	if err := run("netsh", "interface", "ip", "set", "address",
		fmt.Sprintf("name=%s", ifaceName),
		"source=static",
		fmt.Sprintf("addr=%s", addr),
		fmt.Sprintf("mask=%s", hostMask(plan)),
		"store=active"); err != nil {
		return err
	}

	if err := run("netsh", "interface", "ipv4", "set", "subinterface",
		ifaceName, fmt.Sprintf("mtu=%d", plan.MTU), "store=active"); err != nil {
		return err
	}

	for _, route := range plan.Routes {
		if err := run("netsh", "interface", "ipv4", "add", "route",
			fmt.Sprintf("prefix=%s", route.String()),
			fmt.Sprintf("interface=%s", ifaceName),
			"store=active"); err != nil {
			return err
		}
	}

	return nil
}

func removePlan(ifaceName string, plan *Plan) error {
	for _, route := range plan.Routes {
		runTolerant("netsh", "interface", "ipv4", "delete", "route",
			fmt.Sprintf("prefix=%s", route.String()),
			fmt.Sprintf("interface=%s", ifaceName),
			"store=active")
	}

	runTolerant("netsh", "interface", "ip", "delete", "address",
		fmt.Sprintf("name=%s", ifaceName),
		fmt.Sprintf("addr=%s", plan.Address.Addr().String()),
		"store=active")

	return nil
}

// hostMask is the netmask for the device's own address. The overlay prefix is
// carried as an explicit route instead, so the address itself is a /32 and
// Windows does not invent an on-link route wider than we asked for.
func hostMask(plan *Plan) string {
	if plan.Address.Addr().Is4() {
		return "255.255.255.255"
	}

	return "128"
}
