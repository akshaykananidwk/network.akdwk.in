//go:build linux

package netcfg

import (
	"fmt"
	"os/exec"
)

// On Linux this is sysctl plus two iptables rules per advertised prefix.
//
// The rules are narrow on purpose. A FORWARD rule that accepted anything would
// turn the gateway into an open router for whatever else is on its LAN, and a
// MASQUERADE without a source match would translate the site's own traffic.
// Both are scoped to: from the overlay, to this prefix, on this interface.
func applyGateway(plan *GatewayPlan) error {
	if err := run("sysctl", "-qw", "net.ipv4.ip_forward=1"); err != nil {
		return fmt.Errorf("gateway: enabling IP forwarding: %w", err)
	}

	for _, prefix := range plan.Advertised {
		// Forward overlay traffic towards the LAN…
		if err := ensureRule("filter", "FORWARD",
			"-i", plan.Interface,
			"-s", plan.Overlay.String(),
			"-d", prefix.String(),
			"-j", "ACCEPT",
		); err != nil {
			return err
		}

		// …and let the answers back, but only for conversations that started
		// from the overlay. Without the state match this would be a way into
		// the tunnel from the LAN.
		if err := ensureRule("filter", "FORWARD",
			"-o", plan.Interface,
			"-s", prefix.String(),
			"-d", plan.Overlay.String(),
			"-m", "conntrack", "--ctstate", "ESTABLISHED,RELATED",
			"-j", "ACCEPT",
		); err != nil {
			return err
		}

		// The LAN device has no route back to the overlay, so traffic reaching
		// it must appear to come from the gateway.
		if err := ensureRule("nat", "POSTROUTING",
			"-s", plan.Overlay.String(),
			"-d", prefix.String(),
			"-j", "MASQUERADE",
		); err != nil {
			return err
		}
	}

	return nil
}

func removeGateway(plan *GatewayPlan) error {
	for _, prefix := range plan.Advertised {
		runTolerant("iptables", "-t", "filter", "-D", "FORWARD",
			"-i", plan.Interface, "-s", plan.Overlay.String(), "-d", prefix.String(), "-j", "ACCEPT")
		runTolerant("iptables", "-t", "filter", "-D", "FORWARD",
			"-o", plan.Interface, "-s", prefix.String(), "-d", plan.Overlay.String(),
			"-m", "conntrack", "--ctstate", "ESTABLISHED,RELATED", "-j", "ACCEPT")
		runTolerant("iptables", "-t", "nat", "-D", "POSTROUTING",
			"-s", plan.Overlay.String(), "-d", prefix.String(), "-j", "MASQUERADE")
	}

	// IP forwarding is left on. It may have been on before the agent started,
	// and turning it off could break whatever else the machine was doing —
	// a NAS, a hypervisor, a developer's container host.
	return nil
}

// ensureRule adds a rule only if it is not already there, so re-applying a
// configuration does not accumulate duplicates on every poll.
func ensureRule(table, chain string, spec ...string) error {
	// -C asks iptables whether the rule exists and says so in its exit code,
	// which is the only way to be idempotent without parsing its output.
	check := append([]string{"-t", table, "-C", chain}, spec...)
	if exec.Command("iptables", check...).Run() == nil {
		return nil
	}

	add := append([]string{"-t", table, "-A", chain}, spec...)
	if err := run("iptables", add...); err != nil {
		return fmt.Errorf("gateway: adding %s/%s rule: %w", table, chain, err)
	}

	return nil
}
