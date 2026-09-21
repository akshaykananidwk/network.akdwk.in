// Command akconnect-setup installs and removes the AKConnect agent on Windows.
//
// §33. A customer double-clicks one file, types the join code their supplier
// gave them, and is done. No command line, no separate driver download, no
// "now open PowerShell as administrator".
//
// What it does, in order:
//
//   - asks Windows to run it elevated, if it is not already
//   - writes the agent and wintun.dll into Program Files
//   - asks for the join code, unless one was passed
//   - enrols, which is what puts the device in front of an administrator
//   - registers the service and opens the firewall port
//   - starts the service
//
// And with -uninstall, takes all of it back off: the service, the firewall
// rule, the DNS policy rules, the Wintun adapter and the files. "Leaves
// nothing behind" is a requirement rather than an aspiration — a tester checks
// it, and Stage 15 of the test pack is that check.
//
// It is built for the windowsgui subsystem, so double-clicking it opens no
// console window. Everything a customer sees is a dialog.
package main

import (
	"flag"
	"fmt"
	"os"
	"strings"
)

// version is stamped at build time with -ldflags "-X main.version=1.2.3".
var version = "dev"

const (
	productName = "AKConnect"
	serviceName = "AKConnectAgent"
)

func main() {
	var (
		uninstall = flag.Bool("uninstall", false, "remove the agent and everything it installed")
		code      = flag.String("code", "", "join code, if you would rather not be asked for it")
		panelURL  = flag.String("panel", "", "panel address, when it is not the one built in")
		name      = flag.String("name", "", "device name to show in the panel (default: this computer's name)")
		silent    = flag.Bool("silent", false, "no dialogs; for deployment tools")
		showVer   = flag.Bool("version", false, "print the version and exit")
	)
	flag.Parse()

	if *showVer {
		report(*silent, fmt.Sprintf("%s setup %s", productName, version))

		return
	}

	ui := &console{silent: *silent}

	if err := runSetup(ui, *uninstall, *code, *panelURL, *name); err != nil {
		ui.fail(err.Error())
		os.Exit(1)
	}
}

// runSetup is the whole flow, separated from flag parsing so it can be read in
// one screen and tested on any platform.
func runSetup(ui *console, uninstall bool, code, panelURL, name string) error {
	if err := requireWindows(); err != nil {
		return err
	}

	// Elevation first, before anything is written or asked. A customer who
	// typed their join code into a dialog and then got a UAC prompt, and then
	// had to type it again, would reasonably conclude the software is broken.
	elevated, err := isElevated()
	if err != nil {
		return fmt.Errorf("could not tell whether this is running as administrator: %w", err)
	}
	if !elevated {
		return relaunchElevated()
	}

	if uninstall {
		return runUninstall(ui)
	}

	if err := checkPayload(); err != nil {
		return err
	}

	if code == "" {
		code, err = ui.askJoinCode()
		if err != nil {
			return err
		}
	}

	code = strings.TrimSpace(code)
	if code == "" {
		return fmt.Errorf("no join code was entered, so nothing was installed")
	}

	return runInstall(ui, code, panelURL, name)
}
