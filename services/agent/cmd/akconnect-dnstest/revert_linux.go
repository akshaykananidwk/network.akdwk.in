//go:build linux

package main

import (
	"os/exec"
	"strings"
)

// reverted asks resolved whether the link still carries our domain.
func reverted(iface, zone string) bool {
	out, err := exec.Command("resolvectl", "status", iface).CombinedOutput()
	if err != nil {
		// The link is gone, which is as reverted as it gets.
		return true
	}

	return !strings.Contains(string(out), zone)
}
