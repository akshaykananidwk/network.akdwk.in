package acl

import (
	"encoding/binary"
	"net/netip"
	"testing"
)

func port(n int) *int { return &n }

// ipv4 builds a datagram with the given protocol, addresses and ports.
func ipv4(proto uint8, src, dst string, srcPort, dstPort uint16) []byte {
	b := make([]byte, 20+8)
	b[0] = 0x45
	b[9] = proto
	copy(b[12:16], netip.MustParseAddr(src).AsSlice())
	copy(b[16:20], netip.MustParseAddr(dst).AsSlice())
	binary.BigEndian.PutUint16(b[20:22], srcPort)
	binary.BigEndian.PutUint16(b[22:24], dstPort)

	return b
}

func icmp(src, dst string) []byte {
	b := make([]byte, 20+8)
	b[0] = 0x45
	b[9] = ipProtoICMP
	copy(b[12:16], netip.MustParseAddr(src).AsSlice())
	copy(b[16:20], netip.MustParseAddr(dst).AsSlice())

	return b
}

const (
	self = "10.99.0.2"
	nvr  = "10.99.0.3"
	till = "10.99.0.4"
)

// §36: AK Support may reach the hotel's server, NVR and reception PC and
// nothing else. The "nothing else" half is the one worth testing, because it
// is the half a customer is trusting.
func TestAPeerTheOperatorNeverAllowedIsDropped(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(nvr, nil)

	v := table.Check(Outbound, ipv4(ipProtoTCP, self, till, 40000, 445))
	if v.Allowed {
		t.Fatalf("reached a device that is not a permitted peer: %s", v.Reason)
	}
}

// A peer with no port rules is reachable: the panel's peer-level decision has
// already been made and this layer has nothing to add.
func TestAPeerWithNoPortRulesIsReachable(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(nvr, nil)

	if v := table.Check(Outbound, ipv4(ipProtoTCP, self, nvr, 40000, 445)); !v.Allowed {
		t.Fatalf("an unrestricted peer was blocked: %s", v.Reason)
	}
}

// An allow rule naming a port is also a denial of every other port.
func TestAnAllowOnOnePortBlocksTheOthers(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(nvr, []Filter{{Action: "allow", Protocol: ProtoTCP, PortFrom: port(554), RuleID: 7}})

	if v := table.Check(Outbound, ipv4(ipProtoTCP, self, nvr, 40000, 554)); !v.Allowed {
		t.Fatalf("the allowed port was blocked: %s", v.Reason)
	}
	if v := table.Check(Outbound, ipv4(ipProtoTCP, self, nvr, 40000, 445)); v.Allowed {
		t.Fatal("a port outside the allow rule was permitted")
	}
}

// The reply to an allowed connection carries the service port as its *source*,
// so a filter that only looked at the destination would allow the request and
// silently break every answer to it.
func TestRepliesToAnAllowedPortAreNotBlocked(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(nvr, []Filter{{Action: "allow", Protocol: ProtoTCP, PortFrom: port(554), RuleID: 7}})

	v := table.Check(Inbound, ipv4(ipProtoTCP, nvr, self, 554, 40000))
	if !v.Allowed {
		t.Fatalf("the reply to an allowed connection was dropped: %s", v.Reason)
	}
}

// A deny beats an allow that would otherwise cover the same packet. An
// operator writing "allow the subnet, deny the database port" means the deny.
func TestADenyBeatsABroaderAllow(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(nvr, []Filter{
		{Action: "allow", Protocol: ProtoAny, RuleID: 1},
		{Action: "deny", Protocol: ProtoTCP, PortFrom: port(3306), RuleID: 2},
	})

	if v := table.Check(Outbound, ipv4(ipProtoTCP, self, nvr, 40000, 3306)); v.Allowed {
		t.Fatal("a broad allow shadowed a narrower deny")
	}
	if v := table.Check(Outbound, ipv4(ipProtoTCP, self, nvr, 40000, 80)); !v.Allowed {
		t.Fatalf("the deny blocked more than it named: %s", v.Reason)
	}
}

// Ping is the first thing anyone tries, and it has no ports at all. A rule
// about TCP ports must not accidentally permit it.
func TestAPortRuleDoesNotPermitPing(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(nvr, []Filter{{Action: "allow", Protocol: ProtoTCP, PortFrom: port(554), RuleID: 7}})

	if v := table.Check(Outbound, icmp(self, nvr)); v.Allowed {
		t.Fatal("a TCP port rule allowed an ICMP packet through")
	}
}

// And an explicit ICMP allow does permit it, or "allow ping for diagnostics"
// would be unexpressible.
func TestAnIcmpRuleAllowsPing(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(nvr, []Filter{{Action: "allow", Protocol: ProtoICMP, RuleID: 9}})

	if v := table.Check(Outbound, icmp(self, nvr)); !v.Allowed {
		t.Fatalf("an explicit ICMP allow did not permit ping: %s", v.Reason)
	}
}

// A protocol name the agent does not recognise must match nothing. Treating it
// as "any" would turn a typo in the panel into an open door.
func TestAnUnknownProtocolMatchesNothing(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(nvr, []Filter{{Action: "allow", Protocol: Protocol("sctp"), RuleID: 3}})

	if v := table.Check(Outbound, ipv4(ipProtoTCP, self, nvr, 40000, 80)); v.Allowed {
		t.Fatal("an unrecognised protocol name behaved like 'any'")
	}
}

// A packet the filter cannot read is dropped rather than guessed at.
func TestUnparseablePacketsAreDropped(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(nvr, nil)

	for name, raw := range map[string][]byte{
		"too short":       {0x45, 0, 0},
		"unknown version": append([]byte{0x75}, make([]byte, 30)...),
		"empty":           {},
	} {
		if v := table.Check(Outbound, raw); v.Allowed {
			t.Fatalf("%s: an unreadable packet was allowed through", name)
		}
	}
}

// A port range covers its endpoints.
func TestAPortRangeIsInclusive(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(nvr, []Filter{
		{Action: "allow", Protocol: ProtoTCP, PortFrom: port(8000), PortTo: port(8010), RuleID: 4},
	})

	for _, p := range []uint16{8000, 8005, 8010} {
		if v := table.Check(Outbound, ipv4(ipProtoTCP, self, nvr, 40000, p)); !v.Allowed {
			t.Fatalf("port %d inside the range was blocked: %s", p, v.Reason)
		}
	}
	for _, p := range []uint16{7999, 8011} {
		if v := table.Check(Outbound, ipv4(ipProtoTCP, self, nvr, 40000, p)); v.Allowed {
			t.Fatalf("port %d outside the range was allowed", p)
		}
	}
}

// A rule set containing only denies leaves everything else alone: the
// peer-level allow already decided the rest.
func TestOnlyDenyRulesLeaveTheRestAllowed(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(nvr, []Filter{{Action: "deny", Protocol: ProtoTCP, PortFrom: port(22), RuleID: 5}})

	if v := table.Check(Outbound, ipv4(ipProtoTCP, self, nvr, 40000, 22)); v.Allowed {
		t.Fatal("the denied port was allowed")
	}
	if v := table.Check(Outbound, ipv4(ipProtoTCP, self, nvr, 40000, 80)); !v.Allowed {
		t.Fatalf("a deny on one port blocked another: %s", v.Reason)
	}
}

// A fragment that carries no transport header cannot satisfy a port rule.
// Allowing it would let anyone bypass a port filter by fragmenting.
func TestAFragmentWithoutPortsCannotSatisfyAPortRule(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(nvr, []Filter{{Action: "allow", Protocol: ProtoTCP, PortFrom: port(554), RuleID: 7}})

	raw := ipv4(ipProtoTCP, self, nvr, 40000, 554)
	// A non-zero fragment offset: the ports in the buffer are not really ports.
	binary.BigEndian.PutUint16(raw[6:8], 0x00b9)

	if v := table.Check(Outbound, raw); v.Allowed {
		t.Fatal("a fragment with no transport header satisfied a port rule")
	}
}
