//go:build !windows

package main

// Apps & features is a Windows registry key. These exist so the flow around it
// compiles and is tested here; see programs_windows.go for what they do.

func registerInPrograms(string, string) error { return errNotWindows }
func unregisterFromPrograms() error           { return errNotWindows }
func installedVersion() string                { return "" }
