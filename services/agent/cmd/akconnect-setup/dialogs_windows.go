//go:build windows

package main

import (
	"fmt"
	"os/exec"
	"strings"
	"syscall"

	"golang.org/x/sys/windows"
)

// Dialogs.
//
// MessageBox for telling, because it is a single call into user32 that has
// looked the same on every Windows since 1995 and cannot fail in an
// interesting way.
//
// Asking is harder: Win32 has no input box. The options are to build a dialog
// out of CreateWindowEx and an edit control — a few hundred lines of code that
// cannot be tested anywhere but Windows — or to call the one Microsoft already
// ships, `Microsoft.VisualBasic.Interaction.InputBox`, which is present on
// every Windows with .NET Framework, which is all of them.
//
// The second is chosen deliberately. It is less code, it is code Microsoft
// maintains, and the failure mode if it is somehow unavailable is a clear
// message rather than a window that does not appear. A customer typing a join
// code once does not need us to have written the text box.
const (
	mbOK              = 0x00000000
	mbIconInformation = 0x00000040
	mbIconError       = 0x00000010
	mbSetForeground   = 0x00010000
	mbTopMost         = 0x00040000
)

func info(title, text string) {
	messageBox(title, text, mbOK|mbIconInformation)
}

func alert(title, text string) {
	messageBox(title, text, mbOK|mbIconError)
}

func messageBox(title, text string, flags uint32) {
	titlePtr, err := syscall.UTF16PtrFromString(title)
	if err != nil {
		return
	}
	textPtr, err := syscall.UTF16PtrFromString(text)
	if err != nil {
		return
	}

	// Foreground and topmost: an installer launched from a browser's download
	// bar otherwise puts its dialog behind the browser, and a customer
	// concludes nothing happened.
	_, _ = windows.MessageBox(0, textPtr, titlePtr, flags|mbSetForeground|mbTopMost)
}

func prompt(title, text string) (string, error) {
	// Single-quoted in PowerShell, with any single quote doubled: these two
	// strings are ours, but building a command line out of text without
	// escaping it is a habit worth not having.
	statement := fmt.Sprintf(
		`Add-Type -AssemblyName Microsoft.VisualBasic; `+
			`[Microsoft.VisualBasic.Interaction]::InputBox('%s', '%s', '')`,
		psQuote(text), psQuote(title))

	cmd := exec.Command("powershell.exe",
		"-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass",
		"-WindowStyle", "Hidden", "-Command", statement)
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}

	out, err := cmd.Output()
	if err != nil {
		return "", fmt.Errorf(
			"could not open the box to type the join code into (%w).\n\n"+
				"Run this instead, from a command prompt opened as administrator:\n"+
				"  akconnect-setup.exe -code YOUR-JOIN-CODE", err)
	}

	return strings.TrimSpace(string(out)), nil
}

func psQuote(s string) string {
	return strings.ReplaceAll(s, "'", "''")
}

func hideWindow(cmd *exec.Cmd) {
	// Without this, every agent invocation flashes a console window on a
	// customer's screen — half a dozen of them during one install, which looks
	// like something going wrong.
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}
}
