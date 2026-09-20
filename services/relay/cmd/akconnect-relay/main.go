// Command akconnect-relay carries traffic for peer pairs that cannot reach
// each other directly.
//
// It forwards already-encrypted WireGuard packets and is never a party to the
// peers' handshake. It holds no WireGuard key and cannot decrypt anything it
// carries; a compromised relay yields volumes, timing and addresses, never
// plaintext. Say that to customers plainly — a private overlay that quietly
// proxies traffic through the vendor is exactly what they are trying to avoid.
//
// Usage:
//
//	akconnect-relay serve --control :9000 --panel https://net.example.com --name mumbai-1
//
// Environment:
//
//	AKCONNECT_RELAY_SECRET   shared with the coordinator; verifies tickets
package main

import (
	"context"
	"flag"
	"fmt"
	"log"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/relay/internal/forwarder"
)

var version = "dev"

func main() {
	if len(os.Args) < 2 {
		usage()
		os.Exit(2)
	}

	var err error

	switch os.Args[1] {
	case "serve":
		err = runServe(os.Args[2:])
	case "version", "--version", "-v":
		fmt.Printf("akconnect-relay %s\n", version)
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
	fmt.Fprint(os.Stderr, `akconnect-relay — fallback path for peers that cannot connect directly

  serve    Run the relay.

    --control ADDR   UDP address for bind requests (default :9000)
    --listen IP      address for data sockets (default: all)
    --idle DURATION  close a session idle this long (default 5m)
    --name NAME      this relay's name, for logs and reporting

Environment:
  AKCONNECT_RELAY_SECRET   shared with the coordinator; required

The relay cannot read the traffic it carries.
`)
}

func runServe(args []string) error {
	fs := flag.NewFlagSet("serve", flag.ExitOnError)
	control := fs.String("control", ":9000", "UDP control address")
	listenIP := fs.String("listen", "", "address for data sockets")
	idle := fs.Duration("idle", 5*time.Minute, "idle session timeout")
	name := fs.String("name", "relay", "this relay's name")
	if err := fs.Parse(args); err != nil {
		return err
	}

	// From the environment, never a flag: a flag is visible in ps to every
	// user on the box.
	secret := os.Getenv("AKCONNECT_RELAY_SECRET")
	if secret == "" {
		return fmt.Errorf("AKCONNECT_RELAY_SECRET is not set; it must match the coordinator's secret for this relay")
	}

	logger := log.New(os.Stdout, "["+*name+"] ", log.LstdFlags)
	logf := func(format string, a ...any) { logger.Printf(format, a...) }

	relay, err := forwarder.New(forwarder.Options{
		Control:     *control,
		ListenIP:    *listenIP,
		Secret:      []byte(secret),
		IdleTimeout: *idle,
		Logf:        logf,
		OnUsage: func(usage map[uint64]forwarder.Usage) {
			// Reporting to the panel is the next piece of work; logging it
			// means the numbers are observable in the meantime rather than
			// silently accumulating.
			for tenant, u := range usage {
				logf("usage tenant=%d sessions=%d bytes=%d", tenant, u.Sessions, u.Bytes)
			}
		},
	})
	if err != nil {
		return err
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	return relay.Run(ctx)
}
