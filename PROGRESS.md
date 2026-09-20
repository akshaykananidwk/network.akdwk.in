# Progress

What is built, what is stubbed, what is next. Kept honest — a plan that
overstates itself is worse than no plan.

Last updated: 2026-09-20 · version 1.0.8

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

The reason Phase 2 is still not started is in that list. A control plane that
cannot survive its own update is not a foundation worth building a data plane
on, and until this week it could not.

---

## Summary

| Phase | Scope | State |
|---|---|---|
| **P1 Foundation** | Installer, framework, auth, multi-tenancy, CRUD, audit, **auto-update**, backups | **Complete and verified** |
| P2 Networking | Go agent, enrolment-to-tunnel, IPAM wiring, WireGuard, split tunnel, coordinator, NAT traversal | **Not started** |
| P3 Relay | Relay service, fallback and silent upgrade to direct, usage accounting | Not started |
| P4 Advanced | ACL enforcement on the agent, routes, DNS, subnet router, site-to-site | Control plane done, agent side not started |
| P5 Commercial | Plans, limits, billing, invoices, API keys, OpenAPI, white-label | Mostly done (see below) |
| P6 Enterprise | HA, multi-region relays, SSO/SAML, staged agent rollout | Not started |

**425 assertions pass** (`php tests/run.php`).

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

## Phase 2 — not started, and it is the important one

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

1. **Agent skeleton** — enrol, poll, bring up a TUN interface, and ping a peer
   over a manually configured endpoint. Proves the config contract end to end.
2. **Coordinator** — reflexive discovery and endpoint exchange, so two agents
   behind NAT find each other.
3. **Hole punching** — simultaneous open with backoff, LAN shortcut, UPnP.
4. **Relay** — fallback, then silent upgrade to direct, with usage accounting.
5. **Agent ACL enforcement** — compile the filters the panel already emits.
6. Only then Phase 4 and the rest of Phase 5.

The order matters: each step is testable on its own, and step 1 is what turns
this from a dashboard into a product.
