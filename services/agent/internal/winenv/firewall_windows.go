//go:build windows

package winenv

import (
	"fmt"
	"os"
	"os/exec"
	"strings"
)

// Windows Firewall prompts the first time a program listens on a socket, and
// the prompt appears on the interactive desktop. A service running as
// LocalSystem has no desktop, so nobody ever sees it and the rule is silently
// never created — the agent then looks like it is running while no peer can
// reach it.
//
// So the rules are created explicitly at install time rather than waited for.
//
// They name the PROGRAM, not a port, and that is the whole lesson of 1.9.5.
// Each device picks its own UDP port and moves off one a router will not
// carry, so a rule naming a number is wrong the moment either happens — and
// it happened: every Windows device that moved port became unreachable
// inbound while its own firewall still held a rule for the port it had left.
// A rule naming the executable is right whatever port it ends up on, and it
// is also what the fallback needs, because that path is TCP.

// FirewallRuleName is the prefix every rule shares, so they group together in
// wf.msc and so removal can find them all.
const FirewallRuleName = "AK Connect"

// firewallRules is what gets created: both protocols, both directions.
//
// Outbound looks redundant, because Windows allows outbound by default. It is
// not: a machine with outbound filtering on — which is ordinary in a managed
// fleet — otherwise fails in the one way this product has no answer for, with
// every send refused and nothing to say why.
var firewallRules = []struct {
	suffix, dir, protocol string
}{
	{"inbound UDP", "in", "UDP"},
	{"inbound TCP", "in", "TCP"},
	{"outbound UDP", "out", "UDP"},
	{"outbound TCP", "out", "TCP"},
}

// EnsureFirewallRules allows the agent through the firewall on every profile.
//
// exe is the program to allow; empty means this one. Idempotent: the rules are
// removed and recreated, so an upgrade that moved the executable does not
// leave a rule pointing at a path that no longer exists.
func EnsureFirewallRules(exe string) error {
	if exe == "" {
		self, err := os.Executable()
		if err != nil {
			return fmt.Errorf("could not find this program's own path: %w", err)
		}
		exe = self
	}

	RemoveFirewallRule()

	for _, rule := range firewallRules {
		err := run("netsh", "advfirewall", "firewall", "add", "rule",
			"name="+FirewallRuleName+" ("+rule.suffix+")",
			"dir="+rule.dir,
			"action=allow",
			"protocol="+rule.protocol,
			"program="+exe,
			"profile=any",
			"description=AK Connect overlay networking. Allows the agent itself rather than "+
				"a port number, because each device chooses its own port and changes it when a "+
				"router will not carry one.",
		)
		if err != nil {
			return err
		}
	}

	return nil
}

// RemoveFirewallRule takes them all back out, including the port-named rule
// that versions up to 1.9.5 created — an upgrade must not leave behind a rule
// allowing inbound UDP on a port this device no longer uses.
func RemoveFirewallRule() {
	names := []string{
		"AKConnect Agent (WireGuard UDP)", // 1.9.0 to 1.9.5
	}
	for _, rule := range firewallRules {
		names = append(names, FirewallRuleName+" ("+rule.suffix+")")
	}

	for _, name := range names {
		_ = exec.Command("netsh", "advfirewall", "firewall", "delete", "rule", "name="+name).Run()
	}
}

func run(name string, args ...string) error {
	out, err := exec.Command(name, args...).CombinedOutput()
	if err != nil {
		return fmt.Errorf("%s: %w: %s", name, err, strings.TrimSpace(string(out)))
	}

	return nil
}
