package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"os"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/state"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/tunnel"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/winenv"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/winsvc"
)

// runService handles "akconnect-agent service <verb>".
func runService(ctx context.Context, args []string) error {
	if len(args) == 0 {
		return errors.New("service needs a verb: install, uninstall, start, stop, status or run")
	}

	switch args[0] {
	case "install":
		return serviceInstall(args[1:])
	case "uninstall":
		return serviceUninstall()
	case "start":
		return winsvc.Start()
	case "stop":
		return winsvc.Stop()
	case "status":
		state, err := winsvc.Status()
		if err != nil {
			return err
		}
		fmt.Printf("  Service: %s\n", state)

		return nil
	case "run":
		// Invoked by the service control manager, never by a person.
		return winsvc.Run(func(ctx context.Context) error {
			return runUp(ctx, nil)
		})
	default:
		return fmt.Errorf("unknown service verb %q", args[0])
	}
}

func serviceInstall(args []string) error {
	fs := flag.NewFlagSet("service install", flag.ExitOnError)
	// Accepted and ignored, so an older deployment script or a saved command
	// line does not start failing. The rules name this program now, on every
	// port it will ever use — see winenv.EnsureFirewallRules.
	_ = fs.Int("port", 0, "no longer used: the firewall rules name this program, not a port")
	if err := fs.Parse(args); err != nil {
		return err
	}

	// The rule has to name the file that will run as the service, which is
	// this one: `service install` is run from the directory the installer put
	// the agent in, by that agent.
	exe, err := os.Executable()
	if err != nil {
		return fmt.Errorf("could not find this program's own path: %w", err)
	}

	if err := winsvc.Install(); err != nil {
		return err
	}
	fmt.Printf("  Service installed and set to start automatically.\n")

	// Done here rather than on first run: the firewall prompt appears on the
	// interactive desktop, and a service running as LocalSystem has no
	// desktop, so nobody would ever see it and the rule would never be made.
	if err := winenv.EnsureFirewallRules(exe); err != nil {
		fmt.Fprintf(os.Stderr, "  warning: could not create the firewall rules: %v\n", err)
		fmt.Fprintf(os.Stderr, "  this program must be allowed through or peers cannot reach this device.\n")
	} else if winenv.FirewallRuleName != "" {
		fmt.Printf("  Firewall rules named %q allow this program on every profile, in and out.\n",
			winenv.FirewallRuleName)
	}

	fmt.Printf("  Start it with: akconnect-agent service start\n")

	return nil
}

func serviceUninstall() error {
	if err := winsvc.Uninstall(); err != nil {
		return err
	}

	winenv.RemoveFirewallRule()
	fmt.Printf("  Service and firewall rules removed.\n")
	fmt.Printf("  The device identity and enrolment are untouched; use 'reset' to clear them.\n")

	return nil
}

// checkEnvironment reports anything that will stop the agent working, before
// it tries and fails with a driver-level error nobody can act on.
func checkEnvironment() error {
	problems := winenv.Preflight()
	if len(problems) == 0 {
		return nil
	}

	var fatal bool
	for _, p := range problems {
		marker := "warning"
		if p.Fatal {
			marker = "blocked"
			fatal = true
		}
		fmt.Fprintf(os.Stderr, "\n  %s: %s\n    %s\n", marker, p.Summary, p.Remedy)
	}

	if fatal {
		return errors.New("the environment is not ready; see above")
	}

	return nil
}

// installedListenPort is the port this device has recorded, or the historical
// default when it has never run.
//
// Nothing is chosen here: choosing would write a port the agent might not use,
// and the rule is re-made at runtime with whatever is actually bound.
func installedListenPort() int {
	store, err := state.Open()
	if err != nil {
		return tunnel.DefaultListenPort
	}

	st, err := store.Load()
	if err != nil || st == nil || st.ListenPort < 1024 || st.ListenPort > 65535 {
		return tunnel.DefaultListenPort
	}

	return st.ListenPort
}
