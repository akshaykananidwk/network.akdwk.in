//go:build !windows

package winenv

// FirewallRuleName has no meaning off Windows but keeps the callers uniform.
const FirewallRuleName = ""

// EnsureFirewallRule is a no-op. Linux hosts vary too much for the agent to
// guess whether nftables, firewalld or nothing at all is in charge, and
// silently rewriting a server's firewall is not a thing an agent should do
// uninvited. The install documentation states the port instead.
func EnsureFirewallRule(port int) error { return nil }

// RemoveFirewallRule is a no-op off Windows.
func RemoveFirewallRule() {}
