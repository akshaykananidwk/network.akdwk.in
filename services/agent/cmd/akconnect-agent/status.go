package main

import (
	"context"
	"errors"
	"flag"
	"fmt"

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

	return nil
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
