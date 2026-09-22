package main

import (
	"context"
	"flag"
	"fmt"
	"os"
	"path/filepath"
	"runtime"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/selfupdate"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/state"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/winsvc"
)

// runUpdate handles "akconnect-agent update".
//
// §14: fixing a defect must not mean visiting every machine. This is the
// manual half — a technician can run it and watch each step — and the same
// steps run by themselves from the service loop.
//
//	akconnect-agent update            check, download, verify, install, restart
//	akconnect-agent update --check    say what is available and stop
//	akconnect-agent update --no-restart   install, leave restarting to somebody
func runUpdate(ctx context.Context, args []string) error {
	fs := flag.NewFlagSet("update", flag.ExitOnError)
	checkOnly := fs.Bool("check", false, "report what is available and change nothing")
	noRestart := fs.Bool("no-restart", false, "install but do not restart the service")
	if err := fs.Parse(args); err != nil {
		return err
	}

	store, err := state.Open()
	if err != nil {
		return err
	}

	st, err := store.Load()
	if err != nil {
		return err
	}

	if !st.Enrolled() {
		return fmt.Errorf("this device is not enrolled, so there is no panel to ask")
	}
	if !st.Approved() {
		return fmt.Errorf("this device is not approved yet; nothing will be offered to it")
	}

	client, err := panel.New(panel.Options{
		BaseURL:   st.PanelURL,
		Token:     st.DeviceToken,
		UserAgent: "akconnect-agent/" + version,
	})
	if err != nil {
		return err
	}

	fmt.Printf("  Running  : %s\n", version)
	fmt.Printf("  Asking   : %s\n", st.PanelURL)

	offer, err := client.CheckUpdate(ctx, runtime.GOOS, runtime.GOARCH)
	if err != nil {
		return fmt.Errorf("asking the panel: %w", err)
	}

	if !offer.Available || offer.Version == "" {
		fmt.Printf("\n  Nothing newer is offered to this device.\n")

		return nil
	}

	fmt.Printf("  Offered  : %s (%d bytes)\n", offer.Version, offer.Size)
	if offer.Notes != "" {
		fmt.Printf("  Notes    : %s\n", offer.Notes)
	}

	if *checkOnly {
		fmt.Printf("\n  --check: nothing was downloaded.\n")

		return nil
	}

	// The controller's public key comes from the configuration the panel
	// publishes, which is cached on disk. Without it there is nothing to check
	// a signature against, and an unverified binary is not installed.
	controllerKey, err := controllerPublicKey(ctx, client)
	if err != nil {
		return err
	}

	staged, err := download(ctx, client, offer)
	if err != nil {
		return err
	}
	defer func() {
		// Removed unless it was moved into place, in which case the remove
		// finds nothing and does nothing.
		_ = os.Remove(staged)
	}()

	fmt.Printf("  Verifying: sha256 and the controller's signature\n")

	if err := selfupdate.Verify(staged, offer.SHA256, offer.Signature, controllerKey); err != nil {
		return fmt.Errorf("refusing this release: %w", err)
	}

	fmt.Printf("  Verified : the digest is signed by this panel's controller key\n")

	previous, err := selfupdate.Apply(staged)
	if err != nil {
		return err
	}

	fmt.Printf("  Installed: %s is now %s\n", filepath.Base(exePath()), offer.Version)
	fmt.Printf("  Previous : %s (removed at the next start)\n", filepath.Base(previous))

	if *noRestart {
		fmt.Printf("\n  --no-restart: the new binary runs at the next restart.\n")

		return nil
	}

	return restartAfterUpdate()
}

// download fetches the offered release beside the running binary.
//
// Beside it deliberately: the install is a rename, and a rename across
// filesystems fails — at the worst possible moment, after the running binary
// has already been moved aside.
func download(ctx context.Context, client *panel.Client, offer *panel.UpdateOffer) (string, error) {
	dir := filepath.Dir(exePath())

	file, err := os.CreateTemp(dir, ".akconnect-agent-update-*")
	if err != nil {
		return "", fmt.Errorf("could not stage a download in %s: %w", dir, err)
	}
	staged := file.Name()

	fmt.Printf("  Fetching : %s\n", offer.URL)

	written, err := client.DownloadTo(ctx, offer.URL, file)
	closeErr := file.Close()

	if err != nil {
		_ = os.Remove(staged)

		return "", err
	}
	if closeErr != nil {
		_ = os.Remove(staged)

		return "", closeErr
	}

	if offer.Size > 0 && written != offer.Size {
		_ = os.Remove(staged)

		return "", fmt.Errorf("the download is %d bytes, the panel said %d", written, offer.Size)
	}

	fmt.Printf("  Fetched  : %d bytes\n", written)

	return staged, nil
}

// controllerPublicKey reads the key from the panel's configuration.
func controllerPublicKey(ctx context.Context, client *panel.Client) (string, error) {
	cfg, _, err := client.FetchConfig(ctx, 0)
	if err != nil {
		return "", fmt.Errorf("reading the configuration to find the controller key: %w", err)
	}

	if cfg == nil || cfg.Controller.PublicKey == "" {
		return "", fmt.Errorf(
			"this panel publishes no controller public key, so a release cannot be verified. " +
				"An unverified binary is not installed")
	}

	return cfg.Controller.PublicKey, nil
}

func exePath() string {
	self, err := os.Executable()
	if err != nil {
		return "akconnect-agent"
	}

	if resolved, err := filepath.EvalSymlinks(self); err == nil {
		return resolved
	}

	return self
}

// restartAfterUpdate hands over to the new binary.
//
// Under the service manager this cannot be done from inside the service — a
// service cannot stop and start itself — so the work is handed to a detached
// process that outlives us. Run by hand, there is nothing to restart and the
// new binary is simply what runs next time.
func restartAfterUpdate() error {
	if !winsvc.IsService() {
		if state, err := winsvc.Status(); err == nil && state == "running" {
			fmt.Printf("\n  The service is running the old binary. Restart it with:\n")
			fmt.Printf("    akconnect-agent service stop && akconnect-agent service start\n")

			return nil
		}

		fmt.Printf("\n  Done. The new binary runs the next time the agent starts.\n")

		return nil
	}

	fmt.Printf("  Restarting the service so the new binary takes over\n")

	return winsvc.RestartDetached()
}
