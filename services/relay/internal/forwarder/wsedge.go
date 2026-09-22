package forwarder

import (
	"context"
	"fmt"
	"net"
	"net/http"
	"net/netip"
	"strings"
	"sync/atomic"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/shared/fallback"
	"github.com/coder/websocket"
)

// Edge is the relay's HTTPS fallback endpoint.
//
// Some networks pass nothing but TCP 443 — hotel Wi-Fi, a guest VLAN, an
// office firewall that allows outbound UDP but silently drops the replies.
// On those the whole UDP design is unavailable, including the coordinator, so
// there is no point offering only a relayed data path: the agent could not
// even ask for one. The edge therefore carries both. Control frames are
// proxied to the coordinator as ordinary UDP, so the coordinator needs no
// knowledge of this path at all, and data frames go through exactly the same
// session machinery a UDP-bound pair uses.
//
// It speaks plain HTTP. TLS and port 443 are Apache's, in front of it — the
// whole point is to arrive on the port a browser uses, and that port is
// already serving the panel.
type Edge struct {
	relay       *Relay
	coordinator netip.AddrPort
	logf        func(string, ...any)

	live atomic.Int64
}

// EdgeOptions configures it.
type EdgeOptions struct {
	// Coordinator is the UDP address control frames are proxied to. Empty
	// disables the control path, leaving a data-only edge.
	Coordinator string
}

// NewEdge builds the fallback endpoint for a relay.
func (r *Relay) NewEdge(opts EdgeOptions) (*Edge, error) {
	e := &Edge{relay: r, logf: r.opts.Logf}

	if opts.Coordinator != "" {
		addr, err := net.ResolveUDPAddr("udp", opts.Coordinator)
		if err != nil {
			return nil, fmt.Errorf("resolving the coordinator %s: %w", opts.Coordinator, err)
		}

		ip, ok := netip.AddrFromSlice(addr.IP)
		if !ok {
			return nil, fmt.Errorf("the coordinator address %s has no IP", opts.Coordinator)
		}

		e.coordinator = netip.AddrPortFrom(ip.Unmap(), uint16(addr.Port))
	}

	return e, nil
}

// Live is how many agents are currently on the fallback path.
func (e *Edge) Live() int64 { return e.live.Load() }

// Handler serves the fallback on the given path plus a health probe.
//
// The probe answers an ordinary GET, because the first thing to establish on a
// customer network is whether our port 443 is reachable at all, and asking
// someone to open a URL in a browser is a question anybody can answer.
func (e *Edge) Handler(path string) http.Handler {
	if path == "" {
		path = "/ws"
	}

	mux := http.NewServeMux()
	mux.Handle(path, e)
	mux.HandleFunc(path+"/health", func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "text/plain; charset=utf-8")
		fmt.Fprintf(w, "ok\nfallback sessions: %d\n", e.Live())
	})

	return mux
}

// ServeHTTP upgrades one agent onto the fallback.
func (e *Edge) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	conn, err := websocket.Accept(w, r, &websocket.AcceptOptions{
		Subprotocols: []string{fallback.Subprotocol},
	})
	if err != nil {
		return // Accept has already written the failure
	}

	if conn.Subprotocol() != fallback.Subprotocol {
		// Anything that is not our agent — a browser that wandered in, a
		// scanner — is closed rather than fed frames it cannot read.
		_ = conn.Close(websocket.StatusPolicyViolation, "unsupported subprotocol")

		return
	}

	conn.SetReadLimit(fallback.MaxFrame + 64)

	client := &wsClient{
		edge:     e,
		conn:     conn,
		observed: observedAddr(r),
		sessions: make(map[[32]byte]*Session, 2),
	}

	e.live.Add(1)
	defer e.live.Add(-1)

	client.serve(r.Context())
}

// observedAddr is the address the agent appears to come from.
//
// Behind Apache the socket's peer is Apache itself, so the forwarded header is
// the only thing that says anything useful. It is taken on trust because it is
// used for one purpose — telling a technician which address the office is
// seen as — and nothing is authorised by it.
func observedAddr(r *http.Request) string {
	if fwd := r.Header.Get("X-Forwarded-For"); fwd != "" {
		if first, _, ok := strings.Cut(fwd, ","); ok {
			return strings.TrimSpace(first)
		}

		return strings.TrimSpace(fwd)
	}

	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		return r.RemoteAddr
	}

	return host
}

// Serve runs an HTTP server for the edge until the context is cancelled.
//
// Plain HTTP on a loopback or private address, with Apache terminating TLS in
// front. Binding this to a public interface would serve the fallback
// unencrypted; the caller chooses the address and the installer chooses
// 127.0.0.1.
func (e *Edge) Serve(ctx context.Context, listen, path string) error {
	server := &http.Server{
		Addr:              listen,
		Handler:           e.Handler(path),
		ReadHeaderTimeout: 10 * time.Second,
		// No write timeout: a fallback connection is long-lived by design and
		// a deadline on it would cut every session at the same age.
	}

	listener, err := net.Listen("tcp", listen)
	if err != nil {
		return fmt.Errorf("binding the fallback listener on %s: %w", listen, err)
	}

	e.logf("HTTPS fallback on http://%s%s (TLS terminates in front of this)", listener.Addr(), path)

	go func() {
		<-ctx.Done()

		shutdown, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()

		_ = server.Shutdown(shutdown)
	}()

	if err := server.Serve(listener); err != nil && ctx.Err() == nil {
		return fmt.Errorf("serving the fallback: %w", err)
	}

	return nil
}
