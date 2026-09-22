package discovery

import (
	"net"
	"net/netip"
	"testing"

	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
)

// deliverAck hands the client a HelloAck as if the coordinator sent one.
func (h *harness) deliverAck(t *testing.T) {
	t.Helper()

	body, err := (&disco.HelloAck{Reflexive: netip.MustParseAddrPort("203.0.113.7:50001")}).Encode()
	if err != nil {
		t.Fatal(err)
	}

	sealed, err := disco.Seal(body, &h.client.opts.SelfPublic, &h.coordPriv)
	if err != nil {
		t.Fatal(err)
	}

	pkt := make([]byte, disco.HeaderLen, disco.HeaderLen+len(sealed))
	disco.WriteHeader(pkt, disco.TypeHelloAck, h.coordPub)
	pkt = append(pkt, sealed...)

	h.client.handle(pkt, netip.MustParseAddrPort("10.0.0.1:8443"))
}

// Defect 23, the half the per-device port does not cover.
//
// Two PCs behind one shop router. The first one out holds the router's lease
// on the external port; everything the second sends from the same port is
// dropped inside the router. Every send succeeds — the socket is perfect — and
// no answer ever comes back. The agent announced into that hole indefinitely.
func TestAPortThatNeverGetsAnAnswerIsMovedAwayFrom(t *testing.T) {
	h := newHarness(t)

	moves := 0
	h.client.opts.MovePort = func() (int, []netip.AddrPort, error) {
		moves++

		return 50000 + moves, []netip.AddrPort{netip.MustParseAddrPort("192.168.30.3:50001")}, nil
	}

	// Two unanswered announcements are ordinary: a lost packet, a coordinator
	// that was restarting. A port that works must not be thrown away for that.
	h.client.announce(true)
	h.client.announce(true)
	h.client.maybeMovePort()

	if moves != 0 {
		t.Fatalf("the port was moved after 2 unanswered announcements (%d move(s)); one lost packet must not move it", moves)
	}

	h.client.announce(true)
	h.client.maybeMovePort()

	if moves != 1 {
		t.Fatalf("the port was moved %d time(s) after %d unanswered announcements, want 1", moves, portMoveAfter)
	}

	// And the device re-introduces itself on the new port, because the
	// coordinator has never heard from it.
	if h.transport.count(disco.TypeHello) == 0 {
		t.Fatal("nothing was announced after the port moved; the coordinator would never learn the new one")
	}

	// The addresses offered to peers must be the new ones. Announcing the old
	// port after moving would be worse than not moving.
	h.client.mu.Lock()
	local := h.client.opts.LocalEndpoints
	h.client.mu.Unlock()

	if len(local) != 1 || local[0].Port() != 50001 {
		t.Fatalf("peers are still being offered %v after the move", local)
	}
}

// A working peer proves the socket and the port are fine. If the coordinator
// alone goes quiet — a panel restart, a VPS reboot — moving the port would
// break every session that was working, to fix nothing.
func TestAQuietCoordinatorDoesNotMoveAPortThatWorks(t *testing.T) {
	h := newHarness(t)

	moves := 0
	h.client.opts.MovePort = func() (int, []netip.AddrPort, error) {
		moves++

		return 50001, nil, nil
	}

	h.client.mu.Lock()
	h.client.paths[[32]byte{9}] = pathDirect
	h.client.mu.Unlock()

	for i := 0; i < 12; i++ {
		h.client.announce(true)
		h.client.maybeMovePort()
	}

	if moves != 0 {
		t.Fatalf("the port moved %d time(s) while a peer was connected through it", moves)
	}
}

// The dead socket and the blocked port are different faults with different
// cures, and only one of them may act at a time: reopening the socket and
// changing its port in the same second is two recoveries fighting.
//
// Two things keep them apart — a send that failed is not an announcement that
// went unanswered, so the count never rises, and the count is only acted on
// while sends are succeeding. This asserts the outcome both are there for.
func TestADeadSocketIsNotTreatedAsABlockedPort(t *testing.T) {
	h := newHarness(t)

	moves := 0
	h.client.opts.MovePort = func() (int, []netip.AddrPort, error) { moves++; return 50001, nil, nil }
	h.client.opts.Rebind = func() error { return nil }

	h.transport.mu.Lock()
	h.transport.failWith = net.ErrClosed
	h.transport.mu.Unlock()

	for i := 0; i < 12; i++ {
		h.client.announce(true)
		h.client.maybeMovePort()
	}

	if moves != 0 {
		t.Fatalf("a dead socket moved the port %d time(s); that is the other detector's fault to fix", moves)
	}
}

// An ack is the only proof the whole path out of this machine works, so it is
// what clears the count. Without this the agent would keep moving ports it had
// already proved good.
func TestAnAnswerClearsTheCount(t *testing.T) {
	h := newHarness(t)

	moves := 0
	h.client.opts.MovePort = func() (int, []netip.AddrPort, error) { moves++; return 50001, nil, nil }

	for i := 0; i < 30; i++ {
		h.client.announce(true)
		h.deliverAck(t)
		h.client.maybeMovePort()
	}

	if moves != 0 {
		t.Fatalf("the port moved %d time(s) while the coordinator was answering every announcement", moves)
	}
}

// A port that is still blocked is left again. The first replacement can be
// leased to the same neighbour, and an agent that tried once and gave up is an
// agent nobody can reach.
func TestAReplacementPortThatIsAlsoBlockedIsLeftToo(t *testing.T) {
	h := newHarness(t)

	moves := 0
	h.client.opts.MovePort = func() (int, []netip.AddrPort, error) { moves++; return 50000 + moves, nil, nil }

	for i := 0; i < 40; i++ {
		h.client.announce(true)
		h.client.maybeMovePort()
	}

	// The property, not a count: each move is rarer than the last, on purpose,
	// so a panel outage does not churn the port every fifteen seconds. What
	// matters is that it keeps trying.
	if moves < 2 {
		t.Fatalf("the port was moved %d time(s) and then abandoned while still unanswered", moves)
	}
}
