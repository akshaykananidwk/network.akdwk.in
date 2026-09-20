// Package disconn shares one UDP socket between WireGuard and discovery.
//
// This is the whole reason NAT traversal can work. A NAT gives each socket its
// own external mapping, so if discovery used a socket of its own, the address
// the coordinator observed would be the mapping for *that* socket, and telling
// a peer to send WireGuard traffic there would send it to a mapping that does
// not exist. Both protocols must leave by the same door.
//
// It works because the two are distinguishable on sight: WireGuard's first
// byte is its message type, 1 to 4, and a discovery packet starts with 'A'.
// See the disco package for the guarantee and the test that holds it.
package disconn

import (
	"net/netip"
	"sync"

	"golang.zx2c4.com/wireguard/conn"

	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
)

// Handler receives the discovery packets sifted out of the WireGuard stream.
type Handler func(pkt []byte, from netip.AddrPort)

// Bind wraps wireguard-go's default bind, intercepting discovery packets
// before WireGuard ever sees them and letting everything else through
// untouched.
type Bind struct {
	inner conn.Bind

	mu      sync.RWMutex
	handler Handler
	port    uint16
}

// New wraps the platform's default bind.
func New() *Bind {
	return &Bind{inner: conn.NewDefaultBind()}
}

// SetHandler installs the discovery handler. It may be called before or after
// Open, because the socket is useful to the device either way.
func (b *Bind) SetHandler(h Handler) {
	b.mu.Lock()
	b.handler = h
	b.mu.Unlock()
}

// Port is the port actually bound, which matters when zero was requested.
func (b *Bind) Port() uint16 {
	b.mu.RLock()
	defer b.mu.RUnlock()

	return b.port
}

// Open binds the socket and wraps each receive function.
func (b *Bind) Open(port uint16) ([]conn.ReceiveFunc, uint16, error) {
	fns, actual, err := b.inner.Open(port)
	if err != nil {
		return nil, 0, err
	}

	b.mu.Lock()
	b.port = actual
	b.mu.Unlock()

	wrapped := make([]conn.ReceiveFunc, len(fns))
	for i, fn := range fns {
		wrapped[i] = b.intercept(fn)
	}

	return wrapped, actual, nil
}

// intercept sifts discovery packets out of a batch.
//
// wireguard-go hands us a batch and expects back the number of packets it
// should process. Discovery packets are dispatched and then removed by
// compacting the surviving entries forward, so WireGuard sees a batch that
// contains only its own traffic and never learns discovery exists.
func (b *Bind) intercept(fn conn.ReceiveFunc) conn.ReceiveFunc {
	return func(packets [][]byte, sizes []int, eps []conn.Endpoint) (int, error) {
		n, err := fn(packets, sizes, eps)
		if err != nil || n == 0 {
			return n, err
		}

		kept := 0

		for i := 0; i < n; i++ {
			pkt := packets[i][:sizes[i]]

			if disco.IsDisco(pkt) {
				b.dispatch(pkt, endpointAddrPort(eps[i]))
				continue
			}

			if kept != i {
				// The buffers themselves belong to wireguard-go's pool, so the
				// slice headers are swapped rather than the contents copied.
				packets[kept], packets[i] = packets[i], packets[kept]
				sizes[kept] = sizes[i]
				eps[kept] = eps[i]
			}
			kept++
		}

		return kept, nil
	}
}

func (b *Bind) dispatch(pkt []byte, from netip.AddrPort) {
	b.mu.RLock()
	handler := b.handler
	b.mu.RUnlock()

	if handler == nil || !from.IsValid() {
		return
	}

	// Copied: the buffer goes straight back into wireguard-go's pool.
	owned := make([]byte, len(pkt))
	copy(owned, pkt)

	handler(owned, from)
}

// Send passes through, which is what lets discovery reuse the socket: a
// discovery packet is sent with the same Send as a WireGuard one, so it leaves
// through the same NAT mapping.
func (b *Bind) Send(bufs [][]byte, ep conn.Endpoint) error { return b.inner.Send(bufs, ep) }

// SendTo sends a discovery packet to a raw address.
func (b *Bind) SendTo(pkt []byte, to netip.AddrPort) error {
	ep, err := b.inner.ParseEndpoint(to.String())
	if err != nil {
		return err
	}

	return b.inner.Send([][]byte{pkt}, ep)
}

func (b *Bind) Close() error                                  { return b.inner.Close() }
func (b *Bind) SetMark(mark uint32) error                     { return b.inner.SetMark(mark) }
func (b *Bind) ParseEndpoint(s string) (conn.Endpoint, error) { return b.inner.ParseEndpoint(s) }
func (b *Bind) BatchSize() int                                { return b.inner.BatchSize() }

func endpointAddrPort(ep conn.Endpoint) netip.AddrPort {
	if ep == nil {
		return netip.AddrPort{}
	}

	parsed, err := netip.ParseAddrPort(ep.DstToString())
	if err != nil {
		return netip.AddrPort{}
	}

	return netip.AddrPortFrom(parsed.Addr().Unmap(), parsed.Port())
}
