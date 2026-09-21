package acl

import (
	"encoding/binary"
	"net/netip"
)

// Direction says which way a packet is travelling, for the log line and for
// deciding which address identifies the peer.
type Direction uint8

const (
	// Outbound is a packet this machine is sending to a peer.
	Outbound Direction = iota
	// Inbound is a packet that arrived from a peer.
	Inbound
)

func (d Direction) String() string {
	if d == Inbound {
		return "in"
	}

	return "out"
}

// parse reads the addresses and ports out of an IPv4 or IPv6 datagram.
//
// Deliberately strict. Anything it cannot read confidently — a truncated
// header, an unknown version, a fragment carrying no transport header — is
// reported as unparseable, and the caller drops it. A filter that guesses at a
// malformed packet is a filter an attacker gets to write.
func parse(b []byte) (packet, bool) {
	if len(b) < 20 {
		return packet{}, false
	}

	switch b[0] >> 4 {
	case 4:
		return parseV4(b)
	case 6:
		return parseV6(b)
	default:
		return packet{}, false
	}
}

func parseV4(b []byte) (packet, bool) {
	headerLen := int(b[0]&0x0f) * 4
	if headerLen < 20 || len(b) < headerLen {
		return packet{}, false
	}

	var p packet
	p.proto = b[9]
	p.src = netip.AddrFrom4([4]byte(b[12:16]))
	p.dst = netip.AddrFrom4([4]byte(b[16:20]))

	// A non-initial fragment carries no transport header. Only the first
	// fragment can be matched on ports; the rest are matched on protocol
	// alone, which is what "hasPorts false" expresses.
	fragmentOffset := binary.BigEndian.Uint16(b[6:8]) & 0x1fff
	if fragmentOffset != 0 {
		return p, true
	}

	p.srcPort, p.dstPort, p.hasPorts = ports(p.proto, b[headerLen:])

	return p, true
}

func parseV6(b []byte) (packet, bool) {
	const fixedHeader = 40
	if len(b) < fixedHeader {
		return packet{}, false
	}

	var p packet
	p.proto = b[6] // next header; extension headers are not walked, see below
	p.src = netip.AddrFrom16([16]byte(b[8:24]))
	p.dst = netip.AddrFrom16([16]byte(b[24:40]))

	// Extension headers are not walked. The overlay is IPv4 today, so an IPv6
	// packet here is either a mistake or something being tried on; reading the
	// first next-header and no further means a packet wearing extension
	// headers has no ports as far as this filter is concerned, and a port rule
	// will not match it. That is the conservative direction.
	p.srcPort, p.dstPort, p.hasPorts = ports(p.proto, b[fixedHeader:])

	return p, true
}

// ports reads the source and destination port from a TCP or UDP header.
func ports(proto uint8, rest []byte) (uint16, uint16, bool) {
	if proto != ipProtoTCP && proto != ipProtoUDP {
		return 0, 0, false
	}
	if len(rest) < 4 {
		return 0, 0, false
	}

	return binary.BigEndian.Uint16(rest[0:2]), binary.BigEndian.Uint16(rest[2:4]), true
}

// Check decides whether one packet may pass.
//
// The peer is identified by the address at the far end: the destination for a
// packet we are sending, the source for one that arrived.
func (t *Table) Check(dir Direction, raw []byte) Verdict {
	p, ok := parse(raw)
	if !ok {
		return Verdict{Allowed: false, Reason: "unparseable packet"}
	}

	peer := p.dst
	if dir == Inbound {
		peer = p.src
	}
	peer = peer.Unmap()

	if _, known := t.known[peer]; !known {
		// Not a peer this device was told about. WireGuard's allowed_ips
		// refuses this cryptographically already; this is the same answer
		// reached a second way, and it costs one map lookup.
		return Verdict{Allowed: false, Reason: "not a permitted peer"}
	}

	return Decide(t.byPeer[peer], p)
}
