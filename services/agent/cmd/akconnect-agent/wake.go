package main

import (
	"context"
	"net"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/winsvc"
)

// Coming back from sleep, a lid closed in one place and opened in another.
//
// This is the ordinary case, not the exotic one: a laptop is shut at a shop
// and opened at a hotel, a desktop resumes from Fast Startup every morning,
// somebody moves from Wi-Fi to a cable at the reception desk. Every one of
// those leaves the agent with a socket bound to an interface that may be gone,
// a public address that is certainly different, and a coordinator whose idea
// of where this device is, is wrong.
//
// Left alone, two mechanisms eventually notice: the keepalive, at twenty
// seconds, and the dead-socket detector, at three failed sends. Between them
// that is a minute or more of a customer looking at something that does not
// work, which is the whole of their experience of the product.
//
// So Windows is asked to tell us instead, and this is what happens when it
// does.

// wakeDebounce collapses a burst of events into one recovery. A resume
// produces a power event and then several network-binding events as adapters
// come back, and reacting to each would mean rebinding the socket four times
// while the machine is still settling.
const wakeDebounce = 3 * time.Second

var (
	wakeMu   sync.Mutex
	lastWake time.Time
)

// installWakeHandler points the service control handler at this session.
//
// Off Windows the hook is never called; it is set unconditionally so there is
// no second code path to keep in step.
func (s *session) installWakeHandler() {
	winsvc.OnWake = s.wake
}

// wake re-establishes this device's connection after the machine or its
// network has changed underneath it.
//
// Order matters. The socket is reopened first, because everything after it
// goes out of that socket; then the coordinator is re-resolved, because the
// address it was on may have been a DNS answer from a network this machine is
// no longer on; then a full hello, which is the only message that makes the
// coordinator re-ask the panel who this device may reach.
func (s *session) wake(reason string) {
	wakeMu.Lock()
	if time.Since(lastWake) < wakeDebounce {
		wakeMu.Unlock()

		return
	}
	lastWake = time.Now()
	wakeMu.Unlock()

	s.logf("%s; re-establishing the connection", reason)

	if s.discovery == nil {
		return
	}

	// A hello, not a ping, and NOT a rebind.
	//
	// A ping refreshes an entry the coordinator already has, and what has just
	// changed is the address that entry holds — so it has to be a hello.
	//
	// The socket is deliberately left alone. Reopening it throws away the NAT
	// mapping the router is holding for this device, which every peer is
	// currently sending to, and costs a fresh hole punch with every one of
	// them. The lab showed exactly that: a scenario that adds an unrelated
	// network interface — which is what docking a laptop, starting a virtual
	// machine or another VPN client looks like from here — made the agent
	// rebind, and the pair that had been talking went quiet until they had
	// punched again.
	//
	// If the socket really is dead, which is the ordinary case after a
	// resume, this announcement fails to send and noteSendFailure owns it
	// from there: three failures, five seconds apart, then a rebind for the
	// reason a rebind is for.
	s.discovery.Rehello()
}

// addressWatchInterval is how often this machine's own addresses are compared
// with what they were.
//
// Five seconds, because the budget for noticing a network change and being
// reachable again is thirty, and the work is reading a list the kernel already
// has. It is not a poll of anything remote.
const addressWatchInterval = 5 * time.Second

// watchAddresses notices the network moving underneath this machine.
//
// The service control handler covers a resume and, on the builds that send
// them, adapter binding changes. It does not cover the common case: a laptop
// switched from Wi-Fi to a cable at a reception desk, a phone hotspot
// replacing a broken line, a router handing out a new lease after a power cut.
// Windows sends the service nothing for any of those, and the agent's own
// address has changed underneath every socket it holds.
//
// Compared rather than subscribed to, deliberately. The subscription APIs
// differ on every platform this builds for and none of them is available on
// all of them; a five-second comparison of a list the kernel already has costs
// nothing measurable and behaves identically everywhere.
func (s *session) watchAddresses(ctx context.Context) {
	previous := localAddressSet(s.tunnelName())

	ticker := time.NewTicker(addressWatchInterval)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}

		current := localAddressSet(s.tunnelName())

		// An empty reading is a machine mid-change — an adapter down, a cable
		// out — not a network worth reacting to. Reacting would mean tearing
		// down a socket at the moment there is nowhere to rebind it.
		if current == "" || current == previous {
			continue
		}

		previous = current
		s.wake("this computer's network address changed")
	}
}

// tunnelName is the overlay interface, excluded from the comparison: its
// address is ours and changes when the panel says so, not when the network
// does. Without this, bringing the tunnel up would look like a network change
// and trigger a recovery from the thing that had just succeeded.
func (s *session) tunnelName() string {
	if s.tun == nil {
		return ""
	}

	return s.tun.Name()
}

// localAddressSet is this machine's routable addresses, as one comparable
// string.
//
// Loopback and link-local are left out: they are present whatever the network
// is doing, and a link-local address appearing as an adapter comes up is not a
// change of network.
func localAddressSet(exclude string) string {
	ifaces, err := net.Interfaces()
	if err != nil {
		return ""
	}

	var out []string

	for _, iface := range ifaces {
		if iface.Flags&net.FlagUp == 0 || iface.Flags&net.FlagLoopback != 0 {
			continue
		}
		if exclude != "" && strings.EqualFold(iface.Name, exclude) {
			continue
		}

		addrs, err := iface.Addrs()
		if err != nil {
			continue
		}

		for _, addr := range addrs {
			ipNet, ok := addr.(*net.IPNet)
			if !ok || ipNet.IP.IsLoopback() || ipNet.IP.IsLinkLocalUnicast() {
				continue
			}

			out = append(out, iface.Name+"="+ipNet.IP.String())
		}
	}

	sort.Strings(out)

	return strings.Join(out, ",")
}
