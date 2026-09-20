//go:build windows

package winenv

import (
	"fmt"
	"os/exec"
	"strings"
)

// Windows Firewall prompts the first time a program listens on a socket, and
// the prompt appears on the interactive desktop. A service running as
// LocalSystem has no desktop, so nobody ever sees it and the rule is silently
// never created — the agent then looks like it is running while no peer can
// reach it.
//
// So the rule is created explicitly at install time rather than waited for.

// FirewallRuleName is how the rule appears in wf.msc.
const FirewallRuleName = "AKConnect Agent (WireGuard UDP)"

// EnsureFirewallRule allows inbound UDP on the agent's listen port.
//
// It is idempotent: an existing rule is deleted and recreated, so changing the
// port does not leave the old rule behind allowing traffic nobody expects.
func EnsureFirewallRule(port int) error {
	RemoveFirewallRule()

	return run("netsh", "advfirewall", "firewall", "add", "rule",
		"name="+FirewallRuleName,
		"dir=in",
		"action=allow",
		"protocol=UDP",
		fmt.Sprintf("localport=%d", port),
		"profile=any",
		"description=Inbound WireGuard and peer discovery for the AKConnect overlay network.",
	)
}

// RemoveFirewallRule takes it back out. Used on uninstall, and before adding,
// so the two never disagree.
func RemoveFirewallRule() {
	_ = exec.Command("netsh", "advfirewall", "firewall", "delete", "rule",
		"name="+FirewallRuleName).Run()
}

func run(name string, args ...string) error {
	out, err := exec.Command(name, args...).CombinedOutput()
	if err != nil {
		return fmt.Errorf("%s: %w: %s", name, err, strings.TrimSpace(string(out)))
	}

	return nil
}
