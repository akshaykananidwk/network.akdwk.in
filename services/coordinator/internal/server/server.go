// Package server is the coordinator's UDP rendezvous service.
//
// Its whole job is to tell two agents where to find each other. It never sees
// their traffic: once they have each other's addresses they talk directly, and
// the coordinator can be switched off without any established tunnel noticing
// (R6). That is the difference between a coordinator and a relay.
package server

import (
	"context"
	"encoding/base64"
	"fmt"
	"net"
	"net/netip"
	"os"
	"strconv"
	"sync"
	"sync/atomic"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/coordinator/internal/panelapi"
	"github.com/akshaykananidwk/network.akdwk.in/services/coordinator/internal/registry"
	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
	"github.com/akshaykananidwk/network.akdwk.in/services/shared/hub"
)

// Options configures a Server.
type Options struct {
	// Listen is the UDP address to bind, e.g. ":8443".
	Listen string
	// PrivateKey is the coordinator's X25519 private key. Agents seal to its
	// public half, which the panel distributes in the agent configuration.
	PrivateKey [32]byte
	// Panel answers the questions the coordinator may not answer itself.
	Panel *panelapi.Client
	// PresenceTTL is how long a device stays introducible without contact.
	PresenceTTL time.Duration
	// Relays the coordinator may hand out when hole punching fails.
	Relays []*RelayTarget
	// Logf receives log lines.
	Logf func(string, ...any)
}

// Server is a running coordinator.
type Server struct {
	opts      Options
	publicKey [32]byte
	conn      *net.UDPConn
	reg       *registry.Registry
	health    *relayHealth
	usage     *usageLedger

	// ticketLifeOverride shortens relay tickets, for the drill that proves a
	// pair survives its ticket expiring. Zero means the production lifetime.
	ticketLifeOverride time.Duration

	// lastKnown keeps the panel's most recent answer about each device, so a
	// panel that cannot answer does not stop traffic. See lastknown.go.
	lastKnown *lastKnown

	verifying *reverifier
	// talking notices a device whose hellos keep coming with no ping between
	// them, which is the shape of an agent that never hears our replies. See
	// unanswered.go.
	talking *talkers

	mu      sync.Mutex
	pending map[string]panelapi.EndpointReport

	// control bounds the goroutines handling control datagrams. It used to
	// be one goroutine per datagram with no limit, so a flood of junk grew
	// memory without bound; now a datagram that finds no free slot is
	// dropped and counted, as a full socket buffer would drop it.
	control        chan struct{}
	controlDropped atomic.Uint64
	// hubDropped counts hub frames this coordinator does not forward yet.
	hubDropped atomic.Uint64
}

// maxControlInFlight is how many control datagrams may be handled at once.
// A Hello waits on the panel, so this is also how many verifications can be
// outstanding before new ones are shed.
const maxControlInFlight = 1024

// New builds a Server without binding anything.
func New(opts Options) (*Server, error) {
	if opts.Logf == nil {
		return nil, fmt.Errorf("a logger is required")
	}
	if opts.Panel == nil {
		return nil, fmt.Errorf("a panel client is required")
	}
	if opts.PresenceTTL == 0 {
		opts.PresenceTTL = 2 * time.Minute
	}

	pub, err := publicOf(opts.PrivateKey)
	if err != nil {
		return nil, err
	}

	// Shortened only when asked, and only by the environment the lab sets.
	// The drill that proves a pair survives its ticket expiring cannot wait
	// ten minutes per run, and a production coordinator never sets this.
	var ticketOverride time.Duration
	if raw := os.Getenv("AKCONNECT_TICKET_SECONDS"); raw != "" {
		if seconds, err := strconv.Atoi(raw); err == nil && seconds > 0 {
			ticketOverride = time.Duration(seconds) * time.Second
			opts.Logf("relay tickets shortened to %s by AKCONNECT_TICKET_SECONDS", ticketOverride)
		}
	}

	return &Server{
		opts:               opts,
		publicKey:          pub,
		ticketLifeOverride: ticketOverride,
		reg:                registry.New(opts.PresenceTTL),
		health:             newRelayHealth(),
		usage:              newUsageLedger(),
		// Made here. It was declared, and every method on it is nil-safe —
		// so when nothing made it, remember did nothing, recall always said
		// "nothing is remembered", and the fallback 1.9.7-dev.14 described
		// (a panel that cannot answer does not stop traffic) never ran: a
		// device that had to announce itself again during a panel outage or a
		// deploy was simply not answered.
		lastKnown: newLastKnown(),
		verifying: newReverifier(),
		talking:   newTalkers(),
		pending:   make(map[string]panelapi.EndpointReport),
	}, nil
}

// PublicKey is what agents seal to.
func (s *Server) PublicKey() [32]byte { return s.publicKey }

// ListenAddr is the address actually bound, which matters when the caller
// asked for port 0 — as a test does, so two runs never collide.
func (s *Server) ListenAddr() netip.AddrPort {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.conn == nil {
		return netip.AddrPort{}
	}

	local := s.conn.LocalAddr().(*net.UDPAddr)

	addr, ok := netip.AddrFromSlice(local.IP)
	if !ok {
		return netip.AddrPort{}
	}

	return netip.AddrPortFrom(addr.Unmap(), uint16(local.Port))
}

// PublicKeyBase64 renders it for the panel configuration.
func (s *Server) PublicKeyBase64() string {
	return base64.StdEncoding.EncodeToString(s.publicKey[:])
}

// Run binds the socket and serves until the context is cancelled.
func (s *Server) Run(ctx context.Context) error {
	addr, err := net.ResolveUDPAddr("udp", s.opts.Listen)
	if err != nil {
		return fmt.Errorf("resolving %s: %w", s.opts.Listen, err)
	}

	conn, err := net.ListenUDP("udp", addr)
	if err != nil {
		return fmt.Errorf("binding %s: %w", s.opts.Listen, err)
	}
	s.mu.Lock()
	s.conn = conn
	s.mu.Unlock()

	s.opts.Logf("coordinator listening on %s", conn.LocalAddr())
	s.opts.Logf("public key: %s", s.PublicKeyBase64())

	go s.housekeeping(ctx)

	// Unblocks the read loop when the context is cancelled.
	go func() {
		<-ctx.Done()
		_ = conn.Close()
	}()

	return s.readLoop(ctx)
}

func (s *Server) readLoop(ctx context.Context) error {
	// Big enough for a hub frame, which carries a whole WireGuard packet. It
	// was disco.MaxPacket, 1200 bytes, and a larger datagram is truncated
	// without an error — every full-size frame would have arrived cut short.
	// The type is checked first; control messages keep their 1200-byte limit.
	buf := make([]byte, hub.MaxFrame)
	if s.control == nil {
		s.control = make(chan struct{}, maxControlInFlight)
	}

	for {
		n, from, err := s.conn.ReadFromUDPAddrPort(buf)
		if err != nil {
			if ctx.Err() != nil {
				s.opts.Logf("coordinator stopped")
				return nil
			}

			return fmt.Errorf("reading: %w", err)
		}

		// Unmapped so a v4 address arriving on a dual-stack socket is
		// recorded as 1.2.3.4:51820 rather than [::ffff:1.2.3.4]:51820,
		// which a peer would then fail to parse as an endpoint.
		src := netip.AddrPortFrom(from.Addr().Unmap(), from.Port())

		// Hub data is handled here, on the reader, in the order it arrived:
		// one goroutine per packet reorders a stream, and WireGuard's replay
		// window is the only thing between that and dropped packets.
		if hub.IsData(buf[:n]) {
			s.hubDropped.Add(1)

			continue
		}

		if n > disco.MaxPacket {
			continue
		}

		select {
		case s.control <- struct{}{}:
		default:
			s.controlDropped.Add(1)

			continue
		}

		// Copied because the handler runs after the buffer is reused.
		pkt := make([]byte, n)
		copy(pkt, buf[:n])

		go func() {
			defer func() { <-s.control }()
			s.handle(ctx, pkt, src)
		}()
	}
}

// housekeeping expires stale presence and flushes observed endpoints to the
// panel in batches, rather than one HTTP call per hello.
func (s *Server) housekeeping(ctx context.Context) {
	ticker := time.NewTicker(30 * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			if gone := s.reg.Sweep(); gone > 0 {
				s.opts.Logf("expired %d device(s); %d still present", gone, s.reg.Len())
			}
			// Answers older than a day are dropped, so the memory is bounded
			// by the devices seen in the last day, not by every device ever.
			s.lastKnown.sweep()
			s.flushEndpoints(ctx)
		}
	}
}

func (s *Server) flushEndpoints(ctx context.Context) {
	s.mu.Lock()
	if len(s.pending) == 0 {
		s.mu.Unlock()
		return
	}

	reports := make([]panelapi.EndpointReport, 0, len(s.pending))
	for _, r := range s.pending {
		reports = append(reports, r)
	}
	s.pending = make(map[string]panelapi.EndpointReport)
	s.mu.Unlock()

	if err := s.opts.Panel.ReportEndpoints(ctx, reports); err != nil {
		// Not fatal: the panel uses these for display, and agents learn
		// endpoints from the coordinator directly rather than through it.
		s.opts.Logf("reporting endpoints to the panel failed: %v", err)
	}
}

// noteUnanswered records, for the panel, that this device is not receiving our
// replies — or that it has started to again.
//
// It rides the endpoint report, which already goes to the panel on a timer for
// every device the coordinator has heard from. A separate channel would be a
// second thing to authenticate, retry and get wrong.
func (s *Server) noteUnanswered(deviceUID string, unanswered bool) {
	s.mu.Lock()
	defer s.mu.Unlock()

	report := s.pending[deviceUID]
	report.DeviceUID = deviceUID
	report.Unanswered = unanswered
	report.UnansweredKnown = true
	s.pending[deviceUID] = report
}

func (s *Server) queueEndpoint(deviceUID string, reflexive netip.AddrPort, local []netip.AddrPort) {
	s.mu.Lock()
	// Built on whatever is already queued for this device, so an endpoint
	// report does not erase a "not hearing us" recorded a moment earlier.
	report := s.pending[deviceUID]
	report.DeviceUID = deviceUID
	report.Endpoint = reflexive.String()
	if len(local) > 0 {
		report.LANEndpoint = local[0].String()
	}
	s.pending[deviceUID] = report
	s.mu.Unlock()
}

func (s *Server) send(pkt []byte, to netip.AddrPort) {
	if _, err := s.conn.WriteToUDPAddrPort(pkt, to); err != nil {
		s.opts.Logf("sending to %s failed: %v", to, err)
	}
}
