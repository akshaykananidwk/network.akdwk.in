//go:build !windows

package state

// Elsewhere the file modes already say this: the status file and the log are
// written 0644 in a directory the agent owns, and an ordinary user can read
// them. Only Windows needs an access control entry to say the same thing.
func allowLocalRead(string) error { return nil }
