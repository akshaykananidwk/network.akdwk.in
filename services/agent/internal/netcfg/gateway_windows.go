//go:build windows

package netcfg

import (
	"fmt"
	"net"
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
// Forwarding on Windows needs three things, and until 1.9.7 this set one of
// them. A field test shared a LAN, saw it mapped, and got a timeout from the
// other end of the tunnel:
//
//   - forwarding on the TUNNEL interface, which was set;
//   - forwarding on the LAN interface as well. Windows routes between two
//     interfaces only when both have it. Packets arrived on the tunnel with
//     the destination already rewritten to the real LAN address, and were
//     dropped on the way out because the Ethernet adapter was not forwarding.
//     That is a silent drop: it looks exactly like a firewall on the far
//     device, which is where anybody would look first and where nothing is
//     wrong.
//   - the global IPEnableRouter. The comment here used to say "plus the
//     global IPEnableRouter. Both are set here", and the string appeared
//     nowhere else in the tree. It was never set.
//
// store=persistent as well as active, because a reception-desk PC is rebooted
// nightly and a gateway that stops routing every morning until somebody logs
// in is not a gateway.
//
// Tailscale's subnet routers need the same thing — its documentation makes
// enabling IP forwarding the first step, and its --snat-subnet-routes flag is
// Linux-only precisely because Windows has no equivalent knob. The difference
// is that Tailscale tells you to do it and fails loudly; this now does it and
// reports which part it could not do.

// natPrefix names the NAT instance so it can be found and removed again. A
// name rather than an address, because an operator reading Get-NetNat should
// be able to tell whose it is.
const natPrefix = "AKConnect-"

func applyGateway(plan *GatewayPlan) error {
	// The tunnel, where the packets arrive.
	if err := enableForwarding(plan.Interface); err != nil {
		return err
	}

	// And every interface that owns an advertised LAN, where they leave.
	// Windows forwards between two interfaces only when both are set, and
	// leaving this one out is a drop with no message anywhere.
	for _, iface := range lanInterfacesFor(plan.Advertised) {
		if err := enableForwarding(iface); err != nil {
			return err
		}
	}

	// The global switch. Some builds want a reboot before it takes effect,
	// which is why the per-interface settings above are not left to it.
	if err := runPowerShell(
		`Set-ItemProperty -Path 'HKLM:\\SYSTEM\\CurrentControlSet\\Services\\Tcpip\\Parameters' ` +
			`-Name 'IPEnableRouter' -Value 1 -Type DWord -ErrorAction Stop`,
	); err != nil {
		return fmt.Errorf("gateway: enabling IPEnableRouter: %w", err)
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

// enableForwarding turns routing on for one interface, now and after a reboot.
func enableForwarding(iface string) error {
	for _, store := range []string{"active", "persistent"} {
		if err := run("netsh", "interface", "ipv4", "set", "interface",
			fmt.Sprintf("interface=%s", iface), "forwarding=enabled",
			fmt.Sprintf("store=%s", store)); err != nil {
			return fmt.Errorf("gateway: enabling forwarding on %s (%s): %w", iface, store, err)
		}
	}

	return nil
}

// lanInterfacesFor names the local interfaces that own the advertised LANs.
//
// Found from the machine's own addresses rather than asked for in the plan,
// because the panel knows which range is shared and has no idea which adapter
// the site's cable is in — and on a reception-desk PC it can be an Ethernet
// port, a Wi-Fi card, or a USB dongle somebody plugged in this morning.
func lanInterfacesFor(advertised []netip.Prefix) []string {
	ifaces, err := net.Interfaces()
	if err != nil {
		return nil
	}

	var names []string
	seen := map[string]bool{}

	for _, iface := range ifaces {
		addrs, err := iface.Addrs()
		if err != nil {
			continue
		}

		for _, raw := range addrs {
			ipnet, ok := raw.(*net.IPNet)
			if !ok {
				continue
			}

			addr, ok := netip.AddrFromSlice(ipnet.IP.To4())
			if !ok {
				continue
			}

			for _, prefix := range advertised {
				if prefix.Contains(addr) && !seen[iface.Name] {
					seen[iface.Name] = true
					names = append(names, iface.Name)
				}
			}
		}
	}

	return names
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
