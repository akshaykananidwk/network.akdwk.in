package discovery

import (
	"crypto/rand"
	"net/netip"
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
}

type sentPacket struct {
	to   netip.AddrPort
	kind disco.MessageType
}

func (f *fakeTransport) SendTo(pkt []byte, to netip.AddrPort) error {
	header, _, err := disco.ParseHeader(pkt)
	if err != nil {
		return nil
	}

	f.mu.Lock()
	defer f.mu.Unlock()
	f.sent = append(f.sent, sentPacket{to: to, kind: header.Type})

	return nil
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
