package main

import (
	"fmt"
	"os"
	"strings"
	"time"
)

// Removing everything, in the order that lets each step work.
//
// "Leaves nothing behind" is a requirement, not an aspiration, and the parts
// people forget are the ones that are not files: a service registration, a
// firewall rule, a DNS policy rule, and a network adapter that a driver
// created. A tester checks each one, because an uninstall that leaves an
// adapter or a policy rule is the kind of thing a customer's IT person finds
// six months later and forms an opinion about.
//
// Every step is best-effort and recorded. One failure must not stop the
// others: a machine where the service had already been removed by hand still
// needs its firewall rule taken away.
func runUninstall(ui *console, managed bool) error {
	dir := installDir()
	var problems []string

	// What was actually removed, printed at the end. A customer who is told
	// "it has been removed" has been told to trust us; a customer who is shown
	// the list can check it, and their IT person can too. Every line is
	// something a tester verifies in Stage 15 of the test pack.
	var removed []string
	note := func(what string, err error, label string) {
		if err != nil {
			problems = append(problems, label+": "+err.Error())

			return
		}
		removed = append(removed, what)
	}

	// The uninstaller lives in the folder it is about to delete, so it moves
	// out of it first. See relaunchFromTemp.
	if moved, err := relaunchFromTemp(); err != nil {
		return err
	} else if moved {
		return nil
	}

	ui.step("Closing the icon in the corner")
	stopTray()

	ui.step("Stopping the service")
	_ = runAgentIn(dir, "service", "stop")
	time.Sleep(2 * time.Second)

	ui.step("Removing the service and the firewall rule")
	// The agent's own uninstall removes both, and knows the names it used.
	if out, err := runAgentOutput(dir, "service", "uninstall"); err != nil {
		// Not fatal: the binary may be gone, or the service may never have
		// been registered. The explicit removals below cover it.
		problems = append(problems, "service: "+trimForDialog(out, err))
	}

	// Belt and braces, and the only path on a machine where the binary is
	// already missing.
	note("the Windows service "+serviceName, removeServiceDirectly(), "service")
	// Two rules since 1.9.2: the inbound UDP one for WireGuard, and the one
	// that lets peers on the overlay reach this machine at all.
	note("the firewall rule for WireGuard", removeFirewallRule(), "firewall")
	note("the firewall rule for the overlay", removeOverlayFirewallRule(), "firewall")

	ui.step("Removing the DNS policy rules")
	note("the DNS policy rules", removeNrptRules(), "DNS policy")

	ui.step("Removing the network adapter")
	note("the Wintun network adapter", removeWintunAdapter(), "adapter")

	ui.step("Removing the Start menu entry")
	note("the Start menu entry and the sign-in icon", removeShortcuts(), "Start menu")

	if !managed {
		// When a deployment package installed this, the entry in Settings is
		// its own and it removes it itself. Claiming to have removed one that
		// was never ours would be a line in the list that is not true.
		ui.step("Removing the entry in Settings")
		note("the entry in Settings \u2192 Apps", unregisterFromPrograms(), "Apps & features")
	}

	ui.step("Removing the files")
	// The data directory holds the device's private key and its token. It goes
	// with everything else: leaving a credential on a machine somebody has
	// just uninstalled our software from is not a thing to do.
	for _, path := range []string{dir, dataDir()} {
		note(path, os.RemoveAll(path), path)
	}

	list := "Removed:\n"
	for _, what := range removed {
		list += "  \u2022 " + what + "\n"
	}

	if problems != nil {
		return fmt.Errorf(
			"%s was removed, but some parts could not be:\n\n%s\n\n%s\n"+
				"Send this message to your supplier — each line under the first heading is "+
				"something that needs removing by hand.",
			displayName, strings.Join(problems, "\n"), list)
	}

	ui.done(fmt.Sprintf(
		"%s has been removed.\n\n%s\n"+
			"Your computer's own network settings were not changed at any point.",
		displayName, list))

	return nil
}
