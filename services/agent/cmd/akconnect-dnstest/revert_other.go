//go:build !linux

package main

// reverted has nothing to ask on a platform where this fixture does not run.
func reverted(string, string) bool { return true }
