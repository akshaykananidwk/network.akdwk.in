package main

import (
	"fmt"
	"strings"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/state"
)

// What the last dialog is allowed to claim.
//
// "Installed and running" used to be said on the strength of the service
// manager reporting a started process, which is true and is not what the
// customer asked. They asked whether the computer is on the network. Those
// come apart in every way that matters: a service can be running while the
// coordinator is unreachable, while this device is waiting to be approved, or
// while it is the only machine on the network and has nobody to reach.
//
// So the installer reads back what the agent has published about itself and
// says which of those it is. Each has a different answer, and only one of them
// is a problem the customer needs to do anything about.

// verifyWithin is how long the installer waits for a path before describing
// what it found.
//
// A first connection has to enrol, be introduced, punch, and in the worst case
// fall back to HTTPS — which the lab measures at about twenty seconds on a
// network that carries no UDP at all. Forty-five leaves room for a slow link
// without leaving somebody watching a progress dialog wondering.
const verifyWithin = 45 * time.Second

// outcome is what the installer found.
type outcome struct {
	// Running is whether the service is up. Established before this.
	Running bool
	// Published is whether the agent has written a status file at all. False
	// means it started and has not got as far as configuring itself.
	Published bool
	// CoordinatorUp is whether the coordinator has answered recently.
	CoordinatorUp bool
	// OverHTTPS is whether it had to use the fallback to manage that.
	OverHTTPS bool
	// Peers is how many other computers this one has been told about, and
	// Reachable how many of those it can actually reach.
	Peers, Reachable int
	// Address is this device's overlay address, once it has one.
	Address string
}

// settled reports whether there is nothing further worth waiting for.
func (o outcome) settled() bool {
	return o.Published && o.CoordinatorUp && (o.Peers == 0 || o.Reachable > 0)
}

// message is what the customer is told, and whether it counts as a success.
//
// Every branch says what is true and what happens next, and none of them asks
// for anything to be typed or restarted. The only branch that mentions a file
// is the one where something is genuinely wrong.
func (o outcome) message() (string, bool) {
	switch {
	case !o.Running:
		return "The software is installed, but its background service is not running. " +
			"A diagnostics file has been saved to your Desktop; send it to your supplier.", false

	case !o.Published:
		return "The software is installed and its service is running, but it has not " +
			"finished starting up. Leave the computer on for a few minutes and it will " +
			"carry on by itself.", true

	case !o.CoordinatorUp:
		return "The software is installed and running, but it cannot yet reach the " +
			"coordination service. If this computer is waiting to be approved by your " +
			"supplier, that is normal and it will connect by itself once they do — there " +
			"is nothing to do here. A diagnostics file has been saved to your Desktop in " +
			"case it is not.", true

	case o.Peers == 0:
		return fmt.Sprintf("This computer is on the network as %s.\n\n"+
			"There is nothing else on it yet. As soon as another computer is added, the "+
			"two will find each other by themselves.", orUnknown(o.Address)), true

	case o.Reachable == 0:
		return fmt.Sprintf("This computer is on the network as %s and can see %s, but has "+
			"not reached %s yet.\n\nThat usually settles within a minute. If the other "+
			"computer is switched off, it will connect as soon as it is on.",
			orUnknown(o.Address), plural(o.Peers), them(o.Peers)), true

	default:
		path := ""
		if o.OverHTTPS {
			path = "\n\nThis network does not allow the usual kind of traffic, so " +
				displayName + " is using the same connection type a web browser uses. " +
				"Everything works; nothing needs changing."
		}

		return fmt.Sprintf("This computer is on the network as %s and can reach %s of %s.%s",
			orUnknown(o.Address), fmt.Sprint(o.Reachable), plural(o.Peers), path), true
	}
}

func orUnknown(address string) string {
	if address == "" {
		return "this network"
	}

	return address
}

func plural(n int) string {
	if n == 1 {
		return "1 other computer"
	}

	return fmt.Sprintf("%d other computers", n)
}

func them(n int) string {
	if n == 1 {
		return "it"
	}

	return "them"
}

// verifyInstall waits for the agent to settle and reports what it found.
func verifyInstall(running bool, within time.Duration) outcome {
	found := outcome{Running: running}
	if !running {
		return found
	}

	deadline := time.Now().Add(within)

	for {
		found = readOutcome(running)
		if found.settled() || time.Now().After(deadline) {
			return found
		}

		time.Sleep(2 * time.Second)
	}
}

// readOutcome takes one reading from the status file.
func readOutcome(running bool) outcome {
	found := outcome{Running: running}

	store, err := state.Open()
	if err != nil {
		return found
	}

	rt, err := store.LoadRuntime()
	if err != nil || rt == nil || !rt.Fresh() {
		// A stale file is worse than none: it describes a run that has ended,
		// and reporting it would claim a connection that is not there.
		return found
	}

	found.Published = true
	found.CoordinatorUp = rt.CoordinatorUp
	found.Address = rt.VirtualIP
	found.OverHTTPS = rt.Fallback != nil
	found.Peers = len(rt.Peers)

	for _, peer := range rt.Peers {
		if peer.Path == "direct" || strings.HasPrefix(peer.Path, "relay") {
			found.Reachable++
		}
	}

	return found
}
