# The hub

*Design. The PC hub (below, "The PC hub, as built") is being built for
1.9.7-dev.25; the phone side is not built yet.*

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


---

## The PC hub, as built

*1.9.7-dev.25. This section replaces "PCs get it too" above, which described
the hub as one more WireGuard peer with the overlay as its AllowedIPs. That
cannot work for PCs: AllowedIPs is longest-prefix with one owner per prefix,
every PC peer already holds its own /32, and WireGuard drops an inbound packet
whose source is not in the delivering peer's AllowedIPs — so "falling back to
the hub" would mean withdrawing /32s on both ends in lockstep, which loses
packets, and it would have the server decrypt PC traffic, which the relay
design (RELAY-DESIGN.md) has always refused to do.*

### What the owner asked for

- Every device keeps a permanent session to the server from the moment it
  starts; traffic between any two devices flows through the server at once,
  with no wait for hole punching.
- A direct path is a silent upgrade on top of that, with no packet loss, and
  when it breaks the pair falls back to the server with no packet loss.
- A device's status is its session to the server — Online or Offline — and
  the path of each pair is a separate small label, "via server" or "direct".
- Acceptance on nb: two PCs, 24 hours, Online the whole time, ping never
  stopping for more than 3 seconds, through panel updates, coordinator and
  relay restarts, network changes and sleep/wake.

### The decision: the coordinator's session is the hub

Every agent already has a permanent session with the coordinator: a sealed,
panel-verified Hello at start, then a ping every 20 seconds, from the same
socket WireGuard uses. The coordinator already holds, per device, everything a
hub needs — its key, tenant and network, the ACL-filtered peer set the panel
verified (with the last known answer if the panel is unreachable), its current
public address, and presence. So the hub is that session carrying data too,
not a new service with a second session to establish, authorise and keep
alive: `akconnect-coordinator` forwards, on its existing UDP port.

This inverts R6 ("the coordinator can be stopped without any established
tunnel noticing") for pairs on the hub, and it is deliberate. What replaces
R6's guarantee is restart survival (below): a coordinator restart must cost a
pair on the hub less than the owner's 3-second budget.

Phones join the same process later, on 51820, as in the first half of this
document: one service, two faces.

### The wire

A hub frame is a disco packet, so the agent's socket already sifts it out:

    "AKC1"  type 0x20 (HubData)  peer (8 bytes)  WireGuard packet

`peer` is the first 8 bytes of the OTHER device's public key: the destination
when the agent sends, the source when the coordinator delivers. 13 bytes of
header; at the default overlay MTU of 1280 the outer packet is 1353 bytes on
IPv4.

The coordinator forwards a frame only when all of these hold:

- the source address is a registered device's current address (the same
  attribution the coordinator already uses for pings);
- the destination is in that device's verified peer set (the ACL);
- the destination is registered, and it forwards to its current address.

It never decrypts: the payload is the pair's own WireGuard ciphertext. A
forged frame can at worst deliver bytes the destination's WireGuard rejects.

Capability: HelloAck carries a flag saying the coordinator forwards. An agent
uses the hub only when it sees it; against an older coordinator it behaves as
it does today (punch, then relay).

### The agent: one stable endpoint per peer, the path chosen per packet

Each WireGuard peer is configured with a fixed pseudo endpoint that never
changes. `disconn.Bind` decides, per packet, where a packet to that endpoint
actually goes:

- **hub** (the default, from the first packet): wrapped in a HubData frame and
  sent to the coordinator;
- **direct**: sent to the peer's address found by punching.

Every packet received — from the hub or from the peer's direct address — is
handed to WireGuard as coming from the peer's pseudo endpoint. WireGuard's own
endpoint therefore never moves: roaming cannot pull it to a stale path, and a
configuration apply cannot reset it to the panel's hint. Switching path is a
table update in the bind, not a UAPI call, and needs no nudge.

### Direct as a silent upgrade, and a fast fall back

- Punching works as today and finds a candidate address. It is not taken on a
  single unsealed punch: the agent proves the path with a sealed ping/pong on
  it first.
- Upgrade: for the first two seconds on a proven direct path, packets go both
  ways, hub and direct. WireGuard's replay window drops the duplicates.
  Nothing is lost at the switch.
- Liveness: while a pair is carrying traffic, a sealed ping goes down the
  direct path every second. After 1 second with no pong and no packet from the
  peer on that path, packets are duplicated to the hub again; after 2.5
  seconds the pair is on the hub alone and the direct path is re-probed in the
  background. The loss on a direct path that dies is bounded by that one
  second of detection.
- An idle pair is checked every 15 seconds. A network change or a wake marks
  every direct path unproven at once, so traffic goes via the hub until it is
  proven again.

### Status

- A device is Online while its session to the server is alive. The panel
  learns that from the heartbeat, and from the coordinator's presence reports.
  Panel maintenance does not turn devices red (1.9.7-dev.24).
- Each pair reports "via server" (hub) or "direct"; the panel shows that as a
  small label, never as the device's status.

### Restart survival

- The coordinator writes its registry (keys, uids, verified peer sets,
  addresses, last-known panel answers) to its state directory on shutdown and
  every 30 seconds, and loads it at start. Forwarding resumes with the first
  packet after a restart; nobody has to say Hello again.
- A frame or ping from a key the coordinator does not know gets an immediate
  "say Hello" answer instead of silence, so a device it forgot re-registers in
  one round trip, not after 5 to 25 seconds.
- The last-known panel answers survive the restart too, so a restart during a
  panel update (when verify answers 503) cannot lock every device out.

### Billing

The coordinator counts hub bytes per tenant itself and reports what it
counted since the last report that the panel accepted. There is no cumulative
figure to turn into a delta, so a restart cannot bill anything twice.

### What stays

The relay stays for agents that predate the hub and for relays on other
servers. An agent on the hub does not ask for one.
