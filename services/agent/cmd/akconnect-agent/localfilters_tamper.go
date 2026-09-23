//go:build labtamper

package main

import (
	"fmt"
	"os"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/acl"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
)

// The tampered build, for the lab only.
//
// It models the realistic threat: a customer with root on their own laptop
// rebuilds the agent with the rule enforcement taken out. Everything else is
// unchanged — it still enrols, still handshakes, still speaks the same
// protocol — so the only thing standing between it and the machines it was
// denied is the enforcement at the far end.
//
// Guarded by a build tag so no shipping binary can contain it, and it announces
// itself on startup so a tampered binary can never be mistaken for a real one
// in a log.
func localFilters(_ []panel.Filter) []acl.Filter { return nil }

func init() {
	fmt.Fprintln(os.Stderr,
		"WARNING: this binary was built with -tags labtamper and does not enforce ACL rules on its own traffic. "+
			"It is a test fixture. Do not deploy it.")
}
