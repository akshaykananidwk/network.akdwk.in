# Verification report

**Version 1.2.1 · 21 September 2026**

What follows is what was actually run and what it actually produced. Where a
requirement is met, the evidence is the command and its output. Where it is
not met, it says so and why. Nothing here is inferred from reading the code.

Everything was exercised against a real installation — PHP 8.4.19, MariaDB
10.11.14 — and against the real GitHub repository, not a mock. Eight releases
(1.0.1 through 1.0.8) were published during this verification and installed
through the panel's own update pipeline. Nine update runs were performed in
all: **three left in place, six rolled back** — five on request and one
automatically, after a failure forced at APPLY.

**The headline caveat, stated first because it is the largest.** Packets now
move: two hosts behind separate NATs ping each other by virtual IP over a real
WireGuard tunnel, and the section below shows the commands and their output.
But every one of those results comes from **Linux network namespaces on a
single machine**. Nothing here has run on Windows, on two real ISPs, or behind
a real carrier-grade NAT. The acceptance criteria for Phase 2 name all three,
and all three remain unmet. See [What Phase 2 has not shown](#what-phase-2-has-not-shown).

---

## Summary

| §   | Area                | Result | Note |
|-----|---------------------|--------|------|
| A   | Installer           | **Pass** | Clean install on an empty database; re-installation refused |
| B   | Auto-update         | **Pass** | 9 runs against live GitHub, 6 rolled back; 8 defects found and fixed |
| C   | Multi-tenancy (R3)  | **Pass** | Fails closed; holds at 1,000 devices |
| D   | Networking (R1, R4, R5) | **Partial** | Real tunnels direct and relayed, relay selection on measured latency, failover drilled with a relay killed mid-traffic — but only in a Linux lab; Windows and real ISPs untested |
| E   | Security            | **Pass** | With the open items listed under [Known limitations](#known-limitations) |
| F   | Scale               | **Pass, with a finding** | 1,000 devices fine; address allocation scales with *pool* size |
| G   | Recovery            | **Pass** | Byte-exact recovery from catastrophic damage |
| H   | The networking gate | **Pass** | Eleven scenarios in one command; found four defects in the code it tests |
| H2  | Dogfooding          | **Pass after two fixes** | Six update runs, two rollbacks; the rollback drill found a P1, and testing its fix found the fix incomplete |

Automated suites:

```
$ php -S 127.0.0.1:8088 -t . tests/dev-server.php &
$ php tests/run.php --url=http://127.0.0.1:8088
  471 passed, 0 failed, 1 skipped  (471 assertions)

$ cd services/<each> && go vet ./... && go test -race ./...
  63 tests, all passing, no races
```

The one skip is the two-factor challenge, which needs a super admin with 2FA
enabled; this installation has none.

The Go tests run under `-race` because the coordinator handles every packet in
its own goroutine. That is not decoration — it found a data race in this
release's own relay-selection code, described below.

---

## A — Installer

The database was dropped and recreated empty, then installed unattended:

```
$ php cli/install.php --answers=/home/user/deploy-answers.json
  ✓ Requirements satisfied
  ✓ Connected to 10.11.14-MariaDB-0ubuntu0.24.04.1.
  ✓ 26 tables created.
  ✓ admin@deploy.test
  ✓ GitHub updates configured for akshaykananidwk/network.akdwk.in
  ✓ config/config.php, .env, install/install.lock
```

| Check | Result |
|---|---|
| Clean install on an empty database | Pass — 26 tables |
| Generated `config/config.php` is valid PHP and loads | Pass — the panel ran from it immediately afterwards |
| Secrets written outside the web root | Pass — `.env` lives in `config/`, and a request for it returns 404 |
| Re-installation refused | Pass — `Already installed (install/install.lock exists).` |
| Web installer sealed after install | Pass — `GET /install/` returns 404 |
| Seeding is idempotent | Pass — re-seeding three times leaves 9 settings rows, not 27 |

It reports one thing it cannot confirm rather than claiming it:

```
  ✗ URL rewriting: probe request failed (need working)
  ! URL rewriting could not be verified from the command line.
```

That is correct behaviour for a CLI install — the probe needs a live web
server — and it says so instead of passing silently.

---

## B — Auto-update

This is the section that found the most, because it is the only one where the
software modifies itself.

### The pipeline, end to end

Nine update runs against the live GitHub repository. The record the panel kept
of itself:

```
 id  from    to      status
  1  1.0.1   1.0.2   rolled_back
  2  1.0.1   1.0.3   rolled_back
  3  1.0.1   1.0.4   rolled_back
  4  1.0.1   1.0.5   success
  5  1.0.5   1.0.6   rolled_back
  6  1.0.5   1.0.6   rolled_back     <- the forced failure at APPLY
  7  1.0.5   1.0.6   success
  8  1.0.6   1.0.8   rolled_back
  9  1.0.6   1.0.8   success
```

A representative run:

```
$ php cli/update.php --apply --yes
  ✓ PRECHECK       Pre-flight passed. 29.5 GB free, PHP 8.4.19, 10.11.14-MariaDB
  ✓ MAINTENANCE    Maintenance mode on...
  ✓ BACKUP_FILES   Backup #5 written (331.1 KB).
  ✓ BACKUP_DB      Backup verified — files and database both match their checksums.
  ✓ DOWNLOAD       Archive downloaded: 446.3 KB.
  ✓ STAGE          Staged and verified: 217 files, 181 PHP files parse cleanly.
  ✓ MIGRATE        Applied 1 migration(s) in batch 3 (5ms). 1 new migration file(s) copied.
  ✓ APPLY          Applied: 6 file(s) written (0 new), 0 removed, 8 protected path(s) left untouched.
  ✓ POST           Post-update complete: 1 script(s) ran, caches cleared.
  ✓ HEALTH         Health check passed (10 checks).
  ✓ FINALISE       Update complete. Now running 1.0.6.
```

### Rollback

Run 5 is the one that matters most, because by then every fix was in the
*installed* code rather than only in the repository — an update pipeline can
only be fixed for the update after next, so a fix is not proven until the code
performing the rollback is the fixed code:

```
$ php cli/update.php --rollback=5 --yes
  ✓ Files: 6 restored, 0 removed.
  ✓ Migrations: 1 reversed, 0 not reversible (covered by the database restore).
  ✓ Removed 1 migration file(s) this update had added.
  ✓ Database restored from backup #5 (69 statements).
  ✓ Rollback complete. The previous version is live again.
```

Verified afterwards by checksumming the whole tree and fingerprinting every
table:

| Check | Result |
|---|---|
| Files restored | **213 of 213 byte-identical** to the pre-update tree |
| Database restored | **20 of 20 tables identical**, except two noted below |
| Migration reversed and its file removed | Pass — `migrate.php --status`: 2 applied, 0 pending |
| `VERSION` restored byte-exactly | Pass — including the absence of a trailing newline |
| Protected paths untouched | Pass — `config/config.php`, `config/.env`, `install/install.lock` unchanged across all nine runs |
| Maintenance mode cleared | Pass |

The two tables that differ are `notifications` (the rollback notifies
super-admins) and `update_settings` (last-check time and current commit). Both
are written *by* the rollback, after the restore, and so cannot match a
snapshot taken before it.

Run 8 repeated the drill with 1.0.8 — the build this report describes — doing
all of the work, and restored **215 of 215** files byte-identically. Backup #12
from that run, verified by the shipped code:

```
  files     332.1 KB, 230 files, archive reads back cleanly
  database   11.2 KB, dump ends with its completion marker
```

### Forced failure at APPLY

APPLY was made to fail on a real, unavoidable error — a target path replaced by
a directory, which cannot be overwritten even by root:

```
  ✓ MIGRATE        Applied 1 migration(s) in batch 3 (7ms). 1 new migration file(s) copied.
  ✓ APPLY          Failed to copy app/Views/admin/update_history.php — the previous version has been restored.
  ! Update failed and was rolled back. The previous version is live.
```

`VERSION` back to 1.0.5, status `rolled_back`, migration reversed, maintenance
mode off, and every file other than the artificial blocker restored.

### The token is never disclosed

With a real (synthetic) token configured:

| Check | Result |
|---|---|
| Stored and decrypted correctly | yes |
| Absent from `toArray()` (what the settings screen renders) | yes — the field reads `NULL` |
| Ciphertext at rest, not plaintext | yes — 100 bytes of AES-256-GCM |
| `Logger::redactString()` strips it from an arbitrary message | yes |
| Present anywhere under the application root | **no** — `grep -r` across every file, log and journal |
| Present anywhere in a full database dump | **no** — only the ciphertext |

### Disk exhaustion

A tmpfs of 256 KB was mounted over `storage/backups`, against a backup needing
about 330 KB:

```
$ php cli/backup.php ; echo $?
  ✗ The backup archive cannot be read back — it was truncated as it was
    written (a full disk is the usual cause). Refusing to treat it as a backup.
1
```

And an 8 KB disk, against a dump needing about 11 KB:

```
  refused: The database dump is incomplete — it has no end marker, so it was
  truncated as it was written. Refusing to treat it as a backup.
```

**This test initially failed, and the failure was serious.** On the first run
the pipeline reported `Backup #7 written (10.2 KB)` and then `Backup verified —
files and database both match their checksums`, and applied the update. The
archive on disk was **zero bytes**. The verification was tautological: it
hashed the file it had just written and compared that to the hash it had just
computed, and a truncated backup hashes perfectly consistently with itself.
Fixed in 1.0.7 — see [What this verification found](#what-this-verification-found).

### Defects found and fixed during this verification

Eight, all found by running the system rather than reading it:

| # | Defect | Fixed in |
|---|---|---|
| 1 | Backups containing stored generated columns could not be restored (error 1906) | 1.0.2 |
| 2 | A failed restore left the connection holding table locks, so the rollback could not record why it failed | 1.0.2 |
| 3 | The rollback journal was discarded at FINALISE, so History's rollback restored no files | 1.0.2 |
| 4 | A rollback reconstructed `VERSION` instead of restoring it | 1.0.3 |
| 5 | A rollback left the new version's migration *files* behind, showing as pending | 1.0.4 |
| 6 | `DB::rollback()` raised error 1305 on top of the caller's real error after an implicit commit | 1.0.4 |
| 7 | "Roll back to this point" was offered for updates whose journal had been pruned | 1.0.6 |
| 8 | A backup truncated by a full disk was reported as written and verified | 1.0.7 |

Every one now has a test that turns red if the fix is reverted. Defect 1 was
confirmed by reverting it and watching error 1906 reappear in the suite.

---

## C — Multi-tenancy (R3)

| Check | Result |
|---|---|
| A tenant-scoped query with no resolvable tenant | Pass — throws rather than running unfiltered |
| Another customer's row by id | Pass — 404, not 403 |
| Cross-tenant access by URL over HTTP | Pass — covered by the HTTP suite |
| Every tenant-owned model declares its scope | Pass — enforced by static analysis over the token stream, not by convention |
| API keys are scoped to their tenant | Pass — a key sees none of another tenant's devices |
| Isolation with 1,000 devices in the table | Pass — a neighbouring tenant sees **0** of them |

`TenantScope` is the single place that decides whose rows a request may see,
and it fails closed. The static check means a model added later cannot quietly
opt out.

---

## D — Networking — **partial**

### What the server guarantees

| Check | Result |
|---|---|
| R1 — no default route in any agent config | Pass — asserted server-side, and over HTTP |
| R1 — no IPv6 default route either | Pass |
| R1 — config declares itself split-tunnel-only | Pass |
| R4 — a device is pending until an admin approves it | Pass — no token and no address are issued before that |
| R5 — no private key material in any response | Pass — the server stores public keys only |
| A revoked device's token stops working immediately | Pass |

### What the agent now does about it

R1 is enforced twice, independently. The server strips a default route before a
configuration leaves it; the agent refuses one on arrival. Both are needed,
because they fail separately: a compromised or simply buggy panel must not be
able to route a customer's entire internet through the overlay, and "the server
promised" is not something the machine whose traffic it is should take on
trust.

The agent's refusal is tested against every way a default route can arrive:

```
$ go test ./internal/netcfg/ -v
--- PASS: TestRefusesADefaultRouteFromEverySource/in_the_routes_list
--- PASS: TestRefusesADefaultRouteFromEverySource/in_a_peer's_allowed_ips
--- PASS: TestRefusesADefaultRouteFromEverySource/as_the_network_CIDR_itself
--- PASS: TestRefusesADefaultRouteFromEverySource/as_an_IPv6_default_route
--- PASS: TestRefusesADefaultRouteFromEverySource/as_a_pair_of_/1_routes
```

The last one matters: two `/1` routes cover the whole address space exactly as
a `/0` does, and slip past any check that only looks for `0.0.0.0/0`.

### Enrolment, approval and key custody

Enrolling a real agent against the running panel:

```
$ akconnect-agent enroll --panel http://10.0.0.1:8099 --join-code ********
  Generated a new device identity.
  Private key: /tmp/agent-alpha/device.key (owner-only file, mode 0600) — it does not leave this machine.
  Public key : BjKpTJIPAGsyUWC3pPeeKLK4zIdVFXiDrQSALHmp6UI=
  Enrolled as dev_08289edb00f467f03c88
  Status     : pending

  This device is waiting for an administrator to approve it.
  Nothing connects until they do.
```

| Check | Result |
|---|---|
| R4 — enrolment yields `pending`, no token, no address | Pass — `virtual_ip` and `token_hash` both NULL in the panel |
| R5 — the panel holds only the public key | Pass — searching `devices` for the private key returns 0 rows |
| Key file permissions | Pass — `0600` in a `0700` directory; the agent refuses to start if either is loosened |

### A tunnel, across two NATs

The lab puts each host behind its own NAT gateway, sharing only the segment the
coordinator sits on. They provably cannot reach each other first:

```
$ ip netns exec alpha ping -c1 -W2 192.168.20.2
1 packets transmitted, 0 received, 100% packet loss
```

Both agents then announce themselves, learn their own translated addresses, and
punch towards each other:

```
alpha: discovery: our public address is 10.0.0.10:51820
alpha: discovery: direct path to peer bQZJ2RM4mqPm… via 10.0.0.11:51820
beta : discovery: our public address is 10.0.0.11:51820
beta : discovery: direct path to peer BjKpTJIPAGsy… via 10.0.0.10:51820
```

```
$ ip netns exec alpha ping -c 5 -W 3 10.99.0.3
5 packets transmitted, 5 received, 0% packet loss
rtt min/avg/max/mdev = 0.807/0.980/1.416/0.223 ms
```

| Criterion | Result |
|---|---|
| Two hosts ping by virtual IP | **Pass in the lab** — across two independent NATs |
| No default route through the tunnel | **Pass** — see below |
| Traceroute to a public IP misses our infrastructure | **Pass** — see below |
| A revoked device loses traffic within 10s | **Pass** — 7.7s |
| Controller stopped, tunnel survives | **Pass** — 112 pings with panel and coordinator both dead |

### R1 on a real routing table

Not a claim about the config — the kernel's own answer, under NAT:

```
$ ip netns exec alpha ip route
default via 192.168.10.1 dev veth-alpha
10.99.0.0/24 dev akc0 scope link
192.168.10.0/24 dev veth-alpha proto kernel scope link src 192.168.10.2
```

The default route is on the physical interface. Only the overlay prefix is on
`akc0`. And the kernel agrees per destination:

```
$ ip netns exec alpha ip route get 1.1.1.1
1.1.1.1 via 10.0.0.1 dev veth-alpha src 10.0.0.2

$ ip netns exec alpha ip route get 10.99.0.3
10.99.0.3 dev akc0 src 10.99.0.2
```

```
$ ip netns exec alpha traceroute -n -m 4 -w 2 1.1.1.1
 1  10.0.0.1   0.199 ms
 2  192.0.2.1  0.849 ms
 3  21.4.2.135 0.634 ms
```

Internet traffic leaves by the physical path and never enters the overlay.

### R6 — the data plane outlives the control plane

Both the panel and the coordinator were killed, then traffic was run for a
minute:

```
$ pgrep -c akconnect-coordinator ; ps -eo args | grep -c 'php -S 0.0.0.0:8099'
0
0
$ ip netns exec alpha ping -D -i 1 -c 60 10.99.0.3
[…] icmp_seq=112 ttl=64 time=0.985 ms
```

112 consecutive replies, no loss, while the agent logged exactly what it should:

```
heartbeat failed, tunnel left up: dial tcp 10.0.0.1:8099: connect: connection refused
```

This is also the proof that the path is direct rather than relayed: killing the
coordinator would end a relayed session immediately, and did not.

### R4 — revocation reaches the data plane

```
revoked dev_332cd8023927a64898d7 at 1789930533.125
last reply              icmp_seq=24 at 1789930540.803
agent log:  18:55:41 this device has been revoked; disconnecting
```

**7.7 seconds** from the administrator's click to the last packet, against a
requirement of ten. The interface is then removed entirely — `Device "akc0"
does not exist.`

### Defects found while building this

| # | Defect | Found by |
|---|---|---|
| 9 | The agent sent `Hello` once and then only keepalive `Ping`s, which the coordinator rejects from a device it does not know. A lost first `Hello`, or a coordinator restart, left the agent permanently undiscovered while looking healthy. | The first live run: the panel 500'd on one request and the agent never recovered |
| 10 | The agent advertised its own overlay address as a way to reach it, so a peer adopted `10.99.0.3:51820` as an endpoint — asking WireGuard to carry its own encrypted traffic through the tunnel it was establishing. | Watching the log adopt a second, wrong endpoint six seconds after the right one |

Both are fixed. The second is now refused twice: the agent does not advertise
its tunnel interface, and it rejects any candidate inside the overlay prefix
whoever offers it — including the coordinator, which is not something the data
plane should take on faith.

### The relay, and the case hole punching cannot win

A symmetric NAT allocates a different external port per destination, so the
address the coordinator observes is useless to a peer. This is not a tuning
problem — it defeats hole punching by construction, and it is common on Indian
broadband and mobile. The lab has a mode for it.

Under that NAT, the agents try, fail, and fall back within the punch deadline:

```
discovery: no direct path to bQZJ2RM4mqPm… after 5s; asking for a relay
discovery: relaying to peer bQZJ2RM4mqPm… via 10.0.0.1:54770 (still trying for a direct path)
```

```
$ ip netns exec alpha ping -c 8 -W 3 10.99.0.3
8 packets transmitted, 8 received, 0% packet loss
```

The relay then carries the traffic, and the panel records it as relayed:

```
name        connection_type  last_endpoint
lab-alpha   relay            10.0.0.10:9650
lab-beta    relay            10.0.0.11:54712
```

**The silent upgrade.** With traffic flowing continuously, the NAT was replaced
with a full-cone one — as if a customer had replaced their router:

```
discovery: upgraded peer bQZJ2RM4mqPm… from relay to direct via 10.0.0.11:51820
```

| Check | Result |
|---|---|
| Packets lost during the switch | **0**, of 98 sent |
| WireGuard session | **Not reconnected** — handshake age kept increasing across the switch |
| Panel indicator | 🟡 relay → 🟢 direct, from real agent state |

Nothing is torn down because pointing WireGuard at a relay and pointing it at a
peer are the same call.

### Defects found while building the relay

| # | Defect | Found by |
|---|---|---|
| 11 | The relay replied to the address the bind arrived from. Under symmetric NAT that is a different mapping from the data port's, so every reply was dropped. | The first relayed run: bytes forwarded, handshake never completed |
| 12 | The periodic re-bind overwrote the data-learned return address, breaking the path every 20 seconds, forever. | `tx 692, rx 124` — sending fine, receiving almost nothing |
| 13 | The silent upgrade never fired: candidates refreshed only on hello, and a settled agent only pings, so both ends punched at addresses that no longer existed — and their retry timers were independent, while hole punching needs both ends to punch at the same instant. | Watching 170 pings cross a relay that should have been abandoned |
| 14 | The agent sent `rx_bytes`/`tx_bytes`; the panel reads `rx_delta`/`tx_delta`. Traffic counters were never recorded at all. | Reading the controller while wiring the indicator |

A fifth was in the lab rather than the product, and is worth recording because
it would have produced a false pass: **plain `MASQUERADE` is not a reliable
cone NAT.** Linux preserves a source port only while it is free, so once an
agent has flows to the coordinator and the relay from the same port, a new flow
to a peer gets a different one and the NAT behaves symmetrically. The lab's
cone mode now uses an explicit SNAT/DNAT pair, so "cone" means cone.

---

## E — Security

| Check | Result | Evidence |
|---|---|---|
| Passwords | Pass | Argon2id, measured at 142 ms per hash on this machine |
| Secrets at rest | Pass | AES-256-GCM; verified by dumping the database and finding only ciphertext |
| Two-factor | Pass | TOTP checked against all five published RFC 6238 vectors |
| SQL injection | Pass | Payloads through every model entry point; injected sort/filter columns rejected outright, not escaped |
| SQL construction | Pass | Static analysis over the token stream rejects interpolated SQL; annotated identifier exceptions are individually justified |
| Output escaping | Pass | Static analysis that every echo in every view is escaped |
| CSRF | Pass | Double-submit with a per-session secret; HTTP suite confirms rejection |
| Rate limiting | Pass | Repeated failed logins return 429 with `Retry-After` |
| Security headers | Pass | Checked over HTTP |
| Archive handling | Pass | Zip-slip, absolute paths, symlinks and zip-bomb expansion tested with real malicious archives; no canary escaped |
| Updater containment | Pass | Refuses to write outside the app root or delete a protected path, even when the manifest lists it |
| Secrets in the repository | Pass | Every commit scanned before pushing; no secret committed |

---

## F — Scale

One tenant, one `/16`, one thousand devices, all created through the real
services — no direct inserts:

```
$ php tests/scale.php --devices=1000
  ✓ materialised the /16 address pool            65,533 rows in 1.2s
  ✓ every device enrolled and was given an address   1000 in 151.7s — 152ms each, 7/s
  ✓ every device holds exactly one address
  ✓ and no address was handed out twice
  ✓ device list, first page of 50                1.0ms (budget 250ms)
  ✓ device list, page 20                         1.3ms (budget 250ms)
  ✓ device count for the dashboard               0.3ms (budget 100ms)
  ✓ online-device count                          0.2ms (budget 150ms)
  ✓ address map, capped at 512                  14.0ms (budget 400ms)
  ✓ finding the next free address                1.1ms (budget 250ms)
  ✓ building one agent config                    6.3ms (budget 150ms)
  ✓ 200 agent configs stay under 50ms each       3.9ms each, 770ms total
  ✓ a neighbouring tenant sees none of the 1000 devices
  ✓ and the owner still sees all of them
```

Read queries do not degrade: page 20 of the device list costs the same as page
1, and the dashboard counts stay under a millisecond with a full table.

**The finding is the write path.** Enrolment plus approval costs 152 ms per
device on a `/16`. Profiling separates it cleanly:

| Pool | `enroll()` | `approve()` |
|---|---|---|
| `/24` — 254 addresses | 3.5 ms | 4.4 ms |
| `/16` — 65,533 addresses | 3.6 ms | 59.0 ms |

Enrolment is flat; approval is not. To find out which variable it tracks, a
`/16` was filled while timing a single claim at intervals:

```
  pool rows: 65,533
      1 already allocated -> next claim took  190.4 ms
    250 already allocated -> next claim took  236.0 ms
    500 already allocated -> next claim took  236.6 ms
   1000 already allocated -> next claim took  226.9 ms
   2000 already allocated -> next claim took  229.1 ms
   4000 already allocated -> next claim took  228.5 ms
```

The cost is **flat in the number of devices** and **proportional to the size of
the pool**. Claiming the four-thousandth address costs the same as claiming the
first; claiming into a `/16` costs an order of magnitude more than claiming
into a `/24`, whether it is the first claim or the four-thousandth. So this is
not a degradation that creeps up on a growing customer — it is a fixed toll
paid on every approval, set by how wide the CIDR is.

The cause is visible in the query plan. The allocation is
`UPDATE ... WHERE device_id IS NULL AND reserved = 0 ORDER BY ip_numeric LIMIT 1`,
and MariaDB reports `Extra: Using where; Using buffer` — it materialises the
ordered free set before updating, and that set is the whole pool.

(The absolute figures differ between the two tables above — 59 ms there, ~230 ms
here — because the second run had 4,100 extra device rows and a busier machine.
The ratios and the shape are what the measurements support; the absolute
milliseconds are not a benchmark of anything but this container.)

This is not a correctness problem — every address was unique, and nothing was
handed out twice — and at 7 devices per second a thousand-device fleet
enrolling from scratch takes about two and a half minutes, which is tolerable.
It is, however, the wrong shape: a customer who provisions a `/12` because they
can will pay for it on every approval, forever, for addresses they never use.
It is listed under [Recommended next steps](#recommended-next-steps) rather
than fixed here, because fixing it properly means changing how free addresses
are found, and that deserves its own change with its own tests rather than
being bolted onto a verification run.

Not tested: concurrency. Every measurement above is sequential, against a
development server that handles one request at a time. How the allocator
behaves when fifty agents enrol simultaneously is unknown.

---

## G — Recovery

The strongest test in this report, because nothing was simulated.

A full backup was taken, verified, and then the installation was damaged badly
enough that the panel could not boot at all:

- `app/Core/Router.php` overwritten with garbage
- `app/Models/Device.php` deleted
- every row in `devices` deleted
- the tenant renamed to `WIPED`

At that point `cli/backup.php --restore` could not run either — the CLI needs
the classes that were destroyed. That is precisely the situation the printed
recovery instructions exist for, so those were used, exactly as the panel
prints them:

```
$ tar -xzf storage/backups/20260920-174726-e7b88c/files.tar.gz
$ gunzip -c storage/backups/20260920-174726-e7b88c/db.sql.gz | mysql -u <user> -p <db>
$ rm storage/maintenance.flag
```

Result:

| Check | Result |
|---|---|
| Files | **213 of 213 byte-identical** — the corrupted file and the deleted file both back |
| Database | **20 of 20 tables identical** to the pre-damage snapshot |
| Panel | Boots and reports `✓ You are on the latest version.` |

Backup verification now reports facts that can be false:

```
  files     OK   331.8 KB, 228 files, archive reads back cleanly
  database  OK   10.7 KB, dump ends with its completion marker
```

rather than the unfalsifiable "sha256 matches" it reported before 1.0.7.

---

## H2 — Dogfooding, and the two defects it found

Every release goes out through our own updater. 1.3.0 did, from a deployment
at 1.2.1, against the real GitHub branch:

```
  ✓ PRECHECK       Pre-flight passed. 27.4 GB free, PHP 8.4.19, MariaDB 10.11.14.
  ✓ MAINTENANCE    Maintenance mode on.
  ✓ BACKUP_FILES   Backup #1 written (6 MB).
  ✓ BACKUP_DB      Backup verified — both artefacts were read back, not just checksummed.
  ✓ DOWNLOAD       Archive downloaded: 6.2 MB.
  ✓ STAGE          Staged and verified: 314 files, 188 PHP files parse cleanly.
  ✓ MIGRATE        Applied 1 migration(s) in batch 2 (6ms).
  ✓ APPLY          Applied: 39 file(s) written (14 new), 0 removed, 8 protected left untouched.
  ✓ POST           Post-update complete: 1 script(s) ran, caches cleared.
  ✓ HEALTH         Health check passed (10 checks).
  ✓ FINALISE       Update complete. Now running 1.3.0.
```

Eleven steps clean, the new files present, the migration applied, the panel
serving and `config/config.php` still denied. Then the rollback drill:

```
  ✓ Files: nothing to undo (the update had not reached APPLY).
  ✓ Migrations: 1 reversed, 0 not reversible.
  ✓ Database restored from backup #1 (65 statements).
  ✓ Rollback complete. The previous version is live again.
```

**Every line of that is a tick and the result was wrong.** The update had
plainly reached APPLY — it had written 39 files a minute earlier. The
deployment was left with 1.3.0 application files running against a restored
1.2.1 schema, and told the operator it had recovered.

### Why

FINALISE had pruned the journal of the update that had just written it.

Journals are named by update id and retained in id order. Ids are not monotonic
across a database restore: restoring rewinds `app_updates`, so the next update
gets a low id while journals with high ids are still on disk. On a deployment
rebuilt from backup, the new update was id 1 and the stale journals were 10 to
16 — so ordering alone made the newest journal look like the oldest, and
FINALISE deleted it. One line in the log said so and nothing treated it as
serious:

```
  Pruned 1 old rollback journal(s), freeing 205 KB.
```

The rollback then found no journal and took the only other explanation
available: the update must not have reached APPLY.

### Fixed in 1.3.1, both halves

FINALISE now names its own update as protected, whatever the ordering says. And
a rollback that finds no journal for an update that *did* reach APPLY now stops
before touching the database, rather than restoring it under the new files and
reporting success — it says what state the installation is in and names the
file backup to restore from. Refusing is the correct answer there: the mixed
state it would otherwise produce is worse than either version on its own.

There is a test for the pruning, and it fails against the unfixed code.

### And the fix was not the fix

1.3.1 made a missing journal report a failure. Testing it rather than trusting
it showed that was necessary and not sufficient: the refusal fired, and the
database restore ran immediately afterwards, because the `throw` was inside the
method's own `try` and never reached the caller. The four rollback phases were
running unconditionally. The rollback reported the failure and produced the
mixed state in the same breath.

1.3.3 makes everything after the file restore conditional on it. Proven by
removing a journal and attempting the rollback:

```
  ✗ Files: the rollback journal is missing, so the files this update replaced
    cannot be restored. The database and version marker have been left alone
    deliberately — rewinding them under the new files would produce a mixture
    of two versions. Restore from the file backup listed in History.

  ✗ Rollback incomplete. Maintenance mode has been left ON deliberately.

  Recover manually:
    tar -xzf storage/backups/20260921-092135-830cf1/files.tar.gz
    gunzip -c storage/backups/20260921-092135-830cf1/db.sql.gz | mysql ...
    rm storage/maintenance.flag
```

Afterwards: `VERSION` still 1.3.3, CHANGELOG still 1.3.3, database untouched,
maintenance on. The installation is wholly on one version. Before the fix the
same drill left `VERSION` at 1.3.1 with 1.3.3 files on disk.

The happy path was then re-proven on the same deployment — journal restored,
`Files: 4 restored, 0 removed`, version back to 1.3.1 — and rolled forward
again to 1.3.3, serving 200.

### What this says about the verification

This is the **third** mechanism for surviving a bad update found broken by
using it, and the fourth if the incomplete first fix is counted separately. The
first two were in 1.0.x — a database backup that could not be restored, and a
file rollback that restored nothing. All of them reported success. None was
visible by reading the code, and one of them was only visible by testing a fix
I had just written and would otherwise have called done.

It is also the first one found on a code path I had already exercised nine
times. What made it appear was a *restored database* — a condition the report
has described under [Known limitations](#known-limitations) since 1.0.x
without anyone, including me, noticing it invalidated retention ordering.

## H — The gate, and what it found

### The drills, as one command

Everything in §D above was run by hand the first time. It is now
`services/lab/run-all.sh`, which builds the namespaces, brings up a real panel,
coordinator and two real relays, and runs every scenario in one go. It prints
one table and exits non-zero on any failure, which is what makes it a release
gate rather than a report.

```
$ ./services/lab/run-all.sh

  Scenario results
  CHECK                   RESULT  DETAIL
  ----------------------  ------  ------------------------------------
  cone/tunnel             PASS    alpha pinged 10.99.0.3 across two separate NATs
  cone/direct             PASS    path is direct, not relayed
  cone/R1                 PASS    default route stays on veth-alpha; 1.1.1.1 does not enter the tunnel
  relay/tunnel            PASS    alpha pinged 10.99.0.3 with hole punching impossible
  relay/path              PASS    path is relay
  relay/R1                PASS    default route stays on veth-alpha; 1.1.1.1 does not enter the tunnel
  cone-sym/tunnel         PASS    connected via direct
  cone-sym/R1             PASS    default route stays on veth-alpha; 1.1.1.1 does not enter the tunnel
  sym-cone/tunnel         PASS    connected via direct
  sym-cone/R1             PASS    default route stays on veth-alpha; 1.1.1.1 does not enter the tunnel
  ctrl-down/traffic       PASS    coordinator killed mid-ping; 0% loss (handshake 3m20s -> 15s)
  relay-down/recovery     PASS    traffic resumed after the relay was killed mid-stream (36.5% loss over 40s)
  failover/recovery       PASS    moved from lab-a to lab-b and traffic resumed (49.5% loss over 40s)
  accounting/load         PASS    pushed 400 packets of 1000B each, all received
  accounting/source       PASS    the billed figure came from the relay (agents separately reported 1717684 bytes)
  accounting/agree        PASS    panel billed 1717704 bytes, relay carried 1717704 - within 0% (tolerance 10%)
  acl/baseline            PASS    alpha reaches beta:8080 with no rules in force
  acl/deny-tcp            PASS    TCP to beta:8080 stopped 4s after the rule was written, service still listening
  acl/scope               PASS    the deny named tcp/8080 and left ping alone
  srcport/allowed         PASS    alpha reaches the allowed port tcp/554
  srcport/bypass          PASS    source port 554 did not open tcp/8080; the rule is about the destination
  tamper/blocked          PASS    the deny held after alpha rewrote its state and restarted, service still listening
  enrol/guessing          PASS    4 of 20 bad codes throttled after 16 rejections
  enrol/blocked-after     PASS    the address stays blocked while the failure window holds
  enrol/bulk              PASS    50 devices enrolled from one address, none throttled
  gw/approval             PASS    an unapproved route carries nothing
  gw/reach                PASS    alpha reached the agentless NVR at 192.168.77.50:554 through beta
  gw/denied               PASS    tcp/8080 on the NVR is refused; the rule named 554 and meant it
  gw/no-reverse           PASS    the NVR cannot reach the overlay; forwarding is one-way
  clash/refused           PASS    the agent refused the colliding route and named the prefix
  clash/local-lan         PASS    the technician's own LAN route survived the advertisement
  clash/overlay           PASS    the overlay still carries traffic after the refusal
  gwtamper/tunnel         PASS    the tampered agent enrolled and handshaked exactly like the real one
  gwtamper/allowed        PASS    the tampered agent still reaches tcp/554, so the path is working
  gwtamper/blocked        PASS    tcp/8080 refused at the gateway, with the client's own enforcement removed
  revoke/cutoff           PASS    traffic stopped 4s after revocation

  36 checks, all passed. The networking proof holds for this build.

$ echo $?
0
```

That was the 1.6.0 gate. 1.7.0 added eight more checks and runs 44:

```
  gw/approval             PASS    an unapproved route carries nothing
  gw/reach                PASS    alpha reached the agentless NVR (192.168.77.50)
                                  at 10.128.0.50:554 through beta
  gw/denied               PASS    tcp/8080 on the NVR is refused
  gw/no-reverse           PASS    the NVR cannot reach the overlay
  clash/setup             PASS    alpha's own network is 10.128.0.0/24, the first
                                  prefix the panel will hand out
  clash/collided          PASS    the panel mapped the customer to 10.128.0.0/24,
                                  on top of alpha's own network
  clash/refused           PASS    the agent refused the colliding route
  clash/local-lan         PASS    the technician's own network survived
  clash/overlay           PASS    the overlay still carries traffic
  gwtamper/tunnel         PASS    the tampered agent enrolled and handshaked normally
  gwtamper/allowed        PASS    it still reaches tcp/554
  gwtamper/blocked        PASS    tcp/8080 refused at the gateway
  map/own-lan             PASS    alpha's own 192.168.1.50 answers from its office
  map/allocated           PASS    the customer's 192.168.1.0/24 became 10.128.0.0/24
  map/reach               PASS    alpha reached the customer's NVR at 10.128.0.50 —
                                  it answered CUSTOMER-NVR
  map/own-lan-intact      PASS    192.168.1.50 still reaches the technician's office
  map/acl                 PASS    tcp/8080 on the mapped NVR is refused
  map/gateway-acl         PASS    the gateway refused it with the client's own
                                  enforcement removed
  revoke/cutoff           PASS    traffic stopped 4s after revocation

  44 checks, all passed. The networking proof holds for this build.
```

1.8.0 adds split DNS and runs 58:

```
  dns/setup           PASS  a stand-in ISP resolver is answering in the natgw namespace
  dns/resolver        PASS  alpha serves lab-net.internal at 127.0.0.54:53 (2 names)
  dns/device          PASS  beta.lab-net.internal resolves to 10.99.0.3
  dns/lan-host        PASS  nvr.beta.lab-net.internal resolves to 10.128.0.50,
                            not to 192.168.1.50
  dns/refused         PASS  www.google.com is refused by our resolver, not looked up
  dns/nxdomain        PASS  an unknown name in the zone is NXDOMAIN, not a lookup elsewhere
  dns/untouched       PASS  alpha's own resolver configuration is byte-identical
  dns/public          PASS  public lookups arrived at the ISP resolver
  dns/not-in-path     PASS  our resolver was asked about no public name
  dns/os-routing      PASS  the operating system resolves lab-net.internal through
                            the hosts file (systemd-resolved is not available here)
  dns/system          PASS  getent resolves nvr.beta.lab-net.internal to 10.128.0.50
                            with no server named
  dns/system-device   PASS  and beta.lab-net.internal to 10.99.0.3
  dns/cleanup         PASS  stopping the agent removed the names; nothing was left behind
  dns/localhost       PASS  localhost still resolves; the machine's own hosts file is intact

  58 checks, all passed.
```

Two builds did **not** ship on their own gate. 1.5.0's `gw/reach` failed while
subnet-router mode was being built. And the first 1.7.0 run failed
`accounting/tunnel` — which turned out to be the drill's own defect, not the
product's, and is written up with the other lab faults below.

Several results in that table are worth reading twice.

**The controller-down figure is 0% loss, and the handshake tells you why.**
Not "the ping kept working" — the drill records the WireGuard handshake age
either side of the kill, which is what turns the result from an assertion into
a demonstration. Two outcomes have both been observed, and both prove R6:

- **Ageing, no renegotiation** (an earlier run: 5s → 25s across a twenty-second
  window). The session simply continued.
- **Renegotiation with the controller dead** (the 1.6.0 run above: 3m20s → 15s,
  so the age went *down*). The two agents rekeyed with each other while the
  coordinator was not running.

The second is the stronger result. A tunnel that coasts on an existing session
might still be depending on the control plane for its next handshake; one that
rekeys without it is not depending on it at all.

**The mixed-NAT cases are not deterministic.** `cone-sym` came up *direct* on
one run and *relayed* on the next, from identical topology. Whether punching
beats the five-second escalation deadline is a race, and it is a race that
decides whether a customer costs us relay bandwidth or nothing. The drill
records which path resulted rather than asserting one, because both are
correct outcomes — but relay capacity has to be sized for the pessimistic case,
not the one that happened to show up in a demo.

**Relay recovery is slow, and the numbers say so.**

| Event | Loss over a 40-second window | Outage |
|---|---|---|
| Relay killed and restarted, 20-second rebind | 37% | ~15s |
| Relay killed and restarted, 5-second rebind | 36.5% | ~15s |
| Relay killed for good, traffic moves to another | 49% | ~20s |

The first two rows are the same number. Shortening the rebind interval from
twenty seconds to five did **not** make a relay restart cheaper, which means
something else dominates that recovery — most likely the WireGuard handshake
backoff already in progress by the time the relay returns. The shorter interval
earns its place by making failover possible in about fifteen seconds instead of
a minute, not by making a restart faster, and saying otherwise would be
claiming an improvement that was not measured.

Twenty seconds of silence when a relay dies is tolerable for a shop's CCTV and
is not good. The detection is the floor: nothing tells an agent a relay has
died except the absence of a reply. Watching the data path rather than the
rebind acknowledgement, or having the coordinator health-check its own fleet
and push new offers, would both beat it. Neither is built.

### Relay selection, and what the numbers are worth

Relay choice used to be region-then-first-in-the-list. It is now the lowest
round trip the two agents actually measured, scored for the pair rather than
for whichever end asked: the worse of the two ends' numbers decides, because a
relay 5 ms from one device and 300 ms from the other is a 300 ms relay for the
conversation between them.

Eleven unit tests cover the decision, including the two the requirement names
explicitly — selection with no region set anywhere, and a region label losing
to a measurement that contradicts it. Four of them fail against the old
round-robin code:

```
$ go test ./internal/server/ -run 'Picks|Selection|Refuses.*Reach|Failover'
--- FAIL: TestPicksTheRelayWithTheLowestMeasuredRTT
--- FAIL: TestPicksForTheWorseEndNotTheAsker
--- FAIL: TestSelectionWorksWithNoRegionsSetAnywhere
--- FAIL: TestRefusesARelayOneEndCannotReach
```

**One thing the lab cannot show.** Both lab relays sit on the same host, a
fraction of a millisecond from both agents. The drill proves the measurement is
taken, reported, stored and used; it cannot prove the choice is *better*,
because there is no worse relay to reject. That needs two relays at a real
distance from each other, and it is not done.

Writing this section surfaced a second problem, which is now fixed. An agent
probed the fleet at startup but reported the result on the twenty-second
keepalive, while the deadline that sends a stalled peer to a relay is five
seconds. Every *first* relay assignment was therefore made with no
measurements at all and fell back to whichever relay was first in the fleet —
so latency-based selection applied from the second assignment onwards, which
is not what it claims to do. Measurements are now sent as soon as the first
probe round answers, rate-limited so a flapping relay cannot turn into a
stream of reports, and there are tests for both halves of that.

### Billing: the number checked against something that did not produce it

The panel's relayed-byte figure is what becomes an invoice, so the drill pushes
a known load through a relayed tunnel and compares that figure against the
relay's own count of what it forwarded. The two are built from different
things: the panel's from `rx_delta` + `tx_delta` in each agent's heartbeat, the
relay's from bytes it actually moved between two sockets.

```
accounting/load    PASS  pushed 400 packets of 1000B each, all received
accounting/agree   PASS  panel billed 1717684 bytes, relay carried 1717704
                         — within 0% (tolerance 10%)
```

**Twenty bytes apart on 1.7 MB**, or 0.001%. The stated tolerance is 10%
because the drill does not control everything crossing the relay — WireGuard
handshakes and keepalives go through it too, and the two counters are sampled
seconds apart — but the measured agreement is three orders of magnitude inside
it. The arithmetic is right.

**The convention, which has to be stated rather than discovered.** Both
counters count ingress *and* egress at each hop: a byte relayed from A to B is
counted when it arrives at the relay and again when it leaves. 400 packets of
1000 bytes, echoed, is 800 KB of payload in each direction, and the figure is
roughly twice that. This is defensible — it is what the relay's bandwidth bill
looks like — but it is the difference between an invoice and an argument, and
it belongs in the terms rather than in a customer's inference.

**The figure is no longer agent-reported.** It was, and that was the one number
in the system where the party being charged was also the party doing the
measuring: a customer running a modified agent could lower their own invoice by
reporting less.

The relay now reports what it carried — our hardware, our count — over a
MAC-authenticated message to the coordinator, which already holds a panel
credential so no relay needs one. Totals are cumulative, so a lost report costs
nothing: the next one carries the whole figure and the coordinator takes the
difference, treating a decrease as a relay restart rather than as zero usage.

The agents' own figure is kept under a separate metric for display and as a
cross-check. The two are compared rather than reconciled, and a disagreement
beyond 10% is logged with which side is reporting less — because two
independent counts of one thing that disagree is information, and quietly
preferring one of them is not. The lab measured them agreeing to **20 bytes in
1.7 MB**, so 10% is an alarm threshold with a great deal of room in it.

The drill now checks the *source* as well as the sum: `accounting/source`
fails if the billed figure is empty while the agents have reported traffic,
which is what would happen if the relay path stopped working and the panel
silently fell back.

### Phase 4 — the ACL, enforced on the device

§36 is the use case that sells this: AK Support may reach the hotel's server,
NVR and reception PC and nothing else. The half worth proving is the "nothing
else", because that is the half a customer is trusting.

```
acl/baseline     PASS  alpha reaches beta:8080 with no rules in force
acl/deny-tcp     PASS  TCP to beta:8080 stopped 4s after the rule was written,
                       service still listening
acl/scope        PASS  the deny named tcp/8080 and left ping alone
tamper/blocked   PASS  the deny held after alpha rewrote its state and
                       restarted, service still listening
```

The agent says what it did, and names the rule:

```
acl: 1 of 1 peer(s) carry port rules, enforced in both directions
acl: dropped outbound packet — denied by rule (rule 3)
```

**Four seconds**, against the ten the requirement allows. A deny cannot land
faster than the configuration poll that carries it, so four seconds is the poll
doing its job; anything faster would mean the drill was measuring something
else, which is exactly what it was doing before (see the false pass above).

**A failed TCP connect, not only a failed ping.** ICMP and TCP are different
protocols and a rule can block one without the other, so the drill does both —
and `acl/scope` confirms the deny named tcp/8080 and did not quietly take ping
with it. A rule that blocks more than it says is as wrong as one that blocks
less.

**The service is checked alive from inside its own namespace** before anything
is concluded from a refused connection. A dead listener refuses connections
just as convincingly as a filter does.

#### The gap in the control plane this found

`AclService::buildPeerSet` evaluated rules in the forward direction only. A
rule "alpha may not reach beta on tcp/8080" was compiled into alpha's
configuration and **not into beta's** — so every rule lived on exactly one of
the two machines.

That is the same as trusting that machine's agent, and the agent runs on
hardware the customer owns. The file's own docblock had said since Phase 1 that
"enforcement happens on both endpoints, because a filter that only exists on
one side is a filter an attacker can choose not to run". The code did not do
it. Both directions are now evaluated and their filters merged, deduplicated by
rule id.

#### What the tamper test does and does not show

The drill has alpha rewrite its own `state.json` and restart its agent, which
is the most a customer with root on their own machine can do without rebuilding
the binary. The deny holds.

It does **not** show that a customer who recompiles the agent is stopped —
nothing running on their hardware could show that. What stops them is beta's
agent enforcing the mirror of the rule, which is why the one-sided compilation
above was worth fixing and why the drill is run after it. A stronger test would
run a deliberately modified agent on alpha; that is not built.

#### The bypass in the first version of this filter

The filter shipped in 1.4.0 matched a rule's port against a packet's source
*or* destination. I wrote that up as a stateless trade-off. It was not a
trade-off.

"AK Support may reach the NVR on tcp/554" is a rule about the port being
connected *to*. Matching the source as well means a device binds source port
554 locally and reaches **every port on the NVR** — the rule permits precisely
what it was written to forbid, and §36's "and nothing else" becomes "and
everything else, if you ask in the right voice". Documenting it did not make it
smaller; it made it a hole with a footnote.

1.5.0 matches the destination port only, and recognises replies by the flow
they belong to.

```
$ go test ./internal/acl/ -run SourcePort -v
--- PASS: TestBindingTheServicePortAsSourceDoesNotOpenOtherPorts
```

and in the lab, against a real service on a real tunnel:

```
srcport/allowed  PASS  alpha reaches the allowed port tcp/554
srcport/bypass   PASS  source port 554 did not open tcp/8080;
                       the rule is about the destination
```

#### What connection tracking costs

A bounded, LRU-evicted table keyed on the 5-tuple. TCP flags retire a
conversation on FIN or RST rather than waiting out an idle timer; UDP and ICMP
have their own timeouts; ICMP echoes are matched on their identifier so a ping
reply belongs to the ping that asked for it.

```
$ go test ./internal/acl/ -run Memory -v
    conntrack_test.go:208: 10000 flows: 2801 KB total, 286 bytes per flow
```

**2.8 MB at ten thousand flows**, which is far more than a shop PC opens. The
test fails if a future change makes a flow substantially more expensive, so the
figure is a floor under a regression rather than a note in a document.

Open conversations survive a configuration change. An operator editing one rule
should not drop the customer's RDP session on every device in the network, and
`TestOpenFlowsSurviveAConfigurationChange` says so.

#### Failing closed on what cannot be read

An IPv6 packet wearing a hop-by-hop or routing header used to be treated as
having no ports — so no port rule matched it, and a deny-only rule set would
forward it. Anyone could put an extension header in front of a TCP segment and
walk past a port rule.

Extension headers are still not walked. The difference is that such a packet is
now **refused** rather than forwarded, along with a truncated transport header,
which is malformed rather than port-less. Walking the chain properly is the fix
when the overlay becomes dual-stack; until then the safe answer is the honest
one, and `TestIPv6ExtensionHeadersFailClosed` covers five header types.

### A commercial problem the gate surfaced by accident, and fixed

The ninth scenario failed to enrol a device, and the reason was not the
network:

```
error: enrolling: panel returned 429 (rate_limited):
       Too many requests. Please wait and try again.
```

Enrolment and claim share a throttle of **60 requests per hour per IP
address** (`security.enroll_rate_per_hour`). The drill enrols two devices per
scenario from two addresses, and nine scenarios in, it runs out.

That is correct behaviour for a public endpoint with no credential — it is what
stops someone grinding through join codes. But it is worth looking at against
a real rollout. A customer installing the agent on **forty machines in one
office in one afternoon** comes from one public address. Each machine sends one
enrolment and then polls `claim` until an administrator approves it. Forty
machines is comfortably past sixty requests, and the failure arrives as
"Too many requests" on a laptop belonging to whoever is doing the installing.

**Fixed in 1.5.0.** The limit was not simply raised. The brute-force vector is
a *failed* enrolment — a join code that does not exist, has expired or is used
up — and that is what is now counted strictly: fifteen failures per fifteen
minutes per address, recorded by the controller after the attempt rather than
by the middleware before it, because only the controller knows whether the code
was real. A claim for a public key nobody enrolled counts as a failure too,
because it is a probe rather than a poll.

Volume is limited generously — 1,200 an hour — which is a flood ceiling rather
than an abuse control. Fifty devices enrolling and then polling for approval is
several hundred legitimate requests from one address.

The third leg is the join code's own use limit, which an administrator sets.
Redemption was selecting the row, checking the count and then incrementing,
which let two devices enrolling in the same moment both take the last use. That
was academic while devices trickled in one at a time and is not when fifty
machines enrol together, so the claim is now a single conditional UPDATE and
the row is read only if it succeeded.

Drilled:

```
enrol/bulk           PASS  50 devices enrolled from one address, none throttled
enrol/guessing       PASS  N of 20 bad join codes were throttled
enrol/blocked-after  PASS  the address stays blocked while the failure window holds
```

#### What the gate caught

Three defects in the code it was written to test, which is the only reason to
believe it is worth running.

**The agent panicked on its first relay offer.** `assignment to entry in nil
map` — two maps added for relay failover were declared and never initialised.
The build was clean, `go vet` was clean and the unit tests were green, because
nothing constructed a client and handed it an offer. It would have crashed
every device behind a symmetric NAT, which is most of the customers the relay
exists for.

**An agent could not fail over from a relay that never worked.** Failover keyed
on the peer's path already being `relay`, which only becomes true once a bind
is acknowledged. A relay that was already dead when the coordinator offered it
was never rebound, never counted as missing and never replaced: the pair sat in
`connecting` indefinitely.

This one is worth dwelling on, because it is worse than the failure failover
was written for. Failover protects a pair whose relay dies under them. This
defect meant that once a relay was down, *every new pair the coordinator handed
it to* was stranded — and the coordinator goes on handing it out until two
separate devices have complained, which they cannot do if they are stuck. It
surfaced only because the failover scenario leaves a relay dead and the
accounting scenario ran next against the same fleet. No unit test I would have
thought to write covers "the thing was broken before you first touched it".

**Relay selection mutated shared state.** `pickRelay` briefly removed a relay
from `s.opts.Relays` to exclude it, then put it back. Every packet is handled
in its own goroutine, so two concurrent requests would have corrupted each
other's view of the fleet. Found by running the Go tests under `-race`, which
they now are, in every service.

All three have regression tests that fail against the unfixed code.

It also caught five faults in itself, which are recorded here because a drill
that is wrong is worse than no drill:

| Fault | Effect |
|---|---|
| `ip netns exec` forks, so killing the recorded pid left the agent running | Every scenario leaked two agents; a later scenario failed for reasons that had nothing to do with it |
| `ping` prints `loss,` with a comma, and the parser matched `loss` | Every failover measurement read `?%` — the drill could not measure the thing it existed to measure |
| The control plane bound to the bridge address, which each scenario deletes | Nothing ran at all until the sockets moved to the wildcard address |
| The ACL drill used a one-shot listener, and the baseline check consumed it | **A false pass.** The deny appeared to take effect in *zero seconds* — impossible, because an agent only learns a rule when it next polls. The connection was refused because nothing was listening |
| The stray-agent reaper matched the binary path exactly, so it missed both `" (deleted)"` and the tampered agent added for the gateway drills | **A false failure.** A leaked agent from an earlier run kept writing the state file a later scenario reads. `accounting` reported "the pair is not relayed" about a pair that was relayed, on a build where nothing about relaying had changed |
| A host-side veth outlived its namespace, so the rebuild failed with "File exists" — and nothing checked the topology builder's exit code | **A false failure.** That side never got its default route, and the scenario reported the panel unreachable. The lab was broken and the product was blamed |

The last two are a matched pair, and worth keeping in mind together: one
reported success that had not happened, the other reported a failure that was
not the product's. A drill is only evidence if it can be wrong in neither
direction, and the topology builder's exit code is now checked because an
unchecked one turned a lab fault into a product bug report.

The reaper is worth a paragraph of its own, because it is the same lesson
arriving a third time. The gateway work added a second agent binary — the
tampered build the ACL drills need — and the reaper matched one exact path, so
that binary leaked a process on every run. Every rebuild then made the leaked
processes invisible a second way, because the kernel appends `" (deleted)"` to
`/proc/pid/exe` once the file they came from has been replaced. Three of them
were alive, one for over two hours, all still polling the panel and all still
writing the state files the drills read.

The symptom was `accounting` reporting "the pair is not relayed" — a product
failure, on a build where nothing about relaying had changed. The state file it
read had been written by an agent from a run that ended thirty-three minutes
earlier. The tell was in the file: a handshake age of `33m0s` on a lab that had
been up for two minutes.

The false pass is the one to keep in mind. It did not look like a bug; it looked
like the feature working extremely well. The tell was the number: a deny cannot
land faster than the poll that delivers it, so "0s" was the drill reporting a
result it had not measured. The scenario now checks the listener is still alive
from inside its own namespace before concluding anything from a refused
connection, and refuses a sub-second result outright.

---

### Phase 4 — subnet-router mode, and the failure that looked like everything else

§16–17, and the case that sells the product: an NVR, a printer or a DVR that
will never run our agent, reached through one PC at the site that can.

| Check | Result | Evidence |
|---|---|---|
| An unapproved route carries nothing | **PASS** | `gw/approval` — the route exists in the panel, the NVR is unreachable |
| An agentless machine is reachable through the gateway | **PASS** | `gw/reach` — namespace `nvr`, 192.168.77.50, no agent, no overlay address, reached on tcp/554 |
| The rule names one port and means it | **PASS** | `gw/denied` — tcp/8080 on the same machine refused |
| Forwarding is one-way | **PASS** | `gw/no-reverse` — the NVR cannot reach into the overlay |
| A route colliding with the technician's own LAN is refused | **PASS** | `clash/refused`, `clash/local-lan` |
| Refusing a route does not cost the overlay | **PASS** | `clash/overlay` |
| The gateway enforces the rule itself | **PASS** | `gwtamper/blocked` — see below |
| Two customers on identical prefixes stay separate | **PASS** | `GatewayTests.php`, 12 assertions |
| Windows gateway mode | **NOT RUN** | No Windows hardware. Stage 13 of the test pack covers it |

#### The defect, and why it took so long to see

`gw/reach` failed for two days. Everything you would check was right: the
tunnel was up, WireGuard was handshaking, the client's routing table sent
192.168.77.50 down the tunnel and kept its default route on the physical
interface, and the gateway's iptables rules were exactly as designed —
`FORWARD` accepting overlay-to-prefix, conntrack allowing the answers back,
`MASQUERADE` on the way out. The gateway even logged that it had configured
itself.

The gateway had installed a tunnel route for its own LAN.

The panel sends every gateway's prefixes in the routes list, because every
device needs to know which prefixes exist and through whom. The agent installed
all of them without asking whether it was itself the gateway for any. On the
gateway, `ip route replace 192.168.77.0/24 dev akc0` did two bad things at
once:

- it **replaced** the interface route the kernel had installed for the LAN the
  PC was sitting on, so the gateway lost its own site; and
- it pointed packets the PC was supposed to be forwarding *back down the tunnel
  they had arrived on*.

So the packet arrived from the support laptop, the kernel looked up
192.168.77.50, found the tunnel, and the NVR was never reached. Nothing in the
logs said "routing loop"; it said the tunnel was healthy, which it was.

It was found by leaving the lab standing after the failure — a `KEEP=1` escape
added to the drill's cleanup for exactly this — and reading `ip route` on the
gateway. Reading the code had not found it, twice, because each piece of the
code is correct on its own.

Two regression tests now cover it, and both fail against the pre-fix code:

```
$ go test ./internal/netcfg/ -run TestBuildSkips     # against the old Build()
--- FAIL: TestBuildSkipsPrefixesThisDeviceServes
    policy_test.go:163: plan routes this device's own LAN through the tunnel:
        [10.99.0.0/24 192.168.77.0/24 192.168.88.0/24]
--- FAIL: TestBuildSkipsAdvertisedPrefixWithoutVia
    policy_test.go:194: expected the overlay alone, got [10.99.0.0/24 192.168.77.0/24]
```

#### The gateway now enforces the rule, not just the device it restricts

The first version compiled routed rules into the client only. That put every
rule about the NVR on the machine the rule restricts — a customer's laptop, on
hardware they own, running a binary they can rebuild. It is the same shape of
hole as matching a source port, reached from a different direction, and it was
found by asking the question the source-port fix had taught rather than by a
failing test.

Each peer now carries, in the **gateway's** configuration, what that peer may
reach inside the LANs the gateway routes for. The gateway reaches its own
verdict.

Proven with a client built `-tags labtamper`, which has rule enforcement
compiled out. A build tag rather than a runtime flag, so no binary we ship can
contain it, and it prints a warning to stderr on startup so a tampered binary
cannot be mistaken for a real one in a log.

```
gwtamper/tunnel    PASS  the tampered agent enrolled and handshaked exactly like the real one
gwtamper/allowed   PASS  the tampered agent still reaches tcp/554, so the path is working
gwtamper/blocked   PASS  tcp/8080 refused at the gateway, with the client's own enforcement removed
```

And the logs say which machine made the decision:

```
$ grep -c "acl: dropped" beta-up.log     # the gateway
1
$ grep -c "acl: dropped" alpha-up.log    # the tampered client
0
```

One drop, at the gateway. The client refused nothing, because it could not.

#### Forwarding is one-way in the filter, not only in iptables

A machine behind a gateway could not open a connection into the overlay,
because the Linux conntrack rule stopped it. But the **filter** would have
allowed it — and the machines behind a gateway are precisely the ones nobody
could install an agent on, usually because nobody can patch them either. An NVR
on five-year-old firmware is not a thing to leave with a route into every
customer a support laptop touches.

Found by a unit test written for the *reply* path, which is the only reason it
was found at all: the test asserted that an unsolicited connection from the
same LAN address is not a reply, and it failed.

A packet whose source is inside a prefix this device serves is now refused
unless it belongs to a flow the overlay started.

#### Subnet mapping, and the rule that was enforced twenty times too widely

The limitation 1.6.0 shipped with — a technician on 192.168.1.0/24 cannot reach
a customer on 192.168.1.0/24 — was not acceptable for this business, and it was
right not to accept it. Almost every router sold in India defaults to that range
or to 192.168.0.0/24, so the collision is not an edge case; it is most
installations.

The overlay no longer carries the customer's range at all. Each advertised LAN
is given a prefix of its own from a pool, unique within the network, and the
gateway rewrites between the two with the host part preserved.

**The proof is not that a connection succeeded.** In the drill, the technician's
own office and the customer's site are both 192.168.1.0/24 and *both have a
machine at .50*, so a connection to 192.168.1.50 succeeds either way — reaching
your own printer and believing you reached the customer is the failure that
looks exactly like success. Both machines therefore announce themselves:

```
  map/own-lan          PASS  alpha's own 192.168.1.50 answers from its office
                             before anything is advertised
  map/allocated        PASS  the panel gave the customer's 192.168.1.0/24 the
                             overlay prefix 10.128.0.0/24
  map/reach            PASS  alpha reached the customer's NVR at 10.128.0.50 —
                             it answered CUSTOMER-NVR
  map/own-lan-intact   PASS  192.168.1.50 still reaches the technician's own
                             office, not the customer's
  map/acl              PASS  tcp/8080 on the mapped NVR is refused; the rule
                             named the real IP and still bound the mapped one
  map/gateway-acl      PASS  the gateway refused tcp/8080 on the mapped address
                             with the client's own enforcement removed
```

And the routing table afterwards, which is the other half of the claim:

```
$ ip netns exec alpha ip route
default via 192.168.10.1 dev veth-alpha
10.99.0.0/24 dev akc0 scope link
10.128.0.0/24 dev akc0 scope link
192.168.1.0/24 dev lan-alpha proto kernel scope link src 192.168.1.1
```

The default route is untouched (R1), the technician's own LAN is still on their
own adapter, and the customer's LAN is reachable beside it.

The last row of that table is worth reading twice. `map/gateway-acl` re-runs the
check with a client built `-tags labtamper`, its rule enforcement compiled out,
and the logs say which machine decided:

```
alpha drops: 0        # the tampered client refused nothing, because it could not
beta drops:  1        # the gateway did
```

**Where the rewriting happens.** In the agent, not in iptables. Linux has
`NETMAP` and does this natively; Windows has nothing equivalent — `New-NetNat`
masquerades many-to-one, `Add-NetNatStaticMapping` forwards a single port, and
neither maps a prefix. Doing it in `services/agent/internal/netmap` gives one
implementation for both platforms, and it sits *inside* the ACL filter so that
everything above it lives in one address space.

**The checksum arithmetic is tested against full recomputation**, not against
itself. That is not ceremony: the first version had the IPv4 header checksum at
offset 8, which is the TTL, so every rewritten packet would have been dropped by
the next hop. A test that only checked "the address changed" would have passed.
The same tests cover a UDP checksum of zero staying zero, and an ICMP error's
quoted header being carried across — the case that decides whether path MTU
discovery works, which a customer experiences as a camera stream stalling on its
first large frame rather than as an error anybody sees.

#### A rule about one machine was enforced as a rule about the whole LAN

Found while building the above, not by a drill.

Route filters were selected by *overlap*. A rule naming 192.168.1.50 overlaps
the /24 that contains it, so it was compiled into the entry for the whole LAN —
"AK Support may reach the NVR on tcp/554" opened tcp/554 on the till at
192.168.1.60 as well. The rule said one machine and the agent enforced twenty.

`gw/denied` had been passing throughout, because it only ever asked about the
one machine the rule named. The drill was not wrong; it was narrow, and a
narrow drill is how a bug this shape survives three releases.

A rule now governs an entry only when it **covers** it — equal or wider. A
narrower rule gets an entry of its own, which wins on longest prefix and picks
up the wider rules again through the same test, so "allow the subnet, deny the
till" still means what it says.

#### Split DNS, and the half that cannot be proved by asking our own resolver

§18. The claim has two halves and they need proving differently.

That names resolve is proved by asking: `nvr.beta.<zone>` comes back as
`10.128.0.50`, the **mapped** address, not the `192.168.1.50` printed on the
recorder. Answering with the real one would send a technician to whatever sits
at that address on their own LAN — which in the drill's topology is their own
office machine, deliberately.

That nothing else was touched cannot be proved that way. It is proved by
watching the *customer's* resolver: the drill runs a stand-in for it in the
namespace that plays the ISP, points alpha at it, and afterwards checks that
the public lookups arrived there, that alpha's `/etc/resolv.conf` is
byte-identical, and that our own resolver was asked about no public name at
all.

The design makes that structural rather than configured. `dnsd` is an
authoritative server for one zone with **no code in it that speaks to another
DNS server**. A name outside the zone is REFUSED; there is nowhere for it to be
forwarded to. A forwarding resolver on a customer's machine is a thing that can
be pointed at, misconfigured into the path of their browsing, or blamed when
their bank's website is slow, and this cannot be any of those.

**The mechanism that carries it is the part with a hole in it.** Writing a
nameserver into `/etc/resolv.conf` or onto the Windows adapter would have made
this work everywhere in an afternoon and would have made us the resolver for
everything the machine looks up. The mechanisms that do what was actually asked
are systemd-resolved's per-interface domain routing and Windows' NRPT — and
neither exists on every machine.

The first version shipped with only those two, and on a machine with neither —
a Debian server, a container — the resolver ran with nothing pointing at it and
no name resolved. The drill said so, in the table, rather than passing on the
resolver answering correctly when asked directly. The hosts file is the
fallback: consulted before DNS by both platforms' resolvers, affecting no other
name, and touching no DNS configuration at all.

It is also the one path the gate actually exercises, because no machine in this
lab has systemd-resolved. **NRPT and resolvectl are written, reviewed and have
never run.**

Nine tests cover the hosts file, and every one of them is about what is still
there afterwards:

```
  the machine's own lines survive a write
  rewriting replaces the block rather than accumulating a second one
  a changed address does not leave the old one behind
  removing everything restores the file byte for byte
  an unterminated block is recovered from, not duplicated around
  a name with whitespace in it is refused, because it would alias a second name
  permissions stay 0644, because a hosts file written 0600 breaks localhost
```

#### A fixture that lied, for one run

`dns/cleanup` failed the first time it ran: the name still resolved after the
agent stopped. The hosts block *had* been removed — the lookup fell through to
DNS, and the stand-in ISP resolver in the lab answered everything, including
`.internal`.

No real resolver does that, because `.internal` is not delegated. The stand-in
returns NXDOMAIN for it now. A lab fixture that is more permissive than reality
produces exactly this: a failure that is the drill's, reported against the
product, and it would have been just as easy for it to produce a pass the
product had not earned.

#### Overlapping LAN ranges

Every consumer router hands out 192.168.0.0/24 or 192.168.1.0/24, so this is
the normal state of the product rather than an edge case.

Across tenants it does not arise: a device belongs to one tenant and the routes
list is tenant-scoped. `GatewayTests.php` proves it rather than asserting it —
two customers advertise byte-identical 192.168.1.0/24, and neither customer's
gateway UID or overlay address appears anywhere in the other's configuration.

Within one network, a second gateway for the same prefix is refused, because a
client has one routing table and cannot hold two routes for one destination.

On one machine, an advertised prefix can collide with a LAN the machine is
physically on, and there the agent loses the contest deliberately:

```
route 192.168.77.0/24 not installed: this machine is already on that network.
Devices in the remote 192.168.77.0/24 are unreachable from here until one side
is renumbered
```

Taking that route would cut a technician off from the printer beside them and
often from their own default gateway. `Remove` now deletes only the routes
`Apply` installed, so a stopping agent cannot take a site's own LAN route with
it either.

**This is a limitation, not a fix, and the log line does not make it smaller.**

---

### The 1.6.0 dogfood, and what it did not exercise

Every release goes out through our own updater. 1.6.0 did:

```
  ✓ PRECHECK       Pre-flight passed. 27.2 GB free, PHP 8.4.19, 10.11.14-MariaDB.
  ✓ MAINTENANCE    Maintenance mode on. Visitors see a 503 with a retry hint.
  ✓ BACKUP_FILES   Backup #25 written (45.7 MB).
  ✓ BACKUP_DB      Backup verified — both artefacts were read back, not just checksummed.
  ✓ DOWNLOAD       Archive downloaded: 6.3 MB.
  ✓ STAGE          Staged and verified: 340 files, 192 PHP files parse cleanly.
  ✓ MIGRATE        No pending migrations.
  ✓ APPLY          Applied: 0 file(s) written (0 new), 0 removed, 8 protected path(s) left untouched.
  ✓ POST           Post-update complete: 1 script(s) ran, caches cleared.
  ✓ HEALTH         Health check passed (10 checks).
  ✓ FINALISE       Update complete. Now running 1.6.0.
```

Then the rollback drill, on that same run:

```
$ php cli/update.php --rollback=1 --yes
  ✓ Files: 0 restored, 0 removed.
  ✓ Database restore not needed — no migrations ran.
  ✓ Rollback complete. The previous version is live again.

$ php cli/update.php --status
No update running. Last: #1 1.6.0 → 1.6.0 (rolled_back)
```

160 PHP files checksummed before and after: **byte-identical**, and the run is
correctly recorded as `rolled_back` rather than `success`.

**What that run did not prove.** APPLY wrote **zero files**, because the
installed tree was already identical to the target commit — this machine is
where the release was built. The eleven steps, the backup, the verification and
the rollback bookkeeping were all exercised; the file-writing and file-removing
paths were not.

That was not a small caveat. The 1.3.x P1 — FINALISE pruning its own rollback
journal, leaving 1.3.0 files on a 1.2.1 schema and reporting success — lived in
exactly the paths a zero-file apply skips.

### The cross-version drill, which is now the standard

`services/lab/dogfood.sh` installs the **previous** release into a scratch app
root with a database and a database user of its own, updates it forward through
our own updater, and rolls it back. Nothing it does touches the deployment; the
database, the user and the root are destroyed on the way out, including on
failure, and the credentials it creates are scoped to that one database so a
bad variable cannot reach the real one.

1.4.0 → 1.6.0 → 1.4.0:

```
  ✓ MIGRATE        Applied 4 migration(s) in batch 1 (22ms).
  ✓ APPLY          Applied: 60 file(s) written (20 new), 0 removed,
                   8 protected path(s) left untouched.
  ✓ FINALISE       Update complete. Now running 1.6.0.

$ php cli/update.php --rollback=1 --yes
  ✓ Files: 40 restored, 20 removed.
  ✓ Migrations: 4 reversed, 0 not reversible (covered by the database restore).
  ✓ Database restored from backup #1 (64 statements).

  dogfood/files-written     PASS  APPLY wrote 60 file(s)
  dogfood/apply-removes     PASS  APPLY removed 0 — this release deletes no files,
                                  so that path did not run
  dogfood/files-changed     PASS  100 manifest line(s) differ
  dogfood/rollback          PASS  restored 40 file(s) and removed 20 the update had added
  dogfood/rollback-removes  PASS  20 file(s) the update added were removed again
  dogfood/byte-exact        PASS  all 315 files are byte-identical to before the update
  dogfood/schema            PASS  the database schema is back to 8e2febe50a7d
  dogfood/data              PASS  every table the update touched holds what it held before
  dogfood/version-back      PASS  the installed version is 1.4.0 again
  dogfood/recorded          PASS  the run is recorded as rolled_back, not success

  14 checks, all passed.
```

And 1.6.0 → 1.7.0 → 1.6.0, which is the one that exercises a migration:

```
  ✓ MIGRATE        Applied 1 migration(s) in batch 1 (10ms). 1 new migration file(s) copied.
  ✓ APPLY          Applied: 41 file(s) written (8 new), 0 removed.

  ✓ Files: 33 restored, 8 removed.
  ✓ Migrations: 1 reversed, 0 not reversible (covered by the database restore).
  ✓ Removed 1 migration file(s) this update had added.
  ✓ Database restored from backup #1 (64 statements).

  dogfood/schema-changed    PASS  1 migration(s) changed the schema to 3ac9e9991fef,
                                  so the restore below is tested
  dogfood/schema            PASS  the database schema is back to 5ecab8f3b902

  15 checks, all passed.
```

1.7.0 → 1.8.0 → 1.7.0 is the same shape, and the schema moved again:

```
  dogfood/files-written     PASS  APPLY wrote 43 file(s)
  dogfood/schema-changed    PASS  1 migration(s) changed the schema to d4d9c66792a4,
                                  so the restore below is tested
  dogfood/rollback          PASS  restored 25 file(s) and removed 18 the update had added
  dogfood/byte-exact        PASS  all 344 files are byte-identical to before the update
  dogfood/schema            PASS  the database schema is back to 3ac9e9991fef

  15 checks, all passed.
```

`dogfood/schema-changed` exists because the first version of this drill could
have passed on nothing. It compared the schema before the update with the
schema after the rollback and found them equal — which is also what happens
when the migration is a no-op, which most of ours are on a freshly installed
schema. The drill now says, in the table, whether the schema actually moved
during the update, and therefore whether the restore it checks afterwards was
tested at all. The fingerprint covers indexes as well as columns, because
1.7.0's migration adds a unique key and a column-only fingerprint could not
have seen it.

One line in that table is a "did not run" rather than a pass in disguise:
**APPLY removed nothing**, because 1.6.0's manifest deletes no files. That path
is exercised only by a release that removes one, and the drill says so instead
of letting a green tick imply otherwise. The rollback's removal of the twenty
new files is a different path and is checked separately.

`services/lab/release.sh` runs the four gates — PHP suite, Go suites under
`-race`, the networking gate, the update gate — and a release ships only when
all four are clean.

---

### The installer, and what could not be tested about it

§33 asks for one signed-ready file that installs the agent and asks only for
the join code. `akconnect-setup.exe` is that, and almost none of it can be
tested here.

What was verified:

- it builds for amd64 and arm64, and **the built file genuinely contains both
  payloads** — checked by searching the executable for the first 4 KB of each,
  because an `//go:embed` that silently resolved to nothing would produce a
  plausible-looking binary that failed on a customer's machine;
- a build whose payload was never filled in **refuses to install**, naming the
  file and the script that fixes it, rather than writing a placeholder into
  Program Files and registering a service pointing at it;
- `-silent` without a join code fails with a reason instead of waiting for a
  dialog no deployment tool can answer;
- failures carry the steps that came before them, because "could not start the
  service" means something different depending on whether enrolment had already
  succeeded.

What was not, and cannot be from here: the UAC prompt, the dialog, SmartScreen,
the service registration, the firewall rule, the Wintun adapter, and the
uninstall leaving nothing behind. Stage 15 of the test pack is that list, one
check at a time, and it is the stage I would run first.

### Deployment

`DEPLOY.md` is a checklist for the real servers, not a description of one.

The expected output in it is real where it could be: the panel installer's
output is a transcript of an actual unattended install against a scratch
database, including the line about URL rewriting that a command-line install
always produces and a browser install should not. The sizing numbers are
measured — 5.2 MB resident for a relay, 2 KB of process memory per session,
with a test that fails if that grows fourfold, because somebody buys a server
on the strength of it.

`install-edge.sh` was run as far as this machine allows, which is up to its
first `systemctl` call:

```
── creating the service account
  created the akconnect system user
── generating keys and secrets
  generated a coordinator keypair and two shared secrets
── installing the services
  System has not been booted with systemd as init system (PID 1).
  ✗ the relay would not start
```

Stopping there rather than half-configuring is the behaviour wanted. The files
it wrote have the right modes (`640 root:akconnect` for anything with a secret
in it), both units pass `systemd-analyze verify`, **both services start from
those exact files**, and the public key the coordinator prints on startup is
byte-for-byte the one written to `coordinator.pub` for pasting into the panel.

What could not be verified is everything that needs the VPS: TLS, the vhost,
the document root, the firewall. `DEPLOY.md` says so at each of those steps
rather than implying otherwise.

**One number in it was a bug before it was a document.** The relay's data
sockets took whatever port the kernel handed out, so the honest firewall
instruction would have been "open UDP 32768–60999" — most of the unprivileged
port space. `--data-ports` pins the range instead; the systemd unit uses
51900–52400, which is 250 concurrent sessions.

---

## What this verification found

The single most important result is not in the table above. It is that **three
separate mechanisms for surviving a bad update were all broken at once, and all
three reported success**:

- the database backup could not be restored (generated columns, error 1906);
- the file rollback restored nothing (the journal was discarded at FINALISE);
- and a backup truncated by a full disk was reported as *verified*.

Each of these was invisible to inspection. The code was straightforward and the
tests were green. They surfaced only when a rollback was actually performed
against a real database, and when a disk was actually filled.

Phase 2 repeated the lesson immediately. Its two defects — an agent that could
never recover from a lost first announcement, and one that advertised its own
tunnel address as a way to reach it — were both found in the first live run,
and neither would have been found by reading the code or by any unit test I
would have thought to write. That is the argument for this kind of
verification, and the reason the report leads with what was run rather than
what was written.

---

## What Phase 2 has not shown

Every networking result above came from Linux network namespaces on one
machine. That is real networking — real interfaces, real routing tables, real
NAT, real packets — and it is not the same as the acceptance criteria, which
name conditions this environment cannot produce. Taking them one at a time:

| Criterion | Status | Why |
|---|---|---|
| Two machines **on different ISPs**, one behind CGNAT or 4G | **Not met** | One container, one uplink. The NATs are `iptables MASQUERADE` between namespaces. Field kit prepared. |
| `route print` on **Windows** showing no default route | **Not met** | No Windows host and no hypervisor in this environment. Cannot be tested from here at all. Field kit prepared. |
| `ip route` on Linux showing no default route | **Met** | Shown above, under NAT. |
| Traceroute missing our infrastructure | **Met** | Shown above. |
| Revoked device loses traffic within 10s | **Met** | 7.7s. |
| Controller stopped, tunnel survives | **Met** | 112 pings, both services dead. |

Two of these gaps deserve to be stated more plainly than a table can.

**The NAT is not a carrier's NAT.** `MASQUERADE` on Linux preserves the source
port where it can and gives an endpoint-independent mapping — which is the
easy case, and the case hole punching is most likely to win. A carrier-grade
NAT is frequently symmetric: a different external port per destination, which
defeats the technique used here entirely. **The success above is therefore
weak evidence for the case the criterion actually names.** What it does prove
is that the machinery works: reflexive address discovery, simultaneous open,
and adoption of the address that answered. What it does not prove is that it
will work from a 4G connection, and I would expect a meaningful fraction of
real CGNAT paths to need the Phase 3 relay.

**Windows is compiled, not tested, and I cannot test it.** The environment I
build in has no Windows host, no hypervisor and no nested virtualisation
(`/dev/kvm` absent, no `vmx`/`svm` flags), so the agent cannot be run on
Windows from here at all. Wine is installable and would be worse than useless:
its DPAPI is a stub, there is no Wintun driver, no service control manager with
Windows' semantics, no Windows Firewall and no Defender — a green result under
Wine would be a lie about every item in the Windows checklist.

So the Windows agent is **unverified, and will stay unverified until someone
runs it on Windows.** `services/kit/` exists for exactly that: binaries, a
runbook, and a script that collects the evidence.

One Windows defect was found by inspection rather than execution, and it would
have been the first thing to break:

> wireguard-go loads `wintun.dll` with `LOAD_LIBRARY_SEARCH_APPLICATION_DIR |
> LOAD_LIBRARY_SEARCH_SYSTEM32`. That DLL is a separate artefact from
> wintun.net, is not vendored by any Go module, and **was not being shipped**.
> The agent would have failed on first run with a bare LoadLibrary error from
> inside a driver load. It now checks for it at startup and says where to get
> it and where to put it.

The remaining Windows risk is concentrated in three places, all of which
compile cleanly and none of which has executed: the **DPAPI blob** round trip
(including across a reboot and a user password change), **ACL inheritance** on
`C:\ProgramData\AKConnect` (`PROTECTED_DACL_SECURITY_INFORMATION` is meant to
strip inherited entries — untested), and the **Wintun adapter lifecycle**,
especially whether a hard kill leaves a stale adapter that blocks the next
start.

---

## Known limitations

These are real and currently shipped.

1. **Address allocation scales with pool size, not device count.** A fixed
   toll on every approval, set by how wide the network's CIDR is: roughly an
   order of magnitude more on a `/16` than on a `/24`, and unchanged whether
   it is the first device or the four-thousandth. Correct, but the wrong
   shape. See §F.

2. **Concurrency is untested.** Every measurement is sequential. The address
   allocator takes a transaction and relies on `UPDATE ... LIMIT 1` for
   mutual exclusion, which is sound in principle and unverified in practice.

3. **A fix to the update pipeline only takes effect from the next update.**
   The CLI runs all eleven steps in one process, so the code that performs an
   update is the code that was installed *before* it. This is inherent to
   self-updating software, not a defect, but it means a defect in MIGRATE or
   FINALISE survives exactly one more update. The web UI runs each step in its
   own request and so picks up the new code earlier — from APPLY onwards.

4. **A rollback that has to restore the database rewinds the update system's
   own records.** `app_updates` and `app_backups` are in the dump like any
   other table, so a restore moves them back too. Status, error text and step
   are re-written afterwards, and the run log on disk is authoritative, but
   the row is not a complete account of a rolled-back run.

5. **Relayed bytes are counted at ingress *and* egress on each hop.** A byte
   relayed from A to B is counted when it arrives at the relay and again when
   it leaves, so the figure is roughly twice the payload. This is defensible —
   it is what the relay's own bandwidth bill looks like — but it belongs in
   the terms a customer signs rather than in an inference they make later.

   (The billed figure itself is no longer agent-reported; see §H.)

6. **Relay failover costs about fifteen to twenty seconds.** Nothing tells an
   agent a relay has died except the absence of a reply, and three missed
   five-second rebinds is how long establishing that takes. Measured
   repeatedly: 37% loss over a 40-second window for a restart, 49–50% for a
   move to another relay. Deferred deliberately; BACKLOG.md B4 carries the
   measurements and two ways to beat it.

7. **The ACL filter is stateful but not a full firewall.** It tracks flows to
   recognise replies, which closes the source-port bypass, but it does not
   validate TCP sequence numbers or reassemble streams. A packet that matches
   an open flow's 5-tuple is accepted on that basis. Closing that gap means a
   great deal more state for an attack that already requires the ability to
   inject into an established WireGuard tunnel.

8. **IPv6 extension headers are refused, not parsed.** The overlay is IPv4, so
   this costs nothing today and would need doing properly before it becomes
   dual-stack.

9. **PHP cannot hold a socket open.** There are no WebSockets; live progress
   uses Server-Sent Events. This was a deliberate choice, stated when it was
   made, not a limitation discovered late.

10. **A machine numbered out of the mapping pool still loses the contest.**
    Duplicate LAN ranges no longer collide — each advertised LAN gets a prefix
    of its own from `10.128.0.0/10` — but somebody already using that range on
    their own network will be handed a mapped prefix that lands on top of it.
    The agent refuses the advertised route and keeps the local one, naming the
    prefix that clashed. The fix is to change `network.mapped_pool`; there is
    no way for the agent to reach both. Drilled as `gateway-clash`.

11. **One agent install belongs to one customer.** `devices.network_id` is a
    single column, so a support laptop that services twenty customers needs
    twenty enrolments and one install holds one. Multi-network membership is
    not implemented, and would still need per-network routing tables before
    twenty hotels on 192.168.1.0/24 could work. This is the architectural
    question after subnet-router mode, not a defect in it.

12. **Gateway mode has never run on Windows.** `New-NetNat` plus per-interface
    forwarding is the only mechanism available on the Windows 10 and 11
    machines customers have — RRAS is Server-only, ICS cannot target a prefix
    — and none of it has executed. Stage 13 of the test pack covers it,
    including the case where Internet Connection Sharing already owns NAT and
    Windows reports the failure as "The parameter is incorrect". Until those
    results come back, subnet-router mode is proven on Linux only.

    Subnet mapping is the one part of it that is platform-independent by
    construction, because the agent does the rewriting rather than the
    operating system. That is an argument for why it should work, not a
    result. Stage 13g asks for the result.

13. **Two of the three split-DNS mechanisms have never run.** Names work, and
    the gate proves it end to end — but what it exercises is the hosts-file
    fallback, because no machine in this lab has systemd-resolved. The
    `resolvectl` path and Windows' NRPT are written, reviewed and unexercised.
    Stage 14 of the test pack covers NRPT; the Linux half needs a machine with
    systemd-resolved on it, which is an afternoon rather than a project.

6. **`UNLOCK TABLES` commits.** The restore path issues it only when locks are
   actually held, precisely because issuing it unconditionally would commit a
   caller's open transaction. Callers that restore inside a transaction should
   not expect to roll that transaction back.

7. **Ten files remain above the ~400-line guideline.** `BackupManager` was
   split for exactly this reason during 1.0.7 (517 → 417 plus a 240-line
   `ArchiveStore`), but the rest were not:

   | File | Lines |
   |---|---|
   | `app/Updater/UpdateSteps.php` | 738 |
   | `tests/DatabaseTests.php` | 716 |
   | `tests/HttpTests.php` | 683 |
   | `install/Installer.php` | 667 |
   | `tests/UnitTests.php` | 587 |
   | `tests/StaticAnalysisTests.php` | 516 |
   | `install/index.php` | 471 |
   | `app/Updater/UpdateManager.php` | 434 |
   | `app/Updater/BackupManager.php` | 417 |
   | `app/Updater/GithubClient.php` | 409 |

   `UpdateSteps.php` is the clear offender — it is a step machine and eleven
   steps in one file, and it grew during this verification rather than
   shrinking. The test files are less pressing but are not exempt.

8. **Signature verification is implemented but unused.** Manifests carry a
   `signature` field and the code checks it when present; no release in this
   verification was signed, so the *verified* path is the unsigned one.

---

## Not implemented, and why

1. **Relay selection is not yet intelligent.** The relay exists and works, but
   selection is round-robin within a region and `devices` has no region
   column, so in practice it picks the first configured relay. Latency-based
   selection needs the agents to measure and report round-trip times to each
   candidate relay, which they do not. A customer in Ahmedabad could today be
   assigned a relay anywhere.

   **R7 is partially met.** Packets move, directly and relayed, so the panel
   is no longer a dashboard with nothing behind it. But a product whose agent
   has never run on Windows and has never crossed two real ISPs is not one I
   would put in front of a paying customer. Nothing in this report should be
   read as claiming otherwise.

2. **ACL enforcement on the agent.** The panel compiles per-peer filters and
   sends them in the configuration; the agent currently applies the peer set
   and the allowed_ips, which is coarse-grained allow/deny, and ignores the
   port and protocol filters. A rule that says "only 443" is today enforced as
   "that peer is reachable".

3. **Billing and payment.** Plans and limits are enforced at the action; there
   is no payment provider, invoicing or dunning.

4. **Agent release distribution.** The `agent_releases` table exists and is
   empty. There is now an agent to release, so this has gone from "nothing to
   serve" to "not built yet".

5. **Horizontal scale testing.** The session store is in the database and the
   web tier is stateless by design, so more than one node should work. It has
   never been run on more than one node.

---

## Recommended next steps

In the order I would do them.

1. **Change how free addresses are found**, so allocation does not depend on
   the size of the pool. The `ORDER BY ip_numeric` is what forces MariaDB to
   buffer the whole free set; a per-network "next free" cursor, or an index
   the optimiser can walk without ordering, would both remove it. Measure it
   the same way — fill a `/16` and time a claim at 1, 1,000 and 4,000
   allocations — and require the result to be flat *and* close to the `/24`
   figure, not merely flat.

2. **Test the allocator under concurrency** before it meets a real fleet
   restarting at once. Fifty parallel approvals into one pool, asserting that
   no address is issued twice.

3. **Run the agent on Windows.** It cross-compiles and has never executed.
   DPAPI, the ACL and the Wintun adapter are all places where code that
   compiles can still be wrong, and `route print` on a real Windows host is
   one of the acceptance criteria. Stage 13 — gateway mode — is the newest and
   the least certain: `New-NetNat` is the only mechanism that works on the
   Windows versions customers have, and a PC where Internet Connection Sharing
   already owns NAT is a case I expect to have to handle in code.

4. **Test against a real CGNAT path** — a 4G connection is the cheapest way.
   The lab's `MASQUERADE` is the easy case; a symmetric carrier NAT defeats
   the technique entirely, and knowing what fraction of real paths fall back
   is what sizes the relay work.

5. **Split `UpdateSteps.php`** along the same lines as `BackupManager` — the
   step machine and the individual steps are two different things.

6. **Sign a release and verify the signed path**, so the code that is already
   written is also exercised.

7. **Run the update pipeline once through the web UI**, not only the CLI. The
   two share `UpdateManager` and the CLI path is thoroughly exercised, but the
   step-per-request path has been reasoned about rather than run.

8. **Decide what multi-network membership looks like.** One support laptop
   reaching twenty customers is the thing AK Support is for, and it needs two
   changes that are not small: a device belonging to more than one network,
   and per-network routing so twenty identical 192.168.1.0/24 ranges do not
   collide in one table. Worth designing before more is built on the
   assumption that a device has exactly one network.
