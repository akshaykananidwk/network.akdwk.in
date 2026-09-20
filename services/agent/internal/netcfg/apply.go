package netcfg

import (
	"fmt"
	"os/exec"
	"strings"
)

// Apply installs a plan's address and routes on the named interface.
//
// It is implemented per platform, in apply_linux.go and apply_windows.go, by
// driving the OS's own networking tools. Shelling out rather than using
// netlink or the Windows IP Helper API directly is a deliberate trade: the
// commands are the ones an administrator would run by hand, so a failure can
// be reproduced and diagnosed without the agent in the picture.
func Apply(ifaceName string, plan *Plan) error {
	return applyPlan(ifaceName, plan)
}

// Remove takes the configuration back off. Used on shutdown, and on a
// configuration change that drops a route.
func Remove(ifaceName string, plan *Plan) error {
	return removePlan(ifaceName, plan)
}

// run executes a command and turns a non-zero exit into an error carrying the
// output, because "exit status 2" on its own is useless in a bug report.
func run(name string, args ...string) error {
	cmd := exec.Command(name, args...)

	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("%s %s: %w: %s",
			name, strings.Join(args, " "), err, strings.TrimSpace(string(out)))
	}

	return nil
}

// runTolerant is for teardown, where "it was already gone" is success.
func runTolerant(name string, args ...string) {
	_ = exec.Command(name, args...).Run()
}
