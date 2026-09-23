package server

import (
	"encoding/base64"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"
)

// A panel that cannot answer must not stop a device it has already approved
// from being served, and that has to hold for a device that has to announce
// itself again — after its presence expired, or when the coordinator had to
// forget it — not only for one that keeps pinging.
//
// 1.9.7-dev.14 wrote the memory this needs and never created it: New left the
// field nil, every method on it is nil-safe, and so the fallback was never
// used. Nothing tested it. This does.
func TestADeviceThatReannouncesDuringAPanelOutageIsServedFromMemory(t *testing.T) {
	book := newKeyBook()
	real := stubPanel(t, book)

	var down atomic.Bool
	panel := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if down.Load() {
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(http.StatusServiceUnavailable)
			_ = json.NewEncoder(w).Encode(map[string]any{"success": false, "message": "maintenance"})

			return
		}
		proxy, err := http.NewRequestWithContext(r.Context(), r.Method, real.URL+r.URL.Path, r.Body)
		if err != nil {
			w.WriteHeader(http.StatusBadGateway)

			return
		}
		proxy.Header = r.Header.Clone()
		resp, err := http.DefaultClient.Do(proxy)
		if err != nil {
			w.WriteHeader(http.StatusBadGateway)

			return
		}
		defer resp.Body.Close()
		w.Header().Set("Content-Type", resp.Header.Get("Content-Type"))
		w.WriteHeader(resp.StatusCode)
		var body any
		_ = json.NewDecoder(resp.Body).Decode(&body)
		_ = json.NewEncoder(w).Encode(body)
	}))
	t.Cleanup(panel.Close)

	srv, coordPK := startCoordinator(t, panel.URL)
	coord := srv.ListenAddr()

	known := newTestAgent(t, "known", coord, coordPK)
	friend := newTestAgent(t, "friend", coord, coordPK)
	stranger := newTestAgent(t, "stranger", coord, coordPK)

	// The stub needs both keys before it can report them as each other's peers.
	book.set("known", base64.StdEncoding.EncodeToString(known.pub[:]))
	book.set("friend", base64.StdEncoding.EncodeToString(friend.pub[:]))

	// Both approved while the panel is up.
	friend.hello(t)
	time.Sleep(100 * time.Millisecond)
	known.hello(t)
	if _, ok := known.awaitPeers(t, 2*time.Second); !ok {
		t.Fatalf("precondition: the device was not answered while the panel was up")
	}

	// The panel goes away, and the coordinator no longer has the device in
	// its registry — its presence expired, or it was restarted into memory.
	down.Store(true)
	srv.reg.Forget(known.pub)
	known.drain()

	known.hello(t)
	peers, ok := known.awaitPeers(t, 2*time.Second)
	if !ok {
		t.Fatalf("a device the panel had approved was not answered while the panel was down")
	}
	if len(peers.Peers) != 1 || peers.Peers[0].PublicKey != friend.pub {
		t.Fatalf("answered from memory, but without its peer: %+v", peers.Peers)
	}

	// Memory is for devices the panel really approved, and nothing else.
	stranger.hello(t)
	if _, ok := stranger.awaitPeers(t, 500*time.Millisecond); ok {
		t.Fatalf("a device the panel never answered for was served during the outage")
	}
}

// Old answers are dropped by the housekeeping sweep, so the memory holds the
// devices seen in the last day and not every device ever seen.
func TestRememberedAnswersExpire(t *testing.T) {
	l := newLastKnown()
	key := [32]byte{9}
	l.entries[key] = lastKnownEntry{at: time.Now().Add(-lastKnownTTL - time.Minute)}

	l.sweep()

	if _, ok := l.recall(key); ok {
		t.Fatalf("an answer older than %s was still recalled after a sweep", lastKnownTTL)
	}
	if len(l.entries) != 0 {
		t.Fatalf("the sweep left %d expired entries", len(l.entries))
	}
}
