// Package acl enforces the panel's access rules on the device.
//
// The panel authors rules; it never enforces them. Enforcement happens here,
// on both endpoints of every conversation, because a filter that runs on only
// one side is a filter an attacker can choose not to run. A device with a
// tampered agent still cannot reach a service the *other* device's agent
// refuses to deliver.
//
// This sits above WireGuard rather than replacing any of it. WireGuard's
// allowed_ips already decides which peer may claim which address, and a peer
// the panel denied outright is never given a key at all. What is left for this
// package is the narrower question the panel expresses as port and protocol
// rules: given that these two devices may talk, may *this packet* pass.
package acl

import (
	"net/netip"
	"sort"
	"strings"
)

// Protocol is the transport a rule applies to.
type Protocol string

const (
	ProtoAny  Protocol = "any"
	ProtoTCP  Protocol = "tcp"
	ProtoUDP  Protocol = "udp"
	ProtoICMP Protocol = "icmp"
)

// IP protocol numbers, for reading the header rather than guessing from it.
const (
	ipProtoICMP = 1
	ipProtoTCP  = 6
	ipProtoUDP  = 17
)

// Filter is one compiled rule, as the panel's AclService emits it.
type Filter struct {
	// Action is "allow" or "deny".
	Action string `json:"action"`
	// Protocol is any, tcp, udp or icmp.
	Protocol Protocol `json:"protocol"`
	// PortFrom and PortTo bound the service port, inclusive. Nil means the
	// rule is about the protocol and not about any particular port.
	PortFrom *int `json:"port_from"`
	PortTo   *int `json:"port_to"`
	// RuleID is the panel's row, carried so a dropped packet can be explained
	// by pointing at the rule that dropped it.
	RuleID int `json:"rule_id"`
}

// Verdict is what the filter set decided, and why.
type Verdict struct {
	Allowed bool
	// RuleID is the rule responsible, or 0 when the decision came from the
	// absence of rules rather than from one.
	RuleID int
	Reason string
}

// packet is the part of an IP datagram a rule can be about.
type packet struct {
	proto   uint8
	src     netip.Addr
	dst     netip.Addr
	srcPort uint16
	dstPort uint16
	// hasPorts is false for ICMP and for a fragment that does not carry the
	// transport header. A rule about ports cannot match what has no ports.
	hasPorts bool
}

// Decide applies a peer's filter set to one packet.
//
// Rules are evaluated deny-first, then allow. The panel has already ordered
// them by priority and resolved which apply to this pair, so what is left here
// is the per-packet question, and the safe reading of "some rules matched,
// none of them this packet" is to drop.
//
// An empty filter set means the panel placed no port restriction on this peer:
// the peer-level decision already allowed it, so the packet passes.
func Decide(filters []Filter, p packet) Verdict {
	if len(filters) == 0 {
		return Verdict{Allowed: true, Reason: "no port restrictions for this peer"}
	}

	// A deny is checked first and wins outright. Evaluating allows first would
	// let a broad allow shadow a narrow deny, which reverses what an operator
	// writing "allow the subnet, deny the database port" means.
	for _, f := range filters {
		if f.Action == "deny" && matches(f, p) {
			return Verdict{Allowed: false, RuleID: f.RuleID, Reason: "denied by rule"}
		}
	}

	allows := 0
	for _, f := range filters {
		if f.Action != "allow" {
			continue
		}
		allows++
		if matches(f, p) {
			return Verdict{Allowed: true, RuleID: f.RuleID, Reason: "allowed by rule"}
		}
	}

	if allows == 0 {
		// Only denies were configured and none matched, so nothing here has an
		// opinion about this packet and the peer-level allow stands.
		return Verdict{Allowed: true, Reason: "no allow rules; peer-level decision stands"}
	}

	return Verdict{Allowed: false, Reason: "no allow rule covers this packet"}
}

// matches reports whether one rule is about this packet.
//
// Ports are matched in **either** direction. A rule saying "support may reach
// the NVR on TCP 554" has to permit the NVR's replies too, and those carry 554
// as the *source* port. Without connection tracking — which is a lot of state
// to keep on a shop PC, and a lot of ways to leak it — matching either end is
// what makes a stateless filter usable. It is more permissive than a stateful
// firewall: a packet that merely originates from port 554 also matches. That
// is a deliberate trade and it is written down rather than discovered.
func matches(f Filter, p packet) bool {
	if !protocolMatches(f.Protocol, p.proto) {
		return false
	}

	if f.PortFrom == nil {
		return true
	}

	if !p.hasPorts {
		// A rule about ports cannot be about a packet that has none.
		return false
	}

	from := *f.PortFrom
	to := from
	if f.PortTo != nil {
		to = *f.PortTo
	}
	if to < from {
		from, to = to, from
	}

	return inRange(int(p.dstPort), from, to) || inRange(int(p.srcPort), from, to)
}

func protocolMatches(want Protocol, got uint8) bool {
	switch strings.ToLower(string(want)) {
	case "", string(ProtoAny):
		return true
	case string(ProtoTCP):
		return got == ipProtoTCP
	case string(ProtoUDP):
		return got == ipProtoUDP
	case string(ProtoICMP):
		return got == ipProtoICMP
	default:
		// An unknown protocol name matches nothing. Treating it as "any" would
		// turn a typo in the panel into an open door.
		return false
	}
}

func inRange(port, from, to int) bool { return port >= from && port <= to }

// Table is the per-peer filter set, keyed by the peer's overlay address.
//
// Built once per configuration revision and replaced wholesale, so a rule
// change takes effect on the next packet rather than the next reconnection.
type Table struct {
	byPeer map[netip.Addr][]Filter
	// known is every address this device may exchange traffic with. A packet
	// to or from anything else is dropped: WireGuard's allowed_ips already
	// refuses it cryptographically, and checking again here costs one map
	// lookup and closes the gap if a peer is ever added without one.
	known map[netip.Addr]struct{}
}

// NewTable compiles a table from the panel's peer list.
func NewTable() *Table {
	return &Table{
		byPeer: make(map[netip.Addr][]Filter),
		known:  make(map[netip.Addr]struct{}),
	}
}

// Add records one peer's address and the filters that apply to it.
func (t *Table) Add(virtualIP string, filters []Filter) {
	addr, err := netip.ParseAddr(strings.TrimSpace(virtualIP))
	if err != nil {
		return
	}
	addr = addr.Unmap()

	t.known[addr] = struct{}{}
	if len(filters) > 0 {
		// Sorted so the rule quoted in a log line is stable between runs,
		// which matters when an operator is comparing two devices.
		sorted := append([]Filter(nil), filters...)
		sort.SliceStable(sorted, func(i, j int) bool { return sorted[i].RuleID < sorted[j].RuleID })
		t.byPeer[addr] = sorted
	}
}

// AddSelf records this device's own overlay address, which is never filtered
// against: traffic to and from ourselves is not a conversation with a peer.
func (t *Table) AddSelf(virtualIP string) {
	if addr, err := netip.ParseAddr(strings.TrimSpace(virtualIP)); err == nil {
		t.known[addr.Unmap()] = struct{}{}
	}
}

// Peers is how many peers the table covers, for the status file.
func (t *Table) Peers() int { return len(t.known) }

// Restricted is how many of them carry port rules.
func (t *Table) Restricted() int { return len(t.byPeer) }
