// Command akconnect-coordinator introduces overlay peers to one another.
//
// It learns each agent's public address by observing where its packets come
// from, and passes that address to the peers the panel says it may talk to.
// It never carries data traffic: once two agents know where to find each
// other they talk directly, and the coordinator can be stopped without any
// established tunnel noticing (R6).
//
// Usage:
//
//	akconnect-coordinator serve --listen :8443 --panel https://net.example.com
//	akconnect-coordinator keygen
//
// Configuration comes from flags or the environment:
//
//	AKCONNECT_COORDINATOR_KEY      base64 X25519 private key (required)
//	AKCONNECT_COORDINATOR_SECRET   shared secret for the panel (required)
//	AKCONNECT_PANEL_URL            panel base URL
package main

import (
	"context"
	"crypto/rand"
	"encoding/base64"
	"flag"
	"fmt"
	"log"
	"os"
	"os/signal"
	"syscall"
	"time"

	"golang.org/x/crypto/curve25519"

	"github.com/akshaykananidwk/network.akdwk.in/services/coordinator/internal/panelapi"
	"github.com/akshaykananidwk/network.akdwk.in/services/coordinator/internal/server"
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
	case "keygen":
		err = runKeygen()
	case "version", "--version", "-v":
		fmt.Printf("akconnect-coordinator %s\n", version)
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
	fmt.Fprint(os.Stderr, `akconnect-coordinator — peer rendezvous for the overlay network

  serve    Run the coordinator.

    --listen ADDR   UDP address to bind (default :8443)
    --panel URL     panel base URL
    --ttl DURATION  how long a device stays introducible (default 2m)

  keygen   Generate an X25519 keypair. The private half goes in
           AKCONNECT_COORDINATOR_KEY; the public half goes in the panel's
           coordinator.public_key so agents know what to seal to.

Environment:
  AKCONNECT_COORDINATOR_KEY     base64 X25519 private key (required)
  AKCONNECT_COORDINATOR_SECRET  shared secret for the panel (required)
  AKCONNECT_PANEL_URL           panel base URL, if --panel is not given
`)
}

func runServe(args []string) error {
	fs := flag.NewFlagSet("serve", flag.ExitOnError)
	listen := fs.String("listen", ":8443", "UDP address to bind")
	panelURL := fs.String("panel", os.Getenv("AKCONNECT_PANEL_URL"), "panel base URL")
	ttl := fs.Duration("ttl", 2*time.Minute, "presence lifetime")
	if err := fs.Parse(args); err != nil {
		return err
	}

	// Keys and secrets come from the environment, never a flag: a flag is
	// visible in ps to every user on the machine.
	privateKey, err := keyFromEnv("AKCONNECT_COORDINATOR_KEY")
	if err != nil {
		return err
	}

	secret := os.Getenv("AKCONNECT_COORDINATOR_SECRET")
	if secret == "" {
		return fmt.Errorf("AKCONNECT_COORDINATOR_SECRET is not set; it must match the panel's coordinator.shared_secret")
	}

	panelClient, err := panelapi.New(*panelURL, secret)
	if err != nil {
		return err
	}

	logger := log.New(os.Stdout, "", log.LstdFlags)

	srv, err := server.New(server.Options{
		Listen:      *listen,
		PrivateKey:  privateKey,
		Panel:       panelClient,
		PresenceTTL: *ttl,
		Logf:        func(format string, a ...any) { logger.Printf(format, a...) },
	})
	if err != nil {
		return err
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	return srv.Run(ctx)
}

func runKeygen() error {
	var priv [32]byte
	if _, err := rand.Read(priv[:]); err != nil {
		return err
	}

	priv[0] &= 248
	priv[31] &= 127
	priv[31] |= 64

	pubSlice, err := curve25519.X25519(priv[:], curve25519.Basepoint)
	if err != nil {
		return err
	}

	fmt.Printf("  Private key (AKCONNECT_COORDINATOR_KEY):\n    %s\n\n",
		base64.StdEncoding.EncodeToString(priv[:]))
	fmt.Printf("  Public key (panel coordinator.public_key):\n    %s\n\n",
		base64.StdEncoding.EncodeToString(pubSlice))
	fmt.Printf("  Keep the private key out of version control and out of flags.\n")

	return nil
}

func keyFromEnv(name string) ([32]byte, error) {
	encoded := os.Getenv(name)
	if encoded == "" {
		return [32]byte{}, fmt.Errorf("%s is not set; run 'akconnect-coordinator keygen' to make one", name)
	}

	raw, err := base64.StdEncoding.DecodeString(encoded)
	if err != nil {
		return [32]byte{}, fmt.Errorf("%s is not valid base64: %w", name, err)
	}
	if len(raw) != 32 {
		return [32]byte{}, fmt.Errorf("%s is %d bytes, want 32", name, len(raw))
	}

	var key [32]byte
	copy(key[:], raw)

	return key, nil
}
