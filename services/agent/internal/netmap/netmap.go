// Package netmap rewrites one IPv4 prefix to another, host part preserved.
//
// It exists because of a fact about the Indian market rather than a fact about
// networking: nearly every router sold here hands out 192.168.1.0/24 or
// 192.168.0.0/24. A technician's laptop on one of those cannot reach a
// customer whose LAN is the same range — one routing table holds one route per
// destination — and the honest answer the agent used to give, "route refused,
// renumber one side", is not an answer a business can give its customers.
//
// So the overlay never carries the customer's real range. The panel gives each
// advertised LAN a unique prefix, and the gateway swaps one for the other:
// 192.168.1.50 at the hotel is reached at 10.201.5.50.
//
// The mapping is arithmetic, not a table. The mapped address is the host part
// of the real one grafted onto the mapped prefix, so a LAN with two hundred
// cameras costs exactly what a LAN with one costs, and nothing has to be
// remembered between packets.
//
// # Why this is here and not in iptables
//
// Linux has NETMAP and it does exactly this. Windows has nothing equivalent —
// New-NetNat is many-to-one masquerading and Add-NetNatStaticMapping is
// per-port forwarding, neither of which maps a prefix. Doing it in the agent
// gives one implementation that behaves identically on both, at the one point
// in the path where packets are already plaintext and already ours.
package netmap

import (
	"encoding/binary"
	"net/netip"
	"sort"
	"strings"
)

// Mapping is one advertised LAN: what the overlay calls it, and what it is.
type Mapping struct {
	// Mapped is the prefix the overlay uses, unique within the network.
	Mapped netip.Prefix
	// Real is the prefix on the gateway's own LAN.
	Real netip.Prefix
}

// Table is the set of mappings a gateway applies.
//
// Empty on every device that is not a subnet router, which is almost all of
// them, and an empty table costs one nil check per packet.
type Table struct {
	mappings []Mapping
}

// NewTable builds a table from pairs of prefixes.
//
// A pair whose two halves are different sizes is dropped rather than
// approximated: mapping a /24 onto a /25 would silently put half the LAN
// somewhere else.
func NewTable() *Table { return &Table{} }

// Add records one mapping. Prefixes that will not parse are ignored, because a
// gateway that refused to start over one bad route would take a working site
// down for a configuration error somewhere else in the panel.
func (t *Table) Add(mapped, real string) {
	m, err := netip.ParsePrefix(strings.TrimSpace(mapped))
	if err != nil || !m.Addr().Is4() {
		return
	}

	r, err := netip.ParsePrefix(strings.TrimSpace(real))
	if err != nil || !r.Addr().Is4() {
		return
	}

	if m.Bits() != r.Bits() {
		return
	}

	if m.Masked() == r.Masked() {
		// Identity. Nothing to rewrite, and recording it would put every
		// packet for that prefix through the checksum arithmetic for no
		// reason.
		return
	}

	t.mappings = append(t.mappings, Mapping{Mapped: m.Masked(), Real: r.Masked()})

	// Longest first, so a mapping for one machine beats one for the subnet it
	// sits in, the same way routing does.
	sort.SliceStable(t.mappings, func(i, j int) bool {
		return t.mappings[i].Mapped.Bits() > t.mappings[j].Mapped.Bits()
	})
}

// Len is how many mappings this table holds.
func (t *Table) Len() int {
	if t == nil {
		return 0
	}

	return len(t.mappings)
}

// Mappings is what the table holds, for logging.
func (t *Table) Mappings() []Mapping {
	if t == nil {
		return nil
	}

	return append([]Mapping(nil), t.mappings...)
}

// Real turns an address the overlay uses into the one on this gateway's own
// LAN, when this gateway is the one that advertises it.
//
// Exported for the reachability test. A gateway asked to check the router on
// the network it shares was sending to the MAPPED address — 10.128.5.1 rather
// than 192.168.10.1 — and its own machine has no route for that: the mapping
// is applied to traffic arriving FROM the overlay, not to traffic the gateway
// itself originates. The router was reported unreachable while a browser on
// the same machine was showing its login page.
func (t *Table) Real(addr netip.Addr) (netip.Addr, bool) {
	mapped, real, ok := t.toReal(addr)
	if !ok {
		return netip.Addr{}, false
	}

	return translate(addr, mapped, real), true
}

// addressedToReal reports whether a packet from a peer names a translated
// LAN by its REAL address instead of the mapped one.
//
// No client is ever given a route to a real range — only the mapped one — so
// such a packet was built by hand to go around the mapping. And going around
// the mapping is going around the rules: the access rules are compiled against
// mapped addresses and enforced above this layer, so the filter judged a
// packet to 192.168.10.50 by the peer's rules for the gateway PC itself, not
// by the route's rules for the LAN. The gateway (the agent's own NAT, or
// iptables) then delivered it.
func (t *Table) addressedToReal(pkt []byte) bool {
	if len(t.mappings) == 0 || len(pkt) < ipv4MinHeader || pkt[0]>>4 != 4 {
		return false
	}
	dst := netip.AddrFrom4([4]byte(pkt[16:20]))
	if _, _, ok := t.toReal(dst); ok {
		return false // the mapped address: the legitimate path
	}
	_, _, ok := t.toMapped(dst)

	return ok
}

// toReal finds the mapping covering an address the overlay used.
func (t *Table) toReal(addr netip.Addr) (netip.Prefix, netip.Prefix, bool) {
	for _, m := range t.mappings {
		if m.Mapped.Contains(addr) {
			return m.Mapped, m.Real, true
		}
	}

	return netip.Prefix{}, netip.Prefix{}, false
}

// toMapped finds the mapping covering an address on the real LAN.
func (t *Table) toMapped(addr netip.Addr) (netip.Prefix, netip.Prefix, bool) {
	for _, m := range t.mappings {
		if m.Real.Contains(addr) {
			return m.Real, m.Mapped, true
		}
	}

	return netip.Prefix{}, netip.Prefix{}, false
}

// translate grafts an address's host part onto another prefix of the same size.
func translate(addr netip.Addr, from, to netip.Prefix) netip.Addr {
	a := word(addr)
	t := word(to.Addr())

	var mask uint32 = 0xFFFFFFFF
	if from.Bits() < 32 {
		mask = ^(uint32(1)<<(32-from.Bits()) - 1)
	}

	var buf [4]byte
	binary.BigEndian.PutUint32(buf[:], (t&mask)|(a&^mask))

	return netip.AddrFrom4(buf)
}

func word(addr netip.Addr) uint32 {
	b := addr.As4()

	return binary.BigEndian.Uint32(b[:])
}
