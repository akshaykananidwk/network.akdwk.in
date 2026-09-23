//go:build !windows

package main

import (
	"bufio"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"strings"
)

// The installer is for Windows. These exist so the package compiles, and is
// vetted and formatted, on the machine it is developed on — a build that only
// exists for one platform is one nobody notices breaking.
//
// They are also what makes `-silent` testable here: the flow, the payload
// check and the message text can all be exercised without Windows.

var errNotWindows = errors.New(
	"akconnect-setup installs the Windows agent and only runs on Windows")

func requireWindows() error { return errNotWindows }

func isElevated() (bool, error) { return os.Geteuid() == 0, nil }

func relaunchElevated() error { return errNotWindows }

func info(title, text string)  { fmt.Printf("%s: %s\n", title, text) }
func alert(title, text string) { fmt.Fprintf(os.Stderr, "%s: %s\n", title, text) }

func prompt(title, text string) (string, error) {
	fmt.Printf("%s\n%s\n> ", title, text)

	line, err := bufio.NewReader(os.Stdin).ReadString('\n')
	if err != nil {
		return "", err
	}

	return strings.TrimSpace(line), nil
}

func hideWindow(*exec.Cmd) {}

func relaunchFromTemp() (bool, error) { return false, nil }

func removeServiceDirectly() error { return errNotWindows }
func removeFirewallRule() error    { return errNotWindows }

func removeOverlayFirewallRule() error { return errNotWindows }
func removeNrptRules() error           { return errNotWindows }
func removeWintunAdapter() error       { return errNotWindows }
