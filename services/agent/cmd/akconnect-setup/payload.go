package main

import (
	"bytes"
	_ "embed"
	"errors"
)

// The files the installer puts on disk, carried inside it.
//
// One download, one double-click. Telling a customer to fetch a driver from a
// third-party website is how you get the wrong architecture, an unverified
// file, or somebody who gives up — and it is a support call either way.
//
// Wintun's prebuilt licence permits redistribution alongside software that
// uses only its documented API, which is what the agent does; see
// docs/WINTUN-LICENSING.md.
var (
	//go:embed payload/akconnect-agent.exe
	agentBinary []byte

	//go:embed payload/wintun.dll
	wintunDLL []byte

	//go:embed payload/akconnect-tray.exe
	trayBinary []byte
)

// placeholder is what the committed stand-ins contain. They exist so the tree
// builds on a machine that has not run the pack script, and they are not
// software.
const placeholder = "AKCONNECT-PLACEHOLDER-NOT-A-REAL-BINARY"

// checkPayload refuses to install a build whose payload was never filled in.
//
// A setup binary that cheerfully wrote a placeholder to Program Files and
// registered a service pointing at it would fail later, on a customer's
// machine, in a way nobody could diagnose from the error.
func checkPayload() error {
	for _, file := range []struct {
		name    string
		content []byte
	}{
		{"akconnect-agent.exe", agentBinary},
		{"wintun.dll", wintunDLL},
	} {
		if len(file.content) < 4096 || bytes.Contains(file.content, []byte(placeholder)) {
			return errors.New("this installer was built without a real " + file.name +
				" inside it. Rebuild it with services/kit/build-windows-pack.sh; " +
				"it is not a build that should have left the machine it was made on")
		}
	}

	return nil
}

// hasTray reports whether this pack carries the notification-area icon. It is
// optional: a pack built without it installs a working agent that simply has
// no icon, which is worth shipping and not worth refusing to install.
func hasTray() bool {
	return len(trayBinary) >= 4096 && !bytes.Contains(trayBinary, []byte(placeholder))
}
