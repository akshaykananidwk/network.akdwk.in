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
//	akconnect-relay serve --control :9000 --name mumbai-1
//
// The relay does not talk to the panel. It authorises sessions from the
// ticket the coordinator signed, and its usage counters are logged rather
// than reported — see VERIFICATION_REPORT.md for what that means for
// billing.
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
	"strconv"
	"strings"
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
    --data-ports A-B  UDP range for data sockets, e.g. 51900-52400
    --name NAME      this relay's name, for logs and reporting
    --ws-listen ADDR  plain-HTTP address for the HTTPS fallback, e.g. 127.0.0.1:9443
    --ws-path PATH   path the fallback is served on (default /ws)

Environment:
  AKCONNECT_RELAY_SECRET   shared with the coordinator; required

The relay cannot read the traffic it carries.
`)
}

func runServe(args []string) error {
	fs := flag.NewFlagSet("serve", flag.ExitOnError)
	control := fs.String("control", ":9000", "UDP control address")
	coordinator := fs.String("coordinator", "", "coordinator address for usage reports, e.g. 10.0.0.1:8443")
	listenIP := fs.String("listen", "", "address for data sockets")
	idle := fs.Duration("idle", 5*time.Minute, "idle session timeout")
	name := fs.String("name", "relay", "this relay's name")
	dataPorts := fs.String("data-ports", "",
		"UDP range data sockets may use, e.g. 51900-52400; empty lets the kernel choose")
	wsListen := fs.String("ws-listen", "",
		"address for the HTTPS fallback, e.g. 127.0.0.1:9443; empty disables it")
	wsPath := fs.String("ws-path", "/ws", "path the fallback is served on")
	if err := fs.Parse(args); err != nil {
		return err
	}

	portFrom, portTo, err := parsePortRange(*dataPorts)
	if err != nil {
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

	// Usage reporting is optional so a relay can be run standalone for
	// testing, but a relay carrying customer traffic without it is a relay
	// whose bandwidth nobody is billed for.
	var reporter *forwarder.UsageReporter
	if *coordinator != "" {
		created, err := forwarder.NewUsageReporter(*coordinator, *name, []byte(secret), logf)
		if err != nil {
			return fmt.Errorf("usage reporting to %s: %w", *coordinator, err)
		}
		reporter = created
	} else {
		logf("warning: no --coordinator given, so this relay's traffic will not be billed")
	}

	relay, err := forwarder.New(forwarder.Options{
		Control:      *control,
		ListenIP:     *listenIP,
		DataPortFrom: portFrom,
		DataPortTo:   portTo,
		Secret:       []byte(secret),
		IdleTimeout:  *idle,
		Logf:         logf,
		OnUsage: func(usage map[uint64]forwarder.Usage) {
			// Logged as well as reported: the log is what an operator reads
			// when a customer disputes an invoice, and it is the only copy
			// that survives the coordinator being unreachable.
			for tenant, u := range usage {
				logf("usage tenant=%d sessions=%d bytes=%d", tenant, u.Sessions, u.Bytes)
			}

			reporter.Report(usage)
		},
	})
	if err != nil {
		return err
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	if *wsListen != "" {
		edge, err := relay.NewEdge(forwarder.EdgeOptions{Coordinator: *coordinator})
		if err != nil {
			return err
		}

		go func() {
			if err := edge.Serve(ctx, *wsListen, *wsPath); err != nil {
				// Fatal to the fallback, not to the relay: UDP sessions keep
				// working, and saying so is better than exiting and taking
				// them with it.
				logf("the HTTPS fallback stopped: %v", err)
			}
		}()
	} else {
		logf("warning: no --ws-listen given, so devices on UDP-blocked networks cannot connect at all")
	}

	return relay.Run(ctx)
}

// parsePortRange reads "from-to", or nothing.
//
// A relay on a public server needs its data sockets inside a range somebody
// can open in a firewall. Without one the kernel hands out ephemeral ports —
// 32768 to 60999 on a normal Linux box — and "open most of the unprivileged
// port space" is not an instruction to give anybody.
func parsePortRange(spec string) (int, int, error) {
	spec = strings.TrimSpace(spec)
	if spec == "" {
		return 0, 0, nil
	}

	fromText, toText, ok := strings.Cut(spec, "-")
	if !ok {
		return 0, 0, fmt.Errorf("--data-ports must look like 51900-52400, not %q", spec)
	}

	from, err := strconv.Atoi(strings.TrimSpace(fromText))
	if err != nil {
		return 0, 0, fmt.Errorf("--data-ports: %q is not a port number", fromText)
	}
	to, err := strconv.Atoi(strings.TrimSpace(toText))
	if err != nil {
		return 0, 0, fmt.Errorf("--data-ports: %q is not a port number", toText)
	}

	if from < 1024 || to > 65535 || to < from {
		return 0, 0, fmt.Errorf(
			"--data-ports must be a range between 1024 and 65535 with the lower number first, not %q", spec)
	}

	// Two ports per session, and a relay with room for only a handful is one
	// that will start refusing customers without anybody understanding why.
	if to-from+1 < 64 {
		return 0, 0, fmt.Errorf(
			"--data-ports %q leaves room for %d sessions; give it at least 64 ports", spec, (to-from+1)/2)
	}

	return from, to, nil
}
