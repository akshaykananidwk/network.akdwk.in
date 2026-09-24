package gwnat

import (
	"context"
	"encoding/binary"
	"net/netip"
	"time"
)

// pingTimeout is how long one echo to a LAN machine may take.
const pingTimeout = 3 * time.Second

// maxPingsInFlight bounds the echoes being answered at once. Each is a
// goroutine and a socket for up to pingTimeout; a peer pinging an address
// that never answers, fast, must not turn that into thousands of each.
// `ping -t` is one in flight; a sweep of a /24 is 254.
const maxPingsInFlight = 256

// handleEcho answers an ICMP echo request to a LAN machine by pinging that
// machine from here, and reports whether the packet was one. Anything else
// ICMP is not ours to translate and is dropped by the caller's accounting.
func (d *Device) handleEcho(pkt []byte) bool {
	if pkt[9] != 1 {
		return false // TCP and UDP go to the stack
	}

	// ICMP is consumed here whatever it is: only an echo request is
	// translated, and nothing else ICMP from a peer has anywhere to go.
	ihl := int(pkt[0]&0x0f) * 4
	if ihl < 20 || len(pkt) < ihl+8 {
		d.stats.Dropped.Add(1)
		return true
	}
	// A fragment: the echo's data is split across packets this does not
	// reassemble, and answering the first alone would echo the wrong bytes.
	// Dropped, so a ping larger than the tunnel's MTU times out plainly.
	if frag := binary.BigEndian.Uint16(pkt[6:8]); frag&0x2000 != 0 || frag&0x1fff != 0 {
		d.stats.Dropped.Add(1)
		return true
	}
	icmp := pkt[ihl:]
	if icmp[0] != 8 || icmp[1] != 0 { // echo request, code 0
		d.stats.Dropped.Add(1)
		return true
	}

	src, _ := netip.AddrFromSlice(pkt[12:16])
	dst, _ := netip.AddrFromSlice(pkt[16:20])
	id := binary.BigEndian.Uint16(icmp[4:6])
	seq := binary.BigEndian.Uint16(icmp[6:8])
	data := append([]byte(nil), icmp[8:]...)

	select {
	case d.pingSlots <- struct{}{}:
	default:
		d.stats.Dropped.Add(1) // as a busy router would: the sender retries

		return true
	}

	go func() {
		defer func() { <-d.pingSlots }()
		ctx, cancel := context.WithTimeout(context.Background(), pingTimeout)
		defer cancel()

		ok, err := d.opts.Ping(ctx, dst, id, seq, data)
		if err != nil || !ok {
			d.stats.PingsFailed.Add(1)
			if err != nil {
				d.opts.Logf("gateway: ping %s: %v", dst, err)
			}

			return
		}
		d.stats.PingsOK.Add(1)
		d.reply(echoReply(dst, src, id, seq, data))
	}()

	return true
}

// echoReply builds the IPv4 ICMP echo reply a LAN machine would have sent.
func echoReply(from, to netip.Addr, id, seq uint16, data []byte) []byte {
	pkt := make([]byte, 20+8+len(data))

	pkt[0] = 0x45
	binary.BigEndian.PutUint16(pkt[2:4], uint16(len(pkt)))
	pkt[8] = 64 // TTL
	pkt[9] = 1  // ICMP
	f, t := from.As4(), to.As4()
	copy(pkt[12:16], f[:])
	copy(pkt[16:20], t[:])
	binary.BigEndian.PutUint16(pkt[10:12], checksum(pkt[:20]))

	icmp := pkt[20:]
	icmp[0] = 0 // echo reply
	binary.BigEndian.PutUint16(icmp[4:6], id)
	binary.BigEndian.PutUint16(icmp[6:8], seq)
	copy(icmp[8:], data)
	binary.BigEndian.PutUint16(icmp[2:4], checksum(icmp))

	return pkt
}

func checksum(b []byte) uint16 {
	var sum uint32
	for i := 0; i+1 < len(b); i += 2 {
		sum += uint32(binary.BigEndian.Uint16(b[i:]))
	}
	if len(b)%2 == 1 {
		sum += uint32(b[len(b)-1]) << 8
	}
	for sum>>16 != 0 {
		sum = sum&0xffff + sum>>16
	}

	return ^uint16(sum)
}
