//go:build !windows

package main

// serviceImagePath is a Windows service-manager query. The decisions around it
// live in heal.go without a build tag, so they are testable here.
func serviceImagePath() string { return "" }
