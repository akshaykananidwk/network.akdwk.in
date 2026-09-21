package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/keystore"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/state"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/wgkey"
)

func runStatus(ctx context.Context, args []string) error {
	fs := flag.NewFlagSet("status", flag.ExitOnError)
	if err := fs.Parse(args); err != nil {
		return err
	}

	stateStore, err := state.Open()
	if err != nil {
		return err
	}

	st, err := stateStore.Load()
	if err != nil {
		return err
	}

	keyStore, err := keystore.Open()
	if err != nil {
		return err
	}

	fmt.Printf("  akconnect-agent %s\n\n", version)

	priv, keyErr := keyStore.Load()
	switch {
	case errors.Is(keyErr, wgkey.ErrNoKey):
		fmt.Printf("  Identity   : none — this machine has never enrolled\n")
	case keyErr != nil:
		fmt.Printf("  Identity   : unreadable — %v\n", keyErr)
	default:
		pub, err := priv.Public()
		if err != nil {
			return err
		}
		fmt.Printf("  Identity   : %s\n", pub.Base64())
		fmt.Printf("  Private key: %s\n", keyStore.Describe())
	}

	if !st.Enrolled() {
		fmt.Printf("\n  Not enrolled. Run 'akconnect-agent enroll --panel URL --join-code CODE'.\n")
		return nil
	}

	fmt.Printf("\n  Panel      : %s\n", st.PanelURL)
	fmt.Printf("  Device     : %s\n", st.DeviceUID)
	fmt.Printf("  Network    : %s\n", st.NetworkUID)

	// The token is a bearer credential. Its presence is worth reporting; its
	// value is not, here or anywhere else.
	if st.Approved() {
		fmt.Printf("  Approved   : yes (device token held)\n")
		fmt.Printf("  Overlay IP : %s\n", orDash(st.VirtualIP))
		fmt.Printf("  Revision   : %d\n", st.Revision)
	} else {
		fmt.Printf("  Approved   : no — waiting for an administrator (R4)\n")
	}

	fmt.Printf("  State file : %s\n", stateStore.Path())

	return printRuntime(stateStore)
}

// printRuntime reports what the running agent knows, or says plainly that
// nothing is running rather than showing a stale picture as if it were live.
func printRuntime(stateStore *state.Store) error {
	rt, err := stateStore.LoadRuntime()
	if err != nil {
		return err
	}

	if rt == nil {
		fmt.Printf("\n  Tunnel     : not running\n")
		return nil
	}

	if !rt.Fresh() {
		fmt.Printf("\n  Tunnel     : not running (last status %s old, from pid %d)\n",
			time.Since(rt.UpdatedAt).Truncate(time.Second), rt.PID)

		return nil
	}

	fmt.Printf("\n  Tunnel     : up on %s, port %d (pid %d)\n", rt.Interface, rt.ListenPort, rt.PID)
	fmt.Printf("  Overlay    : %s via %s\n", orDash(rt.VirtualIP), orDash(rt.OverlayCIDR))
	fmt.Printf("  Public addr: %s\n", orDash(rt.Reflexive))
	fmt.Printf("  Coordinator: %s\n", reachable(rt.CoordinatorUp))
	fmt.Printf("  Panel      : %s\n", reachable(rt.ControlPlaneUp))

	if rt.Names != nil && rt.Names.Zone != "" {
		fmt.Printf("\n  Names      : %d under %s, served at %s\n",
			rt.Names.Records, rt.Names.Zone, rt.Names.Resolver)
		if rt.Names.RoutedBy != "" {
			fmt.Printf("               routed there by %s; every other name is untouched\n",
				rt.Names.RoutedBy)
		} else {
			// Said plainly rather than left to be discovered by a name not
			// resolving: the resolver is running, and nothing is asking it.
			fmt.Printf("               NOT in use — %s\n", orDash(rt.Names.Note))
			fmt.Printf("               addresses still work; this machine's own DNS is unchanged\n")
		}
	}

	if len(rt.Mappings) > 0 {
		// The only place a technician can look this up. The rewriting happens
		// inside the agent, so neither `ip route` on Linux nor `Get-NetNat` on
		// Windows shows which overlay address reaches which machine.
		fmt.Printf("\n  This device is a gateway. Machines on these LANs are reached\n")
		fmt.Printf("  at the matching address in the overlay range:\n\n")
		for _, m := range rt.Mappings {
			fmt.Printf("    %-20s on the site  →  %-20s on the overlay\n", m.LAN, m.Overlay)
		}
	}

	if len(rt.Peers) == 0 {
		fmt.Printf("\n  No peers.\n")
		return nil
	}

	fmt.Printf("\n  Peers:\n")
	for _, p := range rt.Peers {
		fmt.Printf("    %-20s %-14s %-11s %s\n",
			truncate(orDash(p.Name), 20), orDash(p.VirtualIP), p.Path, orDash(p.Endpoint))
		if p.LastHandshakeAgo != "" {
			fmt.Printf("      handshake %s ago, rx %s, tx %s\n",
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
