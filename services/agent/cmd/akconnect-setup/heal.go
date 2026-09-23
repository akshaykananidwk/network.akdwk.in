package main

import (
	"os"
	"path/filepath"
	"strings"
)

// What a previous installation can leave behind, and why it has to be cleared
// before anything else happens.
//
// The ordinary upgrade path is already safe: an existing service is repointed
// and restarted, and an enrolled machine keeps its identity. This is about the
// other case — a machine that has been through a failed install, a folder
// somebody deleted by hand, a crash that left an adapter behind, an antivirus
// that quarantined the binary. Every one of those leaves a fragment that makes
// the NEXT install fail, and it fails in the middle, having half-replaced
// things.
//
// So the installer looks first, and repairs what it finds. Nothing here asks
// the customer anything, and nothing here touches the device's identity: a
// machine that is still enrolled and still approved keeps its key and its
// address, which is the one thing an installer must never throw away.

// staleService reports whether a registered service points at a program that
// is not there any more.
//
// Split out from the Windows call so the decision can be exercised anywhere.
// The rule is the narrow one: a service registered against a path that does
// not exist is stale. A service pointing somewhere else that does exist is a
// working installation in another folder, and removing it is not this
// installer's business.
func staleService(imagePath string, exists func(string) bool) bool {
	program := programOf(imagePath)

	return program != "" && !exists(program)
}

// programOf takes the executable out of a service's command line.
//
// The registration is a command line, not a path: it carries arguments, and
// the path is quoted when it has a space in it — which it always does, because
// it lives under Program Files.
func programOf(imagePath string) string {
	line := strings.TrimSpace(imagePath)
	if line == "" {
		return ""
	}

	if strings.HasPrefix(line, `"`) {
		if end := strings.Index(line[1:], `"`); end >= 0 {
			return line[1 : end+1]
		}

		return strings.Trim(line, `"`)
	}

	// Unquoted: everything up to the first argument. A path with a space and
	// no quotes is already broken as a service registration, and taking the
	// first word is what Windows itself does with it.
	if space := strings.Index(line, " "); space > 0 {
		return line[:space]
	}

	return line
}

// heal clears what a previous installation left behind.
//
// Best-effort throughout, and silent about the things that were not there:
// a first install on a clean machine must not read like a repair.
func heal(ui *console, dir string) {
	// The icon holds its own executable open, and on an upgrade that is what
	// stops the new one being written.
	stopTray()

	image := serviceImagePath()
	stale := image != "" && staleService(image, fileExists)

	if stale {
		ui.step("Clearing a previous installation that is no longer there")

		// Not stopped first: there is nothing to stop, because the program it
		// would run is gone.
		_ = removeServiceDirectly()
	}

	// A Wintun adapter left behind by a crash keeps the name, so the next one
	// becomes "AKConnect #2" and every rule and route naming the first one
	// points at a dead adapter.
	//
	// Removed only when nothing can be using it — which is the case on a
	// half-installed machine and is not the case during an ordinary upgrade,
	// where the service is about to be restarted onto the same adapter.
	// Removing it there would tear a working tunnel down to no purpose.
	if orphanedAdapter(image, stale, dir) {
		if err := removeWintunAdapter(); err == nil {
			ui.step("Removed a network adapter left behind by an earlier install")
		}
	}

	// DNS policy rules outlive the process that made them, and a stale one
	// sends the network's names to a resolver that is not running — which
	// breaks name lookups for the customer even though our software is not
	// installed. They are rewritten on every start, so removing them here
	// costs nothing.
	_ = removeNrptRules()
}

// orphanedAdapter decides whether a network adapter belongs to nothing.
//
// A decision rather than a Windows call, so it can be exercised here. Three
// shapes count, and they are all "this machine has wreckage on it": no service
// registered at all, a service registered against a program that is gone, and
// a program that is not where this installer puts it. An ordinary upgrade —
// service registered, program present — is none of them.
func orphanedAdapter(image string, stale bool, dir string) bool {
	if image == "" || stale {
		return true
	}

	return !fileExists(filepath.Join(dir, "akconnect-agent.exe"))
}

func fileExists(path string) bool {
	info, err := os.Stat(path)

	return err == nil && !info.IsDir()
}
