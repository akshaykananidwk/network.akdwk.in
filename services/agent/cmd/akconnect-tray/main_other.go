//go:build !windows

package main

import "errors"

// The tray is a Windows notification-area icon. These exist so the package
// compiles, is vetted and is tested on the machine it is developed on: the
// status wording and the diagnostics bundle are the parts worth testing, and
// neither of them needs Windows.

func run() error {
	return errors.New("akconnect-tray is the Windows notification-area icon and only runs on Windows")
}
