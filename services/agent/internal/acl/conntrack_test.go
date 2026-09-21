package acl

import (
	"fmt"
	"net/netip"
	"runtime"
	"testing"
	"time"
)

func trackedTable(max int) *Table {
	t := NewTable()
	t.AddSelf(self)
	t.Add(nvr, []Filter{{Action: "allow", Protocol: ProtoTCP, PortFrom: port(554), RuleID: 7}})
	t.flows = newConntrack(max)

	return t
}

// A flow must not outlive the conversation. An idle UDP flow is gone within
// its timeout, and a table that kept them would be a slow memory leak on a
// device that is left running for months.
func TestAnIdleUdpFlowExpires(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(nvr, []Filter{{Action: "allow", Protocol: ProtoUDP, PortFrom: port(53), RuleID: 3}})

	clock := time.Now()
	table.flows.now = func() time.Time { return clock }

	table.Check(Outbound, ipv4(ipProtoUDP, self, nvr, 40000, 53))
	if table.Flows() != 1 {
		t.Fatalf("the allowed request did not open a flow (%d tracked)", table.Flows())
	}

	// The reply arrives inside the window.
	clock = clock.Add(udpIdleTimeout / 2)
	if v := table.Check(Inbound, ipv4(ipProtoUDP, nvr, self, 53, 40000)); !v.Allowed {
		t.Fatalf("a reply inside the timeout was dropped: %s", v.Reason)
	}

	// And a late one does not.
	clock = clock.Add(udpIdleTimeout * 2)
	if v := table.Check(Inbound, ipv4(ipProtoUDP, nvr, self, 53, 40000)); v.Allowed {
		t.Fatalf("a reply long after the flow expired was accepted: %s", v.Reason)
	}
}

// A TCP conversation that is closed properly releases its entry quickly,
// rather than sitting at the idle timeout for an hour.
func TestAClosedTcpFlowIsRetiredQuickly(t *testing.T) {
	table := trackedTable(maxFlows)

	clock := time.Now()
	table.flows.now = func() time.Time { return clock }

	table.Check(Outbound, ipv4Flags(ipProtoTCP, self, nvr, 40000, 554, tcpSYN))
	table.Check(Outbound, ipv4Flags(ipProtoTCP, self, nvr, 40000, 554, tcpFIN))

	// Well before the idle timeout, but after the closing one.
	clock = clock.Add(tcpClosingTimeout * 2)
	if v := table.Check(Inbound, ipv4(ipProtoTCP, nvr, self, 554, 40000)); v.Allowed {
		t.Fatalf("a closed flow still accepted traffic: %s", v.Reason)
	}
}

// A reset ends it immediately too.
func TestAResetRetiresAFlow(t *testing.T) {
	table := trackedTable(maxFlows)

	clock := time.Now()
	table.flows.now = func() time.Time { return clock }

	table.Check(Outbound, ipv4Flags(ipProtoTCP, self, nvr, 40000, 554, tcpSYN))
	table.Check(Inbound, ipv4Flags(ipProtoTCP, nvr, self, 554, 40000, tcpRST))

	clock = clock.Add(tcpClosingTimeout * 2)
	if v := table.Check(Inbound, ipv4(ipProtoTCP, nvr, self, 554, 40000)); v.Allowed {
		t.Fatalf("a reset flow still accepted traffic: %s", v.Reason)
	}
}

// The table is bounded. A peer that is allowed to send traffic must not be
// able to make this device allocate without limit.
func TestTheFlowTableIsBoundedAndEvictsTheOldest(t *testing.T) {
	const max = 64
	table := trackedTable(max)

	for i := 0; i < max*4; i++ {
		table.Check(Outbound, ipv4Flags(ipProtoTCP, self, nvr, uint16(10000+i), 554, tcpSYN))
	}

	if got := table.Flows(); got > max {
		t.Fatalf("the table grew to %d flows, bound is %d", got, max)
	}

	// The most recent conversation survived; the first did not.
	recent := ipv4(ipProtoTCP, nvr, self, 554, uint16(10000+max*4-1))
	if v := table.Check(Inbound, recent); !v.Allowed {
		t.Fatalf("the newest flow was evicted: %s", v.Reason)
	}

	oldest := ipv4(ipProtoTCP, nvr, self, 554, 10000)
	if v := table.Check(Inbound, oldest); v.Allowed {
		t.Fatalf("the oldest flow survived eviction: %s", v.Reason)
	}
}

// A rule change must not reset every open connection on the device. An
// operator editing an unrelated rule would otherwise drop the customer's RDP
// session, which is not what "apply a rule" should mean.
func TestOpenFlowsSurviveAConfigurationChange(t *testing.T) {
	before := trackedTable(maxFlows)
	before.Check(Outbound, ipv4Flags(ipProtoTCP, self, nvr, 40000, 554, tcpSYN))

	after := NewTable()
	after.AddSelf(self)
	after.Add(nvr, []Filter{{Action: "allow", Protocol: ProtoTCP, PortFrom: port(554), RuleID: 7}})
	after.AdoptFlows(before)

	if v := after.Check(Inbound, ipv4(ipProtoTCP, nvr, self, 554, 40000)); !v.Allowed {
		t.Fatalf("an open conversation was dropped by a configuration change: %s", v.Reason)
	}
}

// §2 of the brief: a packet that cannot be read down to its transport header
// is dropped, never forwarded. An IPv6 packet wearing extension headers is the
// case that matters — without this, anyone puts a hop-by-hop header in front
// of a TCP segment and walks past a port rule.
func TestIPv6ExtensionHeadersFailClosed(t *testing.T) {
	table := trackedTable(maxFlows)
	table.AddSelf("fd00::2")
	table.Add("fd00::3", []Filter{{Action: "allow", Protocol: ProtoTCP, PortFrom: port(554), RuleID: 7}})

	for name, next := range map[string]uint8{
		"hop-by-hop":          0,
		"routing":             43,
		"fragment":            44,
		"destination options": 60,
		"authentication":      51,
	} {
		b := make([]byte, 40+20)
		b[0] = 0x60
		b[6] = next
		copy(b[8:24], netip.MustParseAddr("fd00::2").AsSlice())
		copy(b[24:40], netip.MustParseAddr("fd00::3").AsSlice())

		if v := table.Check(Outbound, b); v.Allowed {
			t.Fatalf("%s: an unreadable IPv6 packet was forwarded (%s)", name, v.Reason)
		}
	}
}

// A truncated TCP header is malformed, not port-less. Treating it as having no
// ports would let it past a deny-only rule set.
func TestATruncatedTransportHeaderIsDropped(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(nvr, []Filter{{Action: "deny", Protocol: ProtoTCP, PortFrom: port(22), RuleID: 5}})

	short := make([]byte, 20+6) // ports present, flags byte missing
	short[0] = 0x45
	short[9] = ipProtoTCP
	copy(short[12:16], netip.MustParseAddr(self).AsSlice())
	copy(short[16:20], netip.MustParseAddr(nvr).AsSlice())

	if v := table.Check(Outbound, short); v.Allowed {
		t.Fatalf("a truncated TCP header was forwarded: %s", v.Reason)
	}
}

// What the table costs on a shop PC, which is the question that decides
// whether this design is usable at all.
func TestMemoryAtTenThousandFlows(t *testing.T) {
	table := trackedTable(maxFlows)

	// Peers first, and outside the measurement: this is about what the *flow*
	// table costs, and folding the peer list into the figure would overstate
	// it by roughly four hundred bytes a flow.
	peers := make([]string, 0, 40)
	for i := 0; i < 40; i++ {
		peer := fmt.Sprintf("10.99.1.%d", i+1)
		table.Add(peer, []Filter{{Action: "allow", Protocol: ProtoTCP, PortFrom: port(554), RuleID: 7}})
		peers = append(peers, peer)
	}

	runtime.GC()
	var before runtime.MemStats
	runtime.ReadMemStats(&before)

	for i := 0; i < maxFlows; i++ {
		// Spread across peers as well as ports, so the keys are as varied as
		// they would be in service.
		peer := peers[i%len(peers)]
		table.Check(Outbound, ipv4Flags(ipProtoTCP, self, peer, uint16(1024+i), 554, tcpSYN))
	}

	runtime.GC()
	var after runtime.MemStats
	runtime.ReadMemStats(&after)

	if got := table.Flows(); got < maxFlows/2 {
		t.Fatalf("only %d flows were tracked, expected close to %d", got, maxFlows)
	}

	used := after.HeapAlloc - before.HeapAlloc
	perFlow := used / uint64(table.Flows())
	t.Logf("%d flows: %d KB total, %d bytes per flow", table.Flows(), used/1024, perFlow)

	// A few megabytes is the stated budget. This is a floor under a regression
	// rather than a tight bound: if a future change makes a flow ten times
	// more expensive, this says so before a customer's PC does.
	const budget = 8 << 20
	if used > budget {
		t.Fatalf("flow table used %d KB, budget is %d KB", used/1024, budget/1024)
	}
}
