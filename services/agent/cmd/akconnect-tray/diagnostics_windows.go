//go:build windows

package main

import (
	"fmt"
	"os"
	"path/filepath"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/state"
)

// "Collect diagnostics for support" — one click, one file on the Desktop, and
// the folder opens so it is under the cursor ready to attach to an email.
//
// A shopkeeper will not find ProgramData. They will find a file on their
// Desktop called AKConnect-diagnostics-2026-09-22-0930.zip.
func (t *tray) collectDiagnostics() {
	desktop, err := desktopDir()
	if err != nil {
		t.say("Could not find your Desktop folder: "+err.Error(), mbIconError)

		return
	}

	path, err := collect(desktop, gatherSources(), time.Now())
	if err != nil {
		t.say("Could not collect the diagnostics: "+err.Error(), mbIconError)

		return
	}

	t.say(fmt.Sprintf(
		"Saved to your Desktop as\n\n%s\n\nEmail that file to your supplier. "+
			"It contains no passwords and no keys.", filepath.Base(path)), mbIconInformation)

	// Selects the file in Explorer rather than opening the zip, which is what
	// somebody about to attach it to an email wants.
	open(desktop)
}

// collectToDesktop writes the bundle without any menu being opened, and prints
// where it went. This is the path the installer uses.
func collectToDesktop() (string, error) {
	desktop, err := desktopDir()
	if err != nil {
		return "", err
	}

	return collect(desktop, gatherSources(), time.Now())
}

// gatherSources is what goes in the bundle. Deliberately short: the service
// log, the published status, and what this computer is. Everything is redacted
// on the way in — see collect.
func gatherSources() []source {
	sources := []source{{
		Name: "about.txt",
		Text: about(),
	}}

	// A failure here is reported and then stepped over rather than returned.
	//
	// It used to return, and a laptop whose state directory the tray could not
	// open produced a bundle of two files — no log, and none of what Windows
	// itself would have said about its adapters and its firewall. The parts
	// that still work are exactly the parts that explain that kind of fault,
	// so they are collected anyway.
	if store, err := state.Open(); err != nil {
		sources = append(sources, source{
			Name: "status.txt",
			Text: "state directory unreadable: " + err.Error() + "\n",
		})
	} else {
		sources = append(sources, source{
			Name: "status.json",
			Path: filepath.Join(filepath.Dir(store.Path()), "runtime.json"),
		})
	}

	// A megabyte from the end. A log that has been running since the machine
	// was installed is not more useful for being complete, and an attachment
	// nobody can email is not a diagnostic.
	sources = append(sources,
		source{Name: "service.log", Path: state.ServiceLogPath(), Limit: 1 << 20})

	// And what Windows knows that the agent does not: adapters, routes,
	// firewall profiles and rules, and which process holds which UDP port.
	// Without these a bundle can say the agent is announcing and getting no
	// answer, and nothing about why. See collect_windows.go.
	sources = append(sources, machineSources()...)

	return sources
}

func about() string {
	host, _ := os.Hostname()

	return fmt.Sprintf(""+
		"%s diagnostics\n"+
		"collected : %s\n"+
		"computer  : %s\n"+
		"tray      : %s\n",
		displayName, time.Now().Format(time.RFC1123), host, version)
}

// desktopDir is where the file lands. USERPROFILE rather than a shell API,
// because that is what it resolves to on every machine this ships to and it
// works when the profile is redirected.
func desktopDir() (string, error) {
	profile := os.Getenv("USERPROFILE")
	if profile == "" {
		return "", fmt.Errorf("Windows did not say where your profile is")
	}

	desktop := filepath.Join(profile, "Desktop")
	if _, err := os.Stat(desktop); err != nil {
		// A redirected Desktop, or OneDrive. The profile folder itself is
		// always there and is still somewhere the customer can find.
		return profile, nil
	}

	return desktop, nil
}
