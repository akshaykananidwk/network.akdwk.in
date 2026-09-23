//go:build windows

package winenv

import (
	"fmt"
	"os"
	"path/filepath"

	"golang.org/x/sys/windows"
)

// wintunDLL is the driver wireguard-go loads to create the adapter. The Go
// wrapper is MIT, but the DLL is a separate artefact from wintun.net that is
// not vendored by any Go module, and wireguard-go looks for it only in the
// application directory and System32.
//
// Without it the failure surfaces as a LoadLibrary error from inside the tun
// package, which tells an operator nothing. Hence this check.
const wintunDLL = "wintun.dll"

func preflight() []Problem {
	var problems []Problem

	if !isAdmin() {
		problems = append(problems, Problem{
			Summary: "The agent is not running as Administrator.",
			Remedy: "Creating a network adapter needs administrator rights. " +
				"Run the agent from an elevated prompt, or install it as a service " +
				"with 'akconnect-agent service install', which runs it as LocalSystem.",
			Fatal: true,
		})
	}

	if path, found := findWintun(); !found {
		problems = append(problems, Problem{
			Summary: fmt.Sprintf("%s was not found next to the agent or in System32.", wintunDLL),
			Remedy: fmt.Sprintf(
				"Download the Wintun driver from https://www.wintun.net and place %s in %s. "+
					"Use the architecture that matches this build (amd64 or arm64).",
				wintunDLL, filepath.Dir(executablePath())),
			Fatal: true,
		})
	} else {
		_ = path
	}

	return problems
}

// findWintun looks where LoadLibraryEx will look: the application directory
// first, then System32. Anywhere else on PATH is deliberately not searched,
// because loading a driver from a directory a user can write to is how a
// local privilege escalation starts.
func findWintun() (string, bool) {
	candidates := []string{
		filepath.Join(filepath.Dir(executablePath()), wintunDLL),
	}

	if system32, err := windows.GetSystemDirectory(); err == nil {
		candidates = append(candidates, filepath.Join(system32, wintunDLL))
	}

	for _, candidate := range candidates {
		if info, err := os.Stat(candidate); err == nil && !info.IsDir() {
			return candidate, true
		}
	}

	return "", false
}

func executablePath() string {
	path, err := os.Executable()
	if err != nil {
		return "."
	}

	return path
}

// isAdmin reports whether the token is elevated.
//
// The membership check is used rather than a bare "am I SYSTEM" test because
// the agent is expected to run both as an elevated user during setup and as
// LocalSystem once installed as a service, and both must pass.
func isAdmin() bool {
	admins, err := windows.CreateWellKnownSid(windows.WinBuiltinAdministratorsSid)
	if err != nil {
		return false
	}

	token := windows.Token(0) // the current process token
	member, err := token.IsMember(admins)
	if err != nil {
		return false
	}

	return member
}
