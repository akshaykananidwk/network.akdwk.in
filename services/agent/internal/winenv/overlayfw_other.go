//go:build !windows

package winenv

// OverlayRuleName has no meaning off Windows but keeps the callers uniform.
const OverlayRuleName = ""

// EnsureOverlayFirewall is a no-op off Windows.
//
// Linux hosts vary too much for the agent to guess whether nftables, firewalld
// or nothing at all is in charge, and a distribution's default is to forward
// and accept on a new interface rather than to drop — so the failure this
// exists for does not happen there. See the Windows file for what does.
func EnsureOverlayFirewall(ifaceName, overlayCIDR string) error { return nil }

// OverlayFirewallNote is empty off Windows.
func OverlayFirewallNote() string { return "" }

// RemoveOverlayFirewall is a no-op off Windows.
func RemoveOverlayFirewall() {}
