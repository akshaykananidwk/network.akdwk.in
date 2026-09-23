package acl

import "testing"

const (
	gateway = "10.99.0.5"
	nvrLAN  = "192.168.1.50"
	tillLAN = "192.168.1.60"
)

// §36 through a gateway: AK Support reaches the NVR's RTSP port and nothing
// else on it. The NVR has no agent and no overlay address — it is a machine
// behind the hotel's reception PC, which is what makes this the case that
// sells the product.
func TestAGatewayRouteAllowsOnlyTheNamedPort(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(gateway, nil)
	table.AddRoute("192.168.1.0/24", gateway, []Filter{
		{Action: "allow", Protocol: ProtoTCP, PortFrom: port(554), RuleID: 11},
	})

	if v := table.Check(Outbound, ipv4Flags(ipProtoTCP, self, nvrLAN, 40000, 554, tcpSYN)); !v.Allowed {
		t.Fatalf("the NVR's RTSP port was blocked: %s", v.Reason)
	}

	if v := table.Check(Outbound, ipv4Flags(ipProtoTCP, self, nvrLAN, 40000, 80, tcpSYN)); v.Allowed {
		t.Fatalf("the NVR's web interface was reachable: %s", v.Reason)
	}
}

// Without a route, a LAN address is not reachable at all. A gateway that has
// not advertised a prefix does not become a way into everything behind it.
func TestALanAddressWithNoRouteIsRefused(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(gateway, nil)

	if v := table.Check(Outbound, ipv4Flags(ipProtoTCP, self, nvrLAN, 40000, 554, tcpSYN)); v.Allowed {
		t.Fatalf("an unadvertised LAN address was reachable: %s", v.Reason)
	}
}

// The most specific route decides. "The LAN is reachable, except the till"
// has to mean the exception.
func TestTheMostSpecificRouteWins(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(gateway, nil)
	table.AddRoute("192.168.1.0/24", gateway, []Filter{
		{Action: "allow", Protocol: ProtoAny, RuleID: 1},
	})
	table.AddRoute("192.168.1.60/32", gateway, []Filter{
		{Action: "deny", Protocol: ProtoAny, RuleID: 2},
	})

	if v := table.Check(Outbound, ipv4Flags(ipProtoTCP, self, nvrLAN, 40000, 554, tcpSYN)); !v.Allowed {
		t.Fatalf("the wider route was not honoured: %s", v.Reason)
	}
	if v := table.Check(Outbound, ipv4Flags(ipProtoTCP, self, tillLAN, 40000, 554, tcpSYN)); v.Allowed {
		t.Fatalf("the host-specific deny was shadowed by the subnet allow: %s", v.Reason)
	}
}

// A reply from a machine behind the gateway belongs to the flow that asked
// for it, exactly as a peer's reply does.
func TestARoutedReplyBelongsToItsFlow(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(gateway, nil)
	table.AddRoute("192.168.1.0/24", gateway, []Filter{
		{Action: "allow", Protocol: ProtoTCP, PortFrom: port(554), RuleID: 11},
	})

	table.Check(Outbound, ipv4Flags(ipProtoTCP, self, nvrLAN, 40000, 554, tcpSYN))

	v := table.Check(Inbound, ipv4(ipProtoTCP, nvrLAN, self, 554, 40000))
	if !v.Allowed {
		t.Fatalf("the NVR's reply was dropped: %s", v.Reason)
	}
}

// And the source-port bypass must not reappear through a route.
func TestTheSourcePortBypassIsClosedForRoutedTrafficToo(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(gateway, nil)
	table.AddRoute("192.168.1.0/24", gateway, []Filter{
		{Action: "allow", Protocol: ProtoTCP, PortFrom: port(554), RuleID: 11},
	})

	v := table.Check(Outbound, ipv4Flags(ipProtoTCP, self, nvrLAN, 554, 80, tcpSYN))
	if v.Allowed {
		t.Fatalf("binding the allowed port as a source reached the NVR's web interface: %s", v.Reason)
	}
}

// The gateway a route points at is recorded, so a device can tell whether it
// is the one expected to forward for a prefix.
func TestTheGatewayForARouteIsRecorded(t *testing.T) {
	table := NewTable()
	table.AddSelf(self)
	table.Add(gateway, nil)
	table.AddRoute("192.168.1.0/24", gateway, nil)

	via, ok := table.GatewayFor(mustAddr(nvrLAN))
	if !ok {
		t.Fatal("no gateway recorded for an advertised prefix")
	}
	if via.String() != gateway {
		t.Fatalf("gateway for the NVR is %s, want %s", via, gateway)
	}

	if _, ok := table.GatewayFor(mustAddr("172.16.0.1")); ok {
		t.Fatal("a gateway was reported for an address no route covers")
	}
}

// The gateway's own half of a routed rule.
//
// This is the test for the case where the restricted agent is not trusted. The
// support laptop's table refuses to send to tcp/80 on the NVR, but the laptop
// belongs to the customer and its agent can be replaced. The gateway is the
// device in the path that the restricted party does not control, so it has to
// reach the same verdict independently.
func TestAGatewayRefusesToForwardWhatTheRuleDoesNotAllow(t *testing.T) {
	const support = "10.99.0.2"

	// This device is the gateway: self is its overlay address, support is a
	// peer, and 192.168.1.0/24 is a LAN it routes for.
	table := NewTable()
	table.AddSelf(self)
	table.Add(support, nil) // no port rules on the peer link itself
	table.AddServed(support, "192.168.1.0/24", []Filter{
		{Action: "allow", Protocol: ProtoTCP, PortFrom: port(554), RuleID: 11},
	})

	if table.Served() != 1 {
		t.Fatalf("Served() = %d, want 1", table.Served())
	}

	// Arriving from the support laptop, addressed to the NVR's RTSP port.
	if v := table.Check(Inbound, ipv4Flags(ipProtoTCP, support, nvrLAN, 40000, 554, tcpSYN)); !v.Allowed {
		t.Fatalf("the gateway refused to forward the port the rule allows: %s", v.Reason)
	}

	// The same peer, the same machine, a port the rule does not name. The peer
	// link carries no restriction, so if the gateway judged this by the peer's
	// filters alone it would forward it.
	if v := table.Check(Inbound, ipv4Flags(ipProtoTCP, support, nvrLAN, 40001, 80, tcpSYN)); v.Allowed {
		t.Fatalf("the gateway forwarded a port the rule did not allow: %s", v.Reason)
	}
}

// Rules are per peer, not per prefix: two support devices with different
// rights into the same LAN must not inherit each other's.
func TestAGatewayKeepsEachPeersRightsSeparate(t *testing.T) {
	const support = "10.99.0.2"
	const contractor = "10.99.0.7"

	table := NewTable()
	table.AddSelf(self)
	table.Add(support, nil)
	table.Add(contractor, nil)
	table.AddServed(support, "192.168.1.0/24", []Filter{
		{Action: "allow", Protocol: ProtoTCP, PortFrom: port(554), RuleID: 11},
	})
	table.AddServed(contractor, "192.168.1.0/24", []Filter{
		{Action: "allow", Protocol: ProtoTCP, PortFrom: port(80), RuleID: 12},
	})

	if v := table.Check(Inbound, ipv4Flags(ipProtoTCP, support, nvrLAN, 40000, 80, tcpSYN)); v.Allowed {
		t.Fatalf("support inherited the contractor's port: %s", v.Reason)
	}
	if v := table.Check(Inbound, ipv4Flags(ipProtoTCP, contractor, nvrLAN, 40000, 554, tcpSYN)); v.Allowed {
		t.Fatalf("the contractor inherited support's port: %s", v.Reason)
	}
	if v := table.Check(Inbound, ipv4Flags(ipProtoTCP, contractor, nvrLAN, 40000, 80, tcpSYN)); !v.Allowed {
		t.Fatalf("the contractor was refused its own port: %s", v.Reason)
	}
}

// The gateway must still let the answers back. The NVR replies with the
// service port as its source, which no rule matches — the flow it belongs to
// is what permits it, exactly as for a direct peer.
func TestAGatewayLetsTheAnswersBack(t *testing.T) {
	const support = "10.99.0.2"

	table := NewTable()
	table.AddSelf(self)
	table.Add(support, nil)
	table.AddServed(support, "192.168.1.0/24", []Filter{
		{Action: "allow", Protocol: ProtoTCP, PortFrom: port(554), RuleID: 11},
	})

	if v := table.Check(Inbound, ipv4Flags(ipProtoTCP, support, nvrLAN, 40000, 554, tcpSYN)); !v.Allowed {
		t.Fatalf("the opening packet was refused: %s", v.Reason)
	}

	// The NVR's reply, on its way back out to the support laptop.
	if v := table.Check(Outbound, ipv4Flags(ipProtoTCP, nvrLAN, support, 554, 40000, tcpSYN)); !v.Allowed {
		t.Fatalf("the NVR's reply was dropped: %s", v.Reason)
	}

	// But an unsolicited connection from the same LAN address is not a reply,
	// and the gateway must not treat it as one.
	if v := table.Check(Outbound, ipv4Flags(ipProtoTCP, nvrLAN, support, 554, 3389, tcpSYN)); v.Allowed {
		t.Fatalf("the NVR opened a connection into the overlay: %s", v.Reason)
	}
}
