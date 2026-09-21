# Changelog

Notable changes per release. This project follows
[Semantic Versioning](https://semver.org): a breaking release sets
`"breaking": true` in `update.json`, and the panel warns before applying it.

---

## [1.9.0] — 2026-09-21

The release the pilot runs on. An installer a customer can use, a deployment
checklist, and two things that would have caused support calls.

### Added

**`akconnect-setup.exe`** — one file, one join code, no command line (§33). It
self-elevates, writes the agent and `wintun.dll` into Program Files, enrols,
registers the service, opens the firewall port and starts. Built for the
`windowsgui` subsystem so double-clicking it opens no console window;
everything a customer sees is a dialog.

`-uninstall` takes back the service, the firewall rule, the NRPT rules, the
Wintun adapter and both folders — including the one holding the device's
private key, because leaving a credential on a machine somebody has just
removed our software from is not a thing to do. Stage 15 of the test pack
checks each of those separately.

**`DEPLOY.md`** — the panel on aaPanel, the coordinator and a relay on a public
VPS, in the same copy-paste style as the Windows runbook, with the expected
output at each step taken from real runs rather than written from memory. It
says plainly which steps I could verify without the VPS and which I could not.

**`deploy/`** — systemd units for both services, hardened (`ProtectSystem=strict`,
no capabilities, a syscall filter) and verified with `systemd-analyze`, plus
`install-edge.sh`, which creates the service account, generates the keypair and
secrets with the right file modes, and prints the two values the panel needs.

**`--data-ports` on the relay.** Its data sockets used to take whatever port
the kernel handed out, which on Linux means 32768–60999 — and "open most of the
unprivileged port space" is not an instruction to give anybody. Pinned to
51900–52400 in the unit file, which gives room for 250 concurrent sessions and
a firewall rule somebody can justify.

**Sizing numbers that were measured.** A relay costs 2 KB of process memory per
session and 5.2 MB at rest; a session is two UDP sockets, and the kernel's
buffers are the larger half. There is a test that fails if a change makes a
session four times more expensive, because `DEPLOY.md` tells somebody what size
server to buy on the strength of that number.

### Changed

**Windows DNS is NRPT only.** There is no hosts-file fallback there and there
will not be one: Defender reports writes to that file as
`SettingsModifier:Win32/HostsFileHijack`, and every antivirus our customers run
does the same. A fallback that worked perfectly would still put an alert on a
customer's screen on install day, and one support call per install costs more
than the feature is worth. Where NRPT refuses, names do not resolve, the agent
says so, and the panel shows it. Linux keeps the fallback, where nothing
objects.

**The mapping pool is per network**, and validated: private space only, between
a /8 and a /24, and never overlapping the network's own range. Plenty of
offices and most ISP-managed connections in this market use 10.x internally,
and a customer who collides with the default needs a way out that does not move
every other customer with them.

### Fixed

**The overlay CIDR was exempt from clash detection.** It was treated as "not
negotiable: without it the device is not on the network" — true, and the wrong
conclusion. A customer whose office already runs 10.50.0.0/16 would have had
that range taken over in order to join an overlay, cutting the machine off from
its own file server. Not joining is what a person would choose every time, so
the agent now refuses, names the clash and reports it.

**Problems reach somebody who can fix them.** A refused route and a refused DNS
policy were both a line in a log file on the customer's machine, which is the
same as not being reported. Devices now carry a problems list, shown on the
device's page, repeated on every heartbeat so it clears itself when the cause
is fixed, and sanitised on arrival because it comes from hardware the customer
owns.

### Verified

**The systemd-resolved path now runs.** It was written and unexercised for a
release. The gate gained a scenario that steps outside the network namespaces —
resolved cannot see an interface inside one — drives the real code against a
throwaway link and reads systemd-resolved's own output back: `Current Scopes:
DNS`, `DNS Domain: ~lab-resolved.internal`, and `resolvectl query` returning our
answer. It fails rather than skips where systemd-resolved is absent;
`services/lab/setup-resolved.sh` installs and starts it, container included.

### Known limitations

**NRPT has still never run.** Stage 14 covers it and needs your hardware.

**Nothing is signed.** The installer will show "Publisher: Unknown" and
SmartScreen will warn. Stage 15a is where you find out what that costs.

---

## [1.8.0] — 2026-09-21

Split DNS (§18). Names instead of addresses, for our domain only.

### Added

**`nvr.hotel-abc.acme.internal`.** Overlay devices are named from the devices
table; machines behind a gateway get a new table, because an NVR has no agent,
no key and no row anywhere — it is an address inside an advertised range, and
an operator now writes down which address is which.

This matters more since subnet mapping than it would have before. The address a
technician connects to is one the panel invented — `10.128.0.50` rather than
the `192.168.1.50` printed on the recorder — and it is different for every
site. A name is what makes that an implementation detail instead of a table
somebody has to keep.

**The address an operator types is the real one**, the one on the label. The
address the name resolves to is derived from the route's mapping when the
configuration is built, so a route withdrawn and re-advertised cannot leave a
stale answer behind.

**The zone is always under `.internal`**, which ICANN reserved for private use
in 2024. Anything else is refused on create and on update: a network whose
search domain could be set to `google.com` would make every one of its agents
authoritative for a domain somebody else owns.

**The agent runs an authoritative server for that one zone and never
forwards.** A name it holds gets an A record, a name in the zone it does not
hold gets NXDOMAIN, and anything else gets REFUSED. There is no code in it that
speaks to another DNS server, so it cannot become a customer's resolver however
it is pointed at. It listens on loopback, starting at `127.0.0.54` because
`127.0.0.53` is systemd-resolved's and taking it would break the machine's own
name resolution.

**Three mechanisms point the operating system at it**, in order of preference:
systemd-resolved's per-interface domain routing on Linux, NRPT on Windows, and
the hosts file where neither is available. Writing a nameserver into
`/etc/resolv.conf` or onto the adapter would have been simpler and would have
made us the resolver for everything the machine looks up — a takeover, not a
split.

The hosts file writes only between its own markers, copies everything else
through byte for byte, and replaces the file by atomic rename. Nine tests cover
it, all of them about what is still there afterwards.

**Route and host management in the panel.** Advertising a LAN, approving it,
withdrawing it and naming machines inside it were service-layer only since
1.6.0 — usable from a script and not from the panel. They have endpoints and
forms now.

### Fixed

**Nothing resolved on a machine without systemd-resolved.** The first version
of this had the resolver and the two split-DNS mechanisms, and on a Debian
server or a container the resolver ran with nothing pointing at it, so no name
worked. The hosts-file fallback is the fix. Caught by the drill reporting,
honestly, that the operating system was not routing the zone anywhere.

### Known limitations

**NRPT has never run.** Stage 14 of the Windows test pack covers it. Until
those results come back, Windows name resolution is an argument from the code.

**systemd-resolved has never run either.** No machine in this lab has it, so
the Linux path the gate exercises is the hosts-file fallback. The resolvectl
path is written and unexercised.

---

## [1.7.0] — 2026-09-21

Subnet mapping, and a real cross-version update drill.

### Added

**The overlay no longer carries a customer's LAN range.** Almost every router
sold in India hands out 192.168.1.0/24 or 192.168.0.0/24, so a technician's
laptop on one of those could not reach a customer whose LAN was the same range.
1.6.0 refused the route and logged which prefix clashed. That was honest and
useless: "renumber one side" is not an answer a business can give its
customers.

Each advertised LAN now gets a prefix of its own from a pool (`10.128.0.0/10`
by default, `network.mapped_pool` to change it), unique within the network. The
hotel's 192.168.1.0/24 becomes 10.128.0.0/24 and the recorder at 192.168.1.50
is reached at 10.128.0.50 — the host part carries across unchanged, so the
mapping is arithmetic rather than a table and a LAN with two hundred cameras
costs what a LAN with one costs.

Rules are still written about the real address, because that is the address on
the label on the recorder. The panel translates them before they are sent; the
agent never learns the real range at all. The panel's route list shows both
addresses side by side, and `akconnect-agent status` lists them on a gateway.

**The rewriting is in the agent, not in iptables.** Linux has `NETMAP` and does
this natively; Windows has nothing equivalent — `New-NetNat` masquerades
many-to-one and `Add-NetNatStaticMapping` forwards a single port, and neither
maps a prefix. `services/agent/internal/netmap` gives one implementation that
behaves identically on both, at the point where packets are already plaintext
and already ours. It sits *inside* the ACL filter, so everything above it — the
rules, wireguard-go, the peer at the other end — works in one address space.

**A cross-version update drill, `services/lab/dogfood.sh`.** It installs the
previous release into a scratch app root with a database and database user of
its own, updates it forward through our own updater, and rolls it back,
failing unless files were genuinely written, the new ones genuinely removed
again, and every file byte-identical afterwards. 1.6.0's dogfood wrote zero
files because the release was built on the machine it was installed on, which
skipped the paths the 1.3.x P1 lived in. `services/lab/release.sh` runs the
four gates together.

### Fixed

**A rule about one machine was enforced as a rule about the whole LAN.** Route
filters were selected by *overlap*, so "AK Support may reach 192.168.1.50 on
tcp/554" was compiled into the entry for the entire 192.168.1.0/24 — which
opened tcp/554 on the till at 192.168.1.60 as well. The rule said one machine
and the agent enforced twenty.

A rule now governs an entry only when it covers it. A narrower rule gets an
entry of its own, which wins on longest prefix and picks the wider rules up
again through the same test. Caught by a test written for the mapping work,
not by the drill: `gw/denied` passed throughout, because it only ever asked
about the one machine the rule named.

### Known limitations

**Windows subnet mapping is untested on real hardware.** The code is
platform-independent by construction — it is the agent doing the rewriting, not
the operating system — but that is an argument, not a result. Stage 13g of the
test pack covers it.

**A machine numbered out of the mapping pool still loses the contest.** Somebody
already using 10.128.0.0/10 on their own network will be handed a colliding
prefix, and the agent will refuse it and keep the local route. The fix is to
change `network.mapped_pool`; the agent's log names the prefix that clashed.
Drilled as `gateway-clash`.

---

## [1.6.0] — 2026-09-21

Phase 4 continues: gateway / subnet-router mode (§16–17). A hotel's NVR, a
shop's printer, a clinic's DVR — none of them will ever run our agent. One
Windows or Linux PC at the site routes for them.

### Added

**Subnet-router mode.** A gateway device advertises a LAN prefix from the
panel; an administrator approves it; agents in that network then route that
prefix through the tunnel. Unapproved, it carries nothing — R4's reasoning
applies to subnets as much as to devices.

On Linux the gateway sets `net.ipv4.ip_forward` and three narrow iptables
rules per prefix: forward from the overlay to the prefix on the tunnel
interface, allow the answers back only for conversations that started in the
overlay, and SNAT so the LAN device — which has no route back to the overlay —
can reply at all. A FORWARD rule without those matches would make the PC an
open router for whatever else is on the site's LAN.

On Windows it is `netsh interface ipv4 set interface forwarding=enabled` plus
`New-NetNat`. RRAS is Server-only and ICS cannot target a prefix, so `New-NetNat`
is the only option that works on the Windows 10 and 11 machines our customers
actually have. **This has not run on real Windows hardware yet.** Stage 13 of
the Windows test pack covers it, including the case where Internet Connection
Sharing already owns NAT on the machine and `New-NetNat` fails with a message
that explains nothing.

**Rules about machines behind a gateway** name the LAN address, not the
device: "AK Support may reach 192.168.1.50 on tcp/554" is about the recorder,
and the PC routing for it is incidental. The most specific advertised prefix
covering an address decides, so "the LAN is reachable, except the till" means
the exception.

**Four drills**, all against a namespace with no agent on it:
`gateway` (reached on tcp/554, refused on tcp/8080, and the NVR cannot reach
back into the overlay), `gateway-clash` (below), `gateway-tamper` (below), and
`GatewayTests.php` for two customers advertising identical prefixes.

### Fixed

**A gateway routed its own LAN down the tunnel, so it forwarded nothing.**
The panel sends every gateway's prefixes in the routes list and the agent
installed all of them, its own included. `ip route replace 192.168.77.0/24 dev
akc0` on the gateway then did two bad things at once: it destroyed the kernel's
interface route for the LAN the PC was sitting on, and it pointed packets the
PC was supposed to be forwarding back down the tunnel they had arrived on. The
tunnel was up, the iptables rules were right, WireGuard was handshaking, and
the NVR was unreachable — the failure looked like everything except what it
was. `netcfg.Build` now skips prefixes this device serves, and two tests fail
against the old code.

**A route that collides with a network the machine is already on is refused,
not installed.** Overlapping private ranges are the normal state of this
product, not an edge case: most consumer routers hand out 192.168.1.0/24, so a
technician will regularly be offered a route to another customer's identical
range. Replacing the machine's own LAN route would cut it off from the printer
beside it and often from its own default gateway, so the tunnel loses that
contest and the agent logs which prefix clashed. `Remove` now deletes only the
routes `Apply` installed, so a stopping agent cannot take a site's own LAN
route with it.

**The gateway enforced nothing of its own.** It forwarded on the strength of
the peer link alone, so a rule about the NVR was enforced only by the agent
being restricted — which runs on hardware the customer owns. That is the same
shape of hole as matching a source port, reached from a different direction.
Each peer now carries, in the gateway's configuration, what it may reach inside
the LANs that gateway routes for, and the gateway reaches its own verdict.

Proven with a client built `-tags labtamper`, with rule enforcement compiled
out: it enrolled, handshaked and reached tcp/554 exactly like the real binary,
and tcp/8080 on the NVR was still refused. The gateway logged the drop; the
tampered client logged none.

**Forwarding is one-way in the ACL, not only in iptables.** A machine behind a
gateway could not open a connection into the overlay because a conntrack rule
stopped it — but the filter itself would have allowed it, and the machines
behind a gateway are the ones nobody could put an agent on, usually because
nobody can patch them either. A packet whose source is inside a served prefix
is now refused unless it belongs to a flow the overlay started. Caught by a
test written for the reply path, which is the only reason it was found.

### Known limitations

**Two identical LAN prefixes cannot both be reached from one machine.** When a
technician's own LAN is 192.168.1.0/24 and the customer's is too, the remote
one is unreachable from that laptop until one side is renumbered. The agent
says so in its log and leaves the local LAN alone. This is not fixed and the
log line is not a fix.

**Windows gateway mode is untested on real hardware.** Stage 13 of the test
pack exists for it. Until that comes back, gateway mode is proven on Linux
only.

---

## [1.5.0] — 2026-09-21 — **never released**

Four fixes to things that were wrong rather than merely incomplete. The first
was a bypass I had written up as a design trade-off.

These were finished, but `services/lab/run-all.sh` was not clean when they
were: `gw/reach` was failing while subnet-router mode was being built, and no
release ships on a red table. They went out in 1.6.0 instead. The entry is
kept because the work and the reasoning are worth reading in their own right,
not because a 1.6.0 upgrade skips anything.

### Fixed

**A rule naming a service port could be bypassed by binding that port as a
source.** "AK Support may reach the NVR on tcp/554" matched any packet whose
source *or* destination port was 554, so a device need only bind 554 locally to
reach every port on the NVR. The rule then permitted precisely what it was
written to forbid.

I had documented this as a stateless trade-off. It is not a trade-off; it is a
hole, and writing it down did not make it smaller.

Rules now match the **destination port only**, and replies are recognised by
the flow they belong to: `services/agent/internal/acl/conntrack.go` keeps a
bounded, LRU-evicted table keyed on the 5-tuple, with TCP flags retiring a
conversation on FIN or RST and idle timeouts for UDP and ICMP. ICMP echoes are
matched on their identifier, so a ping reply belongs to the ping that asked for
it. Flows survive a configuration change, because an operator editing one rule
should not drop every open connection on every device.

**10,000 flows cost 2.8 MB — 286 bytes each**, measured in
`TestMemoryAtTenThousandFlows`, which fails if a future change makes a flow
substantially more expensive.

**An unreadable packet could walk past a port rule.** An IPv6 packet wearing a
hop-by-hop or routing header was treated as having no ports, so no port rule
matched it and a deny-only rule set would forward it. Extension headers are not
walked, so such a packet is now refused outright — fail closed, never open. A
truncated TCP header is likewise malformed rather than port-less.

**Enrolment throttling counted a rollout the same way it counts an attack.**
Sixty requests an hour per IP cut off a forty-machine installation from one
office partway through, in front of the customer. What is worth limiting
tightly is a *failed* enrolment — a join code that does not exist, has expired
or is used up, which is the only shape enumeration takes. Successful enrolments
required a code an administrator issued, and every code carries its own use
limit.

So: failures are limited strictly (15 per 15 minutes per address, recorded by
the controller after the attempt), volume generously (1,200 an hour, a flood
ceiling rather than an abuse control), and a claim for a public key nobody
enrolled counts as a failure because it is a probe rather than a poll.

**Join code redemption was not atomic.** It selected the row, checked the use
count and then incremented, so two devices enrolling in the same moment could
both take the last use. Academic when devices trickled in; not when fifty
machines in one office enrol together. The claim is now a single conditional
UPDATE and the row is read only if it succeeded.

**Relayed-byte billing came from the party being billed.** The panel metered
`rx_delta` + `tx_delta` out of each agent's heartbeat — so a customer running a
modified agent could lower their own invoice. It is the one number in the
system where the party paying was also the party measuring.

The relay now reports what it carried, over an authenticated UDP message to
the coordinator, which already holds a panel credential so no relay needs one.
Totals are cumulative, so a lost report costs nothing; the coordinator takes
the difference and treats a decrease as a relay restart. The agents' figure is
kept under its own metric for display and as a cross-check, and a disagreement
beyond 10% — the lab measured the two agreeing to 20 bytes in 1.7 MB — is
logged with which side is reporting less.

---

## [1.4.0] — 2026-09-21

Phase 4. The panel has been able to author access rules since Phase 1 and
nothing enforced them. Now the agent does, at both ends of every conversation.

### Added

**`services/agent/internal/acl`** — a filter wrapping the tunnel interface. It
sits between wireguard-go and the operating system, the one place on the
machine where packets are both plaintext and attributable to a peer: below it
they are ciphertext, above it we would be asking two different operating
systems to hold rules that belong to us, with no way to know whether they did.
Outbound and inbound are both filtered, because a rule that only stopped what
this device sends would leave a tampered peer free to send whatever it liked.

Sixteen tests, written for the cases that bypass a filter rather than the ones
that exercise it: a TCP port rule must not permit ping; a fragment carrying no
transport header cannot satisfy a port rule; an unrecognised protocol name
matches nothing rather than everything; replies to an allowed port are not
blocked; and a filtered batch compacts its survivors forward, because leaving a
hole hands wireguard-go a stale buffer to encrypt and send.

**Two drills.** A deny proven against a real listener with a real TCP connect,
timed against the ten-second requirement and measured at four; and a device
rewriting its own state and restarting, which does not get it past the far end.

### Fixed

**Access rules were compiled for one end only.** `AclService::buildPeerSet`
evaluated rules in the forward direction, so "alpha may not reach beta on
tcp/8080" was compiled into alpha's configuration and not into beta's. Every
rule lived on exactly one of the two machines — which is the same as trusting
that machine's agent, and the agent runs on hardware the customer owns. The
file's own docblock had claimed both-endpoint enforcement since Phase 1. Both
directions are now evaluated and their filters merged, deduplicated by rule id
so a rule matching both passes is not counted twice.

**The ACL drill passed for the wrong reason before it passed for the right
one.** It used a one-shot listener that the baseline check consumed, so the
deny appeared to take effect in *zero seconds* — impossible, because an agent
only learns a rule when it next polls. The listener is persistent now, the
scenario checks it is still alive from inside its own namespace before
concluding anything from a refused connection, and a sub-second result is
rejected outright as a measurement of something else.

### Known limitations

**The filter is stateless.** Ports match in either direction, so a rule
allowing tcp/554 also matches a packet merely originating from port 554.
Connection tracking would be tighter and would mean keeping per-flow state on a
shop PC. The trade is deliberate.

**IPv6 extension headers are not walked.** The overlay is IPv4; an IPv6 packet
carrying them is treated as having no ports, so a port rule will not match it.
Conservative, and a real limit if the overlay ever becomes dual-stack.

**A recompiled agent is not tested.** The tamper drill rewrites local state,
which is the most a customer with root can do without rebuilding the binary.
What stops a rebuilt one is the far end enforcing the mirror of the rule — now
that it has it — and a drill running a deliberately modified agent is not
built.

---

## [1.3.3] — 2026-09-21

### Fixed

**A rollback that could not restore the files rewound the database anyway.**
1.3.1 made a missing journal report a failure, which was necessary and not
sufficient: the four rollback phases ran unconditionally, so after the file
restore failed the rollback still reversed the migrations, restored the
database and stamped the old version number onto an installation whose files
were still on the new one. The failure was reported and the damage was done in
the same breath.

Everything after the file restore is now conditional on it. If the files
cannot be put back — a missing journal, a partial replay, any error — the
rollback stops, leaves the database and the version marker alone, and says
why. The installation stays wholly on the new version, which is a coherent
state an operator can reason about, and the recovery instructions name the
file backup to restore from.

Found by testing the 1.3.1 fix rather than trusting it: the refusal fired, and
the database step ran directly afterwards because the `throw` was inside the
method's own `try`.

---

## [1.3.2] — 2026-09-21

Documentation only.

Cut so that FINALISE runs 1.3.1's code. A fix to the update pipeline only takes
effect from the *next* update — the CLI runs all eleven steps in one process,
so the code that performs an update is the code installed before it. That is
inherent to self-updating software and is listed under Known limitations, but
it means 1.3.1's journal fix could not be proven by the update that delivered
it. This release is the one that proves it.

---

## [1.3.1] — 2026-09-21

A rollback could restore the database, leave the new files in place, and report
success. Found by dogfooding 1.3.0.

### Fixed

**FINALISE pruned the rollback journal of the update that had just written
it.** Journals are named by update id and retained in id order. Ids are not
monotonic across a database restore — restoring rewinds `app_updates`, so the
next update gets a low id while journals with high ids are still on disk.
Ordering alone then makes the newest journal look like the oldest, and the
update deletes its own undo list on the way out.

The rollback afterwards restored the database, found no journal, said "nothing
to undo (the update had not reached APPLY)" and reported success. The result
was 1.3.0 application files running against a 1.2.1 schema — a state worse than
either version on its own, reached by the one command an operator runs when
something has already gone wrong.

FINALISE now names its own update as protected, whatever the ordering says.

**A rollback with no journal now fails loudly instead of shrugging.** A missing
journal is only benign when the update never reached APPLY. Past that point the
journal is the *only* record of what was overwritten, so continuing would
restore the database under the new files and call it done. It now stops before
touching the database, says exactly what state the installation is in, and
names the file backup to restore from.

This is the third mechanism for surviving a bad update found broken by using
it rather than reading it. The first two were in 1.0.x.

---

## [1.3.0] — 2026-09-21

Relay selection now uses latency the devices actually measured, a relay that
dies is replaced rather than waited for, and the billed figure is checked
against what the relay says it carried. The whole networking proof is one
command, and it is now the release gate.

### Added — relay selection and failover

**Relay selection by measured round trip.** Agents probe every relay in the
fleet on the same socket WireGuard uses — so the measurement includes the NAT
the real traffic will cross — and report what they found to the coordinator,
sealed. The coordinator picks the relay with the lowest round trip *for the
pair*, which is the worse of the two ends' numbers: a relay 5 ms from one
device and 300 ms from the other is a 300 ms relay for the conversation
between them.

The device is the only thing that can measure this. A coordinator in Mumbai
cannot tell how far a shop in Ahmedabad is from a relay in Chennai, and a
device behind CGNAT does not have an address worth guessing from.

**A region column on devices**, to sit alongside the one relays already
carried. It is a hint and nothing more: it settles ties between relays that are
genuinely close and loses to any real difference in measured latency. Empty is
the normal value and selection works perfectly well without it — there is a
test that asserts exactly that.

**Relay failover.** An agent re-presents its ticket every five seconds and every
bind is acknowledged, so an unanswered rebind is the signal that a relay has
gone. Three consecutive misses and the agent asks the coordinator for a
different relay, naming the one that failed. Two devices naming the same relay
take it out of rotation for everyone, for two minutes; one device's report does
not, because a single device that cannot reach a relay is usually telling you
about its own network, and acting on it would be a denial of service anyone
could trigger.

**A relay probe endpoint** on the relay's control port. Unauthenticated,
because an agent that has not been offered a relay yet has no ticket for it and
still needs to know how far away it is. The reply is exactly the same size as
the probe, so bouncing traffic off it gains an attacker nothing over sending
that traffic directly.

**`relay` in the agent's status.** Which relay a device is on, not merely that
it is on one — an operator diagnosing a slow site needs the name.

**Two new drills in `run-all.sh`.** `relay-failover` kills whichever of two
relays the pair actually chose, mid-traffic, and requires the traffic to appear
on the other one. `accounting` pushes a known number of packets through a
relayed tunnel and compares the panel's billed figure against the relay's own
count of what it forwarded — two counters built from different things, one of
which a customer cannot influence.

### Fixed — hardening

**An agent could not fail over from a relay that was never working.** Failover
keyed on the peer's path already being `relay`, which only becomes true once a
bind has been acknowledged. A relay that had already died when the coordinator
offered it was therefore never rebound, never accumulated a missed count, and
never triggered a request for a different one — the pair sat in `connecting`
indefinitely. One dead relay stranded *every new pair it was handed to*, which
is a worse failure than the one failover was built for. Found by the lab gate,
on the scenario after the one that killed a relay.

**Latency-based selection did not apply to a device's first relay.** An agent
probed the fleet at startup but reported the result on the twenty-second
keepalive, while the deadline that sends a stalled peer to a relay is five
seconds. Every first assignment was therefore made with no measurements and
fell back to whichever relay was first in the fleet — the feature worked from
the *second* assignment onwards, which is not what it claims to do.
Measurements now go out as soon as the first probe round answers, rate-limited
so a flapping relay cannot become a stream of reports.

**Relay selection mutated shared state to filter a candidate.** `pickRelay`
temporarily removed the failed relay from `s.opts.Relays` and put it back. The
coordinator handles every packet in its own goroutine, so two concurrent relay
requests would have corrupted each other's view of the fleet. The relay to
avoid is a parameter now. Found by running the Go tests under `-race`, which
they now are.

**The lab served the panel without a router, and six public-surface tests
failed against it.** `tests/dev-server.php` already applies the `.htaccess`
deny list in a form PHP's built-in server obeys; the harness was not using it,
so `config/config.php`, `app/Core/DB.php` and `database/schema.sql` were all
served in the clear on the lab panel. The failures were real and the fix was a
file that already existed. With the router in place: 468 assertions, 0 failed.

**Agents were left running after every lab scenario.** `ip netns exec` forks
rather than execs, so the pid the harness recorded was a wrapper; killing it
reparented the agent to init. Every scenario left two more agents polling the
panel against a topology that no longer existed, and eventually one of them
made a later scenario fail for reasons that had nothing to do with it. Agents
now run in their own process group, and any stray from an earlier run is
identified by the binary it is executing — read from `/proc`, never by
pattern-matching command lines, which has killed the harness itself before.

**The packet-loss measurement never produced a number.** Every failover figure
read `?%`. `ping` prints "0% packet loss," with a comma, and the field-splitting
parser matched on `loss` — which never appears, only `loss,` does. A drill that
cannot measure the thing it exists to measure passes for the wrong reason.



### Added — the drill harness

**`services/lab/run-all.sh`** — builds the namespaces, brings up a real panel,
coordinator and relay, and runs every networking scenario the product depends
on: cone NAT, symmetric NAT, both mixed directions, controller down, relay
down and revocation. It prints one pass/fail table and exits non-zero on any
failure. This is now the dogfood gate — a build that cannot get a clean table
here does not ship.

Each scenario starts from a fresh topology, fresh agent state and a fresh
tenant, because reusing a tunnel between scenarios lets one scenario's success
hide the next one's failure. Enrolment goes through the real
enrol → approve → claim path rather than writing rows directly, so R4 is
exercised rather than assumed.

**`services/lab/topology.sh mixed A B`** — one side behind a cone NAT, the
other behind a symmetric one. The common real case: a shop on fibre talking to
a laptop on 4G.

**Regression tests for every defect found by running Phase 3.** Each was
checked by reverting the fix locally and confirming the test fails without it —
a test that has never failed proves nothing:

| Defect | Test |
| --- | --- |
| Relay replied to the bind address, not the data address | `TestForwardsToTheDataAddressNotTheBindAddress` |
| A re-bind clobbered the address learned from data | `TestRebindDoesNotClobberTheLearnedAddress` |
| Coordinator only sent peers on hello, so candidates went stale | `TestPingIsAnsweredWithTheCurrentPeerList`, `TestPeersAreToldWhenADeviceMoves` |
| Repunch timers ran independently when punching needs simultaneity | `TestPunchesAgainForARelayedPeer` |
| Agent sent `rx_bytes`/`tx_bytes`; the panel reads `rx_delta`/`tx_delta` | `TestHeartbeatUsesTheFieldNamesThePanelReads` |

### Fixed — the drill harness

**`akconnect-relay` documented a `--panel` flag it does not have.** The relay
does not talk to the panel at all: it authorises sessions from the ticket the
coordinator signed, and its usage counters are logged rather than reported.
The usage text now says so. What that means for billing is recorded in
VERIFICATION_REPORT.md rather than left implied.

### Known limitations

**The billed figure is agent-reported.** The panel meters `rx_delta` +
`tx_delta` from each end's heartbeat, and the `accounting` drill now checks
that against the relay's own count of what it forwarded — so the arithmetic is
right. A customer running a modified agent can still under-report. The relay's
counters are the ones nobody outside our infrastructure can touch, and they
still go to a log file and nowhere else.

**Relay failover takes about fifteen seconds.** Three unanswered rebinds at
five-second intervals, which is a floor: nothing tells an agent a relay has
died except the absence of a reply. The measured cost before this work was 37%
loss over a 40-second window.

---

## [1.2.1] — 2026-09-21

### Fixed

**Traffic counters were never recorded.** The agent sent `rx_bytes` and
`tx_bytes`; the panel reads `rx_delta` and `tx_delta`. Every heartbeat was
silently discarded for accounting purposes. The agent now sends deltas, which
is what the panel accumulates — sending totals would have re-added the whole
session on every beat even once the field names matched.

**The connection indicator never showed amber.** The partial for 🟢 direct /
🟡 relay / 🔴 offline has existed since Phase 1 and is rendered in three views,
but the agent only ever reported "direct" or values the panel rejected. It now
reports the real path from discovery, so a relayed device shows as relayed.
Amber if *any* peer is relayed: an operator needs to know some of this device's
traffic crosses our servers, not that all of it does.

---

## [1.2.0] — 2026-09-21

Phase 3. Peers that cannot reach each other at all now connect, and stop
relaying the moment they can do better.

### Added

**`services/relay`** — a UDP forwarder for pairs that cannot punch through.
It carries already-encrypted WireGuard packets, is never a party to the peers'
handshake, and holds no WireGuard key: a compromised relay yields volumes,
timing and addresses, never plaintext. Each side of a pair gets its own
allocated port, so every packet is unambiguously attributable without guessing
from source addresses.

**Tickets.** A relay forwards only for a pair the coordinator has authorised.
The ticket names both ends, carries the tenant and an expiry, and is MAC'd with
a secret the coordinator and relay share — so an agent carries it but cannot
alter it, and a relay with no ticket is not an open reflector. Tested against
forgery, alteration of every field, and expiry.

**Relay allocation in the coordinator**, region-aware, with both ends offered
the same relay at once rather than waiting for the second to give up
independently.

**Silent upgrade.** A relayed pair keeps trying for a direct path and switches
without dropping a packet, because pointing WireGuard at a relay and pointing
it at a peer are the same call.

**Per-tenant byte accounting** in the relay, reported on a timer.

**`services/lab/topology.sh symmetric`** — a lab mode where hole punching
cannot work by construction.

### Fixed

Three faults found by running the relay, none visible by reading it:

**The relay replied to the wrong mapping.** It forwarded to the address the
bind arrived from, but under symmetric NAT the mapping used to reach the
control port is not the one used to reach the data port, and the reply was
dropped. The return address is now learned from the data socket, where the
packet that just arrived proves the path.

**Re-binding broke the path it was meant to keep alive.** A periodic re-bind
arrives on the control flow and was overwriting the data-learned address —
every twenty seconds, forever. A learned address now outranks a bind.

**The upgrade never fired.** Candidate addresses were refreshed only on hello,
and a settled agent only pings, so after a network change both ends punched at
addresses that no longer existed. Worse, the retry timers were independent, and
hole punching needs both ends to punch at the same instant. The coordinator now
returns the peer list on every ping and tells peers when a device moves, and
that message — which both ends receive together — is what triggers the punch.

### Verified

In the lab, under NAT that makes hole punching impossible: relayed tunnel at
0% loss. Then, switching to a full-cone NAT with traffic flowing throughout,
both ends upgraded to direct with **98 packets and zero loss**, and a handshake
age proving the WireGuard session was never reconnected.

### Not yet

Relay selection is round-robin within a region, and `devices` has no region
column yet, so today it picks the first configured relay. Latency-based
selection needs the agents to measure and report, which they do not.

---

## [1.1.2] — 2026-09-21

### Added

**`selftest`** — the agent checks everything that depends on the operating
system and reports each as pass or fail with the actual error: privileges, the
tunnel driver, a key store round trip, whether the stored identity still
unseals, and creating and removing a real adapter. It never destroys an
existing identity — the round-trip check restores whatever was there.

This exists because those are precisely the parts that cannot be tested from a
build machine. A tester now gets "DPAPI unseal failed with X" rather than "the
agent did not start".

**The Windows test pack** — `services/kit/akconnect-windows-test-pack.zip`,
committed so it can be downloaded from one URL. Agent for amd64 and arm64,
**`wintun.dll` bundled** for both, the Wintun licence, a twelve-stage runbook
where every step is a command to paste and an expected output to compare, a
collector, and a packager.

The collector records failures rather than stopping at them. Verified by
running it under PowerShell 7.4 on Linux, where 24 of 27 checks fail: it still
produced a complete report that reached the end, with every failure recorded in
place and nothing escaping to the console.

**`docs/WINTUN-LICENSING.md`** — Wintun's source is GPLv2, but the prebuilt
signed DLLs ship under a separate licence whose clause 3(d) permits
redistribution alongside software using only its documented API. That is us, so
the DLL is now bundled rather than asked for. The pinned checksums are enforced
by the build, so an upstream change cannot silently alter what we ship.

**`docs/CODE-SIGNING.md`** — OV against EV against Azure Trusted Signing, Indian
pricing, the documents an Indian entity needs, what changes in the build, and
how agent auto-update should verify signatures once a certificate exists.

---

## [1.1.1] — 2026-09-21

### Added

**Windows service support** — `service install|uninstall|start|stop|status`.
Installing registers the agent to start automatically as LocalSystem, sets
restart-on-failure, registers an event log source, and creates the firewall
rule itself. That last part is not a convenience: the Windows Firewall prompt
appears on the interactive desktop, and a service running as LocalSystem has no
desktop, so nobody would ever see it and the rule would never be created — the
agent would look healthy while no peer could reach it.

**Preflight checks.** The agent now refuses to start with an explanation rather
than a driver error when it is not elevated, or when `wintun.dll` is missing.

**A live status file.** The running agent publishes what it knows —
interface, listen port, reflexive address, coordinator and panel reachability,
and per-peer path, endpoint, handshake age and byte counters — so `status` and
a support script can answer "is this connected, and how" without being the
process that holds the tunnel. It carries no key and no device token.

**`services/kit/`** — the field test kit: cross-built binaries, `SHA256SUMS`,
a runbook, and evidence collectors for Windows and Linux.

**`docs/RELAY-DESIGN.md`** — the Phase 3 relay, re-planned as a normal path
rather than a rare fallback.

### Fixed

`wintun.dll` was never shipped and nothing checked for it. wireguard-go loads
it from the application directory or System32, so the Windows agent would have
failed on first run with a bare LoadLibrary error from inside a driver load.
Found by reading the dependency, not by running it — which remains impossible
here.

### Still unverified

The Windows agent has never executed. There is no Windows host, hypervisor or
nested virtualisation in the build environment, and Wine would give false
results for DPAPI, Wintun, the service manager, the firewall and Defender
alike. See `VERIFICATION_REPORT.md`.

---

## [1.1.0] — 2026-09-21

Phase 2 begins. Packets now move between devices, which is the first time that
sentence has been true.

### Added

**`services/agent`** — the device agent, in Go. It generates a Curve25519
identity that never leaves the machine, enrols against the panel, waits to be
approved, and brings up a WireGuard interface carrying only the overlay's own
prefixes. The data plane is wireguard-go (MIT), in userspace: no kernel module,
the same install on a stock Windows box and a locked-down Linux host, and a
licence that permits closed-source distribution. The private key is stored in a
0700/0600 file on Linux and sealed with DPAPI under an Administrators-only ACL
on Windows.

**`services/coordinator`** — peer rendezvous, in Go. It observes where each
agent's packets come from, and tells the peers the panel's ACL allows. It never
carries data: once two agents know where to find each other they talk directly,
and stopping the coordinator does not disturb an established tunnel.

**`services/shared/disco`** — the discovery protocol. It shares the agent's
WireGuard UDP socket rather than opening its own, because a second socket gets
a second NAT mapping and would teach peers an address that does not work. The
two protocols are distinguishable on sight: WireGuard's first byte is 1 to 4,
and a discovery packet starts with `A`. Announcements are sealed with NaCl box
under the device's own key, so a packet authenticates its own sender and the
device token never crosses the wire in cleartext.

**`services/lab/topology.sh`** — two hosts on separate network stacks, and a
mode that puts each behind its own NAT. Not a simulation: real namespaces, real
routing tables, real packets.

**Panel** — `POST /api/v1/coordinator/verify` and `/coordinator/endpoints`,
authenticated by HMAC over the timestamp and body with the shared secret the
installer already provisioned. The coordinator holds no database and no copy of
the ACL: every decision about who may talk to whom comes from the panel, so a
revocation cannot be stale in a second copy. `coordinator.public_key` is now
published in the agent configuration.

### Verified

Two hosts behind separate NATs, unable to reach each other's addresses at all
(100% packet loss before the tunnel), pinging each other by virtual IP with 0%
loss after hole punching. Both routing tables keep their default route on the
physical interface. A revoked device stopped passing traffic 7.7 seconds after
revocation. With the panel *and* the coordinator killed, an established tunnel
carried 112 consecutive pings without loss.

### Not yet

R1 and R2 are verified on Linux, in a lab, between namespaces. Not on Windows,
not on two real ISPs, and not behind a real carrier-grade NAT. See
`VERIFICATION_REPORT.md`, which says so at the top rather than the bottom.

---

## [1.0.9] — 2026-09-20

### Fixed

`BACKUP_DB` described its own work as *"files and database both match their
checksums"*. Since 1.0.7 it also opens the archive and re-reads the dump, and
saying only what a checksum proves is precisely how a zero-byte archive came to
be reported as verified. The summary now says both artefacts were read back;
the per-check lines beneath it already carried the specifics.

`VERIFICATION_REPORT.md` is corrected against the record the panel kept of
itself: nine update runs, six rolled back, not four and four. The count came
from memory of the drills rather than from `app_updates`, which is the wrong
source for a document whose whole claim is that it reports what was run. It now
prints that table. Also added: a final drill in which 1.0.8 — the build the
report describes — performs the rollback itself and restores 215 of 215 files.

---

## [1.0.8] — 2026-09-20

### Added

`VERIFICATION_REPORT.md` — what was actually run against a real installation
and the real GitHub repository, section by section, with the output. It leads
with the largest caveat rather than burying it: the control plane is built and
verified, the data plane is not written, and by the project's own rule R7 this
is therefore not yet a shippable product.

`tests/scale.php` — the scale drill from §20.F. It builds a tenant of a given
size through the real services and times the queries the panel runs against it,
so a query that is fine with ten devices and quadratic with a thousand shows up
before a customer finds it. Run on demand, not as part of the default suite:

    php tests/scale.php --devices=1000

It is what surfaced the one performance finding left open: address allocation
costs a fixed toll proportional to the size of a network's CIDR rather than to
the number of devices in it, because the claim query orders the whole free pool
before taking one row from it.

---

## [1.0.7] — 2026-09-20

### Fixed

**A backup is read back before it is called a backup.** Running an update
against a deliberately full disk produced a zero-byte `files.tar.gz` and a
truncated database dump, and the pipeline reported *"Backup #7 written
(10.2 KB)"* followed by *"Backup verified — files and database both match their
checksums"*, then applied the update. The verification was tautological: it
hashed the file it had just written and compared that to the hash it had just
computed. A truncated backup is still valid gzip, still has a plausible size,
and still hashes consistently with itself — so nothing a checksum can see
distinguishes it from a complete one.

Three changes close it. `gzwrite` is now checked for a *short* write, not only
for `false`, since on a full disk it returns a positive number smaller than
asked for. Every dump ends with an explicit completion marker, and the dump is
re-read after writing to confirm the marker arrived. Every archive is opened
after writing and its entries counted against the number of files that went in.
`verify()` performs both of those readbacks in addition to the checksum, and
reports what it found — *"228 files, archive reads back cleanly"*, *"dump ends
with its completion marker"* — rather than the unfalsifiable *"sha256 matches"*.

**A rolled-back update no longer reports the wrong step.** Restoring the
database rewinds `app_updates` to whatever the backup caught, so the history
showed the step the run had reached when the backup was taken rather than where
it actually stopped. The step is captured before the restore and written back
with the status.

### Changed

Archive writing, readback and extraction move to `ArchiveStore`, which brings
`BackupManager` back within the file-size budget and gives the readback rule a
single home.

---

## [1.0.6] — 2026-09-20

### Fixed

*Roll back to this point* was offered for any successful update whose
`journal_path` column was set. That column stays set for the life of the row,
while the journal itself is pruned with the backups and discarded once a
rollback has consumed it — so the button could still appear for an update whose
undo list was long gone, and pressing it would reverse migrations and restore
the database while putting no files back. That is the exact failure retaining
the journal was meant to prevent. Availability is now decided by whether the
journal is actually on disk, and the history and detail pages say plainly when
it has been pruned and a backup is the way to recover instead.

---

## [1.0.5] — 2026-09-20

### Fixed

The list of copied migration files is recorded after the migration runner
rather than before it. The column it is written to was itself added by a
migration, so on the very update that introduces it the write would have come
first and failed. It is written from a `finally` block, because a migration
that fails still has to be rolled back and the rollback needs the list — and a
failure to record it is logged rather than allowed to abort an otherwise good
update, since the cost is a rollback that leaves two files behind.

---

## [1.0.4] — 2026-09-20

### Fixed

**A rollback left the new version's migration files behind.** `MIGRATE` copies
a release's migration files into `database/migrations` before running them, so
the ledger and the disk agree even if a later step fails — but nothing recorded
which files were new. A rollback therefore reversed the migration in the
database and left its file in place, where `migrate.php --status` reported it
as pending, inviting an operator to re-apply a migration from the very version
they had just rolled back from. `app_updates.copied_migrations_json` records
the filenames, and the rollback removes exactly those.

**A lost transaction no longer buries the error that caused it.** MySQL commits
implicitly on any DDL, and on `LOCK TABLES`, `UNLOCK TABLES` and `TRUNCATE`.
The transaction ends and every savepoint under it is destroyed, which the
nesting counter cannot see, so the next `ROLLBACK TO SAVEPOINT` raised error
1305 on top of whatever the caller was actually reporting. `DB::commit()` and
`DB::rollback()` now check the connection rather than trusting the counter, and
log a warning — the work is already committed either way, and an outer rollback
that silently commits must not pass unnoticed.

**The verification suite no longer leaks rows into its own database.** Its
migration-ledger test runs the real migration runner, whose `ALTER TABLE`
committed the transaction the suite wraps itself in — so whenever a migration
was genuinely pending, every fixture created up to that point was committed for
real while the suite still reported success. Six stray tenants had accumulated
this way. The ledger test now runs before the transaction opens, and a new
check compares row counts across the whole suite so the guarantee is verified
instead of merely asserted in a comment.

**The HTTP suite reset its own rate limiter.** It deliberately trips the login
limiter, and enrolment has a limiter of its own; left behind, that state made
the next run fail with 429s that looked like broken endpoints.

---

## [1.0.3] — 2026-09-20

### Added

`app_backups.db_method` records which dumper wrote a backup's database dump.
A dump `mysqldump` produced for a schema with stored generated columns cannot
be replayed at all, and until 1.0.2 the panel could produce one. Those backups
are still on disk and look no different from good ones, so the recovery
instructions on an update's detail page now say plainly when the dump in front
of an operator is one that will fail, and point at a newer backup instead.

### Fixed

A rollback rewrote `VERSION` from the recorded previous version rather than
letting the journal's byte-exact copy stand, appending a trailing newline the
original did not have. Everything reads the file through `trim()`, so nothing
misbehaved — but the next update saw the file as locally modified. The rewrite
is now a fallback, for a rollback that never reached `APPLY` and so has no
journal entry to restore.

---

## [1.0.2] — 2026-09-20

Three fixes to the update system, each found by running a real rollback
against a live MariaDB rather than by reading the code. Every one of them
made a backup unrestorable, which is the same as having no backup.

### Fixed

**A dump no longer writes back generated columns.** `mysqldump` lists STORED
generated columns in its `INSERT` statements — with or without
`--complete-insert` — and the restore then fails outright with *"the value
specified for generated column ... has been ignored"* (error 1906). The
pure-PHP dumper had the same flaw, because it selected `*`. It now reads the
writable columns from `information_schema` and emits an explicit column list,
and a schema containing generated columns always takes the PHP path, since
`mysqldump` cannot be made to produce a restorable dump for one.

**A failed restore no longer strands the connection.** A dump that brackets
its tables in `LOCK TABLES` left the session holding those locks when a
statement in between threw, so every later query answered *"table ... was not
locked with LOCK TABLES"* — turning one restore failure into a rollback that
could not even record why it had failed. Locks are now released on the way
out, and only when they are actually held, because `UNLOCK TABLES` commits an
open transaction as a side effect.

**The rollback journal survives a successful update.** It was discarded at
`FINALISE`, so *Roll back to this point* in History could reverse migrations
and restore the database but silently put no files back, reporting *"nothing
to undo"*. The journal is now kept and pruned on the same retention count as
the backups it pairs with, and discarded only once a rollback has consumed it.

### Added

`tests/BackupTests.php` — a dump-and-restore suite that reproduces all three
faults against a live database: a probe table with a stored generated column
and values carrying quotes, semicolons, newlines, backslashes and multi-byte
UTF-8 is dumped, dropped, restored, and compared row for row.

---

## [1.0.1] — 2026-09-21

### Added

`devices.last_handshake_at`, recorded separately from the control-plane
heartbeat, so the dashboard can tell *"the agent called us"* from *"the tunnel
is up"*.

---

## [1.0.0] — 2026-09-20

First release. Phase 1 of the delivery plan: the complete control plane,
including the GitHub auto-update system.

### Added

**Framework** — router with typed placeholders, kernel with unified error
handling for both HTML and JSON callers, PDO layer that only prepares,
database-backed sessions (so the web tier is stateless behind a load
balancer), CSRF with a per-session secret, sliding-window rate limiting with a
MySQL fallback, structured JSON logging with redaction and rotation, and an
SMTP client with no external dependency.

**Multi-tenancy** — `TenantScope` as the single place that decides whose rows
a request may see. It fails closed: a tenant-scoped query with no resolvable
tenant throws rather than running unfiltered. A row belonging to another
customer returns 404, not 403.

**Authentication** — Argon2id passwords, TOTP two-factor with single-use
recovery codes, progressive lockout, audited impersonation that drops platform
powers for its duration, and five roles with a fixed permission matrix.

**Networks and devices** — networks with materialised IPv4 pools, device
enrolment via short-lived join codes, approval-gated activation, ACL authoring
and compilation, subnet routes, and the agent configuration endpoint.

**Auto-update** — an eleven-step resumable pipeline driven identically by the
web UI and the CLI, with a verified pre-update backup, a per-file rollback
journal, migration tracking, post-update health checks and automatic rollback.
Protected paths are never written, deleted or restored over. Archives are
checked for traversal, absolute paths, symlinks and zip-bomb expansion, and
every staged PHP file is parsed before it goes live. Optional ed25519 manifest
signatures.

**Backups** — file and database backups with checksums recorded at creation
and re-verified before any restore. `mysqldump` when the host allows it, a
complete pure-PHP dumper when it does not. When `uploads/` is too large to
include, the backup records that explicitly rather than implying completeness.

**Installer** — six-step wizard plus an unattended CLI equivalent.
Requirements are probed rather than inferred, and every failing row says what
to do about it.

**Interface** — responsive dashboard, dark and light themes, live updates over
Server-Sent Events with polling fallback, keyboard-accessible forms, English
and Gujarati translations, and a PWA manifest. No build step: no npm, no
Composer.

**Verification** — 425 assertions covering tenant isolation, split-tunnel
enforcement, plan limits, RBAC, SQL injection, CSRF, rate limiting and the
agent endpoints, plus static analysis that fails the build on unscoped models,
interpolated SQL, unescaped view output or a stale documentation citation.

### Fixed during development

Each of these was found by a test written before the fix, and each is now
covered permanently.

* A composite `UNIQUE` over a nullable `tenant_id` did not constrain platform
  rows, because SQL treats every `NULL` as distinct — so platform settings
  duplicated on every upsert. Resolved with a stored generated column.
* `TenantScope::acrossAllTenants()` never actually lifted the tenant filter
  when an actor was signed in, making it a no-op exactly where the platform
  dashboards need it.
* `Validator` could not express a value casting to boolean `false`: pass/fail
  and the cast value shared one return channel, so `false` looked like a
  validation failure.
* The protected-path check stripped the leading dot from a dotfile, so `.env`
  was not recognised as protected.
* PDO with native prepares rejects a named placeholder used twice in one
  statement; one query did. A static test now fails the build on any such
  query.
* The QR encoder placed format information in reverse bit order, skipped
  alignment patterns centred on the timing row or column, and omitted version
  information for versions 7 and up. Now verified by decoding its output with
  an independent decoder across versions 1–10.
* `.env` sat in the web root, protected only by a per-file `.htaccess` rule
  that nginx ignores and Apache skips under `AllowOverride None`. It now lives
  in `config/`, which is denied as a whole directory.

### Not in this release

The Go data plane — agent, coordinator and relay — is Phase 2 and is not
built. Until it lands the panel manages networks and devices but no traffic
flows between them. [PROGRESS.md](PROGRESS.md) and
[VERIFICATION_REPORT.md](VERIFICATION_REPORT.md) say exactly what has and has
not been demonstrated.
