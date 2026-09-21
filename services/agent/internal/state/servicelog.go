package state

import (
	"fmt"
	"os"
	"path/filepath"
)

// maxServiceLog is where the log is started again from the top.
//
// A Windows service runs for months without anyone looking at it, and a log
// nobody rotates is a disk nobody has left. Five megabytes is enough to hold
// weeks of an agent's ordinary chatter and small enough that a customer can
// email it.
const maxServiceLog = 5 << 20

// OpenServiceLog opens the file a service writes its output to.
//
// A service has no console. Everything the agent printed while running under
// the SCM went to a handle pointing at nothing, so a service that started,
// failed and stopped did all of it in silence — which is exactly how the first
// real Windows install presented: "the service starts and silently stops".
//
// The caller closes it. The returned path is for telling a person where to
// look.
func OpenServiceLog() (*os.File, string, error) {
	dir, err := stateDir()
	if err != nil {
		return nil, "", err
	}

	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, "", fmt.Errorf("creating %s: %w", dir, err)
	}

	path := filepath.Join(dir, "service.log")

	// Start again rather than grow without limit. Truncating loses the older
	// half of a long-running agent's history, which is the half nobody is
	// reading; the alternative is filling a customer's system drive.
	flags := os.O_CREATE | os.O_WRONLY | os.O_APPEND
	if info, err := os.Stat(path); err == nil && info.Size() > maxServiceLog {
		flags = os.O_CREATE | os.O_WRONLY | os.O_TRUNC
	}

	file, err := os.OpenFile(path, flags, 0o600)
	if err != nil {
		return nil, "", err
	}

	return file, path, nil
}

// ServiceLogPath is where OpenServiceLog would write, for `status` to print
// without opening anything.
func ServiceLogPath() string {
	dir, err := stateDir()
	if err != nil {
		return ""
	}

	return filepath.Join(dir, "service.log")
}
