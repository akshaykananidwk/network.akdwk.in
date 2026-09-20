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
	elog, err := eventlog.Open(Name)
	if err != nil {
		// Not fatal: losing the event log should not stop the service.
		elog = nil
	}
	if elog != nil {
		defer elog.Close()
	}

	return svc.Run(Name, &handler{fn: fn, elog: elog})
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
	h.log(eventlog.Info, "AKConnect agent started")

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
				h.log(eventlog.Error, "AKConnect agent failed: "+err.Error())
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

// Install registers the service to start automatically.
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
		existing.Close()

		return fmt.Errorf("%s is already installed; run 'service uninstall' first", Name)
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
