//go:build !windows

package main

import "fmt"

// collectToDesktop exists off Windows so the flag that calls it compiles
// everywhere. The bundle is a Windows support artifact: a Linux administrator
// has journalctl and the agent's own status command.
func collectToDesktop() (string, error) {
	return "", fmt.Errorf("collecting diagnostics is a Windows feature")
}
