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

	if !p.readTransport(b[headerLen:]) {
		return packet{}, false
	}

	return p, true
}

func parseV6(b []byte) (packet, bool) {
	const fixedHeader = 40
	if len(b) < fixedHeader {
		return packet{}, false
	}

	var p packet
	p.proto = b[6]
	p.src = netip.AddrFrom16([16]byte(b[8:24]))
	p.dst = netip.AddrFrom16([16]byte(b[24:40]))

	// Extension headers are not walked, so a packet wearing them is one this
	// filter cannot read down to its transport header — and an unreadable
	// packet is refused, not guessed at. Letting it through because "no ports
	// means no port rule matches" would be a bypass: anyone could put a
	// hop-by-hop header in front of a TCP segment and walk past a port rule.
	//
	// Failing closed costs correctness only for traffic the overlay does not
	// carry today. Walking the chain properly is the fix when it does; until
	// then the safe answer is the honest one.
	if isIPv6Extension(p.proto) {
		return packet{}, false
	}

	if !p.readTransport(b[fixedHeader:]) {
		return packet{}, false
	}

	return p, true
}

// isIPv6Extension reports whether a next-header value introduces an extension
// header rather than a transport protocol.
func isIPv6Extension(next uint8) bool {
	switch next {
	case 0, // hop-by-hop options
		43,  // routing
		44,  // fragment
		50,  // encapsulating security payload
		51,  // authentication header
		60,  // destination options
		135, // mobility
		139, // host identity protocol
		140: // shim6
		return true
	default:
		return false
	}
}

// readTransport fills in the ports, TCP flags or ICMP identifier.
//
// It reports false when the header is there but too short to read, which is a
// malformed packet rather than one without ports — and a malformed packet is
// dropped. A truncated TCP header that was treated as "no ports" would match
// no port rule and could still be forwarded by a deny-only rule set.
func (p *packet) readTransport(rest []byte) bool {
	switch p.proto {
	case ipProtoTCP:
		// Ports at 0..3, flags in the low six bits of byte 13.
		if len(rest) < 14 {
			return false
		}
		p.srcPort = binary.BigEndian.Uint16(rest[0:2])
		p.dstPort = binary.BigEndian.Uint16(rest[2:4])
		p.tcpFlags = rest[13]
		p.hasPorts = true

		return true

	case ipProtoUDP:
		if len(rest) < 4 {
			return false
		}
		p.srcPort = binary.BigEndian.Uint16(rest[0:2])
		p.dstPort = binary.BigEndian.Uint16(rest[2:4])
		p.hasPorts = true

		return true

	case ipProtoICMP, ipProtoICMPv6:
		// An echo carries an identifier that a reply repeats, which is what
		// lets a ping reply be attributed to the request that asked for it.
		// Anything else — unreachable, time exceeded — has no identifier and
		// is matched on protocol alone.
		if len(rest) >= 8 && isEcho(p.proto, rest[0]) {
			p.icmpID = binary.BigEndian.Uint16(rest[4:6])
		}

		return true

	default:
		// A protocol with no ports at all. Readable, and only matchable by a
		// rule that names no port.
		return true
	}
}

// isEcho reports whether an ICMP type is an echo request or reply.
func isEcho(proto, icmpType uint8) bool {
	if proto == ipProtoICMPv6 {
		return icmpType == 128 || icmpType == 129
	}

	return icmpType == 0 || icmpType == 8
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

	filters, ok := t.filtersFor(dir, peer, p)
	if !ok {
		// Neither a peer this device was told about nor an address behind a
		// gateway that advertised a route for it. WireGuard's allowed_ips
		// refuses the first case cryptographically already; this is the same
		// answer reached a second way, and it costs one map lookup.
		return Verdict{Allowed: false, Reason: "not a permitted peer or routed destination"}
	}

	// A packet belonging to a conversation the rules already permitted passes
	// without being re-judged. This is what allows replies: a reply carries
	// the service port as its *source*, which no rule matches, and matching it
	// on that basis is the bypass this table exists to close.
	if t.flows != nil && t.flows.belongsToFlow(dir, p) {
		return Verdict{Allowed: true, Reason: "established flow"}
	}

	// Forwarding is one-way. A gateway exists so the overlay can reach the
	// site's LAN, not so the site's LAN can reach the overlay: the machines
	// behind it are the ones nobody could put an agent on, which is usually
	// because nobody can patch them either. An NVR running five-year-old
	// firmware must not be a route into every customer this laptop supports.
	//
	// After the flow check, deliberately — answers to conversations the
	// overlay started are not the LAN opening anything.
	if dir == Outbound && t.servesPrefix(p.src.Unmap()) {
		return Verdict{
			Allowed: false,
			Reason:  "a machine behind this gateway may not open a connection into the overlay",
		}
	}

	verdict := Decide(filters, p)
	if verdict.Allowed && t.flows != nil {
		t.flows.open(dir, p)
	}

	return verdict
}

// filtersFor returns the rules governing traffic to or from one address, and
// whether this device is permitted to exchange traffic with it at all.
//
// A peer is looked up directly. Anything else may still be a machine behind a
// gateway — an NVR or a printer that cannot run an agent — in which case the
// route that covers it decides, and the most specific route wins.
func (t *Table) filtersFor(dir Direction, addr netip.Addr, p packet) ([]Filter, bool) {
	// Traffic arriving from a peer and addressed into a LAN this device is the
	// gateway for is traffic we are about to forward on that peer's behalf. The
	// rules that govern it are the ones about the machine being reached, not
	// the ones about the link the packet came in on — "AK Support may reach the
	// NVR on tcp/554" says nothing about the reception PC doing the routing,
	// and judging the packet by the PC's filters is how a modified support
	// agent would reach every other port on the recorder.
	if dir == Inbound && len(t.served) > 0 {
		if _, known := t.known[addr]; known {
			if filters, ok := t.servedRouteFor(addr, p.dst.Unmap()); ok {
				return filters, true
			}
		}
	}

	if _, known := t.known[addr]; known {
		return t.byPeer[addr], true
	}

	if route, ok := t.routeFor(addr); ok {
		return route.Filters, true
	}

	return nil, false
}
