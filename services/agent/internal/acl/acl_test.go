package acl

import (
	"encoding/binary"
	"net/netip"
	"testing"
)

func port(n int) *int { return &n }

// ipv4 builds a datagram with the given protocol, addresses and ports.
func ipv4(proto uint8, src, dst string, srcPort, dstPort uint16) []byte {
	return ipv4Flags(proto, src, dst, srcPort, dstPort, 0)
}

// ipv4Flags builds a datagram with a full transport header. TCP needs 20
// bytes, not 8: the flags live at offset 13 and the filter reads them to
// retire a flow when the conversation ends.
func ipv4Flags(proto uint8, src, dst string, srcPort, dstPort uint16, flags uint8) []byte {
	b := make([]byte, 20+20)
	b[0] = 0x45
	b[9] = proto
	copy(b[12:16], netip.MustParseAddr(src).AsSlice())
	copy(b[16:20], netip.MustParseAddr(dst).AsSlice())
	binary.BigEndian.PutUint16(b[20:22], srcPort)
	binary.BigEndian.PutUint16(b[22:24], dstPort)
	b[33] = flags

	return b
}

func icmp(src, dst string) []byte {
	b := make([]byte, 20+8)
	b[20] = 8 // echo request
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

	// The request opens the flow.
	if v := table.Check(Outbound, ipv4Flags(ipProtoTCP, self, nvr, 40000, 554, tcpSYN)); !v.Allowed {
		t.Fatalf("precondition: the allowed request was blocked: %s", v.Reason)
	}

	// The reply belongs to it, and carries 554 as its source.
	v := table.Check(Inbound, ipv4(ipProtoTCP, nvr, self, 554, 40000))
	if !v.Allowed {
		t.Fatalf("the reply to an allowed connection was dropped: %s", v.Reason)
	}
	if v.Reason != "established flow" {
		t.Fatalf("the reply was allowed for the wrong reason: %s", v.Reason)
	}
}

// The bypass this filter was rewritten to close.
//
// "AK Support may reach the NVR on tcp/554" must not become "AK Support may
// reach anything on the NVR, provided it says the traffic came from port 554".
// Matching a rule against a source port made exactly that possible: bind 554
// locally and every port on the target is open.
func TestBindingTheServicePortAsSourceDoesNotOpenOtherPorts(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(nvr, []Filter{{Action: "allow", Protocol: ProtoTCP, PortFrom: port(554), RuleID: 7}})

	// Source port 554, destination port 80: a device trying to reach the NVR's
	// web interface while wearing the RTSP port as a disguise.
	v := table.Check(Outbound, ipv4Flags(ipProtoTCP, self, nvr, 554, 80, tcpSYN))
	if v.Allowed {
		t.Fatalf("binding the service port as a source reached a forbidden port: %s", v.Reason)
	}

	// And the same trick inbound, from a peer that has been let onto the
	// network but is not allowed at this port.
	v = table.Check(Inbound, ipv4Flags(ipProtoTCP, nvr, self, 554, 80, tcpSYN))
	if v.Allowed {
		t.Fatalf("an inbound packet claiming source port 554 reached port 80: %s", v.Reason)
	}
}

// A reply is only a reply if something asked for it. An unsolicited packet
// from the service port is not part of any flow and must be judged on the
// rules, which do not cover it.
func TestAnUnsolicitedPacketFromTheServicePortIsNotTreatedAsAReply(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(nvr, []Filter{{Action: "allow", Protocol: ProtoTCP, PortFrom: port(554), RuleID: 7}})

	v := table.Check(Inbound, ipv4(ipProtoTCP, nvr, self, 554, 40000))
	if v.Allowed {
		t.Fatalf("an unsolicited packet was accepted as a reply: %s", v.Reason)
	}
}

// A flow belongs to the pair that opened it. Another peer cannot ride it.
func TestAnotherPeerCannotUseAnEstablishedFlow(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(nvr, []Filter{{Action: "allow", Protocol: ProtoTCP, PortFrom: port(554), RuleID: 7}})
	table.Add(till, nil)

	table.Check(Outbound, ipv4Flags(ipProtoTCP, self, nvr, 40000, 554, tcpSYN))

	// The till reuses the exact ports of the open conversation.
	v := table.Check(Inbound, ipv4(ipProtoTCP, till, self, 554, 40000))
	if v.Reason == "established flow" {
		t.Fatal("a different peer was let in on someone else's flow")
	}
}

// A ping reply is attributable to the request by its echo identifier, so
// "allow icmp" works without also permitting unsolicited ICMP.
func TestAPingReplyBelongsToItsRequest(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(nvr, []Filter{{Action: "allow", Protocol: ProtoICMP, RuleID: 9}})

	if v := table.Check(Outbound, icmp(self, nvr)); !v.Allowed {
		t.Fatalf("the ping was blocked: %s", v.Reason)
	}

	reply := icmp(nvr, self)
	reply[20] = 0 // echo reply
	if v := table.Check(Inbound, reply); !v.Allowed {
		t.Fatalf("the ping reply was blocked: %s", v.Reason)
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

// mustAddr is a test helper for addresses that are known good.
func mustAddr(s string) netip.Addr { return netip.MustParseAddr(s) }
