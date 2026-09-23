//go:build !windows

package winenv

// FirewallRuleName has no meaning off Windows but keeps the callers uniform.
const FirewallRuleName = ""

// EnsureFirewallRules is a no-op. Linux hosts vary too much for the agent to
// edit their firewalls unasked — nftables, firewalld, ufw and plain iptables
// all disagree — and a Linux administrator installing an overlay knows how to
// open a port. Windows is where the rule is invisible and the failure silent.
func EnsureFirewallRules(string) error { return nil }

// RemoveFirewallRule is a no-op off Windows.
func RemoveFirewallRule() {}
