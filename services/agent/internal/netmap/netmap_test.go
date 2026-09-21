package netmap

import (
	"encoding/binary"
	"net/netip"
	"testing"
)

// Every test here checks the incremental checksum against a full
// recomputation. Incremental arithmetic that is subtly wrong produces packets
// that look fine in a hex dump and are discarded silently by the far end, so
// "the bytes changed" is not a result worth having on its own.

func sum16(b []byte) uint16 {
	var sum uint32
	for i := 0; i+1 < len(b); i += 2 {
		sum += uint32(binary.BigEndian.Uint16(b[i : i+2]))
	}
	if len(b)%2 == 1 {
		sum += uint32(b[len(b)-1]) << 8
	}
	for sum>>16 != 0 {
		sum = (sum & 0xFFFF) + (sum >> 16)
	}

	return ^uint16(sum)
}

func ipHeaderOK(t *testing.T, pkt []byte) {
	t.Helper()
	ihl := int(pkt[0]&0x0F) * 4
	if got := sum16(pkt[:ihl]); got != 0 {
		t.Fatalf("IPv4 header checksum does not verify: %#04x", got)
	}
}

// transportOK recomputes a TCP or UDP checksum over the pseudo-header and the
// segment, which is the only check that proves the pseudo-header fixup landed.
func transportOK(t *testing.T, pkt []byte) {
	t.Helper()
	ihl := int(pkt[0]&0x0F) * 4
	proto := pkt[offsetProto]
	payload := pkt[ihl:]

	pseudo := make([]byte, 12, 12+len(payload))
	copy(pseudo[0:4], pkt[offsetSrc:offsetSrc+4])
	copy(pseudo[4:8], pkt[offsetDst:offsetDst+4])
	pseudo[9] = proto
	binary.BigEndian.PutUint16(pseudo[10:12], uint16(len(payload)))
	pseudo = append(pseudo, payload...)

	if got := sum16(pseudo); got != 0 {
		t.Fatalf("transport checksum does not verify: %#04x", got)
	}
}

// build assembles a real IPv4 packet with correct checksums.
func build(proto byte, src, dst string, payload []byte) []byte {
	pkt := make([]byte, ipv4MinHeader+len(payload))
	pkt[0] = 0x45
	binary.BigEndian.PutUint16(pkt[2:4], uint16(len(pkt)))
	pkt[8] = 64
	pkt[offsetProto] = proto

	s := netip.MustParseAddr(src).As4()
	d := netip.MustParseAddr(dst).As4()
	copy(pkt[offsetSrc:offsetSrc+4], s[:])
	copy(pkt[offsetDst:offsetDst+4], d[:])
	copy(pkt[ipv4MinHeader:], payload)

	binary.BigEndian.PutUint16(pkt[offsetChecksum:offsetChecksum+2], sum16(pkt[:ipv4MinHeader]))

	if proto == protoTCP || proto == protoUDP {
		seg := pkt[ipv4MinHeader:]
		pseudo := make([]byte, 12, 12+len(seg))
		copy(pseudo[0:4], s[:])
		copy(pseudo[4:8], d[:])
		pseudo[9] = proto
		binary.BigEndian.PutUint16(pseudo[10:12], uint16(len(seg)))
		pseudo = append(pseudo, seg...)

		offset := 16 // TCP checksum
		if proto == protoUDP {
			offset = 6
		}
		binary.BigEndian.PutUint16(seg[offset:offset+2], sum16(pseudo))
	}

	return pkt
}

func tcpSegment() []byte {
	seg := make([]byte, 20)
	binary.BigEndian.PutUint16(seg[0:2], 40000)
	binary.BigEndian.PutUint16(seg[2:4], 554)
	seg[12] = 0x50
	seg[13] = 0x02
	binary.BigEndian.PutUint16(seg[14:16], 65535)

	return seg
}

func mappedTable(t *testing.T) *Table {
	t.Helper()
	table := NewTable()
	table.Add("10.201.5.0/24", "192.168.1.0/24")
	if table.Len() != 1 {
		t.Fatalf("Len() = %d, want 1", table.Len())
	}

	return table
}

// The case the whole feature exists for: the overlay addresses the recorder at
// 10.201.5.50 and the gateway hands the operating system 192.168.1.50.
func TestInboundDestinationIsRewrittenToTheRealLAN(t *testing.T) {
	table := mappedTable(t)
	pkt := build(protoTCP, "10.99.0.2", "10.201.5.50", tcpSegment())

	if !table.Rewrite(ToLAN, pkt) {
		t.Fatal("the packet was not rewritten")
	}

	dst := netip.AddrFrom4([4]byte(pkt[offsetDst : offsetDst+4]))
	if dst.String() != "192.168.1.50" {
		t.Fatalf("destination is %s, want 192.168.1.50", dst)
	}
	if src := netip.AddrFrom4([4]byte(pkt[offsetSrc : offsetSrc+4])); src.String() != "10.99.0.2" {
		t.Fatalf("the source was touched: %s", src)
	}

	ipHeaderOK(t, pkt)
	transportOK(t, pkt)
}

// And the answer on its way back out.
func TestOutboundSourceIsRewrittenToTheMappedPrefix(t *testing.T) {
	table := mappedTable(t)
	pkt := build(protoTCP, "192.168.1.50", "10.99.0.2", tcpSegment())

	if !table.Rewrite(ToOverlay, pkt) {
		t.Fatal("the packet was not rewritten")
	}

	src := netip.AddrFrom4([4]byte(pkt[offsetSrc : offsetSrc+4]))
	if src.String() != "10.201.5.50" {
		t.Fatalf("source is %s, want 10.201.5.50", src)
	}

	ipHeaderOK(t, pkt)
	transportOK(t, pkt)
}

// The host part is what carries across, so every address in the range has to
// land on its counterpart — not just the one the test author picked.
func TestEveryHostInTheRangeMapsToItsCounterpart(t *testing.T) {
	table := mappedTable(t)

	for _, host := range []int{0, 1, 50, 60, 128, 254, 255} {
		in := netip.AddrFrom4([4]byte{10, 201, 5, byte(host)})
		want := netip.AddrFrom4([4]byte{192, 168, 1, byte(host)})

		pkt := build(protoUDP, "10.99.0.2", in.String(), make([]byte, 12))
		if !table.Rewrite(ToLAN, pkt) {
			t.Fatalf("%s was not rewritten", in)
		}

		got := netip.AddrFrom4([4]byte(pkt[offsetDst : offsetDst+4]))
		if got != want {
			t.Fatalf("%s mapped to %s, want %s", in, got, want)
		}
		ipHeaderOK(t, pkt)
	}
}

// A round trip has to be the identity, or an address is being lost somewhere.
func TestMappingIsReversible(t *testing.T) {
	table := mappedTable(t)

	pkt := build(protoTCP, "10.99.0.2", "10.201.5.50", tcpSegment())
	original := append([]byte(nil), pkt...)

	table.Rewrite(ToLAN, pkt)

	// Send it back the other way by swapping the ends, which is what the LAN
	// device's reply does.
	reply := build(protoTCP, "192.168.1.50", "10.99.0.2", tcpSegment())
	table.Rewrite(ToOverlay, reply)

	if src := netip.AddrFrom4([4]byte(reply[offsetSrc : offsetSrc+4])); src.String() != "10.201.5.50" {
		t.Fatalf("the reply came back as %s", src)
	}

	// And the forward packet, mapped back, is the packet we started with.
	back := NewTable()
	back.Add("192.168.1.0/24", "10.201.5.0/24")
	back.Rewrite(ToLAN, pkt)

	if string(pkt) != string(original) {
		t.Fatal("a round trip did not restore the packet byte for byte")
	}
}

// An ICMP error quotes the packet that caused it, and the quoted header
// carries the address we rewrote. If it is not rewritten too, the far end
// cannot tell which conversation the error belongs to and drops it — which
// shows up as a camera stream that stalls on its first large frame rather than
// as an error anybody sees.
func TestICMPErrorsCarryTheirQuotedHeaderAcross(t *testing.T) {
	table := mappedTable(t)

	// The packet that provoked the error: overlay → the recorder, already
	// translated to its real address by the time the LAN saw it.
	inner := build(protoTCP, "10.99.0.2", "192.168.1.50", tcpSegment())

	// "Fragmentation needed", quoting the first 28 bytes of it.
	icmp := make([]byte, 8+len(inner))
	icmp[0] = 3 // destination unreachable
	icmp[1] = 4 // fragmentation needed
	binary.BigEndian.PutUint16(icmp[6:8], 1400)
	copy(icmp[8:], inner)
	binary.BigEndian.PutUint16(icmp[2:4], sum16(icmp))

	pkt := build(protoICMP, "192.168.1.1", "10.99.0.2", icmp)

	if !table.Rewrite(ToOverlay, pkt) {
		t.Fatal("the ICMP error was not rewritten")
	}

	ipHeaderOK(t, pkt)

	body := pkt[ipv4MinHeader:]
	if got := sum16(body); got != 0 {
		t.Fatalf("ICMP checksum does not verify after rewriting: %#04x", got)
	}

	quoted := body[8:]
	ipHeaderOK(t, quoted)

	if dst := netip.AddrFrom4([4]byte(quoted[offsetDst : offsetDst+4])); dst.String() != "10.201.5.50" {
		t.Fatalf("the quoted destination is %s, want 10.201.5.50", dst)
	}
}

// An echo reply is not an error and quotes nothing. ICMP's checksum does not
// cover the IP addresses, so the message must come through untouched — an
// unnecessary "fix" here would break every ping.
func TestICMPEchoIsLeftAlone(t *testing.T) {
	table := mappedTable(t)

	echo := make([]byte, 16)
	echo[0] = 0 // echo reply
	binary.BigEndian.PutUint16(echo[4:6], 0x1234)
	binary.BigEndian.PutUint16(echo[6:8], 7)
	binary.BigEndian.PutUint16(echo[2:4], sum16(echo))
	before := append([]byte(nil), echo...)

	pkt := build(protoICMP, "192.168.1.50", "10.99.0.2", echo)
	if !table.Rewrite(ToOverlay, pkt) {
		t.Fatal("the source was not rewritten")
	}

	ipHeaderOK(t, pkt)
	if string(pkt[ipv4MinHeader:]) != string(before) {
		t.Fatal("the ICMP message was modified; its checksum does not cover the addresses")
	}
}

// A UDP sender may decline to compute a checksum, and zero means exactly that.
// Writing a repaired value over it would invent a guarantee the sender did not
// give.
func TestUDPWithNoChecksumKeepsNone(t *testing.T) {
	table := mappedTable(t)

	udp := make([]byte, 12)
	binary.BigEndian.PutUint16(udp[0:2], 5000)
	binary.BigEndian.PutUint16(udp[2:4], 5000)
	binary.BigEndian.PutUint16(udp[4:6], 12)
	// checksum left at zero

	pkt := build(protoICMP, "10.99.0.2", "10.201.5.50", udp)
	pkt[offsetProto] = protoUDP
	binary.BigEndian.PutUint16(pkt[offsetChecksum:offsetChecksum+2], 0)
	binary.BigEndian.PutUint16(pkt[offsetChecksum:offsetChecksum+2], sum16(pkt[:ipv4MinHeader]))

	table.Rewrite(ToLAN, pkt)

	if got := binary.BigEndian.Uint16(pkt[ipv4MinHeader+6 : ipv4MinHeader+8]); got != 0 {
		t.Fatalf("UDP checksum became %#04x; it must stay zero", got)
	}
	ipHeaderOK(t, pkt)
}

// A fragment after the first carries no transport header. Reaching in as
// though it did would corrupt payload bytes, so only the IP header is touched.
func TestLaterFragmentsHaveOnlyTheirHeaderTouched(t *testing.T) {
	table := mappedTable(t)

	pkt := build(protoTCP, "10.99.0.2", "10.201.5.50", []byte("payload, not a TCP header at all"))
	binary.BigEndian.PutUint16(pkt[6:8], 185) // fragment offset, not the first
	binary.BigEndian.PutUint16(pkt[offsetChecksum:offsetChecksum+2], 0)
	binary.BigEndian.PutUint16(pkt[offsetChecksum:offsetChecksum+2], sum16(pkt[:ipv4MinHeader]))

	// Snapshotted after build, because build writes a transport checksum into
	// the bytes and the question here is what Rewrite does to them.
	payload := append([]byte(nil), pkt[ipv4MinHeader:]...)

	if !table.Rewrite(ToLAN, pkt) {
		t.Fatal("the fragment's destination was not rewritten")
	}

	ipHeaderOK(t, pkt)
	if string(pkt[ipv4MinHeader:]) != string(payload) {
		t.Fatal("the fragment's payload was modified")
	}
}

// An address outside every mapping is not ours to touch. The overlay's own
// addresses go through a gateway's tunnel constantly.
func TestUnmappedAddressesArePassedThrough(t *testing.T) {
	table := mappedTable(t)

	pkt := build(protoTCP, "10.99.0.2", "10.99.0.3", tcpSegment())
	before := append([]byte(nil), pkt...)

	if table.Rewrite(ToLAN, pkt) {
		t.Fatal("a packet between two overlay addresses was rewritten")
	}
	if string(pkt) != string(before) {
		t.Fatal("the packet was modified anyway")
	}
}

// Mismatched prefix sizes are refused rather than approximated: mapping a /24
// onto a /25 would put half the LAN somewhere else and the arithmetic would
// not be reversible.
func TestMismatchedPrefixSizesAreRefused(t *testing.T) {
	table := NewTable()
	table.Add("10.201.5.0/25", "192.168.1.0/24")
	table.Add("10.201.6.0/24", "192.168.2.0/24")

	if table.Len() != 1 {
		t.Fatalf("Len() = %d, want 1 — the mismatched pair should have been dropped", table.Len())
	}
}

// A mapping for one machine beats one for the subnet it sits in, the same way
// routing works.
func TestTheMostSpecificMappingWins(t *testing.T) {
	table := NewTable()
	table.Add("10.201.5.0/24", "192.168.1.0/24")
	table.Add("10.201.5.50/32", "192.168.9.9/32")

	pkt := build(protoUDP, "10.99.0.2", "10.201.5.50", make([]byte, 12))
	table.Rewrite(ToLAN, pkt)

	if dst := netip.AddrFrom4([4]byte(pkt[offsetDst : offsetDst+4])); dst.String() != "192.168.9.9" {
		t.Fatalf("destination is %s, want 192.168.9.9", dst)
	}
	ipHeaderOK(t, pkt)
}

// A short or malformed packet is left alone rather than being read past.
func TestRunts(t *testing.T) {
	table := mappedTable(t)

	for _, pkt := range [][]byte{nil, {}, {0x45}, make([]byte, 19), {0x60, 0, 0, 0, 0, 0, 0, 0}} {
		if table.Rewrite(ToLAN, pkt) {
			t.Fatalf("a %d-byte packet was rewritten", len(pkt))
		}
	}
}
