//go:build windows

package main

import (
	"fmt"

	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"
)

// serviceName is the name the installer registers. Kept here rather than
// imported, because the tray and the installer are separate programs that
// happen to agree, and a shared constant would imply they must.
const serviceName = "AKConnectAgent"

// serviceRunning reports whether the agent service is running right now.
//
// It is asked at the moment a diagnostics bundle is collected, because that
// one fact decides how to read every other file in it. A bundle taken while
// the service was restarting had no published status, an empty firewall
// query and a UDP listener table with no agent port — three puzzles with one
// answer that the bundle never stated.
//
// Returns a short detail to append, never an error: this is a line in a text
// file, and a machine that will not answer "is it running?" has said
// something useful by refusing.
func serviceRunning() (bool, string) {
	manager, err := mgr.Connect()
	if err != nil {
		return false, " (could not ask Windows: " + err.Error() + ")"
	}
	defer manager.Disconnect()

	service, err := manager.OpenService(serviceName)
	if err != nil {
		return false, " (not installed as a service)"
	}
	defer service.Close()

	status, err := service.Query()
	if err != nil {
		return false, " (Windows would not say: " + err.Error() + ")"
	}

	switch status.State {
	case svc.Running:
		return true, ""
	case svc.StartPending:
		return false, " (starting — this bundle was taken mid-start)"
	case svc.StopPending:
		return false, " (stopping — this bundle was taken mid-stop)"
	case svc.Stopped:
		return false, " (stopped)"
	default:
		return false, fmt.Sprintf(" (state %d)", status.State)
	}
}
