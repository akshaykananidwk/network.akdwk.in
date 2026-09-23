//go:build !windows

package main

// The Start menu and the sign-in entry are Windows. See shortcuts_windows.go.

func createShortcuts(string, string) error { return errNotWindows }
func removeShortcuts() error               { return errNotWindows }
func startTray(string)                     {}
func stopTray()                            {}
