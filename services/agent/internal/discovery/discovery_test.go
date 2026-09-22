package discovery

import (
	"crypto/rand"
	"fmt"
	"net"
	"net/netip"
	"strings"
	"sync"
	"testing"

	"golang.org/x/crypto/nacl/box"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/disconn"
	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
)

// fakeTransport records what the client sent, instead of putting it on a wire.
type fakeTransport struct {
	mu   sync.Mutex
	sent []sentPacket
	// failWith is returned by SendTo instead of sending, so a socket that has
	// died underneath the agent can be reproduced.
	failWith error
	// onSend runs while the send is in flight. It is how the test reproduces
	// an answer that arrives before the sending side has finished bookkeeping,
	// which on a real machine is two goroutines and a fast network.
	onSend func()
}

type sentPacket struct {
	to   netip.AddrPort
	kind disco.MessageType
}

func (f *fakeTransport) SendTo(pkt []byte, to netip.AddrPort) error {
	f.mu.Lock()
	if f.failWith != nil {
		err := f.failWith
		f.mu.Unlock()

		return err
	}
	hook := f.onSend
	f.mu.Unlock()

	if hook != nil {
		hook()
	}

	header, _, err := disco.ParseHeader(pkt)
	if err != nil {
		return nil
	}

	f.mu.Lock()
	defer f.mu.Unlock()
	f.sent = append(f.sent, sentPacket{to: to, kind: header.Type})

	return nil
}

// count is how many packets of one kind were sent.
func (f *fakeTransport) count(kind disco.MessageType) int {
	f.mu.Lock()
	defer f.mu.Unlock()

	n := 0
	for _, pkt := range f.sent {
		if pkt.kind == kind {
			n++
		}
	}

	return n
}

func (f *fakeTransport) SetHandler(disconn.Handler) {}
func (f *fakeTransport) Port() uint16               { return 51820 }

// punchesTo reports the addresses a punch was sent to.
func (f *fakeTransport) punchesTo() []netip.AddrPort {
	f.mu.Lock()
	defer f.mu.Unlock()

	var out []netip.AddrPort
	for _, p := range f.sent {
		if p.kind == disco.TypePunch {
			out = append(out, p.to)
		}
	}

	return out
}

// fakePeers records endpoint changes the client asked for.
type fakePeers struct {
	mu        sync.Mutex
	endpoints map[string]string
}

func (f *fakePeers) SetPeerEndpoint(key, endpoint string) error {
	f.mu.Lock()
	defer f.mu.Unlock()

	if f.endpoints == nil {
		f.endpoints = map[string]string{}
	}
	f.endpoints[key] = endpoint

	return nil
}

// harness is a client wired to fakes, plus the coordinator keys needed to
// seal messages to it.
type harness struct {
	client    *Client
	transport *fakeTransport
	peers     *fakePeers
	coordPub  [32]byte
	coordPriv [32]byte
}

func newHarness(t *testing.T) *harness {
	t.Helper()

	selfPub, selfPriv, err := box.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	coordPub, coordPriv, err := box.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}

	transport := &fakeTransport{}
	peers := &fakePeers{}

	client, err := New(Options{
		SelfPublic:  *selfPub,
		SelfPrivate: *selfPriv,
		Coordinator: netip.MustParseAddrPort("10.0.0.1:8443"),
		CoordKey:    *coordPub,
		DeviceUID:   "dev_test",
		Token:       "a-token",
		Overlay:     netip.MustParsePrefix("10.99.0.0/24"),
		Transport:   transport,
		Peers:       peers,
		Logf:        func(string, ...any) {},
	})
	if err != nil {
		t.Fatal(err)
	}

	return &harness{
		client:    client,
		transport: transport,
		peers:     peers,
		coordPub:  *coordPub,
		coordPriv: *coordPriv,
	}
}

// deliverPeers hands the client a Peers message as if the coordinator sent it.
func (h *harness) deliverPeers(t *testing.T, peers ...disco.PeerInfo) {
	t.Helper()

	msg := &disco.Peers{Peers: peers}

	body, err := msg.Encode()
	if err != nil {
		t.Fatal(err)
	}

	sealed, err := disco.Seal(body, &h.client.opts.SelfPublic, &h.coordPriv)
	if err != nil {
		t.Fatal(err)
	}

	pkt := make([]byte, disco.HeaderLen, disco.HeaderLen+len(sealed))
	disco.WriteHeader(pkt, disco.TypePeers, h.coordPub)
	pkt = append(pkt, sealed...)

	h.client.handle(pkt, netip.MustParseAddrPort("10.0.0.1:8443"))
}

// Defect 13, the half that mattered most: a relayed pair stayed relayed
// forever, because probe() returned early for any peer that already had an
// endpoint — and the coordinator's peer message is the *only* moment both ends
// are known to act together. Hole punching needs simultaneity; independent
// retry timers on each agent almost never line up.
func TestPunchesAgainForARelayedPeer(t *testing.T) {
	h := newHarness(t)

	peer := [32]byte{7}
	candidate := netip.MustParseAddrPort("203.0.113.9:51820")

	// The peer is currently reached through a relay.
	h.client.mu.Lock()
	h.client.paths[peer] = pathRelay
	h.client.established[peer] = netip.MustParseAddrPort("198.51.100.1:40000")
	h.client.mu.Unlock()

	h.deliverPeers(t, disco.PeerInfo{PublicKey: peer, Candidates: []netip.AddrPort{candidate}})

	punches := h.transport.punchesTo()
	if len(punches) == 0 {
		t.Fatal("no punch was sent for a relayed peer; it would stay relayed forever")
	}

	found := false
	for _, to := range punches {
		if to == candidate {
			found = true
		}
	}
	if !found {
		t.Errorf("punched %v, but not the peer's candidate %v", punches, candidate)
	}
}

// The other side of that rule: a peer already reached directly must be left
// alone, or every refresh would disturb a working tunnel.
func TestDoesNotPunchAPeerAlreadyDirect(t *testing.T) {
	h := newHarness(t)

	peer := [32]byte{8}

	h.client.mu.Lock()
	h.client.paths[peer] = pathDirect
	h.client.mu.Unlock()

	h.deliverPeers(t, disco.PeerInfo{
		PublicKey:  peer,
		Candidates: []netip.AddrPort{netip.MustParseAddrPort("203.0.113.10:51820")},
	})

	if punches := h.transport.punchesTo(); len(punches) != 0 {
		t.Errorf("punched %v at a peer that is already direct", punches)
	}
}

// Candidates must be refreshed even when the path is not disturbed, or a
// direct peer that moves could never be re-found.
func TestRefreshesCandidatesForADirectPeer(t *testing.T) {
	h := newHarness(t)

	peer := [32]byte{9}
	moved := netip.MustParseAddrPort("203.0.113.11:51820")

	h.client.mu.Lock()
	h.client.paths[peer] = pathDirect
	h.client.mu.Unlock()

	h.deliverPeers(t, disco.PeerInfo{PublicKey: peer, Candidates: []netip.AddrPort{moved}})

	h.client.mu.Lock()
	got := h.client.candidates[peer]
	h.client.mu.Unlock()

	if len(got) != 1 || got[0] != moved {
		t.Errorf("candidates = %v, want [%v]", got, moved)
	}
}

// An endpoint inside the overlay would ask WireGuard to carry its own
// encrypted traffic through the tunnel it is establishing. Refused whoever
// offers it — including the coordinator.
func TestRefusesAnOverlayAddressAsAPath(t *testing.T) {
	h := newHarness(t)

	peer := [32]byte{10}
	inside := netip.MustParseAddrPort("10.99.0.3:51820")

	h.deliverPeers(t, disco.PeerInfo{PublicKey: peer, Candidates: []netip.AddrPort{inside}})

	for _, to := range h.transport.punchesTo() {
		if to == inside {
			t.Fatalf("punched %v, which is inside the overlay", to)
		}
	}

	h.client.adopt(peer, inside)

	h.peers.mu.Lock()
	defer h.peers.mu.Unlock()
	if len(h.peers.endpoints) != 0 {
		t.Errorf("adopted an overlay address as a peer endpoint: %v", h.peers.endpoints)
	}
}

// The field log that prompted this, from a Windows laptop, verbatim:
//
//	12:21:06 discovery: announcing to the coordinator failed: use of closed network connection
//	12:21:26 discovery: announcing to the coordinator failed: use of closed network connection
//	12:21:46 discovery: announcing to the coordinator failed: use of closed network connection
//	12:22:06 discovery: announcing to the coordinator failed: use of closed network connection
//
// Every twenty seconds, for as long as anyone watched. Something below the
// agent had closed the shared socket; the agent logged it and tried the same
// dead socket again on the next tick. The machine heartbeated, the panel
// showed it Online, its peers were never told where it was, and only a manual
// service restart fixed it.
func TestADeadSocketIsReopenedRatherThanLoggedForever(t *testing.T) {
	h := newHarness(t)

	rebinds := 0
	h.client.opts.Rebind = func() error {
		rebinds++
		h.transport.mu.Lock()
		h.transport.failWith = nil
		h.transport.mu.Unlock()

		return nil
	}

	h.transport.mu.Lock()
	h.transport.failWith = net.ErrClosed
	h.transport.mu.Unlock()

	// Two failures are bad luck: a lost packet, an interface coming up.
	// Nothing should be torn down for those.
	h.client.announce(true)
	h.client.announce(true)

	if rebinds != 0 {
		t.Fatalf("the socket was reopened after %d failure(s); a transient must not churn it", rebinds)
	}

	if h.client.TransportProblem() == "" {
		t.Fatal("a failing socket is not being reported to the panel")
	}

	// The third is a dead socket.
	h.client.announce(true)

	if rebinds != 1 {
		t.Fatalf("the socket was reopened %d time(s), want 1", rebinds)
	}

	// And the agent must re-announce afterwards rather than waiting out the
	// keepalive: the coordinator has heard nothing from it for a minute.
	if h.transport.count(disco.TypeHello) == 0 {
		t.Fatal("nothing was announced after the socket was reopened")
	}

	if h.client.TransportProblem() != "" {
		t.Fatalf("the problem is still reported after recovery: %q", h.client.TransportProblem())
	}
}

// A rebind that does not help must be tried again, not attempted once and
// given up on: the socket is still dead and the device is still unreachable.
func TestARebindThatDoesNotHelpIsTriedAgain(t *testing.T) {
	h := newHarness(t)

	rebinds := 0
	h.client.opts.Rebind = func() error { rebinds++; return nil }

	h.transport.mu.Lock()
	h.transport.failWith = net.ErrClosed
	h.transport.mu.Unlock()

	for i := 0; i < 9; i++ {
		h.client.announce(true)
	}

	// The property, not a count. Each rebind is followed by a re-announcement
	// that also fails, so the exact number depends on how that is scheduled —
	// asserting it would be asserting the implementation. What matters is that
	// a socket which stays dead keeps being reopened rather than being given
	// up on after one attempt.
	if rebinds < 2 {
		t.Fatalf("the socket was reopened %d time(s) and then abandoned while still dead", rebinds)
	}

	if h.client.TransportProblem() == "" {
		t.Fatal("the panel is not being told the device is unreachable")
	}
}

// The recovery must not announce a recovery that has not happened.
//
// The first version of this logged "socket reopened; re-announcing" on the
// line immediately before the next send failed with the same error, and then
// went round again quietly. A reader of that log would conclude the agent had
// fixed itself. It had not, and the machine stayed unreachable.
func TestARebindThatDidNotHelpIsNotReportedAsRecovered(t *testing.T) {
	h := newHarness(t)

	var lines []string
	h.client.opts.Logf = func(format string, args ...any) {
		lines = append(lines, fmt.Sprintf(format, args...))
	}
	h.client.opts.Rebind = func() error { return nil }

	h.transport.mu.Lock()
	h.transport.failWith = net.ErrClosed
	h.transport.mu.Unlock()

	for i := 0; i < 6; i++ {
		h.client.announce(true)
	}

	for _, line := range lines {
		if strings.Contains(line, "working again") {
			t.Fatalf("a dead socket was reported as recovered: %q", line)
		}
	}

	// And the problem stays reported for the panel, because it is still true.
	if h.client.TransportProblem() == "" {
		t.Fatal("the problem was cleared although nothing was ever sent")
	}

	// Once the socket genuinely works, the recovery is announced — otherwise
	// the check above would pass on an agent that never says anything.
	h.transport.mu.Lock()
	h.transport.failWith = nil
	h.transport.mu.Unlock()

	h.client.announce(true)

	recovered := false
	for _, line := range lines {
		if strings.Contains(line, "working again") {
			recovered = true
		}
	}
	if !recovered {
		t.Fatal("a genuine recovery was not reported")
	}
	if h.client.TransportProblem() != "" {
		t.Fatal("the problem is still reported after a real recovery")
	}
}

// Defect 23: two PCs in one shop are on one switch, and reaching each other
// across it must not depend on the router hairpinning traffic back to itself —
// which many consumer routers will not do — nor consume one of the external
// mappings the two of them are already fighting over.
func TestACandidateOnOurOwnSubnetIsTriedFirst(t *testing.T) {
	ours := []netip.AddrPort{netip.MustParseAddrPort("192.168.30.2:51820")}

	peer := disco.PeerInfo{
		Candidates: []netip.AddrPort{
			netip.MustParseAddrPort("203.0.113.7:41000"),  // the public one
			netip.MustParseAddrPort("10.8.0.4:51820"),     // another network
			netip.MustParseAddrPort("192.168.30.3:51820"), // next door
		},
	}

	got := prefer(peer, ours).Candidates

	if got[0] != netip.MustParseAddrPort("192.168.30.3:51820") {
		t.Fatalf("the same-subnet candidate is not first: %v", got)
	}

	// And nothing is dropped: a peer that looks same-subnet may be a different
	// site using the same private range, and that is the case that would
	// otherwise never reach anybody.
	if len(got) != 3 {
		t.Fatalf("candidates were lost: %v", got)
	}
}

func TestOrderingIsLeftAloneWhenNothingIsNear(t *testing.T) {
	ours := []netip.AddrPort{netip.MustParseAddrPort("192.168.30.2:51820")}

	peer := disco.PeerInfo{
		Candidates: []netip.AddrPort{
			netip.MustParseAddrPort("203.0.113.7:41000"),
			netip.MustParseAddrPort("10.8.0.4:51820"),
		},
	}

	got := prefer(peer, ours).Candidates

	if got[0] != netip.MustParseAddrPort("203.0.113.7:41000") || len(got) != 2 {
		t.Fatalf("an unrelated list was reordered: %v", got)
	}
}
