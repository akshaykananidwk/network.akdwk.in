package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

// Everything a customer sees.
//
// This binary is built for the windowsgui subsystem, so there is no console to
// print to: a message that is not in a dialog is a message nobody receives.
// With -silent, for deployment tools, the same text goes to standard error
// instead, where a log will pick it up.
type console struct {
	silent bool
	steps  []string
}

// step records progress without interrupting. Each one is a line in the
// dialog at the end, so a customer sending a screenshot of a failure sends
// how far it got as well.
func (c *console) step(text string) {
	c.steps = append(c.steps, text)

	if c.silent {
		fmt.Fprintf(os.Stderr, "  %s\n", text)
	}
}

func (c *console) done(text string) {
	if c.silent {
		fmt.Fprintln(os.Stderr, text)

		return
	}

	info(productName, text)
}

func (c *console) fail(text string) {
	// The steps go with the failure. "Could not start the service" means
	// something different depending on whether enrolment had succeeded, and a
	// customer cannot be expected to know that.
	message := text
	if len(c.steps) > 0 {
		message += "\n\nWhat happened before this:\n  " + strings.Join(c.steps, "\n  ")
	}

	if c.silent {
		fmt.Fprintln(os.Stderr, message)

		return
	}

	alert(productName, message)
}

// askJoinCode is the only question a customer is asked.
func (c *console) askJoinCode() (string, error) {
	if c.silent {
		return "", fmt.Errorf("no join code was given, and -silent means one cannot be asked for")
	}

	return prompt(productName,
		"Enter the join code from your supplier.\n\n"+
			"It looks like ABCD-EFGH-JKLM and is the only thing this needs.")
}

// runAgentIn runs the agent and ignores what it said.
func runAgentIn(dir string, args ...string) error {
	_, err := runAgentOutput(dir, args...)

	return err
}

// runAgentOutput runs the agent and returns everything it printed.
//
// The agent's own message is the useful part of any failure here: "that join
// code has expired" is something a customer can act on, and "exit status 1" is
// not.
func runAgentOutput(dir string, args ...string) (string, error) {
	cmd := exec.Command(filepath.Join(dir, "akconnect-agent.exe"), args...)
	cmd.Dir = dir
	hideWindow(cmd)

	out, err := cmd.CombinedOutput()

	return strings.TrimSpace(string(out)), err
}

// trimForDialog keeps a command's output to something a dialog can show.
func trimForDialog(out string, err error) string {
	out = strings.TrimSpace(out)
	if out == "" {
		return err.Error()
	}

	return firstLines(out, 8)
}

func firstLines(text string, n int) string {
	lines := strings.Split(strings.TrimSpace(text), "\n")
	if len(lines) > n {
		lines = append(lines[:n], "…")
	}

	return strings.Join(lines, "\n")
}

// report prints a one-line answer, for -version.
func report(silent bool, text string) {
	if silent {
		fmt.Println(text)

		return
	}

	info(productName, text)
}
