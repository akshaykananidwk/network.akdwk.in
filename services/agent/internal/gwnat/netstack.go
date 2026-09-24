package gwnat

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"net/netip"
	"sync"
	"sync/atomic"
	"syscall"
	"time"

	"gvisor.dev/gvisor/pkg/buffer"
	"gvisor.dev/gvisor/pkg/tcpip"
	"gvisor.dev/gvisor/pkg/tcpip/adapters/gonet"
	"gvisor.dev/gvisor/pkg/tcpip/header"
	"gvisor.dev/gvisor/pkg/tcpip/link/channel"
	"gvisor.dev/gvisor/pkg/tcpip/network/ipv4"
	"gvisor.dev/gvisor/pkg/tcpip/stack"
	"gvisor.dev/gvisor/pkg/tcpip/transport/tcp"
	"gvisor.dev/gvisor/pkg/tcpip/transport/udp"
	"gvisor.dev/gvisor/pkg/waiter"
)

const (
	// dialTimeout bounds how long a connection from a peer waits for the
	// LAN machine to answer before it is refused.
	dialTimeout = 10 * time.Second
	// udpIdle closes a UDP flow nothing has used for this long.
	udpIdle = 2 * time.Minute
	// maxTCPInFlight bounds half-open connections being dialled at once.
	maxTCPInFlight = 512
	// maxUDPFlows bounds the UDP flows open at once, each an OS socket held
	// until it has been idle for udpIdle. A DNS client opens one per query;
	// thousands at once is a scan, not a user.
	maxUDPFlows = 4096
	// udpUnanswered closes a flow nothing on the LAN has ever answered, much
	// sooner than udpIdle: a scan of dead ports must not hold sockets for two
	// minutes each.
	udpUnanswered = 30 * time.Second
	// udpBuffer is the largest datagram relayed; a larger one is dropped, as
	// a router drops what it cannot carry, and the flow goes on. Far above
	// any DNS answer or tunnel-sized packet, and a fraction of the 64 KiB a
	// datagram can be, times thousands of flows.
	udpBuffer = 16 << 10

	// Per peer (overlay source address), so one peer cannot use up what the
	// others need: the global limits above still apply to them all together.
	perPeerTCPDialling = 64
	perPeerTCP         = 1024
	perPeerUDP         = 512
)

// peerCounts is what each peer is holding open through this gateway.
type peerCounts struct {
	mu sync.Mutex
	m  map[netip.Addr]*[3]int
}

const (
	useTCPDialling = iota
	useTCP
	useUDP
)

var perPeerLimit = [3]int{perPeerTCPDialling, perPeerTCP, perPeerUDP}

// take reserves one of kind for src, or reports that src is at its limit.
func (p *peerCounts) take(src netip.Addr, kind int) bool {
	p.mu.Lock()
	defer p.mu.Unlock()

	if p.m == nil {
		p.m = make(map[netip.Addr]*[3]int)
	}
	use := p.m[src]
	if use == nil {
		use = new([3]int)
		p.m[src] = use
	}
	if use[kind] >= perPeerLimit[kind] {
		return false
	}
	use[kind]++

	return true
}

func (p *peerCounts) release(src netip.Addr, kind int) {
	p.mu.Lock()
	defer p.mu.Unlock()

	if use := p.m[src]; use != nil {
		use[kind]--
		if *use == [3]int{} {
			delete(p.m, src)
		}
	}
}

// netstack is the userspace TCP/IP stack a gateway terminates connections in.
//
// Promiscuous and spoofing: it accepts packets for any destination — they are
// all for the LAN — and answers from that destination's address, so the
// peer sees the reply coming from the machine it addressed.
type netstack struct {
	dev    *Device
	stack  *stack.Stack
	ep     *channel.Endpoint
	notify *channel.NotificationHandle
	once   sync.Once
}

func newNetstack(dev *Device, mtu int) (*netstack, error) {
	s := stack.New(stack.Options{
		NetworkProtocols:   []stack.NetworkProtocolFactory{ipv4.NewProtocol},
		TransportProtocols: []stack.TransportProtocolFactory{tcp.NewProtocol, udp.NewProtocol},
	})

	sack := tcpip.TCPSACKEnabled(true)
	if err := s.SetTransportProtocolOption(tcp.ProtocolNumber, &sack); err != nil {
		return nil, fmt.Errorf("gateway stack: enabling SACK: %v", err)
	}

	ep := channel.New(1024, uint32(mtu), "")
	ns := &netstack{dev: dev, stack: s, ep: ep}
	ns.notify = ep.AddNotify(ns)

	if err := s.CreateNIC(1, ep); err != nil {
		return nil, fmt.Errorf("gateway stack: %v", err)
	}
	if err := s.SetPromiscuousMode(1, true); err != nil {
		return nil, fmt.Errorf("gateway stack: %v", err)
	}
	if err := s.SetSpoofing(1, true); err != nil {
		return nil, fmt.Errorf("gateway stack: %v", err)
	}
	s.SetRouteTable([]tcpip.Route{{Destination: header.IPv4EmptySubnet, NIC: 1}})

	tcpFwd := tcp.NewForwarder(s, 0, maxTCPInFlight, ns.acceptTCP)
	s.SetTransportProtocolHandler(tcp.ProtocolNumber, tcpFwd.HandlePacket)

	udpFwd := udp.NewForwarder(s, ns.acceptUDP)
	s.SetTransportProtocolHandler(udp.ProtocolNumber, udpFwd.HandlePacket)

	return ns, nil
}

// inject hands the stack one IPv4 packet from a peer. It copies.
func (ns *netstack) inject(pkt []byte) bool {
	pkb := stack.NewPacketBuffer(stack.PacketBufferOptions{Payload: buffer.MakeWithData(append([]byte(nil), pkt...))})
	ns.ep.InjectInbound(header.IPv4ProtocolNumber, pkb)
	pkb.DecRef()

	return true
}

// WriteNotify is the channel endpoint telling us the stack has a packet for
// the tunnel.
func (ns *netstack) WriteNotify() {
	for {
		pkt := ns.ep.Read()
		if pkt == nil {
			return
		}
		view := pkt.ToView()
		pkt.DecRef()
		ns.dev.reply(view.AsSlice())
	}
}

func (ns *netstack) close() {
	ns.once.Do(func() {
		ns.ep.RemoveNotify(ns.notify)
		ns.stack.Close()
		ns.ep.Close()
	})
}

func toAddr(a tcpip.Address) netip.Addr {
	addr, _ := netip.AddrFromSlice(a.AsSlice())

	return addr.Unmap()
}

// acceptTCP runs for each new connection from a peer to the LAN. The LAN
// machine is dialled first, so a port nothing listens on is refused with a
// reset — what the peer would have seen without us in the way — rather than
// accepted and then closed.
func (ns *netstack) acceptTCP(r *tcp.ForwarderRequest) {
	id := r.ID()
	dst := netip.AddrPortFrom(toAddr(id.LocalAddress), id.LocalPort)
	src := toAddr(id.RemoteAddress)
	peers := &ns.dev.perPeer

	refuse := func() {
		ns.dev.stats.TCPRefused.Add(1)
		r.Complete(true)
	}

	// A peer dialling many dead addresses at once would otherwise hold every
	// half-open slot for dialTimeout and starve the rest.
	if !peers.take(src, useTCPDialling) {
		refuse()
		return
	}
	ctx, cancel := context.WithTimeout(context.Background(), dialTimeout)
	out, err := ns.dev.opts.Dial(ctx, "tcp", dst.String())
	cancel()
	peers.release(src, useTCPDialling)
	if err != nil {
		refuse()
		return
	}

	if !peers.take(src, useTCP) {
		out.Close()
		refuse()

		return
	}

	var wq waiter.Queue
	ep, terr := r.CreateEndpoint(&wq)
	if terr != nil {
		peers.release(src, useTCP)
		out.Close()
		refuse()

		return
	}
	r.Complete(false)
	ep.SocketOptions().SetKeepAlive(true)
	ns.dev.stats.TCPOpened.Add(1)

	in := gonet.NewTCPConn(&wq, ep)
	go func() {
		defer peers.release(src, useTCP)
		splice(in, out)
	}()
}

// splice copies both ways until both are done, passing a half-close on.
func splice(a, b net.Conn) {
	done := make(chan struct{}, 2)
	copyHalf := func(dst, src net.Conn) {
		io.Copy(dst, src)
		if cw, ok := dst.(interface{ CloseWrite() error }); ok {
			cw.CloseWrite()
		} else {
			dst.Close()
		}
		done <- struct{}{}
	}
	go copyHalf(a, b)
	go copyHalf(b, a)
	<-done
	<-done
	a.Close()
	b.Close()
}

// acceptUDP runs for each new UDP flow from a peer to the LAN.
func (ns *netstack) acceptUDP(r *udp.ForwarderRequest) {
	id := r.ID()
	dst := netip.AddrPortFrom(toAddr(id.LocalAddress), id.LocalPort)

	src := toAddr(id.RemoteAddress)
	if !ns.dev.perPeer.take(src, useUDP) {
		ns.dev.stats.Dropped.Add(1)
		return
	}
	if ns.dev.udpFlows.Add(1) > maxUDPFlows {
		ns.dev.udpFlows.Add(-1)
		ns.dev.perPeer.release(src, useUDP)
		ns.dev.stats.Dropped.Add(1)

		return
	}
	release := func() {
		ns.dev.udpFlows.Add(-1)
		ns.dev.perPeer.release(src, useUDP)
	}

	var wq waiter.Queue
	ep, terr := r.CreateEndpoint(&wq)
	if terr != nil {
		release()

		return
	}
	in := gonet.NewUDPConn(&wq, ep)

	ctx, cancel := context.WithTimeout(context.Background(), dialTimeout)
	out, err := ns.dev.opts.Dial(ctx, "udp", dst.String())
	cancel()
	if err != nil {
		in.Close()
		release()

		return
	}
	ns.dev.stats.UDPOpened.Add(1)

	go func() {
		defer release()
		relayUDP(in, out)
	}()
}

// relayUDP moves datagrams both ways until the flow has been idle for
// udpIdle — or, if the LAN machine never answered at all, for udpUnanswered.
func relayUDP(in, out net.Conn) {
	defer in.Close()
	defer out.Close()

	var last atomicTime
	var answered atomic.Bool
	last.touch()
	idle := func() time.Duration {
		if answered.Load() {
			return udpIdle
		}

		return udpUnanswered
	}

	half := func(dst, src net.Conn, fromLAN bool) {
		buf := make([]byte, udpBuffer)
		for {
			src.SetReadDeadline(time.Now().Add(idle()))
			n, err := src.Read(buf)
			if err != nil {
				if ne, ok := err.(net.Error); ok && ne.Timeout() && time.Since(last.get()) < idle() {
					continue
				}
				if oversized(err) {
					continue // Windows says so (WSAEMSGSIZE); the flow is fine
				}
				dst.Close()
				src.Close()

				return
			}
			if n == len(buf) {
				// Filled exactly: on Linux and in the userspace stack a
				// larger datagram is cut short without a word. Forwarding
				// the stump would hand the far end a corrupt datagram.
				continue
			}
			if fromLAN {
				answered.Store(true)
			}
			last.touch()
			if _, err := dst.Write(buf[:n]); err != nil {
				return
			}
		}
	}
	done := make(chan struct{})
	go func() { half(out, in, false); close(done) }()
	half(in, out, true)
	<-done
}

type atomicTime struct {
	mu sync.Mutex
	t  time.Time
}

func (a *atomicTime) touch() { a.mu.Lock(); a.t = time.Now(); a.mu.Unlock() }
func (a *atomicTime) get() time.Time {
	a.mu.Lock()
	defer a.mu.Unlock()

	return a.t
}

// oversized reports the error Windows gives for a datagram larger than the
// read buffer (WSAEMSGSIZE). Linux truncates silently instead; see relayUDP.
func oversized(err error) bool {
	return errors.Is(err, syscall.Errno(10040))
}
