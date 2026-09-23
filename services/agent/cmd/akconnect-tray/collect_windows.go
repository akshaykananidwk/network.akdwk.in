//go:build windows

package main

import (
	"fmt"
	"os/exec"
	"strings"
	"syscall"
	"time"
)

// What the machine will say about itself, for the faults the agent cannot see.
//
// The bundle used to carry the agent's own view and nothing else: its status
// file and its log. That is enough for every fault inside the agent and no use
// at all for the one a customer's network actually produces — announcements
// leaving and nothing coming back — because the cause of that is outside the
// agent entirely.
//
// A laptop on an office Wi-Fi did exactly that: hellos reaching the
// coordinator every few seconds, no reply ever arriving, and the same laptop
// working on a phone hotspot an hour earlier. Firewall on a Public profile?
// A second adapter carrying the return path? The office router? Nothing in the
// bundle could tell those apart, so nobody could say which.
//
// Each command below is chosen to separate exactly those. They are read-only —
// every one is a Get- or a show — and none of them can print a credential:
// they report adapters, addresses, routes, firewall rules and profiles.

// commandTimeout bounds each one. A customer is waiting with a menu open, and
// a PowerShell call that hangs must not take the bundle with it.
const commandTimeout = 20 * time.Second

// machineSources runs every probe and packages what it printed.
func machineSources() []source {
	out := make([]source, 0, len(machineProbes()))

	for _, p := range machineProbes() {
		text := p.Note
		if text != "" {
			text += "\n\n"
		}

		for i, statement := range p.Statements {
			if i > 0 {
				text += "\n"
			}
			if p.Headings[i] != "" {
				text += "== " + p.Headings[i] + " ==\n"
			}
			text += powershell(statement)
		}

		out = append(out, source{Name: p.Name, Text: text})
	}

	return out
}

// powershell executes one statement and returns what it printed, or the
// reason it could not.
//
// Never fatal. A machine where one of these is unavailable — an old build, a
// locked-down policy, a missing module — still produces a bundle with
// everything else in it, and the gap says what happened rather than being
// silently absent.
func powershell(statement string) string {
	cmd := exec.Command("powershell.exe",
		"-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", statement)
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}

	done := make(chan struct{})
	var out []byte
	var err error

	go func() {
		out, err = cmd.CombinedOutput()
		close(done)
	}()

	select {
	case <-done:
	case <-time.After(commandTimeout):
		if cmd.Process != nil {
			_ = cmd.Process.Kill()
		}

		return fmt.Sprintf("(gave up after %s)\n", commandTimeout)
	}

	text := strings.TrimSpace(string(out))
	if err != nil {
		return fmt.Sprintf("(could not run: %v)\n%s\n", err, text)
	}
	if text == "" {
		return "(nothing to report)\n"
	}

	return text + "\n"
}
