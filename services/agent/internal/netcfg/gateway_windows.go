//go:build windows

package netcfg

import (
	"fmt"
	"net/netip"
	"os/exec"
	"strings"
)

// Gateway mode on Windows.
//
// Windows has no iptables, and its two routing facilities are not equivalent:
//
//   - "Routing and Remote Access" (RRAS) is the full router role. It is a
//     Windows Server feature, it is not present on the Windows 10 and 11
//     machines sitting on hotel reception desks, and requiring it would mean
//     requiring a server licence to share a camera recorder.
//   - Internet Connection Sharing does NAT on client Windows, but it picks its
//     own address range, cannot be pointed at a specific prefix, and fights
//     with anything else on the machine that uses 192.168.137.0/24.
//
// What client Windows does have is the same NAT engine RRAS uses, reachable
// through the Hyper-V networking PowerShell module as New-NetNat. It is
// present on Windows 10/11 Pro and Enterprise without the Hyper-V role being
// enabled, which covers the machines this product is sold onto.
//
// Forwarding itself is a per-interface registry setting, applied through
// netsh, plus the global IPEnableRouter. Both are set here.
//
// NONE OF THIS HAS RUN ON WINDOWS. It compiles, the commands are the
// documented ones, and the ordering matches what the runbook asks a tester to
// verify by hand. Treat it as untested until the field kit says otherwise —
// see services/kit/WINDOWS-RUNBOOK.md.

// natPrefix names the NAT instance so it can be found and removed again. A
// name rather than an address, because an operator reading Get-NetNat should
// be able to tell whose it is.
const natPrefix = "AKConnect-"

func applyGateway(plan *GatewayPlan) error {
	// Forwarding on the tunnel interface, and globally. The global switch
	// needs a reboot to take effect on some builds, which the runbook says to
	// check rather than assume.
	if err := run("netsh", "interface", "ipv4", "set", "interface",
		fmt.Sprintf("interface=%s", plan.Interface), "forwarding=enabled", "store=active"); err != nil {
		return fmt.Errorf("gateway: enabling forwarding on %s: %w", plan.Interface, err)
	}

	if len(plan.Advertised) == 0 {
		return nil
	}

	// ONE instance, named for the overlay.
	//
	// This used to create one per advertised prefix, each with the same
	// -InternalIPInterfaceAddressPrefix — the overlay. New-NetNat refuses an
	// internal prefix that overlaps an existing instance, and a prefix
	// overlaps itself, so the first call succeeded and every one after it
	// failed and failed the whole gateway. A device sharing one LAN worked; a
	// device sharing two shared neither.
	//
	// One is also what is correct. The NAT is defined by what is being
	// translated — traffic arriving from the overlay — not by where it is
	// going, and the destinations are already decided by the routes.
	name := natName(plan.Overlay)

	// Any instance left by a previous run goes first, for the same
	// overlap reason: an agent that crashed leaves exactly that behind.
	removeNat(name)

	if err := runPowerShell(fmt.Sprintf(
		`New-NetNat -Name '%s' -InternalIPInterfaceAddressPrefix '%s' -ErrorAction Stop`,
		name, plan.Overlay.String(),
	)); err != nil {
		return fmt.Errorf("gateway: creating NAT for %s: %w", plan.Overlay, err)
	}

	return nil
}

func removeGateway(plan *GatewayPlan) error {
	removeNat(natName(plan.Overlay))

	// Instances from before this was one-per-overlay, so upgrading a machine
	// that ran the old code does not leave NAT rules nobody will ever remove.
	for _, prefix := range plan.Advertised {
		removeNat(natName(prefix))
	}

	// Forwarding is left enabled, as on Linux: it may have been on before the
	// agent started and turning it off could break whatever else the machine
	// was doing.
	return nil
}

// natName is the instance name for a prefix. A name rather than an address,
// because an operator reading Get-NetNat should be able to tell whose it is.
func natName(prefix netip.Prefix) string {
	return natPrefix + strings.NewReplacer("/", "-", ".", "_").Replace(prefix.String())
}

func removeNat(name string) {
	_ = runPowerShell(fmt.Sprintf(`Remove-NetNat -Name '%s' -Confirm:$false -ErrorAction SilentlyContinue`, name))
}

// runPowerShell runs one statement with no profile and no execution-policy
// prompt, so it behaves the same on a locked-down machine as on a developer's.
func runPowerShell(statement string) error {
	cmd := exec.Command("powershell.exe",
		"-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass",
		"-Command", statement)

	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("%w: %s", err, strings.TrimSpace(string(out)))
	}

	return nil
}

// outputPowerShell runs a statement and returns what it printed, for the
// checks that need an answer rather than a success or failure.
func outputPowerShell(statement string) (string, error) {
	cmd := exec.Command("powershell.exe",
		"-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass",
		"-Command", statement)

	out, err := cmd.Output()
	if err != nil {
		return "", err
	}

	return string(out), nil
}
