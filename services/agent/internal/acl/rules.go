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
	ipProtoICMP   = 1
	ipProtoTCP    = 6
	ipProtoUDP    = 17
	ipProtoICMPv6 = 58
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
	// tcpFlags carries SYN/FIN/RST so a flow can be retired when the
	// conversation ends rather than when a timer says so.
	tcpFlags uint8
	// icmpID is the echo identifier, which is what makes a ping reply
	// attributable to the request that asked for it.
	icmpID uint16
}

// TCP flags this filter cares about.
const (
	tcpFIN uint8 = 0x01
	tcpSYN uint8 = 0x02
	tcpRST uint8 = 0x04
)

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
// The **destination** port only. A rule saying "support may reach the NVR on
// tcp/554" is about the port being connected *to*; 554 appearing as a source
// port says nothing about what the packet is for.
//
// An earlier version matched either end, so that replies — which carry the
// service port as their source — would be permitted without keeping state.
// That was a bypass, not a trade-off: a device need only bind source port 554
// to reach every port on the target, and the rule then permits exactly what it
// was written to forbid. Replies are now recognised by the flow they belong to
// (see conntrack.go), which is the only way to tell a reply from a packet that
// has been dressed up as one.
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

	return inRange(int(p.dstPort), from, to)
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
		return got == ipProtoICMP || got == ipProtoICMPv6
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
	// flows remembers conversations the rules permitted, so a reply is
	// recognised by the flow it belongs to rather than by its port numbers.
	flows *conntrack
	// routes are prefixes a gateway peer advertised, for machines that cannot
	// run an agent. Sorted longest-prefix-first.
	routes []Route
	// self is this device's own overlay address, needed to tell which end of
	// a routed packet is the far one.
	self netip.Addr
}

// NewTable compiles a table from the panel's peer list.
func NewTable() *Table {
	return &Table{
		byPeer: make(map[netip.Addr][]Filter),
		known:  make(map[netip.Addr]struct{}),
		flows:  newConntrack(maxFlows),
	}
}

// AdoptFlows carries the flow table across a configuration change.
//
// A rule change must not drop conversations the previous rules permitted and
// the new ones still do — an operator editing an unrelated rule would
// otherwise reset every open connection on every device. Flows opened under
// the old rules stay open; new ones are judged by the new rules.
func (t *Table) AdoptFlows(previous *Table) {
	if previous != nil && previous.flows != nil {
		t.flows = previous.flows
	}
}

// Flows is how many conversations are currently tracked.
func (t *Table) Flows() int {
	if t.flows == nil {
		return 0
	}

	return t.flows.Flows()
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
		t.self = addr.Unmap()
		t.known[t.self] = struct{}{}
	}
}

// Peers is how many peers the table covers, for the status file.
func (t *Table) Peers() int { return len(t.known) }

// Restricted is how many of them carry port rules.
func (t *Table) Restricted() int { return len(t.byPeer) }
