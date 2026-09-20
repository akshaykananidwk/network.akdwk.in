package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"os"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/keystore"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/state"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/tunnel"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/wgkey"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/winenv"
)

// runSelftest exercises the parts of the agent that depend on the operating
// system, and reports each one as a pass or a failure with the actual error.
//
// It exists because those are the parts nobody can test from a build machine:
// the keystore's platform backend, the driver load, the privilege check. A
// tester running this gets a specific answer — "DPAPI unseal failed with X" —
// rather than "the agent did not start".
//
// Every check is independent and none of them stops the others running, so a
// broken machine still produces a complete report.
func runSelftest(ctx context.Context, args []string) error {
	fs := flag.NewFlagSet("selftest", flag.ExitOnError)
	keepAdapter := fs.Bool("keep-adapter", false, "leave the test adapter in place")
	if err := fs.Parse(args); err != nil {
		return err
	}

	fmt.Printf("  akconnect-agent %s self-test\n", version)
	fmt.Printf("  %s\n\n", osDescription())

	results := []checkResult{
		checkPrivileges(),
		checkDriver(),
		checkKeystoreRoundTrip(),
		checkExistingIdentity(),
		checkStateDirectory(),
		checkAdapter(*keepAdapter),
	}

	failed := 0
	for _, r := range results {
		if !r.ok {
			failed++
		}
	}

	fmt.Printf("\n  %d of %d checks passed.\n", len(results)-failed, len(results))

	if failed > 0 {
		fmt.Printf("  Send this output back; each failure above names what to fix.\n")

		return fmt.Errorf("%d check(s) failed", failed)
	}

	return nil
}

type checkResult struct {
	ok bool
}

// report prints one check and returns its result, so every check is a single
// expression and none of them can forget to print.
func report(name string, err error, detail string) checkResult {
	if err != nil {
		fmt.Printf("  FAIL  %-34s %v\n", name, err)

		return checkResult{ok: false}
	}

	fmt.Printf("  ok    %-34s %s\n", name, detail)

	return checkResult{ok: true}
}

func checkPrivileges() checkResult {
	if !winenv.IsAdmin() {
		return report("administrator rights", errors.New(
			"not elevated; creating a network adapter will fail"), "")
	}

	return report("administrator rights", nil, "elevated")
}

// checkDriver reports whether the platform's tunnel driver can be found. On
// Windows that is wintun.dll, which is the single most common reason a
// first run fails.
func checkDriver() checkResult {
	problems := winenv.Preflight()

	for _, p := range problems {
		if p.Fatal {
			return report("tunnel driver present", fmt.Errorf("%s %s", p.Summary, p.Remedy), "")
		}
	}

	return report("tunnel driver present", nil, "found")
}

// checkKeystoreRoundTrip writes a throwaway key and reads it back.
//
// This is the DPAPI test on Windows: sealing and unsealing through the real
// code path, in the real location, with the real entropy. A failure here after
// a reboot or a password change is exactly the thing we need to know about.
func checkKeystoreRoundTrip() checkResult {
	store, err := keystore.Open()
	if err != nil {
		return report("keystore round trip", err, "")
	}

	// Whatever is already there must survive this test: the device's real
	// identity is not something a diagnostic may destroy.
	existing, existingErr := store.Load()
	hadIdentity := existingErr == nil

	priv, _, err := wgkey.Generate()
	if err != nil {
		return report("keystore round trip", err, "")
	}

	defer func() {
		if hadIdentity {
			_ = store.Save(existing)

			return
		}
		_ = store.Clear()
	}()

	if err := store.Save(priv); err != nil {
		return report("keystore round trip", fmt.Errorf("writing: %w", err), "")
	}

	readBack, err := store.Load()
	if err != nil {
		return report("keystore round trip", fmt.Errorf("reading back: %w", err), "")
	}

	if readBack != priv {
		return report("keystore round trip",
			errors.New("the key read back did not match the key written"), "")
	}

	return report("keystore round trip", nil, store.Describe())
}

// checkExistingIdentity proves the stored key still unseals — which after a
// reboot or a password change is the question being asked.
func checkExistingIdentity() checkResult {
	store, err := keystore.Open()
	if err != nil {
		return report("stored identity unseals", err, "")
	}

	priv, err := store.Load()
	if errors.Is(err, wgkey.ErrNoKey) {
		return report("stored identity unseals", nil, "none stored yet (not enrolled)")
	}
	if err != nil {
		return report("stored identity unseals", err, "")
	}

	pub, err := priv.Public()
	if err != nil {
		return report("stored identity unseals", err, "")
	}

	return report("stored identity unseals", nil, pub.Base64())
}

func checkStateDirectory() checkResult {
	store, err := state.Open()
	if err != nil {
		return report("state directory writable", err, "")
	}

	st, err := store.Load()
	if err != nil {
		return report("state directory writable", err, "")
	}
	if err := store.Save(st); err != nil {
		return report("state directory writable", err, "")
	}

	return report("state directory writable", nil, store.Path())
}

// checkAdapter creates a real tunnel interface and tears it down.
//
// This is the Wintun lifecycle test: creating the adapter, and — more usefully
// — whether creating it succeeds when a previous run was killed and left one
// behind. A failure with "already exists" is the stale-adapter case.
func checkAdapter(keep bool) checkResult {
	t, err := tunnel.Open(tunnel.Options{
		InterfaceName: "akc-selftest",
		ListenPort:    0,
		Logf:          func(string, ...any) {},
	})
	if err != nil {
		return report("create and remove an adapter", err, "")
	}

	name := t.Name()

	if keep {
		return report("create and remove an adapter", nil, name+" (left in place)")
	}

	if err := t.Close(); err != nil {
		return report("create and remove an adapter", fmt.Errorf("created %s but could not remove it: %w", name, err), "")
	}

	return report("create and remove an adapter", nil, name+" created and removed")
}

func osDescription() string {
	host, _ := os.Hostname()

	return fmt.Sprintf("host %s, %s/%s", host, goos(), goarch())
}
