package discovery

import (
	"net/netip"
	"time"
)

// This file is the second half of defect 23: two PCs behind one router.
//
// The first half is that each device picks its own UDP port instead of every
// device in the world using 51820 (see state.ChooseListenPort). That is enough
// for the ordinary case. It is not enough for the case the field actually
// produced: the second PC on the router had already lost, because the router
// had leased the external port to the first one and simply dropped everything
// the second sent from it. Nothing failed. The socket was fine, every send
// returned success, and the agent announced into a hole for as long as anyone
// watched — the same shape as the dead socket in noteSendFailure, with none of
// the symptoms that detector looks for.
//
// So there is a second detector here, for the case where sends succeed and
// answers never come: move to a different port and say so again.

// portMoveAfter is how many unanswered announcements mean the port itself is
// the problem. Announcements are retried every retryInterval while unacked, so
// three of them is about fifteen seconds — inside the thirty-second budget a
// customer is promised, and long enough that one lost packet on a slow link
// does not move a port that was working.
const portMoveAfter = 3

// retryInterval is how fast an unanswered announcement is repeated. The
// keepalive is twenty seconds, which is right for a conversation that is
// working and far too slow for one that has never started: a lost first hello
// used to cost a full twenty seconds of a sixty-second install.
const retryInterval = 5 * time.Second

// retryBurst bounds that. The fast retry exists to get a device joined and to
// reach the port-move decision quickly, not to hammer a coordinator that is
// down: once the burst is spent the agent falls back to the keepalive and
// waits like everyone else.
const retryBurst = 6

// portMoveCeiling caps the backoff. A coordinator that is down answers nothing
// either, and the agent cannot tell that apart from a blocked port from here.
// Moving the port every fifteen seconds for the length of a panel outage would
// churn the one thing peers use to reach this device, so each move makes the
// next one rarer, up to roughly four minutes.
const portMoveCeiling = 48

// noteAnnouncementSent records an announcement that left the socket. Whether
// it arrived anywhere is a separate question, answered only by an ack.
func (c *Client) noteAnnouncementSent() {
	c.mu.Lock()
	c.unacked++
	c.lastSend = time.Now()
	if c.firstSend.IsZero() {
		c.firstSend = c.lastSend
	}
	c.mu.Unlock()
}

// Unanswered reports how many announcements have gone out with no reply, and
// how long it has been since the coordinator last answered.
//
// The two faults are opposite and are told apart by the caller: sends that
// FAIL are a dead socket, and TransportProblem reports those. This is the
// other one — every send succeeds, nothing ever comes back — which on Windows
// means a firewall, a router, or an adapter that is not carrying the return
// path. It looks identical to "still connecting" from the outside, and a
// device that sits on "connecting" for ever tells nobody anything.
//
// since is measured from the last ack, or from the first announcement when
// there has never been one.
func (c *Client) Unanswered() (int, time.Duration) {
	c.mu.Lock()
	defer c.mu.Unlock()

	if c.unacked == 0 {
		return 0, 0
	}

	from := c.lastAck
	if from.IsZero() {
		from = c.firstSend
	}
	if from.IsZero() {
		return c.unacked, 0
	}

	return c.unacked, time.Since(from)
}

// Answered reports whether the coordinator has replied recently enough to call
// the path out of this machine working.
//
// Recently, not ever. The status file used to latch this on the first reply,
// so a device that was answered once at nine in the morning and never again
// reported a reachable coordinator all day.
func (c *Client) Answered(within time.Duration) bool {
	c.mu.Lock()
	defer c.mu.Unlock()

	return !c.lastAck.IsZero() && time.Since(c.lastAck) <= within
}

// undoAnnouncementSent takes back a count for an announcement that never left
// the socket.
func (c *Client) undoAnnouncementSent() {
	c.mu.Lock()
	if c.unacked > 0 {
		c.unacked--
	}
	c.mu.Unlock()
}

// noteAnswered records that the coordinator replied, which proves the whole
// path out of this machine works: socket, port, router and all.
func (c *Client) noteAnswered() {
	c.mu.Lock()
	moved := c.portMoves
	c.unacked = 0
	c.portMoves = 0
	c.mu.Unlock()

	if moved > 0 {
		c.opts.Logf("discovery: the coordinator is answering again after %d port move(s)", moved)
	}
}

// maybeRetryUnacked repeats an announcement that has not been answered.
//
// Called from the one-second tick, so an agent whose first hello was lost
// tries again in five seconds rather than waiting out the keepalive.
func (c *Client) maybeRetryUnacked(keepalive time.Duration) {
	c.mu.Lock()
	unacked := c.unacked
	since := time.Since(c.lastSend)
	c.mu.Unlock()

	if unacked == 0 || unacked > retryBurst || since < retryInterval {
		return
	}

	c.announce(c.needsHello(keepalive))
}

// maybeMovePort moves this device to a different UDP port when announcements
// keep going out unanswered.
//
// Guarded three ways, because moving the port is disruptive: it is the address
// peers have been told to use, and every session on it has to be re-punched.
//   - Nothing moves while a peer is reachable. A working path is proof that
//     the socket and the port are fine and the silence is the coordinator's.
//   - Nothing moves while sends are failing. That is the dead socket, and
//     noteSendFailure already owns it; both detectors firing at once would
//     reopen the socket and change its port in the same second.
//   - Each move makes the next one rarer, so an outage costs a handful of
//     moves rather than one every fifteen seconds for its duration.
func (c *Client) maybeMovePort() {
	if c.opts.MovePort == nil {
		return
	}

	c.mu.Lock()
	threshold := portMoveThreshold(c.portMoves)
	blocked := c.unacked >= threshold && c.sendFailures == 0
	if blocked && c.anyPeerReachableLocked() {
		// Someone can reach us, so the port works. Reset rather than count on
		// towards a move that must not happen.
		c.unacked = 0
		blocked = false
	}
	if blocked {
		c.unacked = 0
		c.portMoves++
	}
	attempt := c.portMoves
	c.mu.Unlock()

	if !blocked {
		return
	}

	c.opts.Logf("discovery: %d announcements went out with no reply; this port is not getting through. "+
		"Moving to another one (attempt %d)", threshold, attempt)

	port, local, err := c.opts.MovePort()
	if err != nil {
		c.opts.Logf("discovery: moving to another port failed: %v", err)

		return
	}

	c.mu.Lock()
	c.opts.LocalEndpoints = local
	c.mu.Unlock()

	// Deliberately not "fixed". The next ack decides that, and says so in
	// noteAnswered.
	c.opts.Logf("discovery: now on UDP %d; announcing again to find out whether it helped", port)

	c.Rehello()
}

// portMoveThreshold is how many unanswered announcements the next move needs.
//
// Doubling, capped. The cap is applied to the SHIFT and not only to the
// result, which matters more than it looks: shifting a Go int by its own width
// or more yields zero, so after enough moves `portMoveAfter << moves` would
// have been 0 — and a threshold of zero is "move the port every second, for
// ever", which is the exact opposite of the backoff this is.
func portMoveThreshold(moves int) int {
	const maxShift = 5 // 3 << 5 is already past the ceiling

	if moves < 0 {
		moves = 0
	}
	if moves > maxShift {
		moves = maxShift
	}

	threshold := portMoveAfter << moves
	if threshold > portMoveCeiling {
		threshold = portMoveCeiling
	}

	return threshold
}

// anyPeerReachableLocked reports whether any peer currently has a path that
// carries traffic. The caller holds the lock.
func (c *Client) anyPeerReachableLocked() bool {
	for _, p := range c.paths {
		if p == pathDirect || p == pathRelay {
			return true
		}
	}

	return false
}

// localEndpointsOn is the addresses in a set, re-pointed at a new port. Used
// after a move so peers are offered where this device actually is.
func localEndpointsOn(existing []netip.AddrPort, port uint16) []netip.AddrPort {
	moved := make([]netip.AddrPort, 0, len(existing))
	for _, at := range existing {
		moved = append(moved, netip.AddrPortFrom(at.Addr(), port))
	}

	return moved
}
