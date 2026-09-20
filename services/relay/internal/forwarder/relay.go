package forwarder

import (
	"context"
	"fmt"
	"net"
	"net/netip"
	"sync"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
)

// Options configures a Relay.
type Options struct {
	// Control is the UDP address agents send their bind to, e.g. ":9000".
	Control string
	// ListenIP is the address data sockets bind to. Empty means all.
	ListenIP string
	// Secret is shared with the coordinator and verifies every ticket.
	Secret []byte
	// IdleTimeout closes a session that has carried nothing for this long.
	IdleTimeout time.Duration
	// Logf receives log lines.
	Logf func(string, ...any)
	// OnUsage is called periodically with per-tenant byte totals, so the
	// caller can report them for billing without this package knowing what
	// billing is.
	OnUsage func(map[uint64]Usage)
}

// Usage is what one tenant's relayed traffic cost.
type Usage struct {
	Bytes    int64
	Sessions int
}

// Relay forwards traffic for authorised peer pairs.
type Relay struct {
	opts    Options
	control *net.UDPConn

	mu       sync.Mutex
	sessions map[[32]byte]*Session
}

// New builds a Relay.
func New(opts Options) (*Relay, error) {
	if opts.Logf == nil {
		return nil, fmt.Errorf("a logger is required")
	}
	if len(opts.Secret) == 0 {
		// Without it every ticket would verify, and the relay would forward
		// for anyone who asked.
		return nil, fmt.Errorf("a shared secret is required")
	}
	if opts.IdleTimeout == 0 {
		opts.IdleTimeout = 5 * time.Minute
	}

	return &Relay{opts: opts, sessions: make(map[[32]byte]*Session)}, nil
}

// Run serves until the context is cancelled.
func (r *Relay) Run(ctx context.Context) error {
	addr, err := net.ResolveUDPAddr("udp", r.opts.Control)
	if err != nil {
		return fmt.Errorf("resolving %s: %w", r.opts.Control, err)
	}

	conn, err := net.ListenUDP("udp", addr)
	if err != nil {
		return fmt.Errorf("binding %s: %w", r.opts.Control, err)
	}
	r.control = conn

	r.opts.Logf("relay control on %s", conn.LocalAddr())

	go r.housekeeping(ctx)

	go func() {
		<-ctx.Done()
		_ = conn.Close()
	}()

	buf := make([]byte, disco.MaxPacket)

	for {
		n, from, err := conn.ReadFromUDPAddrPort(buf)
		if err != nil {
			if ctx.Err() != nil {
				r.closeAll()
				r.opts.Logf("relay stopped")

				return nil
			}

			return fmt.Errorf("reading control: %w", err)
		}

		pkt := make([]byte, n)
		copy(pkt, buf[:n])

		r.handleBind(pkt, netip.AddrPortFrom(from.Addr().Unmap(), from.Port()))
	}
}

// handleBind authorises one side of a pair and tells it where to send.
func (r *Relay) handleBind(pkt []byte, from netip.AddrPort) {
	header, rest, err := disco.ParseHeader(pkt)
	if err != nil || header.Type != disco.TypeRelayBind {
		return
	}

	ticket, err := disco.DecodeTicket(rest)
	if err != nil {
		return
	}

	// Everything the relay trusts comes from here. The ticket is minted by the
	// coordinator, names both ends of exactly one pair, and expires.
	if err := ticket.Verify(r.opts.Secret, time.Now()); err != nil {
		r.opts.Logf("refused a bind from %s: %v", from, err)

		return
	}

	// The key in the packet header must be the key the ticket was issued to.
	// Without this check, anyone who obtained a ticket could bind as the other
	// end of the pair.
	if header.Sender != ticket.Self {
		r.opts.Logf("refused a bind from %s: sender does not match the ticket", from)

		return
	}

	session := r.sessionFor(ticket)

	sd, err := session.bind(ticket.Self, from, r.opts.ListenIP, r.opts.Logf)
	if err != nil {
		r.opts.Logf("bind failed for %s: %v", from, err)

		return
	}

	pair := ticket.Pair()
	r.opts.Logf("bound %s to pair %x… on port %d", from, pair[:6], sd.port)

	r.sendBindAck(from, sd.port)
}

func (r *Relay) sendBindAck(to netip.AddrPort, port uint16) {
	pkt := make([]byte, disco.HeaderLen)
	// The relay has no key of its own in this exchange; the header's sender
	// field is unused here and left zero.
	disco.WriteHeader(pkt, disco.TypeRelayBindAck, [32]byte{})
	pkt = disco.AppendUint16(pkt, port)

	if _, err := r.control.WriteToUDPAddrPort(pkt, to); err != nil {
		r.opts.Logf("sending bind ack to %s failed: %v", to, err)
	}
}

func (r *Relay) sessionFor(ticket *disco.Ticket) *Session {
	pair := ticket.Pair()

	r.mu.Lock()
	defer r.mu.Unlock()

	if existing, ok := r.sessions[pair]; ok {
		return existing
	}

	session := &Session{
		PairID:   pair,
		TenantID: ticket.TenantID,
		sides:    make(map[[32]byte]*side, 2),
	}
	session.touch()
	r.sessions[pair] = session

	return session
}

// housekeeping expires idle sessions and reports usage.
func (r *Relay) housekeeping(ctx context.Context) {
	ticker := time.NewTicker(30 * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			r.expire()
			r.reportUsage()
		}
	}
}

func (r *Relay) expire() {
	r.mu.Lock()
	var closing []*Session

	for pair, session := range r.sessions {
		if session.Idle() > r.opts.IdleTimeout {
			closing = append(closing, session)
			delete(r.sessions, pair)
		}
	}
	r.mu.Unlock()

	for _, session := range closing {
		session.Close()
	}

	if len(closing) > 0 {
		r.opts.Logf("closed %d idle session(s); %d remain", len(closing), r.Sessions())
	}
}

func (r *Relay) reportUsage() {
	if r.opts.OnUsage == nil {
		return
	}

	usage := make(map[uint64]Usage)

	r.mu.Lock()
	for _, session := range r.sessions {
		rx, tx := session.Bytes()
		entry := usage[session.TenantID]
		entry.Bytes += rx + tx
		entry.Sessions++
		usage[session.TenantID] = entry
	}
	r.mu.Unlock()

	if len(usage) > 0 {
		r.opts.OnUsage(usage)
	}
}

// Sessions is the number of pairs currently relayed.
func (r *Relay) Sessions() int {
	r.mu.Lock()
	defer r.mu.Unlock()

	return len(r.sessions)
}

func (r *Relay) closeAll() {
	r.mu.Lock()
	sessions := make([]*Session, 0, len(r.sessions))
	for _, s := range r.sessions {
		sessions = append(sessions, s)
	}
	r.sessions = make(map[[32]byte]*Session)
	r.mu.Unlock()

	for _, s := range sessions {
		s.Close()
	}
}
