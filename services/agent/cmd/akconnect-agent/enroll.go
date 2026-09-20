package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"os"
	"runtime"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/keystore"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/state"
)

func runEnroll(ctx context.Context, args []string) error {
	fs := flag.NewFlagSet("enroll", flag.ExitOnError)
	panelURL := fs.String("panel", "", "panel base URL")
	joinCode := fs.String("join-code", "", "join code from an administrator")
	name := fs.String("name", "", "device name (default: hostname)")
	wait := fs.Bool("wait", false, "keep polling until approved")
	if err := fs.Parse(args); err != nil {
		return err
	}

	if *panelURL == "" || *joinCode == "" {
		return errors.New("--panel and --join-code are both required")
	}

	hostname := *name
	if hostname == "" {
		hostname, _ = os.Hostname()
	}

	store, err := keystore.Open()
	if err != nil {
		return err
	}

	priv, pub, created, err := keystore.LoadOrCreate(store)
	if err != nil {
		return err
	}
	_ = priv // The private key stays here; only pub is sent.

	if created {
		fmt.Printf("  Generated a new device identity.\n")
	} else {
		fmt.Printf("  Using the existing device identity.\n")
	}
	fmt.Printf("  Private key: %s — it does not leave this machine.\n", store.Describe())
	fmt.Printf("  Public key : %s\n\n", pub.Base64())

	client, err := panel.New(panel.Options{
		BaseURL:   *panelURL,
		UserAgent: "akconnect-agent/" + version,
	})
	if err != nil {
		return err
	}

	enrolled, err := client.Enroll(ctx, panel.EnrollRequest{
		JoinCode:     *joinCode,
		PublicKey:    pub.Base64(),
		Hostname:     hostname,
		OS:           runtime.GOOS,
		Arch:         runtime.GOARCH,
		AgentVersion: version,
	})
	if err != nil {
		return fmt.Errorf("enrolling: %w", err)
	}

	fmt.Printf("  Enrolled as %s\n", enrolled.DeviceUID)
	fmt.Printf("  Status     : %s\n", enrolled.Status)

	stateStore, err := state.Open()
	if err != nil {
		return err
	}

	st := &state.State{
		PanelURL:   *panelURL,
		DeviceUID:  enrolled.DeviceUID,
		NetworkUID: enrolled.NetworkUID,
	}
	if err := stateStore.Save(st); err != nil {
		return err
	}

	// R4: enrolment is not admission. Until an administrator approves the
	// device there is no token and no address, and saying so plainly here is
	// better than leaving the operator to wonder why nothing happened.
	if enrolled.Status != "authorized" {
		fmt.Printf("\n  This device is waiting for an administrator to approve it.\n")
		fmt.Printf("  Nothing connects until they do.\n")
	}

	if !*wait {
		fmt.Printf("\n  Run 'akconnect-agent up' once approved.\n")
		return nil
	}

	return waitForApproval(ctx, client, stateStore, st, pub.Base64())
}

// waitForApproval polls until an administrator lets the device in, honouring
// the interval the panel asks for rather than picking one.
func waitForApproval(ctx context.Context, client *panel.Client, store *state.Store, st *state.State, publicKey string) error {
	fmt.Printf("\n  Waiting for approval (Ctrl-C to stop)")

	for {
		claim, err := client.Claim(ctx, st.DeviceUID, publicKey)
		if errors.Is(err, panel.ErrNotEnrolled) {
			return fmt.Errorf("the panel no longer recognises this device; run 'reset' and enrol again")
		}
		if err != nil {
			return err
		}

		if claim.Authorized {
			st.DeviceToken = claim.DeviceToken
			st.VirtualIP = claim.VirtualIP
			if err := store.Save(st); err != nil {
				return err
			}

			fmt.Printf("\n\n  Approved. Address on the overlay: %s\n", claim.VirtualIP)
			fmt.Printf("  Run 'akconnect-agent up' to connect.\n")

			return nil
		}

		fmt.Print(".")

		delay := time.Duration(claim.PollAfter) * time.Second
		if delay <= 0 {
			delay = 15 * time.Second
		}

		select {
		case <-ctx.Done():
			fmt.Printf("\n  Stopped waiting. The enrolment stands; run 'up' once approved.\n")
			return nil
		case <-time.After(delay):
		}
	}
}
