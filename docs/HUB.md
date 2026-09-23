# The hub

*Design. Nothing in this document is built yet.*

## Why it exists

A phone cannot run our agent. Android has no WireGuard kernel module a
third-party app may drive, the official WireGuard app takes a plain
configuration file and nothing else, and that file describes static peers with
static endpoints. It cannot hole-punch, it cannot present a relay ticket, it
cannot be told about a new peer without being re-imported, and it knows
nothing about our 1:1 prefix mapping.

So the phone does not talk to peers. **The phone has exactly one peer: this
server.** Everything it reaches, it reaches through a component on the VPS
that terminates its WireGuard session and forwards by destination address.

That component is the hub, and it is not an Android feature. It is the first
piece of the architecture where *every* device has a permanent session to the
server from the moment it starts, and a direct path is a silent upgrade on top
— which is what stops a PC ever sitting on "connecting".

## What the phone's configuration looks like

```ini
[Interface]
PrivateKey = <the phone's>
Address    = 10.128.0.57/32
DNS        = 10.128.0.1

[Peer]
PublicKey           = <the hub's>
Endpoint            = nb.akdwk.in:51820
AllowedIPs          = 10.128.0.0/16
PersistentKeepalive = 25
```

One peer. `AllowedIPs` is the overlay and nothing else — R1 holds: a phone on
this network still reaches the rest of the internet the ordinary way, and no
configuration this panel generates will ever contain `0.0.0.0/0`.

## How a packet gets where it is going

    phone ──wireguard──► hub ──►  another phone           (session to session)
                             ──►  a PC running the agent  (session to session)
                             ──►  a shared LAN            (via the PC that shares it)

The hub holds one WireGuard session per device and a table mapping each
overlay address to the session that owns it. A packet arrives, is decrypted,
its destination address is read, and it is re-encrypted into the session that
owns that address. No kernel routing, no NAT, no `net.ipv4.ip_forward` — the
hub never puts these packets on a real interface, so there is nothing to
firewall and nothing to leak.

Reaching **192.168.10.0/24** takes no new machinery at all. The gateway PC
already advertises its LAN as the mapped `10.128.5.0/24`, and the panel
already knows which device holds that route. The hub adds one row to the same
table: `10.128.5.0/24` belongs to that PC's session. A packet from the phone
to `10.128.5.1` is forwarded into the gateway PC's session, and the agent
there does what it does today — rewrites the prefix to `192.168.10.1` and puts
it on the LAN. **The mapping stays at the gateway, which is the only place
that knows the real addresses.** The phone never learns them and cannot be
misconfigured into using them.

## Where it sits relative to what exists

The hub is a new service, `akconnect-hub`, beside the coordinator and the
relay:

| | listens | knows | holds |
|---|---|---|---|
| coordinator | 8443/udp | who may reach whom | nothing after a restart |
| relay | 9000/udp + 443 | nothing; validates a signed ticket | one forwarding pair per bind |
| **hub** | **51820/udp** | **which overlay address belongs to which device** | **one WireGuard session per device** |

It takes its device list and its ACLs from the panel, the same way the
coordinator does — and, like the coordinator from 1.9.7-dev.14, it keeps the
last answer the panel really gave, so a panel outage is invisible to traffic.

An ACL is enforced **at the hub**, by destination, before a packet is
forwarded. This is strictly better than the phone enforcing it: the phone's
configuration cannot be trusted and cannot be changed without re-importing it,
and a rule changed in the panel takes effect on the next packet.

## Why this is the hub and not an Android side-door

Once the hub exists, an agent gets it as a permanent peer too — one more
WireGuard peer, `AllowedIPs` covering the overlay, lowest priority. Then:

- A PC is carrying traffic the moment it has a hub session. There is no
  window where it has announced itself and is waiting to be introduced, which
  is the window "connecting" lives in.
- Discovery, punching and relays become what they should have been all along:
  an **upgrade**. When a direct path is proven, the peer's endpoint moves to
  it and the hub stops seeing that pair's traffic. When it fails, the packets
  go back to the hub — a path that was never torn down.
- The relay does not go away and nothing about its protocol changes. A pair
  that is already relayed stays relayed. This is additive, which is the only
  way to change the data path on machines in the field.

## What it costs, honestly

**Every phone byte crosses this server, in both directions, for ever.** A
phone has no direct path and never will — there is nothing on it to negotiate
one. That is the price of the phone working at all, and it is the number to
watch on the VPS bill.

**A session per device, not per pair.** The relay holds a forwarding pair; the
hub holds a cryptographic session with its own keys and timers. It is more
state and more CPU, and it is the component to size first.

**One more key to protect.** The hub decrypts everything it forwards. The
relay does not — it forwards ciphertext it cannot read. This is a real
reduction in what the server cannot see, and it is the reason the hub is a
*fallback of first resort* rather than the permanent path for two PCs that can
reach each other directly.

## The phone's private key

Two ways, and they trade convenience against who ever sees the key.

1. **The panel generates it.** One QR code, scanned once, and the phone is on.
   The private key exists on the server for the moment it takes to render the
   page. The panel stores only the public half, and the QR is shown once.
2. **The phone generates it.** The WireGuard app makes a keypair, the operator
   types the public half into the panel, and the panel returns a config with
   the private key left blank for the app to fill. Nobody but the phone ever
   holds it.

(2) is the better answer and (1) is the one people use. Both are small to
build; the decision is which is the default.

## Ports

`51820/udp` has to be open. `deploy/getting-started.sh` does not open it yet —
it is not in the firewall list, and it will need to be before any of this
works.
