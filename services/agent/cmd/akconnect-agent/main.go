// Command akconnect-agent is the device-side half of the overlay network.
//
// It generates a Curve25519 identity that never leaves the machine, enrols
// against the control plane, waits for an administrator to approve it, and
// then brings up a WireGuard interface carrying only the overlay's own
// prefixes.
//
// Usage:
//
//	akconnect-agent enroll --panel https://net.example.com --join-code ABC123
//	akconnect-agent up [--verbose]
//	akconnect-agent status
//	akconnect-agent reset [--keep-key]
package main

import (
	"context"
	"fmt"
	"os"
	"os/signal"
	"syscall"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/winsvc"
)

// version is stamped at build time with -ldflags "-X main.version=1.2.3".
var version = "dev"

func main() {
	if len(os.Args) < 2 {
		usage()
		os.Exit(2)
	}

	// Started by the Windows service control manager rather than a person:
	// hand straight over, because the SCM expects a status report within
	// seconds and will kill a process that parses flags instead.
	if winsvc.IsService() {
		if err := winsvc.Run(func(ctx context.Context) error { return runUp(ctx, nil) }); err != nil {
			fmt.Fprintf(os.Stderr, "service failed: %v\n", err)
			os.Exit(1)
		}

		return
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	var err error

	switch os.Args[1] {
	case "enroll":
		err = runEnroll(ctx, os.Args[2:])
	case "up":
		err = runUp(ctx, os.Args[2:])
	case "status":
		err = runStatus(ctx, os.Args[2:])
	case "service":
		err = runService(ctx, os.Args[2:])
	case "selftest":
		err = runSelftest(ctx, os.Args[2:])
	case "update":
		err = runUpdate(ctx, os.Args[2:])
	case "reset":
		err = runReset(os.Args[2:])
	case "version", "--version", "-v":
		fmt.Printf("akconnect-agent %s\n", version)
	case "help", "--help", "-h":
		usage()
	default:
		fmt.Fprintf(os.Stderr, "unknown command %q\n\n", os.Args[1])
		usage()
		os.Exit(2)
	}

	if err != nil {
		fmt.Fprintf(os.Stderr, "\n  error: %v\n", err)
		os.Exit(1)
	}
}

func usage() {
	fmt.Fprint(os.Stderr, `akconnect-agent — device agent for the overlay network

  enroll   Register this device with a panel using a join code.
           The private key is generated here and never sent.

    --panel URL        panel base URL, e.g. https://net.example.com
    --join-code CODE   the code an administrator gave you
    --name NAME        what to call this device (default: hostname)
    --wait             keep polling until an administrator approves

  up       Bring the tunnel up and keep it running.

    --verbose          log WireGuard handshakes
    --iface NAME       interface name (default: platform-specific)
    --port N           UDP listen port (default: this device's own, chosen once)

  status   Show enrolment, approval and tunnel state.

  selftest Check everything that depends on this operating system:
           privileges, the tunnel driver, the key store round trip, and
           creating a real adapter. Reports each as pass or fail with the
           actual error, and never destroys an existing identity.

  update   Install the release this panel offers to this device, if any.
           The download is refused unless its sha256 is signed by the
           controller key the panel publishes.

    --check            say what is available and change nothing
    --no-restart       install, but leave the restart to somebody else

  reset    Forget this enrolment.

    --keep-key         re-enrol with the same identity instead of a new one

  service  Manage the Windows service (Windows only).

    install [--port N]   register it, start automatically, open the firewall
    uninstall            remove it and its firewall rule
    start | stop         control it
    status               report its state

Requires root on Linux and Administrator on Windows to create the interface.
`)
}
