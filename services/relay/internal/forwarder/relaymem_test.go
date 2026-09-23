package forwarder

import (
	"crypto/rand"
	"fmt"
	"net/netip"
	"runtime"
	"testing"
)

// What a relay costs per session, measured rather than guessed.
//
// DEPLOY.md has to say what size server to buy, and "a few hundred megabytes
// should do" is not a number anybody can act on. A session is two UDP sockets,
// two goroutines and a 1600-byte buffer each; the kernel's socket buffers are
// the larger half and do not show up in Go's heap, so this measures what the
// process holds and the deployment note says the rest out loud.
func TestMemoryPerSession(t *testing.T) {
	const sessions = 2000

	ports := dataPorts{}

	runtime.GC()
	var before runtime.MemStats
	runtime.ReadMemStats(&before)

	from := netip.MustParseAddrPort("203.0.113.9:41000")
	kept := make([]*Session, 0, sessions)

	for i := 0; i < sessions; i++ {
		var pair, a, b [32]byte
		_, _ = rand.Read(pair[:])
		_, _ = rand.Read(a[:])
		_, _ = rand.Read(b[:])

		s := &Session{PairID: pair, sides: make(map[[32]byte]*side)}
		if _, err := s.bind(a, from, ports, func(string, ...any) {}); err != nil {
			t.Skipf("could not open %d sessions on this machine: %v", sessions, err)
		}
		if _, err := s.bind(b, from, ports, func(string, ...any) {}); err != nil {
			t.Skipf("could not open %d sessions on this machine: %v", sessions, err)
		}
		kept = append(kept, s)
	}

	runtime.GC()
	var after runtime.MemStats
	runtime.ReadMemStats(&after)

	total := after.HeapAlloc - before.HeapAlloc
	perSession := total / sessions
	fmt.Printf("  %d sessions: %d KB in the Go heap, %d bytes per session\n",
		sessions, total/1024, perSession)

	// DEPLOY.md tells somebody what size server to buy on the strength of this
	// number. A change that made a session ten times more expensive would
	// silently make that advice wrong, and the first sign would be a relay
	// running out of memory at a customer's expense.
	if perSession > 8*1024 {
		t.Fatalf("a session costs %d bytes in the heap; DEPLOY.md says 2 KB", perSession)
	}

	runtime.KeepAlive(kept)
}
