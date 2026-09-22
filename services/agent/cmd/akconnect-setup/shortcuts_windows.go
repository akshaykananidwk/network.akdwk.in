//go:build windows

package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"

	"golang.org/x/sys/windows/registry"
)

// The Start menu entry, and the icon that appears when the customer signs in.
//
// Both are what a customer expects of any program: it is in the Start menu
// under its own name, and the little icon is there in the corner without
// anybody arranging it. Neither is decoration — the tray icon is the only
// place a non-technical person can see whether the network is working.

// runKey starts the tray for every user who signs in. HKLM rather than HKCU
// because the install is machine-wide: the administrator who ran the installer
// is very often not the person who uses the computer.
const runKey = `SOFTWARE\Microsoft\Windows\CurrentVersion\Run`

func startMenuDir() string {
	base := os.Getenv("ProgramData")
	if base == "" {
		base = `C:\ProgramData`
	}

	return filepath.Join(base, `Microsoft\Windows\Start Menu\Programs`, displayName)
}

// createShortcuts writes the Start menu folder and registers the tray to start
// at sign-in. Best-effort by design: a machine without a Start menu entry
// still has a working network, so nothing here is allowed to fail an install.
func createShortcuts(dir, setupPath string) error {
	menu := startMenuDir()
	if err := os.MkdirAll(menu, 0o755); err != nil {
		return fmt.Errorf("could not create the Start menu folder: %w", err)
	}

	var problems []string

	trayPath := filepath.Join(dir, "akconnect-tray.exe")
	if _, err := os.Stat(trayPath); err == nil {
		if err := writeShortcut(filepath.Join(menu, displayName+".lnk"), trayPath, dir,
			displayName+" status"); err != nil {
			problems = append(problems, err.Error())
		}

		if err := registerStartup(trayPath); err != nil {
			problems = append(problems, err.Error())
		}
	}

	if panel := defaultPanel; panel != "" {
		// An internet shortcut is a text file, so it needs no COM and cannot
		// fail in the interesting ways a .lnk can.
		url := "[InternetShortcut]\r\nURL=" + panel + "\r\n"
		if err := os.WriteFile(filepath.Join(menu, displayName+" panel.url"), []byte(url), 0o644); err != nil {
			problems = append(problems, err.Error())
		}
	}

	if problems != nil {
		return fmt.Errorf("%s", strings.Join(problems, "; "))
	}

	return nil
}

// writeShortcut creates a .lnk.
//
// Through WScript.Shell rather than COM from Go: this runs once, during an
// elevated install, and the alternative is three hundred lines of IShellLink
// and IPersistFile marshalling to produce the same four fields. No customer
// ever sees PowerShell — it is the installer that calls it, not them.
func writeShortcut(linkPath, target, workingDir, description string) error {
	script := fmt.Sprintf(
		`$s = (New-Object -ComObject WScript.Shell).CreateShortcut(%s); `+
			`$s.TargetPath = %s; $s.WorkingDirectory = %s; $s.Description = %s; $s.Save()`,
		psLiteral(linkPath), psLiteral(target), psLiteral(workingDir), psLiteral(description))

	cmd := exec.Command("powershell.exe",
		"-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", script)
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}

	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("shortcut %s: %v: %s", filepath.Base(linkPath), err, strings.TrimSpace(string(out)))
	}

	return nil
}

// psLiteral renders a string as a complete PowerShell single-quoted literal,
// where the only escape is a doubled quote. psQuote, next door, escapes the
// inside of one that is already quoted; this one brings its own quotes.
func psLiteral(s string) string {
	return "'" + psQuote(s) + "'"
}

func registerStartup(trayPath string) error {
	key, _, err := registry.CreateKey(registry.LOCAL_MACHINE, runKey, registry.SET_VALUE)
	if err != nil {
		return fmt.Errorf("could not register the tray icon to start: %w", err)
	}
	defer key.Close()

	return key.SetStringValue(productName, `"`+trayPath+`"`)
}

// removeShortcuts takes the Start menu folder and the sign-in entry away.
func removeShortcuts() error {
	var problems []string

	if err := os.RemoveAll(startMenuDir()); err != nil {
		problems = append(problems, err.Error())
	}

	key, err := registry.OpenKey(registry.LOCAL_MACHINE, runKey, registry.SET_VALUE)
	if err == nil {
		defer key.Close()
		if err := key.DeleteValue(productName); err != nil && !os.IsNotExist(err) &&
			!strings.Contains(err.Error(), "cannot find") {
			problems = append(problems, err.Error())
		}
	}

	if problems != nil {
		return fmt.Errorf("%s", strings.Join(problems, "; "))
	}

	return nil
}

// stopTray closes any tray icon still showing, so an uninstall does not leave
// one behind pointing at software that is gone.
func stopTray() {
	cmd := exec.Command("taskkill.exe", "/F", "/IM", "akconnect-tray.exe")
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}
	_ = cmd.Run()
}
