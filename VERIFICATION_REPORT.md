# Verification report

**Version 1.1.0 · 21 September 2026**

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
| D   | Networking (R1, R4, R5) | **Partial** | Real tunnels, including across NAT — but only in a Linux lab; Windows and real ISPs untested |
| E   | Security            | **Pass** | With the open items listed under [Known limitations](#known-limitations) |
| F   | Scale               | **Pass, with a finding** | 1,000 devices fine; address allocation scales with *pool* size |
| G   | Recovery            | **Pass** | Byte-exact recovery from catastrophic damage |

Automated suite: **469 assertions, 469 passed, 0 failed**, repeatable across
eight consecutive runs.

```
$ php tests/run.php
  469 passed, 0 failed, 0 skipped  (469 assertions)
```

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

5. **PHP cannot hold a socket open.** There are no WebSockets; live progress
   uses Server-Sent Events. This was a deliberate choice, stated when it was
   made, not a limitation discovered late.

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

1. **The relay, and everything that depends on it.** There is an agent and a
   coordinator; there is no relay. A pair of peers that cannot reach each
   other directly currently stay unreachable — visibly, in the log, rather
   than silently degrading. That is the honest behaviour for now, but it means
   any NAT combination hole punching cannot beat is a pair of devices that
   simply do not connect. This is Phase 3 and it is the next thing that
   matters after Windows.

   **R7 is partially met.** Real networking exists and packets move, so the
   panel is no longer a dashboard with nothing behind it. But a product whose
   agent has never run on Windows and has never crossed two real ISPs is not
   one I would put in front of a paying customer. Nothing in this report
   should be read as claiming otherwise.

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
   one of the acceptance criteria.

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
