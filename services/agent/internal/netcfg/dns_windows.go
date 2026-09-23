//go:build windows

package netcfg

import (
	"fmt"
)

// On Windows this is the Name Resolution Policy Table, and nothing else.
//
// NRPT is what Windows' own VPN clients use for split DNS: a rule names a
// namespace and the servers to use for it, and every other name the machine
// looks up is unaffected. Setting the adapter's nameserver instead would make
// us the resolver for everything, which is the thing this must not do.
//
// # Why there is no fallback here
//
// Linux falls back to the hosts file when systemd-resolved is missing. Windows
// deliberately does not, and the reason is commercial rather than technical.
//
// Microsoft Defender detects modification of the hosts file as
// `SettingsModifier:Win32/HostsFileHijack`, and every antivirus sold in this
// market does something similar. A fallback that works perfectly would still
// mean an alert on a customer's screen on the day we install, and one support
// call per install is a worse outcome than the feature not working.
//
// So on a machine where NRPT refuses the rule — group policy, a locked-down
// build, an MDM profile — names do not resolve, the agent says so, and the
// problem is reported to the panel where somebody can see it. Addresses still
// work. Nothing on the machine is touched that an antivirus will object to.
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
		// Reported, not worked around. The Problem text goes to the panel on
		// the next heartbeat, so this is visible to whoever can actually fix
		// it rather than only in a log on the customer's machine.
		return DNSResult{
			Mechanism: "Windows refused the NRPT rule, so names in the zone will not resolve",
			Problem: fmt.Sprintf(
				"Windows refused the DNS policy rule for .%s: %v. Names will not resolve on this "+
					"machine; addresses still work. This is usually group policy or an MDM profile "+
					"restricting the Name Resolution Policy Table. The agent will not edit the hosts "+
					"file instead, because antivirus software treats that as tampering.",
				zone, err),
		}, nil
	}

	return DResultApplied("NRPT"), nil
}

func removeDNS(plan DNSPlan) {
	_ = runPowerShell(fmt.Sprintf(
		`Get-DnsClientNrptRule | Where-Object { $_.Comment -eq '%s' } | `+
			`ForEach-Object { Remove-DnsClientNrptRule -Name $_.Name -Force -ErrorAction SilentlyContinue }`,
		nrptComment))
}
