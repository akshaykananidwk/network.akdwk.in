package dnsd

import (
	"context"
	"encoding/binary"
	"fmt"
	"io"
	"net"
	"net/netip"
	"sync"
	"sync/atomic"
	"time"
)

// timeNow is a variable so a test can hold the clock still.
var timeNow = time.Now

const (
	tcpTimeout = 5 * time.Second
	// A TCP message may be larger than a UDP one, but not much larger for the
	// questions we answer. A bound here is what stops a single connection
	// asking us to allocate 64 KB per query.
	maxTCPMessage = 4096
)

// Server answers queries for one zone on a loopback address.
//
// Loopback, not the overlay address: a resolver reachable from the tunnel
// would be a resolver every peer in the network could query, and the names it
// holds are the map of a customer's site. Nothing outside this machine needs
// to ask it anything.
type Server struct {
	addr netip.AddrPort
	zone atomic.Pointer[Zone]
	logf func(string, ...any)

	mu   sync.Mutex
	conn *net.UDPConn
	tcp  *net.TCPListener

	stats counters
}

// Candidate addresses, in order.
//
// 127.0.0.53 and 127.0.0.54 are systemd-resolved's own — the stub listener and
// the extra stub — and neither is ever offered here, even when they are free.
// Two reasons, and the second is the one that bit:
//
//  1. Taking 127.0.0.53 would break the machine's own name resolution, which
//     is the one thing this feature promises not to do.
//  2. systemd-resolved refuses to be pointed at either of them:
//     "resolvectl dns <link> 127.0.0.54" answers "Invalid DNS server address",
//     because a link whose server is resolved's own address is a loop. They are
//     free to bind whenever the stub listener is turned off (DNSStubListener=no,
//     which is what everyone running dnsmasq or Pi-hole alongside it has), and
//     the agent would then take one, get refused by resolvectl, and fall back
//     to the hosts file without ever saying why.
//
// So the list starts above them. There is nothing to be gained from either.
var candidates = []string{
	"127.0.0.55",
	"127.0.0.56",
	"127.0.0.57",
	"127.0.0.58",
	"127.0.0.59",
}

// Port 53, always. A resolver on another port is one nothing can be pointed
// at without also being told the port, and neither systemd-resolved's
// per-interface configuration nor Windows' NRPT carries one.
const port = 53

// New binds a server on the first free candidate address.
func New(logf func(string, ...any)) (*Server, error) {
	if logf == nil {
		logf = func(string, ...any) {}
	}

	var lastErr error
	for _, candidate := range candidates {
		addr := netip.AddrPortFrom(netip.MustParseAddr(candidate), port)

		conn, err := net.ListenUDP("udp4", net.UDPAddrFromAddrPort(addr))
		if err != nil {
			lastErr = err
			continue
		}

		// TCP as well, because a stub that gets a truncated answer retries
		// over TCP and would otherwise hang waiting for a connection nothing
		// accepts. Ours never truncates, but the stub does not know that until
		// it has asked.
		listener, err := net.ListenTCP("tcp4", net.TCPAddrFromAddrPort(addr))
		if err != nil {
			_ = conn.Close()
			lastErr = err
			continue
		}

		s := &Server{addr: addr, conn: conn, tcp: listener, logf: logf}
		s.zone.Store(NewZone(""))

		return s, nil
	}

	return nil, fmt.Errorf("dns: no free loopback address to listen on: %w", lastErr)
}

// Addr is where this server is listening, for the OS configuration that points
// the zone at it and for the status file.
func (s *Server) Addr() netip.AddrPort { return s.addr }

// SetZone replaces the records. Safe while queries are in flight.
func (s *Server) SetZone(z *Zone) {
	if z == nil {
		z = NewZone("")
	}

	s.zone.Store(z)
}

// Zone is what the server currently answers for.
func (s *Server) Zone() *Zone { return s.zone.Load() }

// Stats reports what the server has done, which is what a drill reads to prove
// it did nothing else.
func (s *Server) Stats() (answered, nxdomain, refused, malformed uint64) {
	return s.stats.answered.Load(), s.stats.nxdomain.Load(),
		s.stats.refused.Load(), s.stats.malformed.Load()
}

// Serve answers queries until the context is cancelled.
func (s *Server) Serve(ctx context.Context) {
	go s.serveUDP(ctx)
	go s.serveTCP(ctx)

	<-ctx.Done()
	s.Close()
}

// Close stops listening. Safe to call more than once.
func (s *Server) Close() {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.conn != nil {
		_ = s.conn.Close()
		s.conn = nil
	}
	if s.tcp != nil {
		_ = s.tcp.Close()
		s.tcp = nil
	}
}

func (s *Server) serveUDP(ctx context.Context) {
	buf := make([]byte, maxMessage)

	for {
		s.mu.Lock()
		conn := s.conn
		s.mu.Unlock()
		if conn == nil {
			return
		}

		n, from, err := conn.ReadFromUDP(buf)
		if err != nil {
			if ctx.Err() != nil {
				return
			}
			continue
		}

		if out := s.handle(buf[:n]); out != nil {
			_, _ = conn.WriteToUDP(out, from)
		}
	}
}

func (s *Server) serveTCP(ctx context.Context) {
	for {
		s.mu.Lock()
		listener := s.tcp
		s.mu.Unlock()
		if listener == nil {
			return
		}

		conn, err := listener.Accept()
		if err != nil {
			if ctx.Err() != nil {
				return
			}
			continue
		}

		go s.handleTCP(conn)
	}
}

// handle turns one query into one reply, and counts what it did.
func (s *Server) handle(msg []byte) []byte {
	q, err := parse(msg)
	if err != nil {
		s.stats.malformed.Add(1)

		return refuseMalformed(msg)
	}

	out := s.zone.Load().answer(q)

	// The counters are the drill's evidence, so they are read from the reply
	// rather than recomputed — anything else could agree with what the code
	// was meant to do rather than with what it did.
	if len(out) >= 4 {
		switch out[3] & 0x0F {
		case rcodeRefused:
			s.stats.refused.Add(1)
		case rcodeNXDomain:
			s.stats.nxdomain.Add(1)
		default:
			s.stats.answered.Add(1)
		}
	}

	return out
}

// handleTCP serves one connection. DNS over TCP frames each message with a
// two-byte length, which is the only difference from UDP here.
func (s *Server) handleTCP(conn net.Conn) {
	defer func() { _ = conn.Close() }()

	// A generous deadline on a loopback connection: anything slower than this
	// is not a stub resolver waiting for an answer, it is a socket somebody
	// opened and forgot.
	_ = conn.SetDeadline(timeNow().Add(tcpTimeout))

	var header [2]byte
	if _, err := io.ReadFull(conn, header[:]); err != nil {
		return
	}

	length := int(binary.BigEndian.Uint16(header[:]))
	if length == 0 || length > maxTCPMessage {
		return
	}

	msg := make([]byte, length)
	if _, err := io.ReadFull(conn, msg); err != nil {
		return
	}

	out := s.handle(msg)
	if out == nil {
		return
	}

	var prefix [2]byte
	binary.BigEndian.PutUint16(prefix[:], uint16(len(out)))
	_, _ = conn.Write(append(prefix[:], out...))
}
