//go:build !windows

package winsvc

// RestartDetached has nothing to detach from off Windows: systemd restarts
// its own units, and `systemctl restart` from inside the unit is the
// documented way to do it.
func RestartDetached() error { return errNotWindows }
