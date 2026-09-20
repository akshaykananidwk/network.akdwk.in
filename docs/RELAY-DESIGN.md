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

## What must be built

1. **`services/relay`** — a Go UDP forwarder: ticket validation, pair table,
   forwarding, per-pair byte counters, idle expiry.
2. **Coordinator: relay allocation** — region selection, ticket issuing.
3. **Panel** — the `relays` table already exists and is already served to
   agents in their configuration; it needs an admin UI, health, and region
   metadata.
4. **Agent** — a punch deadline, relay fallback, background re-punch, and
   reporting `path: relay` in the runtime status (the field already exists and
   is documented as carrying `relay`).
5. **Usage** — per-tenant relayed byte counters and a plan limit.

## Sequencing

Windows first: an agent that does not run on Windows has no users to relay for.
Then this, before any further control-plane work.

## What would change this plan

The field test in `services/kit/RUNBOOK.md`. If the 4G pair connects directly
and stays connected, symmetric NAT is less prevalent on these carriers than
assumed and the relay can go back to being a fallback. The runbook is written
to answer exactly that question, and the reflexive address it records per run
is the specific evidence: an address that changes between runs is a symmetric
NAT and a relay case by definition.
