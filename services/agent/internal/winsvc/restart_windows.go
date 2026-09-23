//go:build windows

package winsvc

import (
	"fmt"
	"os/exec"
	"syscall"
)

// RestartDetached asks something else to restart this service.
//
// A service cannot restart itself: the stop blocks until the service exits,
// and the service is the thing waiting for the stop. So the work is handed to
// a short-lived process that is detached from this one — it keeps running
// after the SCM has killed us, does the stop, waits for it, and starts the
// service again with whatever binary is now on disk.
//
// Restart-Service is used rather than two sc.exe calls because it waits for
// the stop to finish; "sc stop" returns while the service is still stopping,
// and the "sc start" behind it then fails with "service is stopping".
//
// Honest note: this path has never been run on a Windows machine by anyone
// working on this code. It compiles for Windows and the pieces around it are
// tested, but the restart itself is unproven until somebody reports it
// working on a real install.
func RestartDetached() error {
	cmd := exec.Command(
		"powershell.exe",
		"-NoProfile",
		"-NonInteractive",
		"-Command",
		fmt.Sprintf("Restart-Service -Name '%s' -Force", Name),
	)

	// DETACHED_PROCESS so the child gets no console and does not die with
	// ours; CREATE_NEW_PROCESS_GROUP so the SCM's shutdown does not reach it.
	cmd.SysProcAttr = &syscall.SysProcAttr{
		CreationFlags: windowsDetached | windowsNewProcessGroup,
	}

	if err := cmd.Start(); err != nil {
		return fmt.Errorf("could not hand the restart to a detached process: %w", err)
	}

	// Deliberately not waited for. Waiting would mean waiting for our own
	// stop, and the process that is meant to outlive us is exactly the one
	// that would be held open.
	return cmd.Process.Release()
}

const (
	windowsDetached        = 0x00000008
	windowsNewProcessGroup = 0x00000200
)
