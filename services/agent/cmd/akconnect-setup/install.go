package main

import (
	"fmt"
	"os"
	"path/filepath"
	"time"
)

// Where things go. Program Files because it is the one location a standard
// user cannot write to, which is what stops somebody replacing the binary a
// service runs as SYSTEM.
func installDir() string {
	base := os.Getenv("ProgramFiles")
	if base == "" {
		base = `C:\Program Files`
	}

	return filepath.Join(base, productName)
}

func dataDir() string {
	base := os.Getenv("ProgramData")
	if base == "" {
		base = `C:\ProgramData`
	}

	return filepath.Join(base, productName)
}

func runInstall(ui *console, code, panelURL, name string) error {
	dir := installDir()

	ui.step("Installing to " + dir)

	if err := os.MkdirAll(dir, 0o755); err != nil {
		return fmt.Errorf("could not create %s: %w", dir, err)
	}
	if err := os.MkdirAll(dataDir(), 0o755); err != nil {
		return fmt.Errorf("could not create %s: %w", dataDir(), err)
	}

	// The service may be running from an earlier install: a file in use cannot
	// be replaced, and the error Windows gives for that is not one a customer
	// can act on.
	_ = runAgentIn(dir, "service", "stop")

	agentPath := filepath.Join(dir, "akconnect-agent.exe")
	if err := writeFile(agentPath, agentBinary, 0o755); err != nil {
		return err
	}

	// Beside the binary rather than in System32. The agent loads it from its
	// own directory, and putting a third-party driver into a system directory
	// is both unnecessary and the sort of thing that makes an uninstall
	// incomplete.
	if err := writeFile(filepath.Join(dir, "wintun.dll"), wintunDLL, 0o644); err != nil {
		return err
	}

	if err := writeFile(filepath.Join(dir, "uninstall.txt"), []byte(uninstallNote), 0o644); err != nil {
		return err
	}

	ui.step("Joining the network")

	args := []string{"enroll", "--join-code", code, "--wait=false"}
	if panelURL != "" {
		args = append(args, "--panel", panelURL)
	}
	if name != "" {
		args = append(args, "--name", name)
	}

	if out, err := runAgentOutput(dir, args...); err != nil {
		// The message from the agent is the useful part: "that join code has
		// expired" is actionable and "exit status 1" is not.
		return fmt.Errorf("this computer could not join the network.\n\n%s", trimForDialog(out, err))
	}

	ui.step("Registering the service")

	if out, err := runAgentOutput(dir, "service", "install"); err != nil {
		return fmt.Errorf("the agent was installed but its service could not be registered.\n\n%s",
			trimForDialog(out, err))
	}

	ui.step("Starting")

	if out, err := runAgentOutput(dir, "service", "start"); err != nil {
		return fmt.Errorf("the service was registered but would not start.\n\n%s", trimForDialog(out, err))
	}

	// A moment, then read back what the agent thinks rather than assuming the
	// start succeeded because the call returned.
	time.Sleep(3 * time.Second)
	status, _ := runAgentOutput(dir, "status")

	ui.done(fmt.Sprintf(
		"%s is installed and running.\n\n"+
			"This computer has been added to your network and is waiting for your supplier to "+
			"approve it. Until they do, nothing can reach it and it can reach nothing — that is "+
			"deliberate.\n\n%s",
		productName, firstLines(status, 6)))

	return nil
}

const uninstallNote = "To remove AKConnect, run akconnect-setup.exe -uninstall as administrator.\r\n" +
	"It removes the service, the firewall rule, the DNS policy rules, the Wintun\r\n" +
	"adapter and this folder.\r\n"

// writeFile replaces a file even when one is already there.
func writeFile(path string, content []byte, mode os.FileMode) error {
	// Written beside the target and renamed, so a failure halfway leaves the
	// previous file rather than a truncated one.
	tmp := path + ".new"
	if err := os.WriteFile(tmp, content, mode); err != nil {
		return fmt.Errorf("could not write %s: %w", tmp, err)
	}

	_ = os.Remove(path)
	if err := os.Rename(tmp, path); err != nil {
		return fmt.Errorf("could not put %s in place: %w", path, err)
	}

	return nil
}
