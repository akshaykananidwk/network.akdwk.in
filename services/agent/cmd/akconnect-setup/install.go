package main

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
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

	// A service already running from an earlier install holds its own .exe
	// open, and Windows will not let it be replaced. It is stopped only when
	// that actually happens.
	//
	// It used to be stopped unconditionally, first thing, and that is how the
	// second PC ended up with a stopped service: the run stopped it, then
	// `service install` refused because the service already existed, and
	// nothing ever started it again. The Application log showed a normal stop
	// at the moment the installer's dialog was clicked, with no crash — which
	// is exactly what this looks like from the outside.
	agentPath := filepath.Join(dir, "akconnect-agent.exe")

	stopped := false
	if err := writeFile(agentPath, agentBinary, 0o755); err != nil {
		_ = runAgentIn(dir, "service", "stop")
		stopped = true

		if err := writeFile(agentPath, agentBinary, 0o755); err != nil {
			// Put back what was running before reporting the failure.
			_ = runAgentIn(dir, "service", "start")

			return err
		}
	}

	// From here on, any failure leaves the service as it was found rather than
	// stopped. An upgrade that goes wrong must not take the working install
	// with it.
	restore := func() {
		if stopped {
			_ = runAgentIn(dir, "service", "start")
		}
	}

	// Beside the binary rather than in System32. The agent loads it from its
	// own directory, and putting a third-party driver into a system directory
	// is both unnecessary and the sort of thing that makes an uninstall
	// incomplete.
	if err := writeFile(filepath.Join(dir, "wintun.dll"), wintunDLL, 0o644); err != nil {
		// In use by a running adapter is the usual reason, and the DLL on disk
		// is almost certainly the same one. Worth saying, not worth failing.
		ui.step("Keeping the existing wintun.dll (in use)")
	}

	if err := writeFile(filepath.Join(dir, "uninstall.txt"), []byte(uninstallNote), 0o644); err != nil {
		restore()

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
		// An enrolment that failed because this machine is already enrolled is
		// not a failure: it is the upgrade case, and the identity on disk is
		// the one to keep. Anything else is reported with the agent's own
		// words, because "that join code has expired" is actionable and
		// "exit status 1" is not.
		if !alreadyEnrolled(out) {
			restore()

			return fmt.Errorf("this computer could not join the network.\n\n%s", trimForDialog(out, err))
		}

		ui.step("Already enrolled; keeping this computer's identity")
	}

	ui.step("Registering the service")

	// Idempotent since 1.9.2: an existing service is repointed at the new
	// binary and restarted rather than refused.
	if out, err := runAgentOutput(dir, "service", "install"); err != nil {
		restore()

		return fmt.Errorf("the agent was installed but its service could not be registered.\n\n%s",
			trimForDialog(out, err))
	}

	ui.step("Starting")

	// Tolerant: `service install` restarts an existing service itself, so this
	// is frequently "already running", which is the outcome wanted.
	if out, err := runAgentOutput(dir, "service", "start"); err != nil && !alreadyRunning(out) {
		return fmt.Errorf("the service was registered but would not start.\n\n%s", trimForDialog(out, err))
	}

	// Read back what is true rather than assuming the start worked because the
	// call returned. A dialog that says "installed and running" over a stopped
	// service is worse than no dialog: it sends the customer away.
	if err := awaitRunning(dir, 30*time.Second); err != nil {
		status, _ := runAgentOutput(dir, "status")

		return fmt.Errorf("%s was installed but its service is not running.\n\n%v\n\n%s",
			productName, err, firstLines(status, 6))
	}

	status, _ := runAgentOutput(dir, "status")

	ui.done(fmt.Sprintf(
		"%s is installed and running.\n\n"+
			"This computer has been added to your network. If your supplier has not approved it "+
			"yet, it will connect by itself as soon as they do — you can close this and leave it "+
			"alone.\n\n%s",
		productName, firstLines(status, 6)))

	return nil
}

// awaitRunning waits for the service to report that it is running.
//
// Polls the service manager rather than sleeping and hoping. The three-second
// sleep this replaces was long enough on a fast machine and not on a slow one,
// and being wrong in that direction meant telling a customer their computer
// was connected when it was not.
func awaitRunning(dir string, within time.Duration) error {
	deadline := time.Now().Add(within)
	last := ""

	for time.Now().Before(deadline) {
		out, err := runAgentOutput(dir, "service", "status")
		if err == nil {
			last = out
			if strings.Contains(out, "running") && !strings.Contains(out, "not running") {
				return nil
			}
		}

		time.Sleep(time.Second)
	}

	if last == "" {
		return fmt.Errorf("the service manager did not answer within %s", within)
	}

	return fmt.Errorf("the service is %s", strings.TrimSpace(strings.TrimPrefix(last, "  Service:")))
}

// alreadyEnrolled recognises the agent refusing to enrol a machine that is
// already enrolled, which during an upgrade is the correct state.
func alreadyEnrolled(out string) bool {
	lower := strings.ToLower(out)

	return strings.Contains(lower, "already enrolled") ||
		strings.Contains(lower, "already has an identity") ||
		strings.Contains(lower, "run 'reset'")
}

// alreadyRunning recognises the service manager saying a service is already
// started, which is not an error when starting is the goal.
func alreadyRunning(out string) bool {
	lower := strings.ToLower(out)

	return strings.Contains(lower, "already running") ||
		strings.Contains(lower, "already been started") ||
		strings.Contains(lower, "1056")
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
