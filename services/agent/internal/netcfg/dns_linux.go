//go:build linux

package netcfg

import (
	"os/exec"
	"strings"
)

// linuxHosts is a variable so a test can point it somewhere harmless. Nothing
// in the product changes it.
var linuxHosts = "/etc/hosts"

// On Linux this is systemd-resolved, or nothing.
//
// `resolvectl domain <iface> ~zone` marks the zone as *routing-only* for that
// interface: queries for it go to that interface's server, and queries for
// anything else are not affected at all. That is precisely the requirement,
// and it is the only mechanism in common use on Linux that meets it.
//
// The alternative — editing /etc/resolv.conf — would make us the resolver for
// everything the machine looks up and leave us forwarding the rest. A
// networking agent that quietly becomes the customer's DNS is not a feature,
// so on a machine without systemd-resolved this configures nothing and says
// which mechanism was missing.
func applyDNS(plan DNSPlan) (DNSResult, error) {
	zone := safeZone(plan.Zone)
	if zone == "" {
		return DNSResult{Mechanism: "the zone is not a usable domain name"}, nil
	}

	if !resolvedAvailable() {
		// No systemd-resolved. Fall back to the hosts file, which resolves the
		// zone for everything on the machine and touches no DNS configuration
		// at all — see hosts.go. Telling a customer to install
		// systemd-resolved is not an answer.
		if err := writeHosts(linuxHosts, plan.Entries); err != nil {
			return DNSResult{Mechanism: "systemd-resolved is absent and " + err.Error()}, nil
		}

		return DResultApplied("the hosts file (systemd-resolved is not available here)"), nil
	}

	// systemd-resolved is there, so use it and make sure no stale hosts block
	// is left behind from a run where it was not.
	_ = writeHosts(linuxHosts, nil)

	// The server first, then the domain: a domain routed to an interface with
	// no server on it would black-hole the zone rather than leave it alone.
	if err := run("resolvectl", "dns", plan.Interface, plan.Resolver.Addr().String()); err != nil {
		return DNSResult{}, err
	}

	if err := run("resolvectl", "domain", plan.Interface, "~"+zone); err != nil {
		// Leave nothing half-configured: an interface with a server and no
		// routing domain is harmless, but an interface that was about to get
		// one and did not should not keep the server either.
		runTolerant("resolvectl", "revert", plan.Interface)

		return DNSResult{}, err
	}

	return DResultApplied("systemd-resolved"), nil
}

func removeDNS(plan DNSPlan) {
	// Both, unconditionally: whichever mechanism was used, neither should be
	// left behind, and removing one that was never applied is a no-op.
	_ = writeHosts(linuxHosts, nil)

	if _, err := exec.LookPath("resolvectl"); err != nil {
		return
	}

	// revert puts the interface back to its defaults, which is exactly what we
	// want and is safe on an interface that is about to disappear anyway.
	runTolerant("resolvectl", "revert", plan.Interface)
}

// resolvedAvailable reports whether systemd-resolved is both installed and
// running.
func resolvedAvailable() bool {
	if _, err := exec.LookPath("resolvectl"); err != nil {
		return false
	}

	return resolvedRunning()
}

// resolvedRunning asks the service rather than assuming. `resolvectl status`
// fails when the daemon is not there, which is the cheapest reliable check and
// does not need systemctl to exist.
func resolvedRunning() bool {
	out, err := exec.Command("resolvectl", "status").CombinedOutput()
	if err != nil {
		return false
	}

	return !strings.Contains(string(out), "not been configured")
}
