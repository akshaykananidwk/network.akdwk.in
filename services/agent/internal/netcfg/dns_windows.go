//go:build windows

package netcfg

import (
	"fmt"
	"os"
	"path/filepath"
)

// On Windows this is the Name Resolution Policy Table.
//
// NRPT is what Windows' own VPN clients use for split DNS: a rule names a
// namespace and the servers to use for it, and every other name the machine
// looks up is unaffected. Setting the adapter's nameserver instead would make
// us the resolver for everything, which is the thing this must not do.
//
// The rule is tagged with a comment so removal can find exactly ours and leave
// an administrator's own rules — or another VPN's — alone. Deleting by
// namespace would be close enough most of the time, and wrong on the machine
// where it is not.
const nrptComment = "AKConnect split DNS"

func applyDNS(plan DNSPlan) (DNSResult, error) {
	zone := safeZone(plan.Zone)
	if zone == "" {
		return DNSResult{Mechanism: "the zone is not a usable domain name"}, nil
	}

	// Remove ours first. Add-DnsClientNrptRule happily creates a second rule
	// for the same namespace, and two rules for one namespace is a coin toss
	// over which server answers.
	removeDNS(plan)

	statement := fmt.Sprintf(
		`Add-DnsClientNrptRule -Namespace '.%s' -NameServers '%s' -Comment '%s' -ErrorAction Stop`,
		zone, plan.Resolver.Addr().String(), nrptComment)

	if err := runPowerShell(statement); err != nil {
		// NRPT can be unavailable or locked down by policy on a managed
		// machine. The hosts file works on every Windows there is and touches
		// no DNS configuration, so it is the fallback rather than a failure.
		if hostsErr := writeHosts(windowsHosts(), plan.Entries); hostsErr != nil {
			return DNSResult{}, fmt.Errorf("dns: NRPT refused the rule for .%s (%v) and %w", zone, err, hostsErr)
		}

		return DResultApplied("the hosts file (NRPT was not available)"), nil
	}

	// NRPT took it, so no stale hosts block should be left from a run where it
	// did not.
	_ = writeHosts(windowsHosts(), nil)

	return DResultApplied("NRPT"), nil
}

// windowsHosts is where Windows keeps its hosts file, which moves with the
// system root on a machine that was not installed to C:.
func windowsHosts() string {
	root := os.Getenv("SystemRoot")
	if root == "" {
		root = `C:\Windows`
	}

	return filepath.Join(root, "System32", "drivers", "etc", "hosts")
}

func removeDNS(plan DNSPlan) {
	_ = writeHosts(windowsHosts(), nil)

	_ = runPowerShell(fmt.Sprintf(
		`Get-DnsClientNrptRule | Where-Object { $_.Comment -eq '%s' } | `+
			`ForEach-Object { Remove-DnsClientNrptRule -Name $_.Name -Force -ErrorAction SilentlyContinue }`,
		nrptComment))
}
