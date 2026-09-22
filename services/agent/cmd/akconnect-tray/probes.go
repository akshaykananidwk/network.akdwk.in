package main

// What the bundle asks Windows about itself.
//
// Data rather than code, in a file with no build tag, because WHICH questions
// are asked is a decision — and one with a rule attached: everything here is
// read-only and nothing here may print a credential. A decision that can only
// be exercised on a Windows laptop is a decision that is never exercised, so
// the list is testable from anywhere and a test enforces the rule.
//
// They exist to separate three causes of the same symptom — the agent
// announcing itself and the coordinator never answering:
//
//	a firewall on this network      → firewall-profiles, firewall-ours, firewall-blocking
//	the wrong adapter carrying it   → adapters, routes, network-profile
//	something else holding the port → udp-listeners
//
// and the agent's own side of it is already in status.json: listen_port,
// reflexive_endpoint and the unanswered count.
type probe struct {
	// Name is the file inside the zip.
	Name string
	// Note is printed at the top, telling whoever opens it what to look for.
	Note string
	// Statements are run in order, each under its own heading.
	Statements []string
	Headings   []string
}

func machineProbes() []probe {
	return []probe{
		{
			Name:     "network-profile.txt",
			Note:     "Which profile Windows put this connection in. Public is the strict one.",
			Headings: []string{""},
			Statements: []string{
				"Get-NetConnectionProfile | Format-List Name, InterfaceAlias, InterfaceIndex, NetworkCategory, IPv4Connectivity, IPv6Connectivity",
			},
		},
		{
			Name: "adapters.txt",
			Note: "Every adapter, its addresses, and the metric that decides which one traffic leaves by.",
			Headings: []string{
				"adapters",
				"addresses",
				"interface metrics, which decide where traffic leaves",
			},
			Statements: []string{
				"Get-NetAdapter | Sort-Object ifIndex | Format-Table -AutoSize ifIndex, Name, InterfaceDescription, Status, LinkSpeed, MacAddress",
				"Get-NetIPAddress -AddressFamily IPv4 | Sort-Object ifIndex | Format-Table -AutoSize ifIndex, InterfaceAlias, IPAddress, PrefixLength, PrefixOrigin, SkipAsSource",
				"Get-NetIPInterface -AddressFamily IPv4 | Sort-Object InterfaceMetric | Format-Table -AutoSize ifIndex, InterfaceAlias, InterfaceMetric, Dhcp, ConnectionState, Forwarding",
			},
		},
		{
			Name:     "routes.txt",
			Note:     "Where this machine sends things, and by which adapter.",
			Headings: []string{""},
			Statements: []string{
				"Get-NetRoute -AddressFamily IPv4 | Sort-Object RouteMetric | Format-Table -AutoSize ifIndex, InterfaceAlias, DestinationPrefix, NextHop, RouteMetric, Protocol",
			},
		},
		{
			Name: "firewall-profiles.txt",
			Note: "Whether the firewall is on for this profile and what it does with inbound traffic by default.",
			Headings: []string{
				"profiles",
				"is inbound blocked outright",
			},
			Statements: []string{
				"Get-NetFirewallProfile | Format-List Name, Enabled, DefaultInboundAction, DefaultOutboundAction, AllowInboundRules, NotifyOnListen",
				"netsh advfirewall show allprofiles state",
			},
		},
		{
			Name: "firewall-ours.txt",
			Note: "The inbound rule the installer creates, and the port it names.\n" +
				"If that port is not the listen_port in status.json, this machine's own\n" +
				"firewall is dropping what the coordinator sends back.",
			Headings: []string{""},
			Statements: []string{
				"Get-NetFirewallRule -DisplayName '*AKConnect*' -ErrorAction SilentlyContinue | " +
					"ForEach-Object { $p = $_ | Get-NetFirewallPortFilter; " +
					"\"{0} | {1} | {2} | enabled={3} | profile={4} | {5}/{6}\" -f " +
					"$_.DisplayName, $_.Direction, $_.Action, $_.Enabled, $_.Profile, $p.Protocol, $p.LocalPort }",
			},
		},
		{
			Name: "firewall-blocking.txt",
			Note: "Every enabled inbound BLOCK rule. A rule from another product — a\n" +
				"security suite, a corporate policy — is the thing that shows up nowhere else.",
			Headings: []string{""},
			Statements: []string{
				"Get-NetFirewallRule -Direction Inbound -Action Block -Enabled True -ErrorAction SilentlyContinue | " +
					"Select-Object -First 200 | Format-Table -AutoSize DisplayName, Profile, DisplayGroup",
			},
		},
		{
			Name: "udp-listeners.txt",
			Note: "Which process holds which UDP port. The agent's listen_port should be\n" +
				"here against akconnect-agent.exe; against anything else, two programs are\n" +
				"fighting over it.",
			Headings: []string{""},
			Statements: []string{
				"Get-NetUDPEndpoint -ErrorAction SilentlyContinue | Where-Object { $_.LocalPort -gt 1024 } | " +
					"Select-Object LocalAddress, LocalPort, OwningProcess | Sort-Object LocalPort | Format-Table -AutoSize",
			},
		},
	}
}
