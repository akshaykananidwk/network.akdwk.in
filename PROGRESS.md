# Progress

What is built, what is stubbed, what is next. Kept honest — a plan that
overstates itself is worse than no plan.

Last updated: 2026-09-22 · version 1.9.6

---

## Verification

`VERIFICATION_REPORT.md` records what was actually run, against a real
installation and the real GitHub repository. The short version:

* 553 PHP assertions offline, 171 more over HTTP, and the Go suites under
  `-race` across four modules — all passing, repeatable across consecutive
  runs.
* Nine update runs through the panel's own pipeline, six of them rolled back.
* A rollback that restores 213 of 213 files byte-for-byte and 20 of 20 tables.
* Byte-exact recovery from damage severe enough that the panel could not boot.
* **Eight defects found and fixed during verification**, every one of them
  invisible to inspection. Three separate mechanisms for surviving a bad
  update — the database backup, the file rollback, and backup verification
  itself — were broken at once, and all three reported success. They surfaced
  only when a rollback was actually performed and a disk was actually filled.
* One open finding: address allocation costs a fixed toll proportional to the
  size of the network's CIDR rather than to the number of devices.

Phase 2 is now under way, and it repeated the lesson on its first live run:
two defects, neither visible by reading the code — an agent that could never
recover from a lost first announcement, and one that advertised its own tunnel
address as a way to reach it, asking WireGuard to carry its own traffic through
the tunnel it was building.

Two hosts behind separate NATs now ping each other by virtual IP over a real
WireGuard tunnel, with both default routes still on the physical interface, a
revoked device cut off in 7.7 seconds, and an established tunnel surviving the
panel and the coordinator both being killed. All of it in Linux network
namespaces on one machine — and, since 1.9.0, on one real Windows laptop over
the real internet. **Not across two real ISPs**, which the Phase 2 acceptance
criteria require.

**Windows cannot be tested from this environment.** There is no Windows host,
no hypervisor and no nested virtualisation, and Wine would give false results
for every item that matters (DPAPI, Wintun, the service manager, the firewall,
Defender). `services/kit/` is the way it gets tested: binaries, a runbook, and
an evidence collector for someone with real Windows machines to run.

**It has now been run on one.** DPAPI key storage, Wintun adapter creation,
service install, the firewall rule, NRPT split DNS, the split-tunnel default
route and coordinator discovery over the real internet all work. Three
defects came back from that run — an agent that refused to start while waiting
for approval, a service that failed in silence because it has no console, and a
clash check that refused the overlay because of the agent's own adapter — and
all three are fixed in 1.9.1 with regression tests that fail on 1.9.0. Gateway
mode (Stage 13) and the installer `.exe` end to end (Stage 15) are still
untested on real hardware.

**Two real Windows PCs, two real ISPs, one behind CGNAT.** 1.9.1 installed
cleanly on both by double-click — and then took two hours of PowerShell to
reach "connecting", after which the machines still could not ping each other.
Eight more defects came back from that session, the two that mattered being
protocol defects the lab could not see: the coordinator froze a device's peer
set at its first hello, so every machine already connected broke the moment a
new one was added; and a relay registered by hostname was never resolved, so
relay fallback never happened on the real internet. Both are fixed in 1.9.2,
both have scenarios that fail against 1.9.1, and the lab now has a CGNAT
topology and a late-joiner drill because it did not have either.

**The acceptance test for 1.9.2 is the customer's, not the lab's**: on two
fresh PCs, double-click the setup, type the join code, click OK, and within
sixty seconds both are ONLINE in the panel and can ping each other's overlay
address. Nothing else. It has not been run yet — the pack is built and
waiting, and no claim that it passes belongs here until somebody runs it.

**And the panel has been deployed.** 1.9.0 went onto a real aaPanel VPS —
Ubuntu 24.04, Apache 2.4 with PHP-FPM 8.3, MariaDB 10.11 — and returned seven
more defects in one evening, every one of them invisible to a lab that ran
entirely on PHP's built-in web server. 1.9.1 adds two container gates that
reproduce six of them (`services/lab/webtarget/`, `services/lab/argon2target/`)
and the first of those immediately found a seventh nobody had reported: an
installation using a table prefix installed successfully and then served 503 to
everything.

---

## Summary

| Phase | Scope | State |
|---|---|---|
| **P1 Foundation** | Installer, framework, auth, multi-tenancy, CRUD, audit, **auto-update**, backups | **Complete and verified** |
| P2 Networking | Go agent, enrolment-to-tunnel, IPAM wiring, WireGuard, split tunnel, coordinator, NAT traversal | **Working in a Linux lab, and on one real Windows laptop over the real internet**; two real ISPs at once still untested |
| P3 Relay | Relay service, fallback and silent upgrade to direct, RTT-based selection, failover, usage accounting | **Working in the lab**, selection on measured RTT, failover drilled; accounting verified against the relay's own count |
| P4 Advanced | ACL enforcement on the agent, routes, DNS, subnet router, site-to-site | **ACL, subnet-router mode with 1:1 subnet mapping, and split DNS all working and drilled on Linux**, including against a client with its enforcement compiled out; NRPT split DNS confirmed on real Windows, gateway mode not; site-to-site not started |
| P5 Commercial | Plans, limits, billing, invoices, API keys, OpenAPI, white-label | Mostly done (see below) |
| **Deployment** | Panel on aaPanel, coordinator + relay on a VPS, field kit pointed at both | `DEPLOY.md` and `deploy/`; **the panel has been deployed** — it found ten defects (1.9.1) and two real Windows PCs found eight more (1.9.2). The edge upgrades in one command (`deploy/upgrade-edge.sh`); the coordinator and relay are not yet on a VPS |
| P6 Enterprise | HA, multi-region relays, SSO/SAML, staged agent rollout | Not started |

**641 assertions pass** (`php tests/run.php --url=…`; 501 without an HTTP
server), and **`services/lab/release.sh` is the release gate**. It runs the
test suites, proves `upgrade-edge.sh` fails honestly eleven different ways,
builds and verifies the Windows pack, watches the agent replace its own binary
and refuse two releases it should refuse, stands up Apache with PHP-FPM and
installs the panel into it through the browser installer, runs the real
`Crypto` class against a PHP whose Argon2 comes from libsodium, runs every
networking scenario, and drills a cross-version update and rollback in a
scratch database. One table, non-zero on any failure. No release ships without
a clean one.

At 1.9.2: PHP 641/0, Go clean under `-race`, edge script 11/0, self-update
15/0, web 36/0, Argon2 6/0, networking **85**/0, update 17/17.

---

## Phase 1 — complete

### Installer
Six-step wizard and an unattended CLI equivalent. Requirements are probed,
not inferred — `mod_rewrite` is tested with a real request, because plenty of
hosts load the module and then ignore `.htaccess`. Every failing row says what
to do. Re-running is blocked by a lock file. Verified end-to-end against a
real MariaDB 10.11.

### Framework
Router, kernel with unified HTML/JSON error handling, PDO layer that only
prepares, database-backed sessions, CSRF, sliding-window rate limiting,
structured logging with redaction, SMTP client, view layer with mandatory
escaping. No Composer, no npm, no build step.

### Multi-tenancy
`TenantScope` is the only thing that decides whose rows a request may see, and
it fails closed. 23 assertions attempt cross-tenant access by id, by listing,
by pagination, by search, through services, and over HTTP — all refused, and
a row belonging to another customer returns 404 rather than 403.

### Authentication and RBAC
Argon2id, TOTP two-factor verified against the RFC 6238 vectors, single-use
recovery codes, progressive lockout, audited impersonation that drops platform
powers, five roles with a fixed permission matrix. A tenant-scoped user can
never hold a platform permission, whatever their role string says.

### Networks, devices, IPAM, ACL
Networks with materialised address pools, enrolment via short-lived join
codes, approval-gated activation, ACL authoring and compilation, subnet
routes, and the agent configuration endpoint. Rule R1 is enforced *server
side*: the configuration builder strips `0.0.0.0/0` before anything is sent,
so split tunnelling is a refusal rather than a convention.

### Auto-update
The core deliverable. An eleven-step resumable pipeline driven identically by
the web UI and `cli/update.php`. Verified against this repository on GitHub —
see [VERIFICATION_REPORT.md](VERIFICATION_REPORT.md).

### Backups
File and database backups with checksums recorded at creation and re-verified
before any restore. `mysqldump` when the host allows it, a complete pure-PHP
dumper when it does not. When `uploads/` is too large to include, the backup
says so rather than implying completeness.

### Interface
Responsive, dark and light, live updates over SSE with polling fallback,
keyboard accessible, English and Gujarati, PWA manifest. The QR code for 2FA
enrolment is generated in the browser by a vendored encoder — the CSP forbids
external scripts, and sending a TOTP secret to a third-party QR service would
be indefensible.

---

## Phase 2 — built and drilled

`services/coordinator/`, `services/relay/` and `services/agent/` are empty.
**No packets move between devices today.** The panel manages networks and
devices; the thing that makes them reachable does not exist yet.

Rule R7 in the brief says a UI without real networking is a failure. That is
the correct standard, and this build does not meet it yet. Phase 1 is the
control plane it needs, not a substitute for it.

### Why this is Go and not PHP

PHP cannot hold a raw UDP socket open for NAT hole punching, cannot run a TUN
interface, and cannot sustain a long-lived encrypted tunnel. Any attempt would
produce a dashboard that looks finished and moves nothing.

### What Phase 2 has to build

**Agent** (Windows, Linux, macOS · amd64 and arm64)
* Curve25519 keypair generated on the device; private half stored via DPAPI,
  Keychain or `0600` root-owned file, and never transmitted
* TUN interface and WireGuard Noise_IK via `wireguard-go`
* Split-tunnel route installation — network CIDRs and explicit routes only
* Split DNS for the search domain, leaving the system resolver alone
* Signed configuration cache so tunnels survive the panel being down (R6)
* ACL filters enforced on both endpoints
* Signed self-update with revert on failure

**Coordinator**
* Device authentication against the panel over HMAC
* STUN-like reflexive address discovery
* Endpoint exchange and simultaneous UDP hole punching with backoff
* LAN-local endpoints for same-subnet peers, for instant direct connection
* UPnP-IGD and NAT-PMP port mapping where available
* Relay fallback after N seconds, then silent upgrade to direct when it
  becomes possible
* Presence in Redis so it scales horizontally

**Relay**
* Stateless encrypted passthrough — never able to decrypt what it forwards
* RTT-based selection, health checks, failover without dropping sessions
* UDP with a TCP/443 fallback for networks that block UDP
* Per-tenant bandwidth accounting into `usage_counters`

The control plane already serves everything these need: `/api/v1/agent/config`
returns peers, allowed IPs, relays, DNS and MTU with a config revision;
`/agent/heartbeat` accepts liveness and byte deltas; `/agent/endpoint`
exchanges reflexive addresses. Those endpoints are built and tested.

### Exit criteria

Two PCs on different ISPs ping each other over their private addresses while
`route print` shows no default route through the tunnel and a public
traceroute does not pass through our infrastructure.

---

## Phase 4 — ACL and subnet router done; DNS and site-to-site not started

| | |
|---|---|
| ACL enforcement on the agent | Done, both ends of every conversation, stateful |
| Advertised routes | Done — advertised from the panel, approved by an administrator, carried by agents |
| Subnet-router mode, Linux | Done and drilled: an agentless NVR reached through a site PC on one port |
| Subnet-router mode, Windows | **Code written, never run on Windows.** `New-NetNat` + per-interface forwarding. Stage 13 of the test pack covers it |
| ACLs on routed traffic | Done, per destination LAN IP and port, enforced on the client **and** on the gateway |
| Overlapping LAN subnets | **Solved.** Each advertised LAN gets a unique virtual prefix and the gateway rewrites between the two, so two customers on 192.168.1.0/24 — and a technician on it too — all work |
| Subnet mapping, Windows | Platform-independent by construction (the agent rewrites, not the OS), but **never run on Windows**. Stage 13g |
| Split DNS | Done and drilled on Linux. `nvr.hotel-abc.<network>.internal` for overlay devices and for machines behind a gateway; our domain only, and the resolver never forwards. NRPT and systemd-resolved are written and **unexercised** — the gate proves the hosts-file path |
| Site-to-site | **Not started** |

### The limitations to say out loud

Duplicate LAN ranges are solved, but a machine already numbered out of the
mapping pool (`10.128.0.0/10`) will be handed a virtual prefix that lands on top
of its own network. The agent refuses that route and keeps the local one; the
fix is to change `network.mapped_pool`.

Split DNS works, but the two mechanisms that give it without touching a
customer's resolver configuration — systemd-resolved on Linux, NRPT on Windows
— have never run. No machine in this lab has systemd-resolved, so what the gate
exercises is the hosts-file fallback. Both other paths are written and
reviewed and nothing more than that.

Related and larger: a device belongs to exactly one network
(`devices.network_id` is a single column), so one support laptop cannot be a
member of twenty customers' networks at once. That is the architectural
question after this feature, not a bug in it.

---

## Phase 5 — mostly done

| | |
|---|---|
| Plans, limits, usage meters | Done, enforced at the action — the 26th device on a 25-device plan fails |
| Suspension behaviour | Done — existing tunnels keep working, no new capacity |
| API keys with scopes | Done, including "a key can never exceed its creator" |
| OpenAPI 3.1 | Done — `docs/openapi.yaml` |
| Subscriptions, invoices | Schema and models done; no payment gateway adapter yet |
| Razorpay / Stripe | **Not started.** `BillingService` is where an adapter plugs in |
| Invoice PDFs | **Not started** |
| White-label | Branding is config-driven and per-tenant overrides are stored; custom-domain routing is **not** wired up |

---

## Stubbed or partial

Listed so nobody discovers them the hard way.

| Thing | State |
|---|---|
| Relay monitoring | One relay is a single point of failure and nothing watches it. The panel shows health from the relay's own heartbeat; there is no alert when it stops |
| Agent release distribution | Schema, model and the `/agent/version` endpoint exist; no upload UI, and no binaries to serve |
| Off-site backup drivers | `backup.offsite_driver` is read but only `none` is implemented |
| Job queue | Table, reservation with retry and dead-lettering, and a worker loop all work; only one job type (`notify.email`) is registered |
| Prometheus `/metrics` | Not implemented — belongs on the Go services |
| Webhook notifications | Notification service supports in-app and email; no webhook transport |
| SSO / SAML | Not started (Phase 6) |
| Custom domains | Column and lookup exist; no routing or certificate automation |
| Relay heartbeat endpoint | Model and staleness sweep exist; the relay-facing endpoint arrives with Phase 3 |

---

## 1.9.6 — any network a browser works on

The standard for this one came from a customer laptop and was stated plainly:
stop asking anybody to change a firewall. It has to work on every network a
web browser works on, with nothing configured, like Tailscale and ZeroTier.

**Done and proved in the lab**

* **A path on TCP 443.** The relay serves a websocket endpoint behind Apache,
  carrying both the agent's control messages and its relayed tunnel traffic.
  The coordinator is not modified and does not know it exists: control frames
  are proxied to it as ordinary UDP from a socket per agent. A bind over that
  path presents the same coordinator-signed ticket and is refused on the same
  grounds — it is a way around the customer's firewall, not around
  authorisation.
* **The gate that had to pass.** Alpha's router drops UDP — all of it in one
  scenario, only the replies in the other, which is the office case exactly —
  and the pair connects in **21 seconds against a 30-second budget**,
  reporting `relay-https` rather than any UDP path, with the relay's own log
  showing the binds. A third scenario lifts the block: back to `relay-udp`
  **ten seconds later, with nothing restarted**.
* **Apache configured automatically**, by version — `mod_proxy_wstunnel`
  below 2.4.47, `upgrade=websocket` on `mod_proxy_http` from it — with the
  module checked as loaded rather than assumed, because `<IfModule>` would
  have made a missing one silent.
* **The automatic self-update path**, which is the one that failed in the
  field and the one nothing tested: everything before this ran `agent
  update`, which is a person typing a command, and nobody typed a command on
  the machine that stayed on 1.9.3. The gate now publishes a release, presses
  the panel's own button, and waits for the binary on disk to change.
* **What each device did about the last release**, reported on the heartbeat
  and shown on the device page. A machine that never checked and one that
  refused a badly signed release used to look identical from the panel.

**Known gaps in this release**

* The lab proves the fallback over `ws://` through a plain TCP proxy, which is
  the production shape minus the TLS. The TLS hop is Apache's, and
  `edge-script-gate.sh` checks the configuration rather than a handshake.
* A relay's fallback URL is a single panel-wide setting. With more than one
  relay in a fleet, a device on the HTTPS path connects to the one the panel
  names, not necessarily the one its peer was offered. One relay is the
  deployment; more than one needs the coordinator to prefer the relay a
  fallback device is already connected to.
* `RelayProbe` latency measurement is not diverted over the fallback, so a
  device on it reports relays as unreachable and the coordinator picks from
  the other end's measurements. Honest, and it costs a better choice of relay.

---

## 1.9.5 — the Windows release

The standard for this one was stated plainly: behave like ZeroTier or
Tailscale on Windows. Double-click, enter a code, done, and keep working
through reboots, sleep, Wi-Fi changes and several PCs behind one router, with
nobody touching it.

**Done and proved in the lab**

* **Two PCs behind one router can reach each other** (defect 23). Every device
  used UDP 51820, so the router leased that external port to the first one out
  and dropped everything the second sent from it. Each device now picks and
  remembers its own port, prefers a peer's LAN address when they are on one
  network, and leaves a port that turns out to be unusable. A new scenario
  proves it: red on 1.9.4, green on this.
* **A machine whose network changes while it runs recovers in 15 seconds** —
  same process, and it notices the change rather than waiting out a keepalive.
* Reconnect after a reboot, and the panel following an address change, still
  pass — now that the lab genuinely stops an agent, which it had never done.

**Done, and only a Windows PC can prove it**

* Listed in Settings → Apps, uninstallable from there, and the uninstaller
  prints what it removed.
* `setup.exe /S /CODE=`, and an MSI for `msiexec /qn`.
* A notification-area icon: status, copy my address, open the panel, collect
  diagnostics.
* Downgrade refused; a reinstall on a machine the panel forgot rejoins.
* Resume and network-change events accepted from the service manager.
* The service configured to survive a reboot (defect 30).

**Deliberately not done**

* **Code signing.** The pipeline signs every executable when
  `AKCONNECT_SIGN_CMD` is set and says "UNSIGNED" in capitals when it is not.
  A certificate is bought, not built. Until there is one, every customer meets
  SmartScreen's "unknown publisher", and that is the single biggest thing
  between this and looking like a normal product.

---

## Next

0. **The gate now runs Apache.** `services/lab/release.sh` stands up Apache
   with PHP-FPM and MariaDB in a container, installs the panel into it through
   the browser installer, and runs a PHP whose Argon2 comes from libsodium. It
   is not aaPanel and it is not Windows, so it narrows the gap rather than
   closing it — but the class of defect that got through in 1.9.0 cannot get
   through the same way again.

1. **`docs/AK-MUST-VERIFY-ON-WINDOWS.md`, on AK's two PCs.** Forty click-only
   lines covering install, two PCs behind one router, reboot, sleep, network
   change, update and uninstall. Everything in 1.9.5 exists for that list, and
   nothing in this file claims it passes until it has been run.
2. **Stage 15 — the installer `.exe`, end to end.** It is what a customer
   experiences, and the one thing in the pack that has still never been run on
   Windows from double-click to uninstall. Uninstall leaving nothing behind
   matters as much as install working.
3. **Two sites on real ISPs, one on 4G.** Everything in §D and §H is one
   machine's network namespaces. It is real networking and it is not two ISPs.
   Stage 5 of DEPLOY.md is this.
4. **Windows gateway mode, on real hardware.** Stage 13 of the test pack.
   `New-NetNat` is the only mechanism that works on the Windows 10 and 11
   machines customers have — RRAS is Server-only and ICS cannot target a
   prefix — and it has never run. The case I expect to hurt is a PC where
   Internet Connection Sharing already owns NAT, where Windows reports the
   failure as "The parameter is incorrect".
5. **Multi-network membership.** One support laptop, many customers. Blocked
   on `devices.network_id` being a single column, and on the fact that twenty
   customers all using 192.168.1.0/24 would collide in one routing table even
   if it were not.
6. **The coordinator and a relay on a real VPS.** The panel is deployed; its
   edge is not. One relay is also a single point of failure with nothing
   watching it, which is fine for a pilot and not for a customer.
7. **Site-to-site** — the rest of Phase 4.
8. Then Phase 6.

The order is deliberate: items 1, 2 and 3 decide whether any of the rest
matters commercially.

NRPT is no longer on this list: it was reported working on the real Windows
laptop that 1.9.0 ran on. Stage 14 still asks for the collected evidence.
