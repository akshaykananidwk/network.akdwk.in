// Package gwnat is a gateway's NAT, done in the agent rather than by the
// operating system.
//
// A device that shares its LAN receives packets from its peers addressed to
// machines on that LAN, with the peers' overlay addresses as their source.
// Those machines — a camera recorder, a ZTE router — have no route back to
// the overlay, so something has to put the gateway's own LAN address in the
// source and translate the replies back. The agent left that to the operating
// system: iptables on Linux, WinNAT (New-NetNat) on Windows. WinNAT exists
// only where Hyper-V or Containers is installed. On an ordinary Windows 10 Pro
// PC it does not exist at all ("Invalid class"), the gateway forwarded the
// packets onto the LAN with the laptop's overlay source, nothing answered, and
// the panel said the share was live.
//
// So on Windows the agent does it itself, the way a userspace subnet router
// does: packets for the shared LAN never reach the operating system. They go
// into a userspace TCP/IP stack (gVisor's netstack), which terminates each TCP
// connection and UDP flow and re-opens it as an ordinary socket from this
// machine — whose source is this machine's LAN address, so every reply comes
// back to it. ICMP echo is answered by pinging the target for real from this
// machine. Nothing on the machine or on the LAN has to be configured: no
// WinNAT, no RRAS, no Hyper-V, no IP forwarding, no route on the router.
//
// # Where it sits
//
//	acl.Wrap( netmap.Wrap( gwnat.Wrap( tun ) ) )
//
// Below the address translation, so it sees the real LAN addresses
// (192.168.10.1, not 10.128.0.1) exactly as the operating system would have,
// and below the ACL, so nothing reaches it that the filter did not let in.
// Replies come out of Read as if the operating system had sent them, and are
// translated and filtered on the way up like any other packet.
package gwnat

import (
	"context"
	"errors"
	"net"
	"net/netip"
	"os"
	"sync"
	"sync/atomic"
	"time"

	"golang.zx2c4.com/wireguard/tun"
)

// Options configures a Device.
type Options struct {
	// Userspace: this device does its gateway NAT here. When false the
	// wrapper passes everything straight through and costs nothing.
	Userspace bool
	// Dial opens a connection to a LAN machine. Nil means the ordinary
	// net.Dialer, which is what production wants: the operating system picks
	// the LAN interface and its address as the source.
	Dial func(ctx context.Context, network, addr string) (net.Conn, error)
	// Ping sends one ICMP echo to dst and reports whether it was answered.
	// Nil means the platform's own (ping_*.go).
	Ping func(ctx context.Context, dst netip.Addr, id, seq uint16, data []byte) (bool, error)
	// Logf receives the rare events worth a line: a stack that could not
	// start, a dial that failed.
	Logf func(string, ...any)
}

// Stats are counters for the status file and for diagnosis.
type Stats struct {
	TCPOpened   atomic.Uint64
	TCPRefused  atomic.Uint64
	UDPOpened   atomic.Uint64
	PingsOK     atomic.Uint64
	PingsFailed atomic.Uint64
	Diverted    atomic.Uint64
	Dropped     atomic.Uint64
}

// Device is a tun.Device that performs a gateway's NAT for the LANs it is
// given.
type Device struct {
	tun.Device

	opts  Options
	stats Stats

	// lans is what is diverted: packets from the overlay to these prefixes.
	// Nil means none, and Write passes everything through.
	lans atomic.Pointer[lanSet]

	mu       sync.Mutex
	stack    *netstack // started on the first SetLANs that names a LAN
	stackMTU int

	// The read side, used only in userspace mode: the operating system's
	// packets and the stack's replies, merged. A goroutine reads the real
	// interface because wireguard-go's single reader cannot be woken from a
	// blocking read when a reply is ready.
	// pingSlots and udpFlows bound what a peer can make this machine hold
	// open: see maxPingsInFlight and maxUDPFlows.
	pingSlots chan struct{}
	udpFlows  atomic.Int64
	perPeer   peerCounts

	pumpOnce sync.Once
	fromOS   chan osPacket
	replies  chan []byte
	closed   chan struct{}
	closeErr atomic.Value

	// pendingErr is an error the pump reported behind packets Read had
	// already taken; it is returned by the next Read, never dropped.
	pendingErr error
}

type lanSet struct {
	overlay netip.Prefix
	lans    []netip.Prefix
}

type osPacket struct {
	data []byte
	err  error
}

// replyQueue bounds the replies waiting to go up. A full queue drops, as a
// full socket buffer would; TCP retransmits.
const replyQueue = 1024

// Wrap puts gateway NAT around a tunnel device.
func Wrap(inner tun.Device, opts Options) *Device {
	if opts.Dial == nil {
		var d net.Dialer
		opts.Dial = d.DialContext
	}
	if opts.Ping == nil {
		opts.Ping = platformPing
	}
	if opts.Logf == nil {
		opts.Logf = func(string, ...any) {}
	}

	return &Device{
		Device:    inner,
		opts:      opts,
		fromOS:    make(chan osPacket, 256),
		replies:   make(chan []byte, replyQueue),
		pingSlots: make(chan struct{}, maxPingsInFlight),
		closed:    make(chan struct{}),
	}
}

// Userspace reports whether this device does its NAT itself.
func (d *Device) Userspace() bool { return d.opts.Userspace }

// Counters are the running totals, for the status file.
func (d *Device) Counters() *Stats { return &d.stats }

// SetLANs names the LANs this device routes for, in their real addresses, and
// the overlay whose packets to them are translated. Empty stops diverting.
// Safe to call while traffic flows.
//
// mtu is the tunnel's, as the panel set it: the largest packet the stack may
// send up the tunnel. Zero means the interface's own figure — which on
// Windows is wintun's fixed 1420, not what netsh configured, so the caller
// passes the real one.
func (d *Device) SetLANs(overlay netip.Prefix, lans []netip.Prefix, mtu int) error {
	if !d.opts.Userspace || len(lans) == 0 {
		d.lans.Store(nil)

		return nil
	}

	if mtu <= 0 {
		if m, err := d.Device.MTU(); err == nil && m > 0 {
			mtu = m
		} else {
			mtu = 1280
		}
	}

	d.mu.Lock()
	defer d.mu.Unlock()

	if d.stack != nil && d.stackMTU != mtu {
		// The link MTU is fixed when the stack is built. Connections through
		// the old one end; an MTU change is rare and resets them anyway.
		d.stack.close()
		d.stack = nil
	}
	if d.stack == nil {
		st, err := newNetstack(d, mtu)
		if err != nil {
			return err
		}
		d.stack = st
		d.stackMTU = mtu
	}

	d.lans.Store(&lanSet{overlay: overlay.Masked(), lans: append([]netip.Prefix(nil), lans...)})

	return nil
}

// classify reports whether a packet from a peer is for a LAN this device
// translates for, and if it is, whether it may be translated.
//
// Only a unicast host on the LAN may be: never the range's network or
// broadcast address — the sockets the agent opens would put a broadcast on
// the site's LAN from the gateway — nor multicast, loopback or the limited
// broadcast. Those are dropped rather than handed to the operating system,
// which on a PC an older agent configured still has IP forwarding on.
func (d *Device) classify(pkt []byte) (forLAN, allowed bool) {
	set := d.lans.Load()
	if set == nil || len(pkt) < 20 || pkt[0]>>4 != 4 {
		return false, false
	}

	src, _ := netip.AddrFromSlice(pkt[12:16])
	dst, _ := netip.AddrFromSlice(pkt[16:20])
	if !set.overlay.Contains(src) {
		return false, false
	}
	for _, lan := range set.lans {
		if lan.Contains(dst) {
			return true, unicastHostOf(lan, dst)
		}
	}

	return false, false
}

// unicastHostOf reports whether addr is an ordinary host of lan.
func unicastHostOf(lan netip.Prefix, addr netip.Addr) bool {
	if addr.IsMulticast() || addr.IsLoopback() || addr.IsUnspecified() || addr == netip.AddrFrom4([4]byte{255, 255, 255, 255}) {
		return false
	}
	if lan.Bits() >= 31 {
		return true // point-to-point and host routes have no broadcast
	}
	a := addr.As4()
	n := lan.Masked().Addr().As4()
	host := uint32(a[0])<<24 | uint32(a[1])<<16 | uint32(a[2])<<8 | uint32(a[3])
	net := uint32(n[0])<<24 | uint32(n[1])<<16 | uint32(n[2])<<8 | uint32(n[3])
	mask := ^uint32(0) >> lan.Bits()

	return host != net && host != net|mask
}

// Write takes packets from peers. Those for a translated LAN go to the
// userspace stack (or the pinger); everything else goes to the operating
// system as before.
func (d *Device) Write(bufs [][]byte, offset int) (int, error) {
	if d.lans.Load() == nil {
		return d.Device.Write(bufs, offset)
	}

	kept := bufs[:0:0]
	for _, b := range bufs {
		if len(b) <= offset {
			continue
		}
		pkt := b[offset:]
		forLAN, allowed := d.classify(pkt)
		if !forLAN {
			kept = append(kept, b)
			continue
		}
		if !allowed {
			d.stats.Dropped.Add(1)
			continue
		}

		d.stats.Diverted.Add(1)
		if !d.handleEcho(pkt) {
			d.mu.Lock()
			st := d.stack
			d.mu.Unlock()
			if st == nil || !st.inject(pkt) {
				d.stats.Dropped.Add(1)
			}
		}
	}

	if len(kept) == 0 {
		return len(bufs), nil
	}
	if _, err := d.Device.Write(kept, offset); err != nil {
		return 0, err
	}

	return len(bufs), nil
}

// reply queues a packet the stack or the pinger produced for the tunnel.
func (d *Device) reply(pkt []byte) {
	select {
	case d.replies <- pkt:
	default:
		d.stats.Dropped.Add(1)
	}
}

// Read hands wireguard-go the operating system's packets and, in userspace
// mode, the replies the translation produced.
func (d *Device) Read(bufs [][]byte, sizes []int, offset int) (int, error) {
	if !d.opts.Userspace {
		return d.Device.Read(bufs, sizes, offset)
	}

	d.pumpOnce.Do(func() { go d.pump() })

	// Only wireguard-go's single reader calls Read, so this needs no lock.
	if err := d.pendingErr; err != nil {
		d.pendingErr = nil

		return 0, err
	}

	n := 0
	fill := func(pkt []byte) bool {
		if len(bufs[n]) < offset+len(pkt) {
			d.stats.Dropped.Add(1)
			return true
		}
		sizes[n] = copy(bufs[n][offset:], pkt)
		n++

		return n < len(bufs)
	}

	// Block for the first packet from either side, then take whatever else
	// is already waiting, up to the batch.
	select {
	case p := <-d.fromOS:
		if p.err != nil {
			return 0, p.err
		}
		fill(p.data)
	case r := <-d.replies:
		fill(r)
	case <-d.closed:
		return 0, os.ErrClosed
	}

	for n < len(bufs) {
		select {
		case p := <-d.fromOS:
			if p.err != nil {
				// Behind packets already taken: those go up now, the error
				// on the next call. Dropping it would leave wireguard-go
				// reading a closed adapter for ever.
				d.pendingErr = p.err

				return n, nil
			}
			if !fill(p.data) {
				return n, nil
			}
		case r := <-d.replies:
			if !fill(r) {
				return n, nil
			}
		default:
			return n, nil
		}
	}

	return n, nil
}

// pump reads the real interface for Read.
func (d *Device) pump() {
	batch := d.Device.BatchSize()
	if batch < 1 {
		batch = 1
	}
	const offset = 16
	bufs := make([][]byte, batch)
	for i := range bufs {
		bufs[i] = make([]byte, offset+65535)
	}
	sizes := make([]int, batch)

	for {
		n, err := d.Device.Read(bufs, sizes, offset)
		for i := 0; i < n; i++ {
			if sizes[i] <= 0 {
				continue
			}
			pkt := make([]byte, sizes[i])
			copy(pkt, bufs[i][offset:offset+sizes[i]])
			select {
			case d.fromOS <- osPacket{data: pkt}:
			case <-d.closed:
				return
			}
		}
		if err != nil {
			if errors.Is(err, tun.ErrTooManySegments) {
				continue
			}
			select {
			case d.fromOS <- osPacket{err: err}:
			case <-d.closed:
			}
			if errors.Is(err, os.ErrClosed) {
				return
			}
			// Anything else: pass it up once and pause, rather than spin.
			time.Sleep(10 * time.Millisecond)
		}
	}
}

// BatchSize: in userspace mode Read can hand over several packets at once,
// because it merges two sources.
func (d *Device) BatchSize() int {
	if !d.opts.Userspace {
		return d.Device.BatchSize()
	}
	if b := d.Device.BatchSize(); b > 16 {
		return b
	}

	return 16
}

// Close stops the stack and the real interface.
func (d *Device) Close() error {
	select {
	case <-d.closed:
	default:
		close(d.closed)
	}

	d.mu.Lock()
	if d.stack != nil {
		d.stack.close()
		d.stack = nil
	}
	d.mu.Unlock()

	return d.Device.Close()
}
