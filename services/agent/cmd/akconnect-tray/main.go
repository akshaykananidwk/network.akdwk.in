// Command akconnect-tray is the icon in the notification area.
//
// It is what makes the agent visible to the person using the computer. The
// service does the work; this says whether it is working, and offers the three
// things every support call starts with: what is my address, open the panel,
// send me the logs.
//
// It runs as the signed-in customer, not as SYSTEM, and it can do nothing
// privileged — it reads the status file the service publishes and nothing
// else. That is deliberate: a tray icon running as administrator is a way to
// turn a tray icon into a security problem.
//
// Built for the windowsgui subsystem, so it opens no console window.
package main

import (
	"os"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/state"
)

// version is stamped at build time with -ldflags "-X main.version=1.2.3".
var version = "dev"

// The brand, stamped the same way the installer's is.
var (
	displayName = "AK Connect"
	publisher   = "AK Computer"
)

const productName = "AKConnect"

func main() {
	if len(os.Args) > 1 && (os.Args[1] == "-version" || os.Args[1] == "--version") {
		os.Stdout.WriteString(productName + " tray " + version + "\n")

		return
	}

	// The installer calls this when it finishes an install it could not fully
	// verify. A customer who has just been told something did not work should
	// not then have to find a menu: the file is already on their Desktop by
	// the time the dialog says so.
	if len(os.Args) > 1 && (os.Args[1] == "-collect" || os.Args[1] == "--collect") {
		path, err := collectToDesktop()
		if err != nil {
			os.Stderr.WriteString(err.Error() + "\n")
			os.Exit(1)
		}

		os.Stdout.WriteString(path + "\n")

		return
	}

	if err := run(); err != nil {
		os.Stderr.WriteString(err.Error() + "\n")
		os.Exit(1)
	}
}

// currentView reads what the agent has published and turns it into the words
// the menu shows.
func currentView(now time.Time) view {
	store, err := state.Open()
	if err != nil {
		return describe(nil, nil, err, now)
	}

	// Both reads are allowed to fail. The tray is a reader of other people's
	// files, running as a user who may not be allowed all of them.
	st, stErr := store.Load()
	if stErr != nil {
		st = nil
	}

	rt, rtErr := store.LoadRuntime()
	if rtErr != nil {
		rt = nil
	}

	if st == nil && rt == nil {
		return describe(nil, nil, err, now)
	}

	return describe(st, rt, nil, now)
}

// panelURL is where "Open the panel" goes. Taken from what this device
// actually joined rather than from anything compiled in, because a device that
// joined a different panel must not be sent to ours.
func panelURL() string {
	store, err := state.Open()
	if err != nil {
		return ""
	}

	st, err := store.Load()
	if err != nil || st == nil {
		return ""
	}

	return st.PanelURL
}
