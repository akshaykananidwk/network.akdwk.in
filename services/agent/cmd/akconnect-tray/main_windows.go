//go:build windows

package main

import (
	"fmt"

	"golang.org/x/sys/windows"
)

// run puts the icon up and stays until the customer signs out.
//
// One icon per session. Without the mutex a customer who has the shortcut in
// their Startup folder and also clicks it from the Start menu gets two
// identical icons, and the second one's menu closes the first one's.
func run() error {
	name, err := windows.UTF16PtrFromString(`Local\` + productName + "-tray")
	if err != nil {
		return err
	}

	handle, err := windows.CreateMutex(nil, false, name)
	if err == nil && handle != 0 {
		defer windows.CloseHandle(handle)
	}
	if err == windows.ERROR_ALREADY_EXISTS {
		// Already showing. Not a failure — exiting quietly is the behaviour
		// somebody double-clicking a shortcut expects.
		return nil
	}

	if err := runTray(); err != nil {
		return fmt.Errorf("%s could not show its icon: %w", displayName, err)
	}

	return nil
}
