package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"io"
	"os"
	"sync/atomic"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/keystore"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/state"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/wgkey"
)

// statusDeadline is how long `status` may take in all. It only reads files,
// so anything longer is something blocking, and saying which step blocked is
// worth more than waiting for it.
var statusDeadline = 15 * time.Second

// statusBody is printStatus, replaceable by a test that needs a step to hang.
var statusBody = printStatus

// runStatus prints what this machine is and what the agent is doing.
//
// It must always print something. A field report had it print nothing at all
// in an administrator's command prompt — no version, no error, no "not
// running" — which leaves a technician with no way to tell a hang from a
// crash from a program that never started. So the first line is printed
// before anything is opened, every step runs against a deadline and names
// itself if it overruns, a panic is reported rather than lost, and --out
// writes the same text to a file for the case where the console shows
// nothing.
func runStatus(ctx context.Context, args []string) error {
	fs := flag.NewFlagSet("status", flag.ExitOnError)
	outFile := fs.String("out", "", "also write the output to this file")
	if err := fs.Parse(args); err != nil {
		return err
	}

	w := io.Writer(os.Stdout)
	if *outFile != "" {
		f, err := os.Create(*outFile)
		if err != nil {
			fmt.Fprintf(os.Stderr, "  cannot write %s: %v\n", *outFile, err)
		} else {
			defer f.Close()
			// The file first: when the console is what swallows the
			// output, the file must still get every line.
			w = io.MultiWriter(f, os.Stdout)
		}
	}

	fmt.Fprintf(w, "  akconnect-agent %s — status\n\n", version)

	var step atomic.Value
	step.Store("starting")

	done := make(chan error, 1)
	go func() {
		defer func() {
			if r := recover(); r != nil {
				done <- fmt.Errorf("status failed while %s: %v", step.Load(), r)
			}
		}()
		done <- statusBody(w, &step)
	}()

	select {
	case err := <-done:
		if err != nil {
			fmt.Fprintf(w, "\n  error: %v\n", err)
		}

		return err
	case <-time.After(statusDeadline):
		err := fmt.Errorf("status gave up after %s while %s", statusDeadline, step.Load())
		fmt.Fprintf(w, "\n  %v\n", err)

		return err
	}
}

func printStatus(w io.Writer, step *atomic.Value) error {
	step.Store("opening the state directory")
	stateStore, err := state.Open()
	if err != nil {
		return err
	}

	// A step that fails is reported and the rest still runs: the live status
	// is readable by any user even when the state file and the key are not,
	// and it is the part that answers "what is the tunnel doing".
	step.Store("reading " + stateStore.Path())
	st, err := stateStore.Load()
	if err != nil {
		fmt.Fprintf(w, "  State file : %s unreadable — %v\n", stateStore.Path(), err)
		fmt.Fprintf(w, "               (run as administrator to see enrolment and identity)\n")
		fmt.Fprintf(w, "  Live status: %s\n", stateStore.RuntimePath())
		step.Store("reading " + stateStore.RuntimePath())

		return printRuntime(w, stateStore)
	}

	step.Store("opening the key store")
	keyStore, err := keystore.Open()
	var priv wgkey.Private
	keyErr := err
	if err == nil {
		step.Store("reading the device identity")
		priv, keyErr = keyStore.Load()
	}
	switch {
	case err != nil:
		fmt.Fprintf(w, "  Identity   : key store unavailable — %v\n", err)
	case errors.Is(keyErr, wgkey.ErrNoKey):
		fmt.Fprintf(w, "  Identity   : none — this machine has never enrolled\n")
	case keyErr != nil:
		fmt.Fprintf(w, "  Identity   : unreadable — %v\n", keyErr)
	default:
		pub, err := priv.Public()
		if err != nil {
			return err
		}
		fmt.Fprintf(w, "  Identity   : %s\n", pub.Base64())
		fmt.Fprintf(w, "  Private key: %s\n", keyStore.Describe())
	}

	if !st.Enrolled() {
		fmt.Fprintf(w, "\n  Not enrolled. Run 'akconnect-agent enroll --panel URL --join-code CODE'.\n")
		return nil
	}

	fmt.Fprintf(w, "\n  Panel      : %s\n", st.PanelURL)
	fmt.Fprintf(w, "  Device     : %s\n", st.DeviceUID)
	fmt.Fprintf(w, "  Network    : %s\n", st.NetworkUID)

	// The token is a bearer credential. Its presence is worth reporting; its
	// value is not, here or anywhere else.
	if st.Approved() {
		fmt.Fprintf(w, "  Approved   : yes (device token held)\n")
		fmt.Fprintf(w, "  Overlay IP : %s\n", orDash(st.VirtualIP))
		fmt.Fprintf(w, "  Revision   : %d\n", st.Revision)
	} else {
		fmt.Fprintf(w, "  Approved   : no — waiting for an administrator (R4)\n")
	}

	fmt.Fprintf(w, "  State file : %s\n", stateStore.Path())
	fmt.Fprintf(w, "  Live status: %s\n", stateStore.RuntimePath())

	// Where the service writes, which is the first thing to ask for when
	// somebody says "it just stops".
	if path := state.ServiceLogPath(); path != "" {
		if _, err := os.Stat(path); err == nil {
			fmt.Fprintf(w, "  Service log: %s\n", path)
		}
	}

	step.Store("reading " + stateStore.RuntimePath())

	return printRuntime(w, stateStore)
}

// printRuntime reports what the running agent knows, or says plainly that
// nothing is running rather than showing a stale picture as if it were live.
func printRuntime(w io.Writer, stateStore *state.Store) error {
	rt, err := stateStore.LoadRuntime()
	if err != nil {
		// Said with what is on disk, because "empty" and "unreadable" have
		// different causes and a field report could not tell them apart.
		desc := "missing"
		if fi, statErr := os.Stat(stateStore.RuntimePath()); statErr == nil {
			desc = fmt.Sprintf("%d bytes, written %s ago", fi.Size(), time.Since(fi.ModTime()).Truncate(time.Second))
		}
		fmt.Fprintf(w, "\n  Tunnel     : unknown — the live status file (%s) cannot be read: %v\n", desc, err)
		fmt.Fprintf(w, "               the service log says why it was not written\n")

		return nil
	}

	if rt == nil {
		fmt.Fprintf(w, "\n  Tunnel     : not running (no live status file — the service writes it every few seconds while up)\n")
		return nil
	}

	if !rt.Fresh() {
		fmt.Fprintf(w, "\n  Tunnel     : not running (last status %s old, from pid %d)\n",
			time.Since(rt.UpdatedAt).Truncate(time.Second), rt.PID)

		return nil
	}

	fmt.Fprintf(w, "\n  Tunnel     : up on %s, port %d (pid %d)\n", rt.Interface, rt.ListenPort, rt.PID)
	fmt.Fprintf(w, "  Overlay    : %s via %s\n", orDash(rt.VirtualIP), orDash(rt.OverlayCIDR))
	fmt.Fprintf(w, "  Public addr: %s\n", orDash(rt.Reflexive))
	fmt.Fprintf(w, "  Coordinator: %s\n", reachable(rt.CoordinatorUp))
	fmt.Fprintf(w, "  Panel      : %s\n", reachable(rt.ControlPlaneUp))

	if rt.Names != nil && rt.Names.Zone != "" {
		fmt.Fprintf(w, "\n  Names      : %d under %s, served at %s\n",
			rt.Names.Records, rt.Names.Zone, rt.Names.Resolver)
		if rt.Names.RoutedBy != "" {
			fmt.Fprintf(w, "               routed there by %s; every other name is untouched\n",
				rt.Names.RoutedBy)
		} else {
			// Said plainly rather than left to be discovered by a name not
			// resolving: the resolver is running, and nothing is asking it.
			fmt.Fprintf(w, "               NOT in use — %s\n", orDash(rt.Names.Note))
			fmt.Fprintf(w, "               addresses still work; this machine's own DNS is unchanged\n")
		}
	}

	if len(rt.Mappings) > 0 {
		// The only place a technician can look this up. The rewriting happens
		// inside the agent, so neither `ip route` on Linux nor `Get-NetNat` on
		// Windows shows which overlay address reaches which machine.
		fmt.Fprintf(w, "\n  This device is a gateway. Machines on these LANs are reached\n")
		fmt.Fprintf(w, "  at the matching address in the overlay range:\n\n")
		for _, m := range rt.Mappings {
			fmt.Fprintf(w, "    %-20s on the site  →  %-20s on the overlay\n", m.LAN, m.Overlay)
		}
	}
	if g := rt.Gateway; g != nil {
		switch {
		case g.Problem != "":
			fmt.Fprintf(w, "\n  Gateway    : NOT working — %s\n", g.Problem)
		case g.Mode == "agent":
			fmt.Fprintf(w, "\n  Gateway    : translated by the agent itself (no WinNAT, RRAS or router change needed)\n")
		default:
			fmt.Fprintf(w, "\n  Gateway    : translated by the operating system (iptables MASQUERADE)\n")
		}
		if g.Mode == "agent" {
			// Per hop: diverted = arrived through the tunnel for the LAN;
			// the rest = what the LAN machines did about it.
			fmt.Fprintf(w, "               arrived for the LAN %d; pings answered %d, unanswered %d\n",
				g.Diverted, g.PingsOK, g.PingsFailed)
			fmt.Fprintf(w, "               TCP opened %d, refused %d; UDP flows %d; dropped %d\n",
				g.TCPOpened, g.TCPRefused, g.UDPOpened, g.Dropped)
		}
	}

	if len(rt.Peers) == 0 {
		fmt.Fprintf(w, "\n  No peers.\n")
		return nil
	}

	fmt.Fprintf(w, "\n  Peers:\n")
	for _, p := range rt.Peers {
		fmt.Fprintf(w, "    %-20s %-14s %-11s %s\n",
			truncate(orDash(p.Name), 20), orDash(p.VirtualIP), p.Path, orDash(p.Endpoint))
		if p.LastHandshakeAgo != "" {
			fmt.Fprintf(w, "      handshake %s ago, rx %s, tx %s\n",
				p.LastHandshakeAgo, humanBytes(p.RXBytes), humanBytes(p.TXBytes))
		}
	}

	return nil
}

func reachable(ok bool) string {
	if ok {
		return "reachable"
	}

	return "not reachable"
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}

	return s[:n-1] + "…"
}

func humanBytes(b int64) string {
	const unit = 1024
	if b < unit {
		return fmt.Sprintf("%d B", b)
	}

	div, exp := int64(unit), 0
	for n := b / unit; n >= unit; n /= unit {
		div *= unit
		exp++
	}

	return fmt.Sprintf("%.1f %cB", float64(b)/float64(div), "KMGT"[exp])
}

func runReset(args []string) error {
	fs := flag.NewFlagSet("reset", flag.ExitOnError)
	keepKey := fs.Bool("keep-key", false, "keep the device identity")
	if err := fs.Parse(args); err != nil {
		return err
	}

	stateStore, err := state.Open()
	if err != nil {
		return err
	}
	if err := stateStore.Clear(); err != nil {
		return err
	}
	fmt.Printf("  Enrolment forgotten.\n")

	if *keepKey {
		fmt.Printf("  Device identity kept — re-enrolling will reuse the same public key.\n")
		return nil
	}

	keyStore, err := keystore.Open()
	if err != nil {
		return err
	}
	if err := keyStore.Clear(); err != nil {
		return err
	}

	fmt.Printf("  Device identity deleted — re-enrolling will generate a new one.\n")
	fmt.Printf("  The old device will still be listed in the panel; revoke it there.\n")

	return nil
}

func orDash(s string) string {
	if s == "" {
		return "—"
	}

	return s
}
