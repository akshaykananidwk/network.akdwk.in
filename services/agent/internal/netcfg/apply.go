package netcfg

import (
	"fmt"
	"net/netip"
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
// It returns the prefixes it declined to install because the machine is
// already directly attached to them. Overlapping LAN ranges are the normal
// case, not the exception — 192.168.1.0/24 is the default on most consumer
// routers, so a support laptop on one such LAN will regularly be offered a
// route to another customer's identical range. Replacing the machine's own
// LAN route would cut it off from its printer, its NAS and often its own
// gateway, so the tunnel loses that contest and the agent says so out loud.
func Apply(ifaceName string, plan *Plan) ([]netip.Prefix, error) {
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

// routesToRemove is what Remove should take back out: the prefixes Apply
// actually installed, not the ones it was asked to.
//
// The difference matters on a machine where an advertised LAN collided with a
// local one. That route belongs to the machine's own network card; deleting it
// on shutdown would leave the site without its LAN until the next reboot.
func routesToRemove(plan *Plan) []netip.Prefix {
	if plan.installed != nil {
		return plan.installed
	}

	return plan.Routes
}
