//go:build !windows

package main

// These read Windows' own view of its adapters, routes and firewall. See
// collect_windows.go for what they are for.

func machineSources() []source { return nil }
