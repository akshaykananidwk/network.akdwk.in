package netmap

import (
	"encoding/binary"
	"net/netip"
)

// Rewriting an address changes three checksums, or none, depending on what the
// packet carries.
//
//   - The IPv4 header checksum always covers the addresses.
//   - TCP and UDP checksums cover a pseudo-header that includes them.
//   - ICMP's checksum does *not* — but an ICMP error carries a copy of the
//     packet that caused it, and that copy has to be rewritten too or the
//     other end will not recognise which conversation the error is about. Path
//     MTU discovery is the case that matters: silently dropping those turns a
//     working camera stream into one that stalls on the first large frame.
//
// All three are fixed incrementally, in the RFC 1624 sense: the delta between
// the old and new words is folded into the existing checksum rather than the
// whole packet being summed again.
const (
	ipv4MinHeader = 20
	// Byte offsets into the IPv4 header. Spelled out rather than counted in
	// the reader's head: offset 8 is the TTL and 10 is the header checksum,
	// and having those two the wrong way round — which this did until a test
	// recomputed the checksum instead of trusting it — produces a packet that
	// looks entirely reasonable in a hex dump and is discarded silently by
	// everything that receives it.
	offsetProto    = 9
	offsetChecksum = 10
	offsetSrc      = 12
	offsetDst      = 16

	protoICMP = 1
	protoTCP  = 6
	protoUDP  = 17
)

// Direction says which way a packet is going, from this device's point of view.
type Direction int

const (
	// ToLAN is a packet that arrived on the tunnel and is about to be handed
	// to the operating system: its destination is a mapped address and has to
	// become a real one.
	ToLAN Direction = iota
	// ToOverlay is a packet the operating system produced for the tunnel: its
	// source is a real address and has to become a mapped one.
	ToOverlay
)

// Rewrite maps one address in place and returns whether it changed anything.
//
// It is deliberately tolerant. A packet it cannot parse is left exactly as it
// found it rather than being dropped: this layer's job is translation, and
// deciding what may pass belongs to the filter above it.
func (t *Table) Rewrite(dir Direction, pkt []byte) bool {
	if t == nil || len(t.mappings) == 0 || len(pkt) < ipv4MinHeader {
		return false
	}

	if pkt[0]>>4 != 4 {
		// IPv6 is not mapped. The overlay is IPv4 and an advertised LAN is
		// IPv4; a v6 packet here is not going to a mapped destination.
		return false
	}

	ihl := int(pkt[0]&0x0F) * 4
	if ihl < ipv4MinHeader || len(pkt) < ihl {
		return false
	}

	offset := offsetDst
	if dir == ToOverlay {
		offset = offsetSrc
	}

	old := netip.AddrFrom4([4]byte(pkt[offset : offset+4]))

	var from, to netip.Prefix
	var ok bool
	if dir == ToLAN {
		from, to, ok = t.toReal(old)
	} else {
		from, to, ok = t.toMapped(old)
	}
	if !ok {
		return false
	}

	replaceAddress(t, dir, pkt, ihl, offset, old, translate(old, from, to))

	return true
}

// replaceAddress writes the new address and repairs every checksum that
// covered the old one.
func replaceAddress(t *Table, dir Direction, pkt []byte, ihl, offset int, old, new netip.Addr) {
	oldWord, newWord := word(old), word(new)
	if oldWord == newWord {
		return
	}

	nb := new.As4()
	copy(pkt[offset:offset+4], nb[:])

	// The IPv4 header checksum.
	fixChecksum(pkt[offsetChecksum:offsetChecksum+2], oldWord, newWord)

	// A fragment after the first carries no transport header, so there is
	// nothing further to repair — and reaching into it as though there were
	// would corrupt payload bytes.
	fragOffset := binary.BigEndian.Uint16(pkt[6:8]) & 0x1FFF
	if fragOffset != 0 {
		return
	}

	payload := pkt[ihl:]
	switch pkt[offsetProto] {
	case protoTCP:
		// 16 bytes in: source port, destination port, sequence,
		// acknowledgement, then offset/flags, window, checksum.
		if len(payload) >= 18 {
			fixChecksum(payload[16:18], oldWord, newWord)
		}
	case protoUDP:
		if len(payload) >= 8 {
			// Zero means "no checksum computed", and it has to stay zero:
			// writing a repaired value where the sender chose not to compute
			// one would be inventing a guarantee.
			if binary.BigEndian.Uint16(payload[6:8]) != 0 {
				fixChecksum(payload[6:8], oldWord, newWord)
			}
		}
	case protoICMP:
		t.rewriteICMP(dir, payload)
	}
}

// rewriteICMP repairs an ICMP error that quotes the packet which caused it.
//
// The quoted header carries the address we have just rewritten, and a receiver
// matches the error to a conversation by reading it. Leaving it alone makes
// "fragmentation needed" and "time exceeded" unattributable, which shows up as
// a stalled transfer rather than as an error anybody sees.
func (t *Table) rewriteICMP(dir Direction, icmp []byte) {
	if len(icmp) < 8 {
		return
	}

	switch icmp[0] {
	case 3, 11, 12: // unreachable, time exceeded, parameter problem
	default:
		// Echo and the rest carry no quoted header, and ICMP's own checksum
		// does not cover the IP addresses, so there is nothing to do.
		return
	}

	quoted := icmp[8:]
	if len(quoted) < ipv4MinHeader || quoted[0]>>4 != 4 {
		return
	}

	// The quoted packet travelled the *other* way — it is the packet the error
	// is about — so the field to translate is the opposite one, and it is a
	// different address from the one on the outer header. Looking it up again
	// rather than reusing the outer delta is the difference between an error a
	// receiver can attribute and one it discards.
	quotedOffset := offsetDst
	if dir == ToLAN {
		quotedOffset = offsetSrc
	}

	old := netip.AddrFrom4([4]byte(quoted[quotedOffset : quotedOffset+4]))

	var from, to netip.Prefix
	var ok bool
	if dir == ToLAN {
		from, to, ok = t.toReal(old)
	} else {
		from, to, ok = t.toMapped(old)
	}
	if !ok {
		return
	}

	// Two things inside the ICMP message change, and ICMP's checksum covers
	// both: the quoted address, and the quoted header's own checksum once it
	// is repaired. Missing the second is the kind of error that produces a
	// packet every implementation quietly discards.
	quotedSumBefore := binary.BigEndian.Uint16(quoted[offsetChecksum : offsetChecksum+2])

	new := translate(old, from, to)
	oldWord, newWord := word(old), word(new)

	nb := new.As4()
	copy(quoted[quotedOffset:quotedOffset+4], nb[:])
	fixChecksum(quoted[offsetChecksum:offsetChecksum+2], oldWord, newWord)

	quotedSumAfter := binary.BigEndian.Uint16(quoted[offsetChecksum : offsetChecksum+2])

	fixChecksum(icmp[2:4], oldWord, newWord)
	fix16(icmp[2:4], quotedSumBefore, quotedSumAfter)
}

// fixChecksum folds the difference between two 32-bit values into a one's
// complement checksum, the RFC 1624 way.
//
// Summing the whole packet again would also work and would be slower on every
// packet for the benefit of none.
func fixChecksum(field []byte, oldWord, newWord uint32) {
	fix16(field, uint16(oldWord>>16), uint16(newWord>>16))
	fix16(field, uint16(oldWord), uint16(newWord))
}

// fix16 is the same for a single 16-bit word: RFC 1624 equation 3,
// HC' = ~(~HC + ~m + m').
func fix16(field []byte, old, new uint16) {
	if old == new {
		return
	}

	sum := uint32(^binary.BigEndian.Uint16(field)) + uint32(^old) + uint32(new)
	for sum>>16 != 0 {
		sum = (sum & 0xFFFF) + (sum >> 16)
	}

	binary.BigEndian.PutUint16(field, ^uint16(sum))
}
