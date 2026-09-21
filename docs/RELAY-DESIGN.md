# Phase 3 — the relay

**Status: designed, not built.**

This document exists because the relay's priority changed. It was scoped as a
rare fallback for awkward networks. It is now a **launch requirement**, and the
design has to change with the priority: a path that a meaningful share of
devices will use permanently is not a fallback, it is a second normal mode.

---

## Why the change

Indian fixed broadband and mobile are heavily carrier-grade NAT'd, and a large
share of CGNAT deployments are **symmetric**: the carrier allocates a different
external port per destination. Hole punching works by getting both ends to
predict each other's external address, and against a symmetric NAT that address
is unpredictable by construction. No amount of retrying fixes it.

The lab result in `VERIFICATION_REPORT.md` — two hosts behind NAT connecting
directly — used `iptables MASQUERADE`, which preserves the source port and maps
endpoint-independently. That is the easy case. It says the machinery works; it
says nothing about a Jio 4G connection.

So the planning assumption is now: **expect a material fraction of devices to
be relayed, indefinitely.** If field testing shows otherwise, that is a pleasant
surprise, not the basis of the design.

---

## What changes because the relay is normal

If the relay were rare, it could be slow, single-region, unmetered and
best-effort. Because it is normal:

| Concern | A fallback could | A normal path must |
|---|---|---|
| Latency | Add whatever it adds | Be region-aware; an Ahmedabad shop must not relay via Frankfurt |
| Capacity | Be one box | Be horizontally scalable, because it carries real customer traffic |
| Cost | Be ignored | Be metered per tenant, because relayed bytes are the main marginal cost of the product |
| Failure | Drop the session | Fail over to another relay without dropping the tunnel |
| Privacy | Be hand-waved | Be provably unable to read the traffic, and be *documented* as such, because customers will ask |
| Observability | Be silent | Report which devices are relayed, and why, in the panel |

The last one matters commercially: "why is this site slow" must be answerable,
and the answer is often "it is relayed, because its carrier is symmetric NAT".

---

## Design

### The relay cannot read the traffic

This is the property everything else hangs off. The relay forwards **already
encrypted WireGuard packets** between two peers that have each other's keys. It
never holds a WireGuard private key and is never a party to the noise handshake.
A compromised relay yields traffic volumes, timing and endpoints — never
plaintext.

Say this explicitly in customer-facing material, because a self-hosted overlay
that silently proxies traffic through the vendor is exactly what a customer
buying a private network is trying to avoid.

### Addressing: a relay is a peer with a fixed address

Rather than inventing a second transport, a relayed session keeps the same
WireGuard session and only changes where its packets are sent. The agent points
the peer's endpoint at the relay, and the relay forwards to the other end.

This is why `Tunnel.SetPeerEndpoint` already exists and is used by discovery:
switching between direct and relayed is the same operation, and the WireGuard
session survives it. **A silent upgrade from relay to direct therefore costs
nothing and drops no packets** — the handshake is not redone.

### Session setup

1. Both agents are already announced to the coordinator and have failed to
   punch (a bounded number of attempts over a few seconds).
2. Each asks the coordinator for a relay allocation for that peer.
3. The coordinator picks a relay — nearest region that both ends can reach —
   and issues both a short-lived **session ticket**: an opaque token binding
   *(relay, peer pair, expiry)*, signed with the relay's shared secret.
4. Each agent sends the ticket to the relay, which learns that agent's address
   and pairs the two halves.
5. From then on the relay forwards datagrams between the two addresses.

The ticket is what stops the relay being an open reflector: no ticket, no
forwarding, and a ticket names exactly one peer pair.

### Keep trying to go direct

A relayed pair keeps punching in the background, at a decaying interval —
every 30s for the first few minutes, then every few minutes. Networks change:
a device moves from 4G to the shop's wifi, a carrier reassigns, a customer
fixes their router. When a punch finally succeeds, the agent repoints the peer
endpoint and stops relaying. Nothing is torn down and the tunnel does not drop.

The panel should show that transition, because it is the difference between a
site that costs us bandwidth and one that does not.

### Metering

Relayed bytes are counted per tenant, per relay, and reported to the panel on
the existing usage-counter path. This is both a billing input and the capacity
signal for adding relays.

---

## Selection: measured, not assumed

The coordinator cannot tell how far a device is from a relay. It knows the
address a NAT presented to it, which behind CGNAT belongs to the carrier and
not to the customer, and it knows whatever region label somebody typed into the
panel. Neither is a latency.

The device can measure it, so the device does:

1. The panel hands every agent the relay fleet in its configuration — that
   already happened; nothing new was needed on the control plane.
2. The agent probes each relay on **the same UDP socket WireGuard uses**. This
   is the same reason discovery shares that socket: a measurement taken from a
   second socket would cross a different NAT mapping and describe a path the
   real traffic will never take.
3. The probe is a nonce; the reply is the same nonce. A nonce rather than a
   bare packet because otherwise a late reply to a probe abandoned ten seconds
   ago is timed as if it were fresh, and the agent records a latency it never
   observed.
4. The agent reports the table to the coordinator, sealed. Unsealed, anyone
   could report on a device's behalf and steer that tenant onto a relay of
   their choosing.
5. The coordinator picks for **the pair**: the score of a relay is the worse of
   the two ends' round trips. A relay 5 ms from one device and 300 ms from the
   other is a 300 ms relay for the conversation between them, and choosing it
   because the asking end liked it optimises for the wrong device.

Region survives as a tiebreak worth five milliseconds. That is deliberately
small: it settles a choice between relays that are genuinely close and loses to
any real measured difference. It is also routinely empty, so selection has to
work without it — there is a test named exactly that.

### The reflection question

The probe endpoint is unauthenticated, because an agent that has not been
offered a relay has no ticket for it and still needs to know how far away it
is. Anything unauthenticated on a public port is a potential DDoS tool, so the
reply is **exactly the same size as the probe**. An attacker who spoofs a
victim's source address gets one packet sent to the victim for one packet sent
to us, which is no better than sending it to the victim directly. Amplification
is the property that makes reflectors worth using; without it this is not worth
an attacker's time.

## Failover

A relay dying is not a hypothetical — it is a box in a datacentre. The problem
is that a dead relay is silent, and silence is indistinguishable from an idle
link.

The signal is already there: the agent re-presents its ticket to keep the NAT
mapping alive, and **the relay acknowledges every bind**. So the rebind is a
heartbeat, and an unanswered one is evidence.

- Rebinds go out every **5 seconds**, on their own ticker rather than riding
  the 20-second coordinator keepalive. This buys detection speed, and only
  that: a relay killed and restarted cost 37% of a 40-second window at 20
  seconds and 36.5% at 5 seconds, which is the same number. Whatever dominates
  that recovery, it is not how often the agent rebinds — most likely the
  WireGuard handshake backoff already in progress by then. The shorter interval
  earns its place by making *failover* possible in fifteen seconds rather than
  a minute, not by making a restart cheaper.
- **Three** consecutive unanswered rebinds mean the relay is gone. Three rather
  than one, so a single dropped packet on a lossy link does not move a working
  session.
- The agent then asks the coordinator for another relay **naming the one that
  failed**. Without the name the failover asks the same question and gets the
  same answer.
- **Two different devices** naming the same relay take it out of rotation for
  everyone, for two minutes. One device does not: a single device that cannot
  reach a relay is usually describing its own network, and acting on one report
  would be a denial of service anyone could trigger by lying.

## Usage, and what the number is worth

The relay counts what it forwards. The panel meters what the agents say they
sent and received. These are built from different things, and the drill in
`services/lab/run-all.sh` pushes a known load through a relayed tunnel and
requires them to agree within a stated tolerance.

They agree because both count ingress and egress at every hop, which is the
convention — a relayed byte is counted where it arrives and again where it
leaves. That has to be stated rather than discovered, because it is the
difference between an invoice and an argument.

What the drill does **not** fix: the figure the panel bills on still comes from
the agents. A customer running a modified agent can under-report. The relay's
own counters are the ones nobody outside our infrastructure can touch, and they
currently go to a log file. Making the relay report them is the outstanding
piece of this, and it is recorded as a known limitation rather than quietly
left.

## What must be built

1. ~~**`services/relay`**~~ — done: ticket validation, pair table, forwarding,
   per-pair byte counters, idle expiry, and an unauthenticated probe endpoint
   for latency measurement.
2. ~~**Coordinator: relay allocation**~~ — done, and on measured RTT rather
   than region. See *Selection: measured, not assumed* above.
3. **Panel** — the `relays` table already exists and is already served to
   agents in their configuration; it needs an admin UI, health, and region
   metadata.
4. **Agent** — a punch deadline, relay fallback, background re-punch, and
   reporting `path: relay` in the runtime status (the field already exists and
   is documented as carrying `relay`).
5. **Usage** — per-tenant counters exist and are checked against the relay's
   own count in the lab. Still outstanding: having the *relay* report them, so
   the billed figure is not one the customer can influence, and a plan limit
   that acts on it.

## Sequencing

Windows first: an agent that does not run on Windows has no users to relay for.
That is still true and still outstanding — the relay work below was done in
parallel because the Windows pack is waiting on hardware, not on code.

## What would change this plan

The field test in `services/kit/RUNBOOK.md`. If the 4G pair connects directly
and stays connected, symmetric NAT is less prevalent on these carriers than
assumed and the relay can go back to being a fallback. The runbook is written
to answer exactly that question, and the reflexive address it records per run
is the specific evidence: an address that changes between runs is a symmetric
NAT and a relay case by definition.
