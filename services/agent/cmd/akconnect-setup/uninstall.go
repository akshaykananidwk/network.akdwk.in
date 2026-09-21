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
func runUninstall(ui *console) error {
	dir := installDir()
	var problems []string

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
	if err := removeServiceDirectly(); err != nil {
		problems = append(problems, "service: "+err.Error())
	}
	if err := removeFirewallRule(); err != nil {
		problems = append(problems, "firewall: "+err.Error())
	}

	ui.step("Removing the DNS policy rules")
	if err := removeNrptRules(); err != nil {
		problems = append(problems, "DNS policy: "+err.Error())
	}

	ui.step("Removing the network adapter")
	if err := removeWintunAdapter(); err != nil {
		problems = append(problems, "adapter: "+err.Error())
	}

	ui.step("Removing the files")
	// The data directory holds the device's private key and its token. It goes
	// with everything else: leaving a credential on a machine somebody has
	// just uninstalled our software from is not a thing to do.
	for _, path := range []string{dir, dataDir()} {
		if err := os.RemoveAll(path); err != nil {
			problems = append(problems, fmt.Sprintf("%s: %v", path, err))
		}
	}

	if problems != nil {
		return fmt.Errorf(
			"%s was removed, but some parts could not be:\n\n%s\n\n"+
				"Send this message to your supplier — each line is something that needs "+
				"removing by hand.",
			productName, strings.Join(problems, "\n"))
	}

	ui.done(fmt.Sprintf(
		"%s has been removed.\n\n"+
			"The service, the firewall rule, the DNS policy rules, the network adapter and "+
			"the program folder are all gone. Your computer's own network settings were not "+
			"changed at any point.",
		productName))

	return nil
}
