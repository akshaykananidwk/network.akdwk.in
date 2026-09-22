//go:build windows

// Package winsvc runs the agent under the Windows Service Control Manager.
//
// Installing as a service is not a convenience on Windows, it is the only
// sensible way to run: the agent needs administrator rights to create an
// adapter, it must start before anyone logs in, and it must keep running
// after they log out. Running it from a console works for a first test and
// for nothing else.
package winsvc

import (
	"context"
	"fmt"
	"os"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/state"

	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/eventlog"
	"golang.org/x/sys/windows/svc/mgr"
)

// Name is the service name in the SCM.
const Name = "AKConnectAgent"

// DisplayName is what appears in services.msc.
const DisplayName = "AKConnect Agent"

// Description is the longer text beneath it.
const Description = "Maintains this device's connection to its private overlay network."

// RunFunc is the agent's real work, run until the context is cancelled.
type RunFunc func(ctx context.Context) error

// IsService reports whether this process was started by the SCM rather than
// from a console, so main can pick the right path without a flag.
func IsService() bool {
	inService, err := svc.IsWindowsService()
	if err != nil {
		return false
	}

	return inService
}

// Run hands control to the SCM and runs fn as the service body.
func Run(fn RunFunc) error {
	elog := openEventLog()
	if elog != nil {
		defer elog.Close()
	}

	return svc.Run(Name, &handler{fn: fn, elog: elog})
}

// openEventLog returns a handle to our event source, registering it first if
// it is not there.
//
// A service installed before the source was registered — or one whose source
// was removed — opens nothing, and then every failure it reports goes nowhere.
// That is half of how "the service starts and silently stops" happened: the
// other half was a console the service does not have. Registration needs
// administrator rights, which a service running as LocalSystem has, so the
// second attempt normally succeeds.
//
// Still not fatal: losing the event log must not stop the agent working.
func openEventLog() *eventlog.Log {
	if elog, err := eventlog.Open(Name); err == nil {
		return elog
	}

	if err := eventlog.InstallAsEventCreate(Name, eventlog.Error|eventlog.Warning|eventlog.Info); err != nil {
		return nil
	}

	elog, err := eventlog.Open(Name)
	if err != nil {
		return nil
	}

	return elog
}

type handler struct {
	fn   RunFunc
	elog *eventlog.Log
}

func (h *handler) Execute(args []string, r <-chan svc.ChangeRequest, s chan<- svc.Status) (bool, uint32) {
	const accepted = svc.AcceptStop | svc.AcceptShutdown

	s <- svc.Status{State: svc.StartPending}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	errc := make(chan error, 1)
	go func() { errc <- h.fn(ctx) }()

	s <- svc.Status{State: svc.Running, Accepts: accepted}
	h.log(eventlog.Info, "AKConnect agent started; output goes to "+state.ServiceLogPath())

	for {
		select {
		case req := <-r:
			switch req.Cmd {
			case svc.Interrogate:
				s <- req.CurrentStatus
			case svc.Stop, svc.Shutdown:
				// Tell the SCM we are stopping before doing it, or a slow
				// teardown is reported as a hung service.
				s <- svc.Status{State: svc.StopPending}
				cancel()

				select {
				case <-errc:
				case <-time.After(20 * time.Second):
					h.log(eventlog.Warning, "AKConnect agent did not stop within 20 seconds")
				}

				h.log(eventlog.Info, "AKConnect agent stopped")
				s <- svc.Status{State: svc.Stopped}

				return false, 0
			default:
				s <- req.CurrentStatus
			}

		case err := <-errc:
			if err != nil {
				// The log path goes in the message because the Event Viewer
				// entry is what a technician finds first, and the detail of
				// what the agent was doing is in the file.
				h.log(eventlog.Error, "AKConnect agent failed: "+err.Error()+
					"\r\nFull output: "+state.ServiceLogPath())
				s <- svc.Status{State: svc.Stopped}

				return false, 1
			}

			s <- svc.Status{State: svc.Stopped}

			return false, 0
		}
	}
}

func (h *handler) log(level uint16, message string) {
	if h.elog == nil {
		return
	}

	switch level {
	case eventlog.Error:
		_ = h.elog.Error(1, message)
	case eventlog.Warning:
		_ = h.elog.Warning(1, message)
	default:
		_ = h.elog.Info(1, message)
	}
}

// Install registers the service to start automatically, or updates the
// registration of one that is already there.
//
// Refusing when the service exists is what it used to do, and it made the
// installer unusable for the thing customers do most: run the new installer
// over an old install. The .exe copied its files, enrolled the device, and
// then stopped with "AKConnectAgent is already installed; run 'service
// uninstall' first" — an instruction requiring a command prompt, given to
// somebody who had just been promised they would not need one, on a machine
// that was now half-installed.
//
// So an existing service is reconfigured to point at the new binary and left
// running. The identity is untouched: the keys and the enrolment live in
// ProgramData, not in the service registration.
func Install() error {
	exe, err := os.Executable()
	if err != nil {
		return fmt.Errorf("locating this executable: %w", err)
	}

	m, err := mgr.Connect()
	if err != nil {
		return fmt.Errorf("connecting to the service manager (are you an administrator?): %w", err)
	}
	defer m.Disconnect()

	if existing, err := m.OpenService(Name); err == nil {
		defer existing.Close()

		return reinstallOver(existing, exe)
	}

	s, err := m.CreateService(Name, exe, mgr.Config{
		DisplayName:  DisplayName,
		Description:  Description,
		StartType:    mgr.StartAutomatic,
		ErrorControl: mgr.ErrorNormal,
	}, "service", "run")
	if err != nil {
		return fmt.Errorf("creating the service: %w", err)
	}
	defer s.Close()

	// Restart on failure rather than leaving a device silently disconnected.
	if err := s.SetRecoveryActions([]mgr.RecoveryAction{
		{Type: mgr.ServiceRestart, Delay: 5 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 30 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 60 * time.Second},
	}, 86400); err != nil {
		// Worth reporting, not worth failing the install over.
		fmt.Fprintf(os.Stderr, "  warning: could not set recovery actions: %v\n", err)
	}

	if err := eventlog.InstallAsEventCreate(Name, eventlog.Error|eventlog.Warning|eventlog.Info); err != nil {
		fmt.Fprintf(os.Stderr, "  warning: could not register the event log source: %v\n", err)
	}

	return nil
}

// reinstallOver repoints an existing service at a new binary.
//
// The service is stopped only if the path actually changed, because a running
// service holds its .exe open on Windows and cannot be overwritten in place.
// When the path is the same — the ordinary upgrade, into the same Program
// Files folder — it is restarted so the new binary is the one running.
func reinstallOver(s *mgr.Service, exe string) error {
	cfg, err := s.Config()
	if err != nil {
		return fmt.Errorf("reading the existing service configuration: %w", err)
	}

	cfg.BinaryPathName = fmt.Sprintf("%q service run", exe)
	cfg.DisplayName = DisplayName
	cfg.Description = Description
	cfg.StartType = mgr.StartAutomatic
	cfg.ErrorControl = mgr.ErrorNormal

	if err := s.UpdateConfig(cfg); err != nil {
		return fmt.Errorf("updating the existing service: %w", err)
	}

	if err := s.SetRecoveryActions([]mgr.RecoveryAction{
		{Type: mgr.ServiceRestart, Delay: 5 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 30 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 60 * time.Second},
	}, 86400); err != nil {
		fmt.Fprintf(os.Stderr, "  warning: could not set recovery actions: %v\n", err)
	}

	if err := eventlog.InstallAsEventCreate(Name, eventlog.Error|eventlog.Warning|eventlog.Info); err != nil {
		// Already registered is the usual reason, and it is not a problem.
		_ = err
	}

	// Restart so the binary that is running is the one just installed.
	// Tolerant of a service that was not running: starting it is the goal
	// either way.
	_ = Stop()
	if err := waitForStopped(20 * time.Second); err != nil {
		fmt.Fprintf(os.Stderr, "  warning: %v\n", err)
	}

	return Start()
}

// waitForStopped blocks until the service is not running, or gives up.
func waitForStopped(within time.Duration) error {
	deadline := time.Now().Add(within)

	for time.Now().Before(deadline) {
		state, err := Status()
		// Status's own words, not svc's: "installed, stopped" is what it says
		// for a service that exists and is not running.
		if err != nil || state == "installed, stopped" || state == "not installed" {
			return nil
		}
		time.Sleep(250 * time.Millisecond)
	}

	return fmt.Errorf("the service did not stop within %s; the new binary may not be the one running", within)
}

// Uninstall removes the service. It stops it first, because removing a
// running service leaves it marked for deletion until the next reboot, which
// then makes a reinstall fail with a confusing error.
func Uninstall() error {
	m, err := mgr.Connect()
	if err != nil {
		return fmt.Errorf("connecting to the service manager (are you an administrator?): %w", err)
	}
	defer m.Disconnect()

	s, err := m.OpenService(Name)
	if err != nil {
		return fmt.Errorf("%s is not installed", Name)
	}
	defer s.Close()

	if status, err := s.Query(); err == nil && status.State != svc.Stopped {
		if _, err := s.Control(svc.Stop); err == nil {
			waitForStop(s)
		}
	}

	if err := s.Delete(); err != nil {
		return fmt.Errorf("deleting the service: %w", err)
	}

	_ = eventlog.Remove(Name)

	return nil
}

// Start starts an installed service.
func Start() error {
	s, m, err := open()
	if err != nil {
		return err
	}
	defer m.Disconnect()
	defer s.Close()

	return s.Start()
}

// Stop stops an installed service and waits for it.
func Stop() error {
	s, m, err := open()
	if err != nil {
		return err
	}
	defer m.Disconnect()
	defer s.Close()

	if _, err := s.Control(svc.Stop); err != nil {
		return fmt.Errorf("stopping the service: %w", err)
	}

	waitForStop(s)

	return nil
}

// Status describes the installed service, for the status command.
func Status() (string, error) {
	s, m, err := open()
	if err != nil {
		return "not installed", nil
	}
	defer m.Disconnect()
	defer s.Close()

	status, err := s.Query()
	if err != nil {
		return "", err
	}

	switch status.State {
	case svc.Running:
		return "running", nil
	case svc.Stopped:
		return "installed, stopped", nil
	case svc.StartPending:
		return "starting", nil
	case svc.StopPending:
		return "stopping", nil
	default:
		return fmt.Sprintf("state %d", status.State), nil
	}
}

func open() (*mgr.Service, *mgr.Mgr, error) {
	m, err := mgr.Connect()
	if err != nil {
		return nil, nil, fmt.Errorf("connecting to the service manager (are you an administrator?): %w", err)
	}

	s, err := m.OpenService(Name)
	if err != nil {
		m.Disconnect()

		return nil, nil, fmt.Errorf("%s is not installed", Name)
	}

	return s, m, nil
}

func waitForStop(s *mgr.Service) {
	deadline := time.Now().Add(30 * time.Second)

	for time.Now().Before(deadline) {
		status, err := s.Query()
		if err != nil || status.State == svc.Stopped {
			return
		}
		time.Sleep(300 * time.Millisecond)
	}
}
