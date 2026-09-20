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
)

// version is stamped at build time with -ldflags "-X main.version=1.2.3".
var version = "dev"

func main() {
	if len(os.Args) < 2 {
		usage()
		os.Exit(2)
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
    --port N           UDP listen port (default: 51820)

  status   Show enrolment, approval and tunnel state.

  reset    Forget this enrolment.

    --keep-key         re-enrol with the same identity instead of a new one

Requires root on Linux and Administrator on Windows to create the interface.
`)
}
