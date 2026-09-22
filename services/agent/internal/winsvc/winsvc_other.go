//go:build !windows

// Package winsvc is a Windows concept. On other platforms the equivalent is
// systemd or launchd, which are configured with a unit file rather than by the
// program installing itself, so these are stubs that say so plainly.
package winsvc

import (
	"context"
	"errors"
)

// Name is the service name, unused off Windows.
const Name = "AKConnectAgent"

// RunFunc matches the Windows signature.
type RunFunc func(ctx context.Context) error

var errNotWindows = errors.New("services are a Windows concept; use the systemd unit in DEPLOY.md")

// IsService is always false: nothing outside Windows starts a process this way.
func IsService() bool { return false }

// Run is never reached off Windows.
func Run(fn RunFunc) error { return errNotWindows }

// Install and friends explain themselves rather than failing obscurely.
func Install() error          { return errNotWindows }
func Uninstall() error        { return errNotWindows }
func Start() error            { return errNotWindows }
func Stop() error             { return errNotWindows }
func Status() (string, error) { return "not applicable on this platform", nil }

// OnWake is the Windows resume hook. Declared here so the agent can set it
// unconditionally; nothing off Windows ever calls it.
var OnWake func(reason string)
