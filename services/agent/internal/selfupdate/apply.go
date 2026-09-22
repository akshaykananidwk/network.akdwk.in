package selfupdate

import (
	"fmt"
	"os"
	"path/filepath"
	"runtime"
)

// previousSuffix names the binary an update moved aside. One constant, because
// Apply writes it and CleanPrevious removes it, and the two drifting apart
// would leave a stale binary on every machine forever.
const previousSuffix = ".old"

// Apply puts a verified binary in place of the running one.
//
// The awkward part is Windows, where a running executable cannot be
// overwritten or deleted — but it *can* be renamed. So:
//
//  1. the current binary is renamed aside to .old, which succeeds while it is
//     running;
//  2. the new one is moved into its place;
//  3. the service is restarted, and whatever starts next is the new binary;
//  4. the .old file is removed on the next start, when nothing holds it.
//
// On Linux a rename over the running file works directly, and step 1 is kept
// anyway so that a failure at step 2 leaves something to put back.
//
// This function does not restart anything. The caller decides, because a
// technician running `akconnect-agent update` by hand and a service updating
// itself need different things to happen next.
func Apply(staged string) (previous string, err error) {
	self, err := os.Executable()
	if err != nil {
		return "", fmt.Errorf("locating this executable: %w", err)
	}

	self, err = filepath.EvalSymlinks(self)
	if err != nil {
		return "", fmt.Errorf("resolving %s: %w", self, err)
	}

	return applyTo(staged, self)
}

// applyTo is Apply with the target named rather than discovered.
//
// Split out so the swap can be tested against a file in a temporary directory:
// a test that let Apply find its own target would be renaming the test binary
// out from under the test runner.
func applyTo(staged, self string) (previous string, err error) {
	if err := sameFilesystem(staged, self); err != nil {
		return "", err
	}

	previous = self + previousSuffix

	// Anything left from a previous update goes first; on Windows this is the
	// one moment it is not held open.
	_ = os.Remove(previous)

	if err := os.Rename(self, previous); err != nil {
		return "", fmt.Errorf("could not move the running binary aside: %w", err)
	}

	if err := os.Rename(staged, self); err != nil {
		// Put it back. A machine with no agent binary at all is much worse
		// than a machine running the old one.
		if restoreErr := os.Rename(previous, self); restoreErr != nil {
			return "", fmt.Errorf(
				"could not install the new binary (%w) AND could not restore the old one (%v). "+
					"The previous agent is at %s — move it back by hand",
				err, restoreErr, previous)
		}

		return "", fmt.Errorf("could not install the new binary, the old one is back in place: %w", err)
	}

	if runtime.GOOS != "windows" {
		if err := os.Chmod(self, 0o755); err != nil {
			return previous, fmt.Errorf("the new binary is in place but not executable: %w", err)
		}
	}

	return previous, nil
}

// CleanPrevious removes the binary a previous update moved aside, and reports
// what it removed so the agent's log says why the version changed.
//
// Called at start-up, which is the first moment nothing holds it open. An
// empty path and no error is the ordinary case: no update has happened.
func CleanPrevious() (string, error) {
	self, err := os.Executable()
	if err != nil {
		return "", err
	}

	if resolved, err := filepath.EvalSymlinks(self); err == nil {
		self = resolved
	}

	previous := self + previousSuffix

	if _, err := os.Stat(previous); err != nil {
		return "", nil
	}

	if err := os.Remove(previous); err != nil {
		return "", err
	}

	return previous, nil
}

// sameFilesystem refuses a staged file that cannot be renamed into place.
//
// os.Rename across filesystems fails on Linux with EXDEV, and the failure
// would happen after the running binary had already been moved aside — the
// worst possible moment. Staging next to the target is the caller's job; this
// is the check that it did it.
func sameFilesystem(staged, target string) error {
	if filepath.Dir(staged) == filepath.Dir(target) {
		return nil
	}

	return fmt.Errorf(
		"the new binary is staged in %s but has to be installed into %s; "+
			"stage it beside the target so it can be moved into place atomically",
		filepath.Dir(staged), filepath.Dir(target))
}
