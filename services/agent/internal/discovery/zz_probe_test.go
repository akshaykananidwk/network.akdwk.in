package discovery

import (
	"net/netip"
	"testing"
)

// Temporary probe: trace what Unanswered() reports while the port-move
// detector is running, with a MovePort that succeeds and one that fails.
func TestZZProbeUnansweredTrajectory(t *testing.T) {
	for _, moveErr := range []bool{false, true} {
		h := newHarness(t)
		moves := 0
		h.client.opts.MovePort = func() (int, []netip.AddrPort, error) {
			moves++
			if moveErr {
				return 0, nil, errFake
			}
			return 50000 + moves, nil, nil
		}

		maxSeen := 0
		// 40 announcements, each followed by the same one-second tick work the
		// real loop does.
		for i := 0; i < 40; i++ {
			h.client.announce(true)
			h.client.maybeMovePort()
			n, _ := h.client.Unanswered()
			if n > maxSeen {
				maxSeen = n
			}
		}
		t.Logf("moveErr=%v: moves=%d maxUnansweredObservedAfterTick=%d", moveErr, moves, maxSeen)
	}
}

var errFake = fakeErr{}

type fakeErr struct{}

func (fakeErr) Error() string { return "rebind refused" }
