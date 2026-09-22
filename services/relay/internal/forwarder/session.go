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
	"math/rand/v2"
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

	// tun is set when this end reached us over the HTTPS fallback instead of
	// over UDP. Such a side has no socket and no port of its own: everything
	// for it arrives on, and leaves by, one TLS connection it opened outwards.
	//
	// A pointer swapped atomically rather than a field under the mutex,
	// because pump() consults it for every packet it forwards and must not
	// contend with a bind for the session lock to do so.
	tun atomic.Pointer[tunnel]

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

// tunnel is the HTTPS path to one side.
//
// send must be safe to call from several goroutines; the websocket connection
// underneath it is, and the relay relies on that — the peer's pump goroutine
// and this side's own keepalives both write.
type tunnel struct {
	send func(peer [32]byte, payload []byte) error
}

// deliver hands one packet to this side by whichever path it is reachable on.
//
// The tunnel wins when both exist. A side that had to open an outbound TLS
// connection did so because UDP did not reach it, and the stored UDP address
// is at best stale; preferring it would send every packet into the same hole
// that caused the fallback.
func (sd *side) deliver(from [32]byte, p []byte) error {
	if t := sd.tun.Load(); t != nil {
		return t.send(from, p)
	}

	if sd.conn == nil {
		return fmt.Errorf("that end has no path yet")
	}

	target := sd.addr.Load()
	if target == nil {
		return fmt.Errorf("that end has no address yet")
	}

	_, err := sd.conn.WriteToUDPAddrPort(p, *target)

	return err
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
		if sd.conn != nil {
			_ = sd.conn.Close()
		}
	}
	s.sides = nil
}

// bind attaches one side of the pair, allocating its socket on first sight.
//
// Re-binding an existing side updates its address rather than allocating
// again, so an agent whose NAT mapping moved keeps the same relay port and the
// WireGuard session it has already established.
func (s *Session) bind(self [32]byte, from netip.AddrPort, ports dataPorts, logf func(string, ...any)) (*side, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.sides == nil {
		return nil, fmt.Errorf("session is closed")
	}

	if existing, ok := s.sides[self]; ok {
		known := existing.addr.Load()
		moved := known == nil || known.Addr().Unmap() != from.Addr().Unmap()

		switch {
		case moved:
			// A different IP is a device that has moved — a laptop rebooted
			// onto another mobile address, a router that got a new lease. The
			// bind is authoritative here because the ticket that carried it is
			// signed by the coordinator and names both ends of this pair, so
			// nobody who could not already get a ticket can redirect a stream.
			//
			// This is defect 24, and it was fatal rather than slow. The stored
			// address was pinned the moment the data socket learned it, and
			// pump() drops anything arriving from a different host — so after
			// a reboot onto a new address the relay silently discarded
			// everything the returning device sent, and kept forwarding the
			// other end's traffic to an address that was no longer anybody's.
			// Both machines looked healthy, both were bound, and no packet
			// could cross. Nothing in any log said so.
			existing.addr.Store(&from)
			existing.learned.Store(false)

			if logf != nil {
				logf("pair %x… : an end moved to %s; forwarding follows it", s.PairID[:6], from)
			}
		case !existing.learned.Load():
			// Same host, and the data socket has not corrected us yet.
			existing.addr.Store(&from)
		default:
			// Same host, port already learned from a data packet. Left alone:
			// under a symmetric NAT the control flow is a different mapping
			// from the data flow, and storing it would break forwarding until
			// the next data packet re-learned it — every time the agent
			// re-bound, which is every few seconds.
		}

		s.touch()

		if existing.conn == nil {
			// It reached us over the fallback first and is now binding over
			// UDP, which means UDP started working again. Give it a socket so
			// the other end can be forwarded to it the cheap way; the tunnel
			// stays until a packet actually arrives on that socket, because
			// until one does this is only a claim.
			if err := s.openSocket(existing, ports, logf); err != nil {
				return nil, err
			}
		}

		return existing, nil
	}

	if len(s.sides) >= 2 {
		// A pair has exactly two ends. A third key presenting a valid ticket
		// for this pair should be impossible, since the ticket names both
		// ends; refusing is cheap insurance.
		return nil, fmt.Errorf("pair already has two ends")
	}

	sd := &side{key: self}
	sd.addr.Store(&from)

	if err := s.openSocket(sd, ports, logf); err != nil {
		return nil, err
	}

	s.sides[self] = sd
	s.touch()

	return sd, nil
}

// openSocket gives a side its own UDP port and starts forwarding from it.
// Called with the session lock held.
func (s *Session) openSocket(sd *side, ports dataPorts, logf func(string, ...any)) error {
	conn, err := ports.listen()
	if err != nil {
		return err
	}

	sd.conn = conn
	sd.port = uint16(conn.LocalAddr().(*net.UDPAddr).Port)

	go s.pump(sd, logf)

	return nil
}

// bindTunnel attaches a side that reached us over the HTTPS fallback.
//
// It allocates no socket and no port: this end is not addressable from
// outside, which is the whole reason it is here. The other end may still be an
// ordinary UDP peer and never learns the difference.
func (s *Session) bindTunnel(self [32]byte, t *tunnel, logf func(string, ...any)) (*side, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.sides == nil {
		return nil, fmt.Errorf("session is closed")
	}

	if existing, ok := s.sides[self]; ok {
		existing.tun.Store(t)
		s.touch()

		return existing, nil
	}

	if len(s.sides) >= 2 {
		return nil, fmt.Errorf("pair already has two ends")
	}

	sd := &side{key: self}
	sd.tun.Store(t)
	s.sides[self] = sd
	s.touch()

	if logf != nil {
		logf("pair %x… : one end is on the HTTPS fallback", s.PairID[:6])
	}

	return sd, nil
}

// dropTunnel detaches a fallback path that has gone away, but only if it is
// still the current one. Without that check a connection closing after the
// agent has already reconnected would tear down the new path with the old.
func (s *Session) dropTunnel(self [32]byte, t *tunnel) {
	s.mu.Lock()
	defer s.mu.Unlock()

	if sd, ok := s.sides[self]; ok {
		sd.tun.CompareAndSwap(t, nil)
	}
}

// fromTunnel forwards one packet that arrived over the fallback.
//
// The counterpart of pump() for a side with no socket to pump.
func (s *Session) fromTunnel(self [32]byte, payload []byte) error {
	s.mu.Lock()
	sd := s.sides[self]
	var peer *side
	for key, other := range s.sides {
		if key != self {
			peer = other
		}
	}
	s.mu.Unlock()

	if sd == nil {
		return fmt.Errorf("this end has not bound")
	}
	if peer == nil {
		// The other end has not bound yet. Dropping is right for the same
		// reason it is right in pump(): there is nowhere to put it.
		return nil
	}

	if err := peer.deliver(self, payload); err != nil {
		return err
	}

	sd.rx.Add(int64(len(payload)))
	peer.tx.Add(int64(len(payload)))
	s.touch()

	return nil
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

		// Tunnel traffic arriving here means this end is reachable over UDP
		// after all, so stop paying for TLS and a proxy hop. Dropping the
		// tunnel is safe: the agent keeps the connection open and will re-bind
		// over it the moment UDP stops working again.
		if sd.tun.Swap(nil) != nil {
			logf("pair %x… : an end came back to UDP; leaving the fallback", s.PairID[:6])
		}

		peer := s.other(sd.key)
		if peer == nil {
			// The other end has not bound yet. Dropping is correct: there is
			// nowhere to send it, and buffering would make the relay a
			// memory-shaped denial of service.
			continue
		}

		if err := peer.deliver(sd.key, buf[:n]); err != nil {
			logf("forwarding across pair %x… failed: %v", s.PairID[:6], err)

			continue
		}

		sd.rx.Add(int64(n))
		peer.tx.Add(int64(n))
		s.touch()
	}
}

// PairKeyOf is a small helper so callers do not import disco for one function.
func PairKeyOf(a, b [32]byte) [32]byte { return disco.PairID(a, b) }

// dataPorts is where a session's own socket may bind.
type dataPorts struct {
	ip       string
	from, to int
}

// listen opens a data socket inside the configured range.
//
// With no range it asks the kernel, which is what this always did. With one it
// walks the range from a random start rather than from the bottom: starting at
// the bottom every time would make the first few ports carry every session on
// a busy relay, and a restart would reuse exactly the ports whose old
// conversations are still being retried.
func (p dataPorts) listen() (*net.UDPConn, error) {
	ip := net.ParseIP(p.ip)

	if p.from <= 0 || p.to < p.from {
		conn, err := net.ListenUDP("udp", &net.UDPAddr{IP: ip})
		if err != nil {
			return nil, fmt.Errorf("allocating a port: %w", err)
		}

		return conn, nil
	}

	width := p.to - p.from + 1
	start := rand.IntN(width)

	for i := 0; i < width; i++ {
		port := p.from + (start+i)%width

		conn, err := net.ListenUDP("udp", &net.UDPAddr{IP: ip, Port: port})
		if err == nil {
			return conn, nil
		}
	}

	// Every port taken means the relay is at capacity, which is a different
	// problem from a bind failing, and an operator needs to be told which.
	return nil, fmt.Errorf(
		"no free port in the configured data range %d-%d; this relay is carrying as many "+
			"sessions as it has ports", p.from, p.to)
}
