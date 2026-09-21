package netcfg

import (
	"net/netip"
	"strings"
)

// Pointing the operating system at our resolver, for one zone and nothing else.
//
// The requirement is narrow and the narrowness is the point: names under the
// network's zone resolve through the agent, and every other name the machine
// looks up goes exactly where it went before. A customer's browsing, their
// bank, their email — none of it may be routed through us, or even be capable
// of being routed through us if something later goes wrong.
//
// That rules out the obvious implementation. Writing a nameserver into
// /etc/resolv.conf, or setting one on the adapter in Windows, makes us the
// resolver for *everything* and leaves us forwarding the rest. Two mechanisms
// do what is actually being asked:
//
//   - **systemd-resolved** routes a domain to a particular server per
//     interface (`~zone`), leaving everything else on the machine's own
//     servers.
//   - **NRPT** on Windows does the same thing, by namespace, and is what
//     Windows' own VPN clients use for split DNS.
//
// On Linux, where neither is available, the hosts file carries it: it is
// consulted before DNS, affects no other name, and touches no DNS
// configuration at all.
//
// **On Windows there is no fallback**, and that is deliberate. Defender
// reports hosts-file modification as `SettingsModifier:Win32/HostsFileHijack`
// and the antivirus products our customers run do the same. A fallback that
// worked perfectly would still put an alert on a customer's screen on install
// day, and one support call per install costs more than the feature is worth.
// Where NRPT refuses, names do not resolve, the agent says so, and the panel
// shows it.

// DNSPlan is what to point where.
type DNSPlan struct {
	// Interface is the tunnel interface. systemd-resolved scopes its
	// configuration to one; NRPT does not use it.
	Interface string
	// Zone is the domain to route, without dots at either end.
	Zone string
	// Resolver is where our own server is listening.
	Resolver netip.AddrPort
	// Entries are the names themselves, for the mechanism that cannot point at
	// a server and has to write the answers down.
	Entries []HostEntry
}

// DNSResult says what actually happened, because "we tried" is not a thing to
// report to a customer as working.
type DNSResult struct {
	// Applied is true only when the operating system is now routing the zone
	// to us.
	Applied bool
	// Mechanism names what did it — "systemd-resolved", "NRPT" — or, when
	// nothing did, why not.
	Mechanism string
	// Problem is a sentence for the panel, set when something went wrong that
	// a person has to fix. Empty when nothing did, including when the
	// mechanism simply is not present and the fallback took over.
	Problem string
}

// ApplyDNS routes one zone to our resolver, or reports that it could not.
//
// It returns an error only when something went wrong that should not have.
// "This machine has no mechanism for split DNS" is not an error: it is a
// result, and the agent carries on with everything else working.
func ApplyDNS(plan DNSPlan) (DNSResult, error) {
	if plan.Zone == "" || !plan.Resolver.IsValid() {
		return DNSResult{Mechanism: "no zone configured for this network"}, nil
	}

	return applyDNS(plan)
}

// RemoveDNS undoes it, so a stopped agent does not leave the machine sending
// queries to a resolver that is no longer listening.
func RemoveDNS(plan DNSPlan) {
	if plan.Zone == "" {
		return
	}

	removeDNS(plan)
}

// safeZone refuses a zone that could not be a domain, because both mechanisms
// take it as part of a command line.
func safeZone(zone string) string {
	zone = strings.ToLower(strings.Trim(strings.TrimSpace(zone), "."))

	for _, r := range zone {
		switch {
		case r >= 'a' && r <= 'z', r >= '0' && r <= '9', r == '-', r == '.':
		default:
			return ""
		}
	}

	return zone
}

// DResultApplied is the success case, named so the platform files read the
// same way.
func DResultApplied(mechanism string) DNSResult {
	return DNSResult{Applied: true, Mechanism: mechanism}
}
