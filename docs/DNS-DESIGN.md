# Split DNS

§18. Names like `nvr.hotel-abc.acme.internal` for overlay devices and for
machines behind a gateway, for our domain only, with the customer's own name
resolution untouched.

## Why the names matter more than they look

Before subnet mapping, a name was a convenience: `10.99.0.3` is not hard to
remember and a technician could always read it off the panel.

Since subnet mapping it is closer to a requirement. The address a technician
connects to is one the panel invented — `10.128.0.50` rather than the
`192.168.1.50` printed on the recorder — and it is different for every site.
Expecting somebody to carry a table of those in their head, per customer, is
how a feature goes unused and the product gets a reputation for being fiddly.

So the name is the thing an operator writes down and the thing a technician
types, and the addresses stay an implementation detail.

## The two kinds of name

```
laptop.acme.internal            a device running our agent
nvr.hotel-abc.acme.internal     a machine behind the gateway "hotel-abc"
```

The first comes from the devices table. The second cannot: an NVR, a printer
and a DVR have no agent, no key and no row anywhere. `route_hosts` is where an
operator writes down which address inside an advertised range is which.

**The address stored is the real one** — the one on the label on the machine,
the one somebody standing in front of it can read. The address the name
resolves to is derived from the route's mapping when the configuration is
built, because a route withdrawn and re-advertised can be given a different
prefix and a stored copy would then be quietly wrong.

## The zone is always under `.internal`

ICANN reserved that TLD for private use in 2024, so a name here can never
collide with a real one.

Refusing anything else is not tidiness. A network whose search domain could be
set to `google.com` would make every one of its agents authoritative for a
domain somebody else owns, and the first anyone would know about it is a
technician's browser going somewhere unexpected. `DnsZone::validate` refuses
it, on create and on update.

## What the agent runs, and what it refuses

`services/agent/internal/dnsd` is an **authoritative server for one zone**, not
a resolver. It answers from a table the panel sent, and:

| Question | Answer |
|---|---|
| A name in the zone it holds | the A record |
| A name in the zone it does not hold | NXDOMAIN, authoritatively |
| Anything else | REFUSED |

**It never forwards.** There is no code in it that speaks to another DNS
server. That is the whole safety argument, and it is structural rather than a
matter of configuration: a forwarding resolver on a customer's machine is a
thing that can be pointed at, misconfigured into the path of their ordinary
browsing, or blamed when their bank's website is slow. This cannot be any of
those.

It listens on loopback — `127.0.0.55` first, then upward. `127.0.0.53` and
`127.0.0.54` are systemd-resolved's own and are never taken, even when they are
free: the first would break the machine's own name resolution, and resolved
refuses to be *pointed* at either (`resolvectl dns <link> 127.0.0.54` answers
"Invalid DNS server address", because a link whose server is resolved's own
address is a loop). They become bindable whenever its stub listener is off,
which is how every machine running dnsmasq or Pi-hole beside it is configured
— so "it was free" is exactly the wrong reason to take one. Loopback rather
than the overlay address, because a resolver reachable from the tunnel would be
one every peer could query, and the names it holds are the map of a customer's
site.

The wire format is implemented directly rather than taken from a library. It is
about a hundred lines for the one question type that matters, the project has
no other third-party dependency to speak of, and a DNS library is a large
amount of parsing exposed to the network for a feature that needs almost none
of it.

## Pointing the operating system at it

This is where the "split" in split DNS is won or lost, and where the obvious
implementation is wrong.

Writing a nameserver into `/etc/resolv.conf`, or setting one on the adapter in
Windows, makes us the resolver for **everything** the machine looks up and
leaves us forwarding the rest. That is a takeover, not a split, and it is not a
trade this product gets to make.

Three mechanisms, in order of preference:

| Mechanism | Where | What it does |
|---|---|---|
| systemd-resolved | Linux, where it is running | `resolvectl domain <iface> ~zone` routes one domain to one server, per interface, leaving everything else alone |
| NRPT | Windows | the same thing by namespace; it is what Windows' own VPN clients use for split DNS |
| the hosts file | everywhere else | writes the answers down; touches no DNS configuration at all |

The hosts file is a worse mechanism in every way except the ones that matter
here. It is consulted before DNS by the standard resolver on both platforms, so
the names resolve for everything on the machine; it affects no other name; and
the promise that a customer's own resolution is untouched stops being a promise
about a mechanism, because there is nothing to interfere with. What it cannot
do is wildcards, and our zone is an enumerated list.

It was added after the first version of this shipped without it. On a machine
with no systemd-resolved — a Debian server, a container, a machine where an
administrator turned it off — the resolver was running and nothing was pointing
at it, so no name resolved. "Install systemd-resolved" is not an answer to give
a customer.

### Writing to a file that is not ours

`/etc/hosts` belongs to the machine. Everything we write goes between two
markers, everything outside them is copied through byte for byte, and the file
is replaced by an atomic rename so a crash halfway cannot leave a machine
unable to resolve `localhost`. A block whose end marker is missing — an
interrupted write, or somebody editing inside it — is recovered from rather
than duplicated around.

Nine tests cover that file, and they are all about what is *still there*
afterwards rather than what we added.

## Lab proof

`services/lab/run-all.sh dns`, on the topology where the technician's own LAN
and the customer's are both `192.168.1.0/24`:

| Check | What it proves |
|---|---|
| `dns/device` | `beta.<zone>` resolves to the peer's overlay address |
| `dns/lan-host` | `nvr.beta.<zone>` resolves to the **mapped** address, not to `192.168.1.50` |
| `dns/refused` | `www.google.com` is REFUSED by our resolver, not looked up |
| `dns/nxdomain` | an unknown name in the zone is NXDOMAIN, authoritatively |
| `dns/untouched` | the machine's own resolver configuration is byte-identical |
| `dns/public` | public lookups still arrive at the stand-in ISP resolver |
| `dns/not-in-path` | our resolver was never asked about a public name |
| `dns/os-routing` | the operating system really is resolving the zone, and says which mechanism |
| `dns/system` | `getent hosts nvr.beta.<zone>` answers, with no server named |
| `dns/cleanup` | stopping the agent removes the names again |
| `dns/localhost` | `localhost` still resolves afterwards |

The second half of that table is the important half. Our resolver answering
correctly proves nothing about whether the customer's DNS was left alone; that
is proved by watching the customer's resolver and seeing the public lookups
still arrive there.

### A fixture that lied, briefly

`dns/cleanup` failed on its first run: the name still resolved after the agent
stopped. The hosts block had been removed correctly — the lookup had fallen
through to DNS, and the stand-in ISP resolver in the lab answered *everything*,
including `.internal`.

No real resolver does that, because `.internal` is not delegated. The stand-in
now returns NXDOMAIN for it, which is both realistic and what makes the check
mean what it says.

## What is not proven

**NRPT has never run.** Stage 14 of the Windows test pack covers it. Until
those results come back, Windows name resolution is an argument from the code.

**systemd-resolved has never run either.** No machine in this lab has it, so
the Linux path that is exercised is the hosts-file fallback. The resolvectl
path is written, reviewed and unexercised.
