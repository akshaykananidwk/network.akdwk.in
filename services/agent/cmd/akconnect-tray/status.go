package main

import (
	"fmt"
	"strings"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/state"
)

// What the tray says, in words a shopkeeper can act on.
//
// The whole point of the icon is that somebody who is not technical can look
// at the corner of their screen and know whether the thing works. So the line
// is never "wg0: 2 peers, 1 relayed" — it is "Connected" or it is a sentence
// saying what to do about it.
//
// Kept away from the Win32 code so it can be tested here rather than by
// squinting at a screenshot.

// staleAfter is how old the status file may be before the tray stops
// believing it. The agent rewrites it every few seconds; a file older than
// this means the service is not running, whatever it last said.
const staleAfter = 90 * time.Second

// view is everything the tray displays, derived once per refresh.
type view struct {
	// Headline is the first menu line and the tooltip: one short phrase.
	Headline string
	// Detail is the second line, or empty. It says what to do when there is
	// something to do.
	Detail string
	// IP is this device's overlay address, or "" when it has none yet. It is
	// what "Copy my IP" copies.
	IP string
	// OK is whether the icon should look healthy.
	OK bool
}

func describe(st *state.State, rt *state.Runtime, loadErr error, now time.Time) view {
	// The status file first, and on its own if need be. The tray runs as the
	// signed-in customer while the service runs as SYSTEM, so the one thing it
	// can count on reading is what the service publishes for it — and a
	// running, connected agent must not be reported as broken because a
	// permission stopped the tray reading something else.
	if rt != nil && now.Sub(rt.UpdatedAt) <= staleAfter {
		return fromRuntime(rt)
	}

	switch {
	case st != nil && st.Enrolled() && !st.Approved():
		return view{
			Headline: "Waiting for approval",
			Detail:   "Your supplier has to approve this computer. It connects by itself when they do.",
		}
	case st != nil && st.Enrolled():
		return view{
			Headline: displayName + " is not running",
			Detail:   "Restart the computer. If it is still not running, tell your supplier.",
			IP:       st.VirtualIP,
		}
	case st != nil:
		return view{
			Headline: "Not joined to a network",
			Detail:   "Run the installer again and enter your join code.",
		}
	case loadErr != nil:
		return view{
			Headline: displayName + " is not running",
			Detail:   "Restart the computer. If it is still not running, tell your supplier.",
		}
	}

	return view{
		Headline: displayName + " is not running",
		Detail:   "Restart the computer. If it is still not running, tell your supplier.",
	}
}

// fromRuntime is the live picture, from the file the running agent writes.
func fromRuntime(rt *state.Runtime) view {
	reachable, total := countReachable(rt)

	switch {
	case rt.VirtualIP == "":
		return view{
			Headline: "Waiting for approval",
			Detail:   "Your supplier has to approve this computer. It connects by itself when they do.",
		}
	case !rt.ControlPlaneUp:
		return view{
			Headline: "No connection to " + displayName,
			Detail:   "This computer cannot reach the internet, or the panel is down.",
			IP:       rt.VirtualIP,
		}
	case total == 0:
		return view{
			Headline: "Connected",
			Detail:   "No other computers on this network yet.",
			IP:       rt.VirtualIP,
			OK:       true,
		}
	case reachable == 0:
		return view{
			Headline: "Connecting to the other computers",
			Detail:   fmt.Sprintf("None of %s reachable yet. This usually takes a few seconds.", plural(total)),
			IP:       rt.VirtualIP,
		}
	case reachable < total:
		return view{
			Headline: "Connected",
			Detail:   fmt.Sprintf("%d of %s reachable.", reachable, plural(total)),
			IP:       rt.VirtualIP,
			OK:       true,
		}
	default:
		return view{
			Headline: "Connected",
			Detail:   fmt.Sprintf("All %s reachable.", plural(total)),
			IP:       rt.VirtualIP,
			OK:       true,
		}
	}
}

// countReachable counts peers with a path that carries traffic.
func countReachable(rt *state.Runtime) (int, int) {
	reachable := 0
	for _, peer := range rt.Peers {
		if peer.Path == "direct" || peer.Path == "relay" {
			reachable++
		}
	}

	return reachable, len(rt.Peers)
}

func plural(n int) string {
	if n == 1 {
		return "1 computer"
	}

	return fmt.Sprintf("%d computers", n)
}

// tooltip is what hovering over the icon shows. Windows truncates a tooltip at
// 127 characters and does it mid-word, so it is cut here instead, at something
// that still reads as a sentence.
func tooltip(v view) string {
	text := displayName + ": " + v.Headline
	if v.Detail != "" && len(text)+len(v.Detail)+1 <= 126 {
		text += "\n" + v.Detail
	}

	if len(text) > 126 {
		text = strings.TrimSpace(text[:123]) + "..."
	}

	return text
}
