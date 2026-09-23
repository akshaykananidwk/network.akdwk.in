package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"os"
	"runtime"
	"strings"
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
	force := fs.Bool("force", false, "enrol again even if this machine already has an identity")
	if err := fs.Parse(args); err != nil {
		return err
	}

	if *panelURL == "" || *joinCode == "" {
		return errors.New("--panel and --join-code are both required")
	}

	stateStore, err := state.Open()
	if err != nil {
		return err
	}

	// Already enrolled on this panel? Then this is an upgrade, and the
	// identity on disk is the one to keep.
	//
	// It used to enrol regardless, overwriting the state file with a fresh one
	// — which threw away the device token and the overlay address, so a
	// machine that was approved came back from an upgrade looking unapproved.
	// It also spent a use of the join code, and a pre-approved code is
	// single-use, so the second run of the installer was refused by the code
	// the first run had used.
	if existing, err := stateStore.Load(); err == nil && !*force {
		if existing.Enrolled() && sameHost(existing.PanelURL, *panelURL) {
			fmt.Printf("  This machine is already enrolled on %s as %s.\n", existing.PanelURL, existing.DeviceUID)

			// But is it still, from the panel's side? A device deleted in the
			// panel leaves a machine that believes it is enrolled, and the
			// customer's remedy — run the installer again — used to print
			// "nothing to do" and change nothing. They had done the one thing
			// they know how to do and it was refused.
			//
			// So the panel is asked. Only a clear "I have never heard of this
			// device" re-enrols; anything else, including being unable to ask,
			// keeps the identity. Discarding a working enrolment because the
			// panel was briefly down would be a far worse failure than the one
			// this fixes, and it would spend a single-use join code doing it.
			switch stillKnown(ctx, *panelURL, existing) {
			case known:
				if existing.Approved() {
					fmt.Printf("  Approved, address %s. Nothing to do.\n", existing.VirtualIP)
				} else {
					fmt.Printf("  Still waiting for an administrator to approve it; the agent will\n")
					fmt.Printf("  pick that up by itself.\n")
				}

				fmt.Printf("\n  Use -force to discard this identity and enrol again.\n")

				return nil
			case unreachable:
				fmt.Printf("  The panel could not be reached, so this identity is being kept.\n")
				fmt.Printf("  Nothing has been changed. Try again when the computer is online.\n")

				return nil
			case forgotten:
				fmt.Printf("  The panel no longer has a record of this device, so it is joining\n")
				fmt.Printf("  again with the code given. Its identity key is unchanged.\n\n")
			}
		}
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

	// Built on what is already there rather than replacing it, so a re-enrol
	// of the same device on the same panel keeps its token and its address.
	st, err := stateStore.Load()
	if err != nil || st == nil {
		st = &state.State{}
	}

	if !sameHost(st.PanelURL, *panelURL) || st.DeviceUID != enrolled.DeviceUID {
		// A different panel or a different device: the old token is worthless
		// and keeping it would be worse than clearing it.
		st.DeviceToken = ""
		st.VirtualIP = ""
		st.Revision = 0
	}

	st.PanelURL = *panelURL
	st.DeviceUID = enrolled.DeviceUID
	st.NetworkUID = enrolled.NetworkUID

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

// sameHost compares two panel URLs without tripping over a trailing slash or
// a difference in case.
func sameHost(a, b string) bool {
	return strings.EqualFold(strings.TrimRight(a, "/"), strings.TrimRight(b, "/"))
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

// knownness is what the panel says about a device this machine believes it is.
type knownness int

const (
	// known: the panel has a record. Approved or not, it is the panel's to
	// decide and there is nothing to re-enrol.
	known knownness = iota
	// forgotten: the panel has no record of this key. Deleted, or restored
	// from a backup taken before this device existed.
	forgotten
	// unreachable: no answer. Not a verdict, and never treated as one.
	unreachable
)

// stillKnown asks the panel whether it has a record of this device.
//
// Deliberately conservative. The only outcome that discards anything is the
// panel answering, in as many words, that it has no enrolment for this key.
func stillKnown(ctx context.Context, panelURL string, st *state.State) knownness {
	store, err := keystore.Open()
	if err != nil {
		return unreachable
	}

	priv, err := store.Load()
	if err != nil {
		return unreachable
	}

	pub, err := priv.Public()
	if err != nil {
		return unreachable
	}

	client, err := panel.New(panel.Options{
		BaseURL:   panelURL,
		UserAgent: "akconnect-agent/" + version,
	})
	if err != nil {
		return unreachable
	}

	// Bounded: this runs inside an installer with a customer watching it, and
	// a panel that is not answering must not hold the dialog open.
	ctx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()

	switch _, err := client.Claim(ctx, st.DeviceUID, pub.Base64()); {
	case errors.Is(err, panel.ErrNotEnrolled):
		return forgotten
	case err != nil:
		return unreachable
	default:
		return known
	}
}
