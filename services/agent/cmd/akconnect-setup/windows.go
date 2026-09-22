//go:build windows

package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"

	"golang.org/x/sys/windows"
)

func requireWindows() error { return nil }

// isElevated asks the token rather than guessing from the username. A member
// of Administrators running without elevation is the normal case on Windows,
// and it looks exactly like an administrator until something is written.
func isElevated() (bool, error) {
	var token windows.Token
	if err := windows.OpenProcessToken(windows.CurrentProcess(), windows.TOKEN_QUERY, &token); err != nil {
		return false, err
	}
	defer token.Close()

	return token.IsElevated(), nil
}

// relaunchElevated asks Windows to start this same file again, elevated.
//
// "runas" is what produces the UAC prompt. The original process exits
// immediately: waiting for the elevated one would leave a second copy in the
// task list for no reason, and the elevated copy owns the dialogs from here.
func relaunchElevated() error {
	exe, err := os.Executable()
	if err != nil {
		return fmt.Errorf("could not work out where this program is: %w", err)
	}

	// The flags are passed through, so -uninstall and -code survive the
	// elevation. Quoted individually, because a path with a space in it is the
	// normal case on Windows.
	args := make([]string, 0, len(os.Args)-1)
	for _, arg := range os.Args[1:] {
		args = append(args, quoteArg(arg))
	}

	verb, _ := syscall.UTF16PtrFromString("runas")
	file, _ := syscall.UTF16PtrFromString(exe)
	params, _ := syscall.UTF16PtrFromString(strings.Join(args, " "))
	cwd, _ := syscall.UTF16PtrFromString(filepath.Dir(exe))

	if err := windows.ShellExecute(0, verb, file, params, cwd, windows.SW_NORMAL); err != nil {
		// The usual cause is the customer clicking "No" on the UAC prompt,
		// which is a decision rather than a fault.
		return fmt.Errorf(
			"this needs to run as administrator, and Windows did not allow it (%w).\n\n"+
				"Right-click %s and choose \"Run as administrator\".",
			err, filepath.Base(exe))
	}

	os.Exit(0)

	return nil
}

// quoteArg wraps an argument so a space or a quote inside it survives.
func quoteArg(arg string) string {
	if arg == "" {
		return `""`
	}
	if !strings.ContainsAny(arg, ` "`) {
		return arg
	}

	return `"` + strings.ReplaceAll(arg, `"`, `\"`) + `"`
}

// relaunchFromTemp restarts this uninstaller from a copy outside the program
// folder, and reports whether it did.
//
// Because the uninstaller is inside the folder it has to delete. Windows will
// not delete a running program, so an uninstall started from Settings — which
// runs the copy in Program Files, by design — would remove everything except
// itself and then report a folder it could not remove. Every real installer
// does this; it is why an uninstall briefly shows a program running from a
// temporary folder.
func relaunchFromTemp() (bool, error) {
	self, err := os.Executable()
	if err != nil {
		return false, nil
	}

	dir := installDir()
	if !strings.HasPrefix(strings.ToLower(self), strings.ToLower(dir)) {
		// Already running from somewhere else — the customer's Downloads
		// folder, or the temporary copy this function made a moment ago.
		return false, nil
	}

	content, err := os.ReadFile(self)
	if err != nil {
		return false, fmt.Errorf("could not read %s: %w", self, err)
	}

	temp := filepath.Join(os.TempDir(), productName+"-uninstall.exe")
	if err := os.WriteFile(temp, content, 0o755); err != nil {
		return false, fmt.Errorf("could not write %s: %w", temp, err)
	}

	cmd := exec.Command(temp, os.Args[1:]...)
	if err := cmd.Start(); err != nil {
		return false, fmt.Errorf("could not start %s: %w", temp, err)
	}

	// Not waited for. This process has to exit for its own file to become
	// deletable, which is the entire point.
	return true, nil
}

// removeServiceDirectly deletes the service without needing our binary.
//
// The agent's own `service uninstall` is tried first and is the better path,
// because it knows the names it registered. This is what happens on a machine
// where the binary is already gone — somebody deleted the folder, and the
// service registration is still there pointing at nothing.
func removeServiceDirectly() error {
	manager, err := windows.UTF16PtrFromString("")
	if err != nil {
		return err
	}
	_ = manager

	// Done through sc.exe rather than the service API: this runs once, at
	// uninstall, and sc.exe reports "service does not exist" in a way that is
	// easy to treat as success.
	out, err := exec.Command("sc.exe", "delete", serviceName).CombinedOutput()
	if err == nil {
		return nil
	}

	text := string(out)
	if strings.Contains(text, "1060") || strings.Contains(strings.ToLower(text), "does not exist") {
		// Already gone, which is the outcome we wanted.
		return nil
	}

	return fmt.Errorf("%s", strings.TrimSpace(text))
}

// serviceImagePath is the program a registered service would run, or "" when
// there is no such service.
//
// Asked of the service manager rather than assumed from where this installer
// happens to be. A machine that has been through a failed install, a moved
// folder or a hand-editing session can have a service registered against a
// path that no longer exists — and every attempt to stop, start or repoint it
// then fails in a way that reads like our software being broken.
func serviceImagePath() string {
	out, err := exec.Command("sc.exe", "qc", serviceName).CombinedOutput()
	if err != nil {
		return ""
	}

	for _, line := range strings.Split(string(out), "\n") {
		if !strings.Contains(line, "BINARY_PATH_NAME") {
			continue
		}

		_, value, ok := strings.Cut(line, ":")
		if !ok {
			continue
		}

		return strings.Trim(strings.TrimSpace(value), `"`)
	}

	return ""
}

// removeFirewallRule takes away every rule any version of this has created.
//
// Every version, and that is the point. The agent's own `service uninstall`
// knows the names it registered and is tried first; this is the path on a
// machine where the binary is already gone, and there the list has to be
// written down. An uninstall that leaves a firewall rule behind is the sort of
// thing a customer's IT person finds months later and forms an opinion about,
// and the rules changed shape in 1.9.6 — from one naming a port to four naming
// the program.
//
// Spelled out here rather than imported because this binary is the installer
// and does not otherwise link the agent's packages. The names must match
// winenv.FirewallRuleName and the table beside it.
func removeFirewallRule() error {
	names := []string{
		// 1.9.0 to 1.9.5: one rule, naming a port.
		"AKConnect Agent (WireGuard UDP)",
		// 1.9.6 onwards: four, naming the program.
		"AK Connect (inbound UDP)",
		"AK Connect (inbound TCP)",
		"AK Connect (outbound UDP)",
		"AK Connect (outbound TCP)",
	}

	var failures []string

	for _, name := range names {
		out, err := exec.Command("netsh", "advfirewall", "firewall", "delete", "rule",
			"name="+name).CombinedOutput()
		if err == nil || strings.Contains(strings.ToLower(string(out)), "no rules match") {
			continue
		}

		failures = append(failures, name+": "+strings.TrimSpace(string(out)))
	}

	if failures != nil {
		return fmt.Errorf("%s", strings.Join(failures, "; "))
	}

	return nil
}

// removeOverlayFirewallRule takes away the inbound rule for the overlay.
//
// Named rather than matched loosely, so an administrator's own rules are left
// alone. The name is winenv.OverlayRuleName; it is spelled out here because
// this binary is the installer and does not otherwise link the agent's
// packages.
func removeOverlayFirewallRule() error {
	out, err := exec.Command("netsh", "advfirewall", "firewall", "delete", "rule",
		"name=AKConnect Overlay (inbound from the overlay)").CombinedOutput()
	if err == nil {
		return nil
	}

	if strings.Contains(strings.ToLower(string(out)), "no rules match") {
		return nil
	}

	return fmt.Errorf("%s", strings.TrimSpace(string(out)))
}

// removeNrptRules takes away the DNS policy rules the agent added.
//
// Matched by our own comment so an administrator's rules, or another VPN's,
// are left alone. Deleting by namespace would be close enough most of the time
// and wrong on the machine where it is not.
func removeNrptRules() error {
	return powershell(
		`Get-DnsClientNrptRule -ErrorAction SilentlyContinue | ` +
			`Where-Object { $_.Comment -eq 'AKConnect split DNS' } | ` +
			`ForEach-Object { Remove-DnsClientNrptRule -Name $_.Name -Force -ErrorAction SilentlyContinue }`)
}

// removeWintunAdapter removes the network adapter this agent created.
//
// This is the step people forget. The adapter normally disappears when the
// agent exits, but a hard kill leaves it, and after an uninstall there is
// nothing left to clean it up — so a customer is left with a network adapter
// named after software they no longer have.
//
// OURS, and only ours. This matched '*Wintun*' as well, which is not our name:
// it is the name of the driver, and WireGuard's own client, Tailscale and
// several others create adapters on the same driver. On a machine running any
// of them, uninstalling AKConnect would have taken their network adapter away
// with it — from software the customer still uses, without mentioning it.
//
// The adapter is ours when it is called what we call ours. Nothing else is
// safe to infer: the driver is shared by design.
func removeWintunAdapter() error {
	return powershell(adapterQuery(adapterName))
}

func powershell(statement string) error {
	cmd := exec.Command("powershell.exe",
		"-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", statement)
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}

	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("%w: %s", err, strings.TrimSpace(string(out)))
	}

	return nil
}
