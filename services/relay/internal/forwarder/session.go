// Package forwarder is the relay's data path.
//
// The relay forwards already-encrypted WireGuard packets between two peers
// that hold each other's keys. It is never a party to their handshake and
// never holds a WireGuard private key, so a compromised relay yields traffic
// volumes, timing and addresses — never plaintext. That property is the reason
// customers can accept a relay at all, and nothing in this package may
// compromise it.
package forwarder

import (
	"fmt"
	"net"
	"net/netip"
	"sync"
	"sync/atomic"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
)

// side is one end of a relayed pair.
//
// Each side gets its own UDP socket, and therefore its own port. That is not
// an implementation detail: the agent points WireGuard at that port, and every
// packet arriving on it is unambiguously from that side of that pair. Sharing
// one port would leave the relay guessing from source addresses, which breaks
// the moment one device relays to two peers at once.
type side struct {
	key  [32]byte
	conn *net.UDPConn
	port uint16

	// addr is where this side's tunnel traffic must be sent.
	//
	// It starts as the address the bind arrived from, which is a guess: behind
	// a symmetric NAT the mapping used to reach the control port is not the
	// mapping used to reach this data port. Once a packet arrives on the data
	// socket, that source is authoritative and replaces it.
	addr atomic.Pointer[netip.AddrPort]
	// learned records that addr came from the data socket rather than from a
	// bind, so a later re-bind cannot overwrite it with the control-flow
	// address and break the path.
	learned atomic.Bool

	rx atomic.Int64
	tx atomic.Int64
}

// Session is one relayed peer pair.
type Session struct {
	PairID   [32]byte
	TenantID uint64

	mu    sync.Mutex
	sides map[[32]byte]*side

	lastSeen atomic.Int64
}

func (s *Session) touch() { s.lastSeen.Store(time.Now().Unix()) }

// Idle reports how long since any packet crossed this session.
func (s *Session) Idle() time.Duration {
	return time.Since(time.Unix(s.lastSeen.Load(), 0))
}

// Bytes is the total carried, for accounting.
func (s *Session) Bytes() (rx, tx int64) {
	s.mu.Lock()
	defer s.mu.Unlock()

	for _, sd := range s.sides {
		rx += sd.rx.Load()
		tx += sd.tx.Load()
	}

	return rx, tx
}

// Close releases both sockets.
func (s *Session) Close() {
	s.mu.Lock()
	defer s.mu.Unlock()

	for _, sd := range s.sides {
		_ = sd.conn.Close()
	}
	s.sides = nil
}

// bind attaches one side of the pair, allocating its socket on first sight.
//
// Re-binding an existing side updates its address rather than allocating
// again, so an agent whose NAT mapping moved keeps the same relay port and the
// WireGuard session it has already established.
func (s *Session) bind(self [32]byte, from netip.AddrPort, listen string, logf func(string, ...any)) (*side, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.sides == nil {
		return nil, fmt.Errorf("session is closed")
	}

	if existing, ok := s.sides[self]; ok {
		// Only before the data socket has taught us better. A re-bind arrives
		// on the control flow, and under a symmetric NAT that is a different
		// mapping — storing it here would break forwarding until the next
		// data packet re-learned it, every single time the agent re-bound.
		if !existing.learned.Load() {
			existing.addr.Store(&from)
		}
		s.touch()

		return existing, nil
	}

	if len(s.sides) >= 2 {
		// A pair has exactly two ends. A third key presenting a valid ticket
		// for this pair should be impossible, since the ticket names both
		// ends; refusing is cheap insurance.
		return nil, fmt.Errorf("pair already has two ends")
	}

	conn, err := net.ListenUDP("udp", &net.UDPAddr{IP: net.ParseIP(listen)})
	if err != nil {
		return nil, fmt.Errorf("allocating a port: %w", err)
	}

	local := conn.LocalAddr().(*net.UDPAddr)

	sd := &side{key: self, conn: conn, port: uint16(local.Port)}
	sd.addr.Store(&from)
	s.sides[self] = sd
	s.touch()

	go s.pump(sd, logf)

	return sd, nil
}

// other returns the opposite end, or nil when it has not bound yet.
func (s *Session) other(self [32]byte) *side {
	s.mu.Lock()
	defer s.mu.Unlock()

	for key, sd := range s.sides {
		if key != self {
			return sd
		}
	}

	return nil
}

// pump forwards everything arriving on one side's socket to the other side.
//
// It does not inspect the payload beyond its length. There is nothing here
// that could decrypt it and nothing that tries.
func (s *Session) pump(sd *side, logf func(string, ...any)) {
	buf := make([]byte, 1600)

	for {
		n, from, err := sd.conn.ReadFromUDPAddrPort(buf)
		if err != nil {
			return // socket closed; the session is going away
		}

		// The host is trusted only as far as the bind established it, but the
		// *port* must be learned here rather than taken from the bind.
		//
		// Behind a symmetric NAT every destination gets a different external
		// port, so the mapping the agent used to reach the control port is not
		// the mapping it uses to reach this data port — and a reply sent to
		// the control-port mapping is dropped by the NAT. The address that
		// works is the one this packet just arrived from, because sending back
		// along the same flow is the one thing a symmetric NAT always allows.
		known := sd.addr.Load()
		if known == nil || from.Addr().Unmap() != known.Addr().Unmap() {
			continue
		}

		source := netip.AddrPortFrom(from.Addr().Unmap(), from.Port())
		if source != *known {
			sd.addr.Store(&source)
		}
		sd.learned.Store(true)

		peer := s.other(sd.key)
		if peer == nil {
			// The other end has not bound yet. Dropping is correct: there is
			// nowhere to send it, and buffering would make the relay a
			// memory-shaped denial of service.
			continue
		}

		target := peer.addr.Load()
		if target == nil {
			continue
		}

		if _, err := peer.conn.WriteToUDPAddrPort(buf[:n], *target); err != nil {
			logf("forwarding to %s failed: %v", target, err)

			continue
		}

		sd.rx.Add(int64(n))
		peer.tx.Add(int64(n))
		s.touch()
	}
}

// PairKeyOf is a small helper so callers do not import disco for one function.
func PairKeyOf(a, b [32]byte) [32]byte { return disco.PairID(a, b) }
