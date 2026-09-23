//go:build windows

package winenv

import (
	"fmt"
	"os/exec"
	"strings"
	"syscall"
)

// OverlayRuleName is how the overlay's inbound rule appears in wf.msc.
const OverlayRuleName = "AKConnect Overlay (inbound from the overlay)"

// EnsureOverlayFirewall lets peers on the overlay reach this machine.
//
// Without this the tunnel works and nothing answers. Windows Firewall blocks
// inbound ICMP by default on every profile, and an unidentified network — which
// is what a new tunnel adapter is until Windows decides otherwise — gets the
// Public profile, the strictest one. So a pair of machines with a perfectly
// good WireGuard session, both showing the peer as connected, could not ping
// each other: the packets arrived and the firewall dropped them. The first
// real two-PC test spent an evening on that.
//
// Two changes, both scoped as narrowly as they can be:
//
//   - the adapter's own profile becomes Private, so Windows stops treating the
//     overlay as a coffee-shop network. This affects the tunnel adapter only;
//     the machine's real network keeps whatever profile it had.
//   - one inbound allow rule, for traffic from the overlay prefix, on that
//     interface alone.
//
// This is not a hole in the customer's firewall. What the overlay may reach is
// decided by the ACL engine inside the agent, which filters every packet in
// both directions and is where the panel's rules are actually enforced —
// Windows Firewall cannot express "this peer may reach tcp/554 on that
// machine" and was never the thing enforcing it. Leaving inbound blocked here
// does not add security; it stops the product working.
//
// Idempotent: the rule is removed and recreated, so an overlay whose range has
// changed does not leave the old prefix allowed.
func EnsureOverlayFirewall(ifaceName, overlayCIDR string) error {
	if ifaceName == "" || overlayCIDR == "" {
		return fmt.Errorf("the interface name and the overlay range are both needed")
	}
	if strings.ContainsAny(ifaceName, `"'`) || strings.ContainsAny(overlayCIDR, `"'`) {
		return fmt.Errorf("refusing to build a rule from %q/%q", ifaceName, overlayCIDR)
	}

	RemoveOverlayFirewall()

	// Private rather than Public. Done first: a rule scoped to an interface
	// applies whatever the profile, but a Public profile brings other defaults
	// with it that are not worth arguing with one rule at a time.
	if err := setInterfacePrivate(ifaceName); err != nil {
		// Worth reporting and not worth failing over: the interface-scoped
		// rule below is what actually admits the traffic.
		lastProfileError = err.Error()
	}

	return run("netsh", "advfirewall", "firewall", "add", "rule",
		"name="+OverlayRuleName,
		"dir=in",
		"action=allow",
		"protocol=any",
		"remoteip="+overlayCIDR,
		"interfacetype=any",
		"profile=any",
		"description=Inbound traffic from peers on the AKConnect overlay. What those peers "+
			"may actually reach is enforced by the agent's own ACL, not here.",
	)
}

// lastProfileError records why the adapter's profile could not be changed, for
// the agent to report in its status rather than swallow.
var lastProfileError string

// OverlayFirewallNote is why the overlay rule is imperfect, or empty when it
// is not.
func OverlayFirewallNote() string { return lastProfileError }

// RemoveOverlayFirewall takes the rule back out, on uninstall and before every
// re-add.
func RemoveOverlayFirewall() {
	_ = exec.Command("netsh", "advfirewall", "firewall", "delete", "rule",
		"name="+OverlayRuleName).Run()
}

// setInterfacePrivate moves one adapter into the Private profile.
//
// PowerShell rather than netsh, because netsh has no verb for this: the
// category of a network connection is a NetConnectionProfile, and Set-
// NetConnectionProfile is the only way to change it. Scoped by interface
// alias, so it is this adapter and no other.
func setInterfacePrivate(ifaceName string) error {
	statement := fmt.Sprintf(
		`$ErrorActionPreference = 'Stop'; `+
			`Set-NetConnectionProfile -InterfaceAlias '%s' -NetworkCategory Private`,
		ifaceName)

	cmd := exec.Command("powershell.exe",
		"-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", statement)
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}

	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("the overlay adapter could not be moved to the Private profile: %s",
			strings.TrimSpace(string(out)))
	}

	return nil
}
