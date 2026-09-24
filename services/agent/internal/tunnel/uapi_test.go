package tunnel

import (
	"net"
	"strings"
	"sync"
	"sync/atomic"
	"testing"

	"golang.zx2c4.com/wireguard/conn"
	"golang.zx2c4.com/wireguard/device"
	"golang.zx2c4.com/wireguard/tun/tuntest"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/wgkey"
)

// countingBind is a socket that is never really opened, and counts how often
// wireguard-go closes and reopens it: every reopen is a moment inbound packets
// are dropped and, behind a NAT, a mapping that may not come back the same.
type countingBind struct {
	opens  atomic.Int32
	mu     sync.Mutex
	closed chan struct{}
}

func (b *countingBind) Open(port uint16) ([]conn.ReceiveFunc, uint16, error) {
	b.opens.Add(1)
	ch := make(chan struct{})
	b.mu.Lock()
	b.closed = ch
	b.mu.Unlock()
	recv := func(_ [][]byte, _ []int, _ []conn.Endpoint) (int, error) {
		<-ch
		return 0, net.ErrClosed
	}
	return []conn.ReceiveFunc{recv}, port, nil
}

func (b *countingBind) Close() error {
	b.mu.Lock()
	defer b.mu.Unlock()
	if b.closed != nil {
		close(b.closed)
		b.closed = nil
	}
	return nil
}

func (b *countingBind) SetMark(uint32) error               { return nil }
func (b *countingBind) Send([][]byte, conn.Endpoint) error { return nil }
func (b *countingBind) BatchSize() int                     { return 1 }
func (b *countingBind) ParseEndpoint(s string) (conn.Endpoint, error) {
	return conn.NewStdNetBind().ParseEndpoint(s)
}

type testPeer struct {
	priv wgkey.Private
	pub  wgkey.Public
}

func newPeer(t *testing.T) testPeer {
	t.Helper()
	priv, pub, err := wgkey.Generate()
	if err != nil {
		t.Fatal(err)
	}
	return testPeer{priv, pub}
}

func (p testPeer) cfg(allowed ...string) panel.Peer {
	return panel.Peer{UID: p.pub.Hex()[:8], PublicKey: p.pub.Base64(), AllowedIPs: allowed, Endpoint: "192.0.2.1:51820"}
}

func (p testPeer) key() device.NoisePublicKey {
	var k device.NoisePublicKey
	copy(k[:], p.pub[:])
	return k
}

type rig struct {
	t    *testing.T
	dev  *device.Device
	bind *countingBind
	priv wgkey.Private
	port int
}

func newRig(t *testing.T) *rig {
	t.Helper()
	priv, _, err := wgkey.Generate()
	if err != nil {
		t.Fatal(err)
	}
	bind := &countingBind{}
	dev := device.NewDevice(tuntest.NewChannelTUN().TUN(), bind, device.NewLogger(device.LogLevelSilent, ""))
	t.Cleanup(dev.Close)

	return &rig{t: t, dev: dev, bind: bind, priv: priv, port: 51820}
}

func (r *rig) apply(peers ...panel.Peer) string {
	r.t.Helper()
	cfg := &panel.Config{Peers: peers}
	cfg.Network.Keepalive = 25

	current, err := r.dev.IpcGet()
	if err != nil {
		r.t.Fatal(err)
	}
	uapi, err := buildApply(r.priv, r.port, parseDeviceState(current), cfg, nil)
	if err != nil {
		r.t.Fatal(err)
	}
	if uapi != "" {
		if err := r.dev.IpcSet(uapi); err != nil {
			r.t.Fatalf("IpcSet: %v\n%s", err, uapi)
		}
	}
	if err := r.dev.Up(); err != nil {
		r.t.Fatal(err)
	}
	return uapi
}

func (r *rig) state() deviceState {
	r.t.Helper()
	current, err := r.dev.IpcGet()
	if err != nil {
		r.t.Fatal(err)
	}
	return parseDeviceState(current)
}

// The revision that moved every pair: an unrelated change to the network must
// leave a working peer exactly as it was — the same peer object, so the same
// session keys, and the endpoint discovery found rather than the panel's hint.
func TestARevisionLeavesAnUnchangedPeerAndItsEndpointAlone(t *testing.T) {
	r := newRig(t)
	a, b, c := newPeer(t), newPeer(t), newPeer(t)

	r.apply(a.cfg("10.50.0.2/32"), b.cfg("10.50.0.3/32"))
	opensAfterFirst := r.bind.opens.Load()

	// Discovery finds a better path to A.
	if err := r.dev.IpcSet("public_key=" + a.pub.Hex() + "\nupdate_only=true\nendpoint=198.51.100.7:40000\n"); err != nil {
		t.Fatal(err)
	}
	before := r.dev.LookupPeer(a.key())

	// A revision: B's allowed IPs change and C joins. A is untouched.
	uapi := r.apply(a.cfg("10.50.0.2/32"), b.cfg("10.50.0.3/32", "10.128.5.0/24"), c.cfg("10.50.0.4/32"))

	if strings.Contains(uapi, "replace_peers") || strings.Contains(uapi, "listen_port") {
		t.Fatalf("the revision still resets the device:\n%s", uapi)
	}
	if strings.Contains(uapi, a.pub.Hex()) {
		t.Fatalf("an unchanged peer was written again:\n%s", uapi)
	}
	if r.dev.LookupPeer(a.key()) != before {
		t.Fatal("peer A was re-created, losing its session")
	}
	st := r.state()
	if got := st.peers[strings.ToLower(a.pub.Hex())].endpoint; got != "198.51.100.7:40000" {
		t.Fatalf("A's endpoint is %q, not the one discovery found", got)
	}
	if got := strings.Join(st.peers[strings.ToLower(b.pub.Hex())].allowed, ","); got != "10.128.5.0/24,10.50.0.3/32" {
		t.Fatalf("B's allowed IPs are %q", got)
	}
	if _, ok := st.peers[strings.ToLower(c.pub.Hex())]; !ok {
		t.Fatal("C was not added")
	}
	if r.bind.opens.Load() != opensAfterFirst {
		t.Fatalf("the socket was reopened %d time(s) by a revision", r.bind.opens.Load()-opensAfterFirst)
	}
}

// Reconciled against the device, not against what was sent last time: a peer
// the configuration no longer lists is removed even if it got there some other
// way, so a missed update can never leave a revoked peer behind.
func TestAPeerNotInTheConfigurationIsRemovedWhateverPutItThere(t *testing.T) {
	r := newRig(t)
	a, stray := newPeer(t), newPeer(t)

	r.apply(a.cfg("10.50.0.2/32"))
	if err := r.dev.IpcSet("public_key=" + stray.pub.Hex() + "\nallowed_ip=10.50.0.9/32\n"); err != nil {
		t.Fatal(err)
	}

	r.apply(a.cfg("10.50.0.2/32"))

	if r.dev.LookupPeer(stray.key()) != nil {
		t.Fatal("a peer the configuration does not list is still on the device")
	}
	if r.dev.LookupPeer(a.key()) == nil {
		t.Fatal("the listed peer was removed")
	}
}

// The same configuration twice is nothing at all.
func TestTheSameConfigurationAgainChangesNothing(t *testing.T) {
	r := newRig(t)
	a := newPeer(t)

	r.apply(a.cfg("10.50.0.2/32"))
	if uapi := r.apply(a.cfg("10.50.0.2/32")); uapi != "" {
		t.Fatalf("re-applying an unchanged configuration sent:\n%s", uapi)
	}
}

// A peer that has never had an address takes the panel's hint when one comes.
func TestAPeerWithNoAddressTakesTheHint(t *testing.T) {
	r := newRig(t)
	a := newPeer(t)

	noHint := a.cfg("10.50.0.2/32")
	noHint.Endpoint = ""
	r.apply(noHint)

	r.apply(a.cfg("10.50.0.2/32"))
	if got := r.state().peers[strings.ToLower(a.pub.Hex())].endpoint; got != "192.0.2.1:51820" {
		t.Fatalf("the hint was not taken: %q", got)
	}
}

// A revoked peer goes even when something later in the same push is refused,
// and a hint that is not an address is never sent at all: wireguard-go stops
// at the first line it refuses and keeps everything before it.
func TestARevokedPeerIsRemovedEvenIfAnotherPeerIsBroken(t *testing.T) {
	r := newRig(t)
	a, revoked, poisoned := newPeer(t), newPeer(t), newPeer(t)

	r.apply(a.cfg("10.50.0.2/32"), revoked.cfg("10.50.0.3/32"))

	bad := poisoned.cfg("10.50.0.4/32")
	bad.Endpoint = "edge.example.com:443"
	uapi := r.apply(a.cfg("10.50.0.2/32"), bad)

	if strings.Contains(uapi, "edge.example.com") {
		t.Fatalf("a hint that is not an IP and port was sent:\n%s", uapi)
	}
	if strings.Index(uapi, "remove=true") > strings.Index(uapi, poisoned.pub.Hex()) {
		t.Fatalf("the removal comes after another peer's block:\n%s", uapi)
	}
	if r.dev.LookupPeer(revoked.key()) != nil {
		t.Fatal("the revoked peer is still on the device")
	}
	if r.dev.LookupPeer(poisoned.key()) == nil {
		t.Fatal("the peer with a bad hint was not added at all")
	}
}
