// Package winenv covers the parts of running on Windows that have no
// equivalent elsewhere: administrator rights, the Wintun driver, the firewall
// and the service control manager.
//
// Every function here is a no-op or a benign answer on other platforms, so
// callers do not need build tags around ordinary logic.
package winenv

// Preflight reports anything that will stop the agent working on this host,
// in the order the operator should fix it. An empty result means ready.
//
// It exists because the failures it catches are otherwise reported by
// Windows as a bare error code from deep inside a driver load, at which point
// the person reading it has no idea what to do.
func Preflight() []Problem { return preflight() }

// Problem is one thing wrong with the environment.
type Problem struct {
	// What is wrong, in a sentence an operator can act on.
	Summary string
	// How to fix it.
	Remedy string
	// Fatal problems stop the agent; others are warnings.
	Fatal bool
}

// IsAdmin reports whether the process has the rights it needs to create a
// network interface. Always true on platforms where that is not a separate
// concept, so the caller's check reads the same everywhere.
func IsAdmin() bool { return isAdmin() }
