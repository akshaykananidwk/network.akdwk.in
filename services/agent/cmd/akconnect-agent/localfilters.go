//go:build !labtamper

package main

import (
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/acl"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
)

// localFilters is the normal build: the rules the panel sent are the rules
// this device enforces on its own traffic.
//
// The only reason this is a function rather than a call to convertFilters is
// the lab's tampered build, which replaces it — see localfilters_tamper.go. It
// exists so the drill can prove that the *other* end still holds when this one
// does not, which is the only way to test that claim without hand-editing a
// binary.
func localFilters(in []panel.Filter) []acl.Filter { return convertFilters(in) }
