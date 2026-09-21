package server

import (
	"context"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"net"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"sync"
	"testing"
	"time"

	"golang.org/x/crypto/nacl/box"

	"github.com/akshaykananidwk/network.akdwk.in/services/coordinator/internal/panelapi"
	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
)

// These drive a real coordinator over a real UDP socket, with a stub panel.
// The fault they exist for is one of presence and timing, not of parsing, and
// it does not reproduce without both ends of the conversation.

// stubPanel answers the coordinator's verification calls, allowing everyone to
// talk to everyone.
// keyBook is the stub panel's device register.
//
// Mutable and mutex-guarded, because the coordinator is already serving — in
// its own goroutine per packet — by the time a test knows which keys its
// agents ended up with. An earlier version swapped the whole panel client out
// from under the running server instead, which -race correctly called a data
// race: the test, not the product, but a test that races makes -race useless
// for finding the ones that are not.
type keyBook struct {
	mu   sync.Mutex
	keys map[string]string
}

func newKeyBook() *keyBook { return &keyBook{keys: map[string]string{}} }

func (k *keyBook) set(uid, key string) {
	k.mu.Lock()
	defer k.mu.Unlock()
	k.keys[uid] = key
}

func (k *keyBook) snapshot() map[string]string {
	k.mu.Lock()
	defer k.mu.Unlock()

	out := make(map[string]string, len(k.keys))
	for uid, key := range k.keys {
		out[uid] = key
	}

	return out
}

func stubPanel(t *testing.T, book *keyBook) *httptest.Server {
	t.Helper()

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			DeviceUID string `json:"device_uid"`
			PublicKey string `json:"public_key"`
		}
		_ = json.NewDecoder(r.Body).Decode(&body)

		type peer struct {
			DeviceUID string `json:"device_uid"`
			PublicKey string `json:"public_key"`
		}

		var peers []peer
		for uid, key := range book.snapshot() {
			if key != body.PublicKey {
				peers = append(peers, peer{DeviceUID: uid, PublicKey: key})
			}
		}

		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{
			"success": true,
			"meta":    map[string]any{},
			"data": map[string]any{
				"authorized": true,
				"device_uid": body.DeviceUID,
				"network_id": 1,
				"tenant_id":  7,
				"virtual_ip": "10.99.0.2",
				"peers":      peers,
			},
		})
	}))
	t.Cleanup(srv.Close)

	return srv
}

// testAgent is a socket that speaks the discovery protocol by hand.
type testAgent struct {
	uid     string
	pub     [32]byte
	priv    [32]byte
	sock    *net.UDPConn
	coord   netip.AddrPort
	coordPK [32]byte
}

func newTestAgent(t *testing.T, uid string, coord netip.AddrPort, coordPK [32]byte) *testAgent {
	t.Helper()

	pub, priv, err := box.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}

	sock, err := net.ListenUDP("udp", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = sock.Close() })

	return &testAgent{uid: uid, pub: *pub, priv: *priv, sock: sock, coord: coord, coordPK: coordPK}
}

func (a *testAgent) send(t *testing.T, kind disco.MessageType, body []byte) {
	t.Helper()

	sealed, err := disco.Seal(body, &a.coordPK, &a.priv)
	if err != nil {
		t.Fatal(err)
	}

	pkt := make([]byte, disco.HeaderLen, disco.HeaderLen+len(sealed))
	disco.WriteHeader(pkt, kind, a.pub)
	pkt = append(pkt, sealed...)

	if _, err := a.sock.WriteToUDPAddrPort(pkt, a.coord); err != nil {
		t.Fatal(err)
	}
}

func (a *testAgent) hello(t *testing.T) {
	t.Helper()

	hello := &disco.Hello{DeviceUID: a.uid, Token: "token"}

	body, err := hello.Encode()
	if err != nil {
		t.Fatal(err)
	}

	a.send(t, disco.TypeHello, body)
}

func (a *testAgent) ping(t *testing.T) { a.send(t, disco.TypePing, []byte{}) }

// awaitPeers reads until a Peers message arrives, or gives up.
func (a *testAgent) awaitPeers(t *testing.T, within time.Duration) (*disco.Peers, bool) {
	t.Helper()

	deadline := time.Now().Add(within)
	buf := make([]byte, disco.MaxPacket)

	for time.Now().Before(deadline) {
		_ = a.sock.SetReadDeadline(deadline)

		n, _, err := a.sock.ReadFromUDPAddrPort(buf)
		if err != nil {
			return nil, false
		}

		header, rest, err := disco.ParseHeader(buf[:n])
		if err != nil || header.Type != disco.TypePeers {
			continue
		}

		body, err := disco.Open(rest, &header.Sender, &a.priv)
		if err != nil {
			continue
		}

		peers, err := disco.DecodePeers(body)
		if err != nil {
			continue
		}

		return peers, true
	}

	return nil, false
}

// drain discards whatever is waiting, so a later read is not answering an
// earlier question.
func (a *testAgent) drain() {
	buf := make([]byte, disco.MaxPacket)

	for {
		_ = a.sock.SetReadDeadline(time.Now().Add(50 * time.Millisecond))
		if _, _, err := a.sock.ReadFromUDPAddrPort(buf); err != nil {
			return
		}
	}
}

func startCoordinator(t *testing.T, panelURL string) (*Server, [32]byte) {
	t.Helper()

	pub, priv, err := box.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}

	client, err := panelapi.New(panelURL, "shared-secret")
	if err != nil {
		t.Fatal(err)
	}

	srv, err := New(Options{
		Listen:      "127.0.0.1:0",
		PrivateKey:  *priv,
		Panel:       client,
		PresenceTTL: time.Minute,
		Logf:        func(string, ...any) {},
	})
	if err != nil {
		t.Fatal(err)
	}

	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)
	go func() { _ = srv.Run(ctx) }()

	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		if addr := srv.ListenAddr(); addr.IsValid() && addr.Port() != 0 {
			return srv, *pub
		}
		time.Sleep(5 * time.Millisecond)
	}

	t.Fatal("the coordinator did not bind within two seconds")

	return nil, [32]byte{}
}

// Defect 13, the half in the coordinator: peer lists were sent only in reply
// to a hello. A settled agent sends pings, not hellos, so its candidate
// addresses were frozen at whatever they were when it first announced. After
// any network change both ends kept punching at addresses that no longer
// existed, and a relayed pair could never find its way back to a direct path.
func TestPingIsAnsweredWithTheCurrentPeerList(t *testing.T) {
	book := newKeyBook()
	panel := stubPanel(t, book)
	srv, coordPK := startCoordinator(t, panel.URL)

	alice := newTestAgent(t, "dev_alice", srv.ListenAddr(), coordPK)
	bob := newTestAgent(t, "dev_bob", srv.ListenAddr(), coordPK)

	// The stub needs both keys before it can report them as peers.
	book.set("dev_alice", base64.StdEncoding.EncodeToString(alice.pub[:]))
	book.set("dev_bob", base64.StdEncoding.EncodeToString(bob.pub[:]))

	alice.hello(t)
	bob.hello(t)
	time.Sleep(200 * time.Millisecond)

	alice.drain()

	// A ping, exactly as a settled agent sends every keepalive.
	alice.ping(t)

	peers, ok := alice.awaitPeers(t, 2*time.Second)
	if !ok {
		t.Fatal("a ping was answered without a peer list; candidates would go stale forever")
	}

	if len(peers.Peers) != 1 || peers.Peers[0].PublicKey != bob.pub {
		t.Fatalf("the peer list did not name the other agent: %+v", peers.Peers)
	}
}

// And the other half: when a device's address changes, its peers must be told,
// or they are the ones left punching at a ghost.
func TestPeersAreToldWhenADeviceMoves(t *testing.T) {
	book := newKeyBook()
	panel := stubPanel(t, book)
	srv, coordPK := startCoordinator(t, panel.URL)

	alice := newTestAgent(t, "dev_alice", srv.ListenAddr(), coordPK)
	bob := newTestAgent(t, "dev_bob", srv.ListenAddr(), coordPK)

	book.set("dev_alice", base64.StdEncoding.EncodeToString(alice.pub[:]))
	book.set("dev_bob", base64.StdEncoding.EncodeToString(bob.pub[:]))

	alice.hello(t)
	bob.hello(t)
	time.Sleep(200 * time.Millisecond)

	bob.drain()

	// Alice reappears from a different socket, which is what a NAT rebind or a
	// change of network looks like from the coordinator's side.
	moved := newTestAgent(t, "dev_alice", srv.ListenAddr(), coordPK)
	moved.pub, moved.priv = alice.pub, alice.priv
	moved.ping(t)

	peers, ok := bob.awaitPeers(t, 2*time.Second)
	if !ok {
		t.Fatal("the peer was not told that the other device moved")
	}

	if len(peers.Peers) != 1 || peers.Peers[0].PublicKey != alice.pub {
		t.Fatalf("the update did not name the moved device: %+v", peers.Peers)
	}
}

// A ping from a key that never said hello must be ignored, or anyone could
// keep an entry alive in the registry.
func TestPingFromAnUnknownKeyIsIgnored(t *testing.T) {
	panel := stubPanel(t, newKeyBook())
	srv, coordPK := startCoordinator(t, panel.URL)

	stranger := newTestAgent(t, "dev_stranger", srv.ListenAddr(), coordPK)
	stranger.ping(t)

	if _, ok := stranger.awaitPeers(t, 500*time.Millisecond); ok {
		t.Fatal("the coordinator answered a ping from a device that never announced itself")
	}
}
