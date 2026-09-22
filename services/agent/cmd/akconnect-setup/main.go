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
	"net/url"
	"os"
	"strings"
)

// version is stamped at build time with -ldflags "-X main.version=1.2.3".
var version = "dev"

// defaultPanel is stamped at build time too, with
// -ldflags "-X main.defaultPanel=https://network.akdwk.in".
//
// It has to be, because a join code does not carry the panel's address: it is
// a code issued by one panel and means nothing without knowing which. A
// production install found this the hard way — the installer copied its files
// and then failed with "--panel and --join-code are both required", which is
// an agent's error message arriving in front of a customer.
//
// Baked in rather than asked for, because §33 says one question and the join
// code is the one worth asking. build-windows-pack.sh stamps it from the
// panel's own configured URL, so the pack a customer downloads already knows
// where it came from. When it is not stamped — somebody building by hand —
// the installer asks, rather than failing after it has written files.
var defaultPanel = ""

const (
	productName = "AKConnect"
	serviceName = "AKConnectAgent"
)

// What Apps & features, the Start menu and the tray call this. Stamped at
// build time like defaultPanel, so the brand lives in one configured place
// rather than being spelled out in a dozen files:
//
//	-ldflags "-X main.displayName=AK Connect -X main.publisher=AK Computer"
//
// productName stays as it is: it is the folder name, the service name and the
// registry key, and those are identifiers rather than words on a screen.
var (
	displayName = "AK Connect"
	publisher   = "AK Computer"
)

// aboutURL is the panel this pack belongs to, shown in Apps & features as the
// place to go for help. Empty when nothing was stamped, and then omitted.
func aboutURL() string { return defaultPanel }

func main() {
	var (
		uninstall = flag.Bool("uninstall", false, "remove the agent and everything it installed")
		code      = flag.String("code", "", "join code, if you would rather not be asked for it")
		panelURL  = flag.String("panel", "", "panel address, when it is not the one built in")
		name      = flag.String("name", "", "device name to show in the panel (default: this computer's name)")
		silent    = flag.Bool("silent", false, "no dialogs; for deployment tools")
		force     = flag.Bool("force", false, "install even when a newer version is already here")
		showVer   = flag.Bool("version", false, "print the version and exit")
	)

	// /S /CODE=ABCD-1234 as well as -silent -code=ABCD-1234, because every
	// deployment tool on Windows speaks the first dialect. See args.go.
	os.Args = append(os.Args[:1], normaliseArgs(os.Args[1:])...)

	flag.Parse()

	if *showVer {
		report(*silent, fmt.Sprintf("%s setup %s", productName, version))

		return
	}

	ui := &console{silent: *silent}

	if err := runSetup(ui, options{
		uninstall: *uninstall,
		code:      *code,
		panelURL:  *panelURL,
		name:      *name,
		force:     *force,
	}); err != nil {
		ui.fail(err.Error())
		os.Exit(1)
	}
}

// runSetup is the whole flow, separated from flag parsing so it can be read in
// one screen and tested on any platform.
// options is what the customer or their deployment tool asked for.
//
// A struct rather than five positional arguments, because the fifth one was
// about to be a bool next to another bool, and a call site reading
// (ui, false, "", "", "", true) is a bug waiting for somebody to transpose it.
type options struct {
	uninstall bool
	code      string
	panelURL  string
	name      string
	force     bool
}

func runSetup(ui *console, opt options) error {
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

	if opt.uninstall {
		return runUninstall(ui)
	}

	if err := checkPayload(); err != nil {
		return err
	}

	// Before anything is written, and before the customer is asked anything:
	// running an older installer over a newer install must not quietly take a
	// working computer backwards. See version.go for why this happens.
	if installed := installedVersion(); isDowngrade(version, installed) && !opt.force {
		return fmt.Errorf(
			"this computer already has %s %s, which is newer than this installer (%s).\n\n"+
				"Nothing has been changed. To go back to %s on purpose, uninstall %s first "+
				"from Settings \u2192 Apps, then run this again.",
			displayName, installed, version, version, displayName)
	}

	// Everything that can fail is settled before a single file is written. A
	// failure after the copy leaves a half-install that a customer cannot
	// reason about and an uninstaller has to guess at.
	panelURL, code := opt.panelURL, opt.code

	if panelURL == "" {
		panelURL = defaultPanel
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

	panelURL = strings.TrimSpace(panelURL)
	if panelURL == "" {
		panelURL, err = ui.askPanelURL()
		if err != nil {
			return err
		}
		panelURL = strings.TrimSpace(panelURL)
	}

	if err := checkPanelURL(panelURL); err != nil {
		return err
	}

	return runInstall(ui, code, panelURL, opt.name)
}

// checkPanelURL refuses an address that cannot work, before anything is
// written.
func checkPanelURL(raw string) error {
	if raw == "" {
		return fmt.Errorf(
			"this installer does not know which panel to join, and no address was given.\n\n" +
				"Ask your supplier for the panel address and run:\n" +
				"  akconnect-setup.exe -panel https://their-address -code YOUR-JOIN-CODE")
	}

	parsed, err := url.Parse(raw)
	if err != nil || parsed.Host == "" {
		return fmt.Errorf("%q is not a web address. It should look like https://network.example.com", raw)
	}

	// The agent sends a device token on every call after enrolment. Over plain
	// HTTP that token is readable by anything between here and the panel, so
	// the agent refuses it — and finding that out now beats finding it out
	// after the service has been registered.
	if parsed.Scheme != "https" {
		if parsed.Scheme == "http" {
			return fmt.Errorf(
				"%s is not secure, and the agent will not send a device token over it.\n\n"+
					"Use https:// — if the panel has no certificate yet, that has to be fixed first.", raw)
		}

		return fmt.Errorf("%q should start with https://", raw)
	}

	return nil
}
