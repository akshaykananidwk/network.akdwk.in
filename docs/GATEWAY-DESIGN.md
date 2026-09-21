# Subnet-router mode

**Status:** agent side built and unit-tested; panel side and lab proof in
progress; Windows written and never executed.

---

## Why this is the feature that matters

A CCTV recorder does not run our agent. Neither does a printer, a DVR, a door
controller or a till. They are the machines a customer actually wants reached,
and they are the reason a support engineer drives to a hotel.

So one PC at the site — a Windows or Linux machine that *can* run the agent —
routes for the LAN behind it. AK Support reaches the NVR at its own LAN
address, through that PC, over the tunnel.

Without this the product connects laptops to laptops, which is a smaller
business.

## What already existed

More than expected. The control plane has had this since Phase 1:

- a `routes` table with `destination_cidr`, `via_device_id`, and `approved`
  defaulting to **0**, so a route is advertised only once an administrator has
  said so;
- `devices.is_gateway`;
- `AclService::buildPeerSet` adding a gateway's routed prefixes to its
  `allowed_ips`, so WireGuard will accept traffic for them from that peer;
- `netcfg.Build` installing the advertised prefixes as routes on the client,
  with R1 validation refusing anything that contains a default route.

A client already got `192.168.1.0/24 via akc0`. What it did not get was
anything at the other end willing to forward.

## What the gateway has to do

**Forward**, between the tunnel and the LAN. A kernel setting on both
platforms.

**Translate**, because the NVR at 192.168.1.50 has no route back to
10.99.0.0/24 and nobody is going to add one to a camera recorder. Traffic
leaving the gateway towards the LAN is translated to the gateway's own LAN
address, and the answers come back to it. Routing properly — with a return
route on the LAN — is cleaner and is not available at a hotel reception desk.

Both are scoped narrowly. The forward rules match *from the overlay, to this
prefix, on this interface*; a rule that accepted anything would turn the
gateway into an open router for whatever else is on its LAN. The return path
requires `ESTABLISHED,RELATED`, because without it the LAN would have a way
*into* the tunnel.

### Linux

`sysctl net.ipv4.ip_forward=1`, plus three iptables rules per prefix: forward
out, forward back for established flows, and a MASQUERADE scoped to
overlay-to-prefix. Rules are added with `-C` first so re-applying a
configuration does not accumulate duplicates on every poll.

IP forwarding is deliberately **not** turned off again on withdrawal. It may
have been on before the agent started, and turning it off could break a NAS, a
hypervisor or a developer's container host that was relying on it.

### Windows

Harder, and the reason this needs real hardware.

Windows has no iptables, and its two routing facilities are not equivalent:

- **Routing and Remote Access (RRAS)** is the full router role. It is a Windows
  *Server* feature. It is not on the Windows 10 and 11 machines sitting on
  hotel reception desks, and requiring it would mean requiring a server licence
  to share a camera recorder.
- **Internet Connection Sharing** does NAT on client Windows, but it picks its
  own address range, cannot be pointed at a specific prefix, and fights with
  anything else on the machine using 192.168.137.0/24.

What client Windows *does* have is the same NAT engine RRAS uses, reachable
through `New-NetNat` in the Hyper-V networking PowerShell module — present on
Windows 10/11 Pro and Enterprise without the Hyper-V role being enabled.
Forwarding is `netsh interface ipv4 set interface … forwarding=enabled`.

**None of this has run on Windows.** It compiles, the commands are the
documented ones, and the ordering matches what the runbook asks a tester to
check by hand. Treat it as untested until the field kit says otherwise.

Known risks to check on hardware, in order of how likely they are to bite:

1. `New-NetNat` may require the Hyper-V networking feature on Home editions, or
   on builds where it has been removed. If so, the gateway is Pro-and-above,
   which needs saying in the sales material rather than discovered by a
   customer.
2. The global `IPEnableRouter` registry switch needs a reboot on some builds.
   The runbook checks forwarding actually works rather than assuming the
   command took effect.
3. A machine that already has ICS or another NAT instance will refuse an
   overlapping prefix. The agent removes its own instance by name before
   creating it; it does not touch anyone else's, and it should not.
4. Windows Firewall may drop forwarded traffic even with forwarding enabled.

## ACLs on routed traffic

This is the part that needed new thinking.

The filter keys on a peer's overlay address. A packet to 192.168.1.50 has a
destination that is not a peer at all, so the first version of the filter
refused it as "not a permitted peer" — the correct answer to the wrong
question.

Rules for routed traffic name the destination by **LAN address**, because that
is how an operator thinks about it: "AK Support may reach 192.168.1.50 on
tcp/554" is about the NVR, not about the PC that happens to route for it. So
each advertised prefix carries its own filter set, and the **most specific
prefix wins** — "the LAN is reachable, except the till" has to mean the
exception.

The source-port bypass is closed here too, and there is a test that says so:
binding 554 locally must not open port 80 on a machine behind a gateway any
more than on a peer.

## Overlapping LAN subnets

Every hotel uses 192.168.1.0/24. This has to not matter, and mostly it does
not:

- **Across tenants** the prefixes never meet. A device belongs to one tenant,
  and traffic for 192.168.1.0/24 is resolved inside that tenant's overlay.
- **Within one network**, two gateways advertising the same prefix *is*
  ambiguous — the client has one routing table and cannot have two routes for
  one destination. The panel must refuse the second, with a message that says
  which device already advertises it.

**The case that does not work today, and it is the one AK Support cares
about:** a single support laptop connected to *many* customers at once. A
device belongs to exactly one network (`devices.network_id` is a single
column), so reaching twenty hotels means twenty enrolments, and one agent
install holds one. Twenty hotels all using 192.168.1.0/24 would collide in one
routing table even if it did.

Neither multi-network membership nor per-network routing tables exist. This is
flagged rather than solved, and it is the next architectural question after
this feature lands — see BACKLOG.md.

## Lab proof

A namespace called `nvr` with **no agent at all**, on the gateway's LAN,
reachable on tcp/554 through the gateway and refused on tcp/80. If the NVR
namespace can be reached without running our software on it, the feature is
real.
