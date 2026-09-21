//go:build windows

package netcfg

import (
	"fmt"
	"net/netip"
	"strings"
)

// On Windows the address and routes go on through netsh, which is present on
// every supported version and needs no extra install.
//
// As on Linux, there is deliberately no code here that could install a default
// route: no `0.0.0.0/0`, and no lowering of the interface metric to make the
// tunnel win against the physical adapter (R1).
func applyPlan(ifaceName string, plan *Plan) ([]netip.Prefix, error) {
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
		return nil, err
	}

	if err := run("netsh", "interface", "ipv4", "set", "subinterface",
		ifaceName, fmt.Sprintf("mtu=%d", plan.MTU), "store=active"); err != nil {
		return nil, err
	}

	var refused []netip.Prefix
	installed := make([]netip.Prefix, 0, len(plan.Routes))

	for _, route := range plan.Routes {
		// The overlay too — see the note in apply_linux.go. A machine already
		// on the range an overlay uses must keep it.
		if occupiedElsewhere(ifaceName, route) {
			refused = append(refused, route)
			continue
		}

		// set-then-add: `add route` fails if the prefix is already on this
		// interface from a previous run, and there is no replace verb.
		if err := run("netsh", "interface", "ipv4", "set", "route",
			fmt.Sprintf("prefix=%s", route.String()),
			fmt.Sprintf("interface=%s", ifaceName),
			"store=active"); err != nil {
			if err := run("netsh", "interface", "ipv4", "add", "route",
				fmt.Sprintf("prefix=%s", route.String()),
				fmt.Sprintf("interface=%s", ifaceName),
				"store=active"); err != nil {
				return nil, err
			}
		}
		installed = append(installed, route)
	}

	plan.installed = installed

	return refused, nil
}

func removePlan(ifaceName string, plan *Plan) error {
	for _, route := range routesToRemove(plan) {
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

// occupiedElsewhere reports whether this exact prefix is already routed out of
// some other adapter.
//
// Get-NetRoute rather than `netsh interface ipv4 show route`, because netsh
// prints localised column headings and the agent has to work on a Hindi or
// Gujarati Windows install as well as an English one. Get-NetRoute returns
// objects, so nothing is parsed out of translated text.
func occupiedElsewhere(ifaceName string, prefix netip.Prefix) bool {
	if strings.ContainsAny(ifaceName, "'`$") {
		// Our own interface name, so this should not happen; refusing to build
		// a statement out of it is cheaper than reasoning about whether it
		// can.
		return true
	}

	statement := fmt.Sprintf(
		`$r = Get-NetRoute -DestinationPrefix '%s' -ErrorAction SilentlyContinue | `+
			`Where-Object { $_.InterfaceAlias -ne '%s' }; if ($r) { Write-Output 'occupied' }`,
		prefix.String(), ifaceName)

	out, err := outputPowerShell(statement)
	if err != nil {
		// Same reasoning as on Linux: a routing table we cannot read is one we
		// do not overwrite.
		return true
	}

	return strings.Contains(out, "occupied")
}
