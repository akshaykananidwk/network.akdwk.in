# Progress

What is built, what is stubbed, what is next. Kept honest — a plan that
overstates itself is worse than no plan.

Last updated: 2026-09-21 · version 1.1.1

---

## Verification

`VERIFICATION_REPORT.md` records what was actually run, against a real
installation and the real GitHub repository. The short version:

* 469 automated assertions, all passing, repeatable across consecutive runs.
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
namespaces on one machine — **not on Windows, and not across two real ISPs**,
which the Phase 2 acceptance criteria both require.

**Windows cannot be tested from this environment.** There is no Windows host,
no hypervisor and no nested virtualisation, and Wine would give false results
for every item that matters (DPAPI, Wintun, the service manager, the firewall,
Defender). The Windows agent is written, cross-compiles, and has never
executed. `services/kit/` is the way it gets tested: binaries, a runbook, and
an evidence collector for someone with real Windows machines to run.

One Windows defect was found by inspection: `wintun.dll` was never being
shipped, and the agent would have died on first run inside a driver load. It
now checks for it and says where to get it.

---

## Summary

| Phase | Scope | State |
|---|---|---|
| **P1 Foundation** | Installer, framework, auth, multi-tenancy, CRUD, audit, **auto-update**, backups | **Complete and verified** |
| P2 Networking | Go agent, enrolment-to-tunnel, IPAM wiring, WireGuard, split tunnel, coordinator, NAT traversal | **Working in a Linux lab**; Windows compiles but is untested, real ISPs untested |
| P3 Relay | Relay service, fallback and silent upgrade to direct, RTT-based selection, failover, usage accounting | **Working in the lab**, selection on measured RTT, failover drilled; accounting verified against the relay's own count |
| P4 Advanced | ACL enforcement on the agent, routes, DNS, subnet router, site-to-site | **ACL enforced on the device, both ends, drilled. Subnet-router mode working and drilled on Linux, including against a client with its enforcement compiled out**; Windows gateway untested on hardware; DNS and site-to-site not started |
| P5 Commercial | Plans, limits, billing, invoices, API keys, OpenAPI, white-label | Mostly done (see below) |
| P6 Enterprise | HA, multi-region relays, SSO/SAML, staged agent rollout | Not started |

**494 assertions pass** (`php tests/run.php --url=…`; 376 without an HTTP
server), and
**`services/lab/run-all.sh` is the networking gate** — it builds the
namespaces, runs every scenario, prints one table and exits non-zero on any
failure. No release ships without a clean table.

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
| Overlapping LAN subnets | Handled across tenants and within a network. On one machine, a collision with the technician's own LAN is refused and logged — see the limitation below |
| Split DNS | **Not started** |
| Site-to-site | **Not started** |

### The limitation to say out loud

A support laptop whose own LAN is 192.168.1.0/24 cannot reach a customer's
192.168.1.0/24. The agent refuses the advertised route rather than taking over
the LAN the machine is sitting on, and logs which prefix clashed. One side has
to be renumbered. This is not solved.

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
| `services/*` | Empty directories. Phase 2. |
| Agent release distribution | Schema, model and the `/agent/version` endpoint exist; no upload UI, and no binaries to serve |
| Off-site backup drivers | `backup.offsite_driver` is read but only `none` is implemented |
| Job queue | Table, reservation with retry and dead-lettering, and a worker loop all work; only one job type (`notify.email`) is registered |
| Prometheus `/metrics` | Not implemented — belongs on the Go services |
| Webhook notifications | Notification service supports in-app and email; no webhook transport |
| SSO / SAML | Not started (Phase 6) |
| Custom domains | Column and lookup exist; no routing or certificate automation |
| Relay heartbeat endpoint | Model and staleness sweep exist; the relay-facing endpoint arrives with Phase 3 |

---

## Next

1. **Windows, on real hardware.** The pack is built and committed; nothing has
   run on Windows, and nearly every customer device is Windows. This is the
   item that decides whether the product can be sold at all.
2. **Two sites on real ISPs, one on 4G.** Everything in §D and §H is one
   machine's network namespaces. It is real networking and it is not two ISPs.
3. **Windows gateway mode, on real hardware.** Stage 13 of the test pack.
   `New-NetNat` is the only mechanism that works on the Windows 10 and 11
   machines customers have — RRAS is Server-only and ICS cannot target a
   prefix — and it has never run. The case I expect to hurt is a PC where
   Internet Connection Sharing already owns NAT, where Windows reports the
   failure as "The parameter is incorrect".
4. **Multi-network membership.** One support laptop, many customers. Blocked
   on `devices.network_id` being a single column, and on the fact that twenty
   customers all using 192.168.1.0/24 would collide in one routing table even
   if it were not.
5. **Split DNS and site-to-site** — the rest of Phase 4.
6. Then Phase 6.

The order is deliberate: items 1 and 3 decide whether any of the rest matters
commercially.
