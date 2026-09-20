# Verification report

**Version 1.0.7 · 20 September 2026**

What follows is what was actually run and what it actually produced. Where a
requirement is met, the evidence is the command and its output. Where it is
not met, it says so and why. Nothing here is inferred from reading the code.

Everything was exercised against a real installation — PHP 8.4.19, MariaDB
10.11.14 — and against the real GitHub repository, not a mock. Seven releases
(1.0.1 through 1.0.7) were published and installed through the panel's own
update pipeline during this verification; four of them were then rolled back.

**The headline caveat, stated first because it is the largest:** Phase 1, the
control plane, is built and verified. Phase 2, the data plane, is not written.
No packets move between devices. By the project's own rule R7 — *"a UI without
real networking is a failure"* — this is not yet a finished product. See
[Not implemented](#not-implemented-and-why).

---

## Summary

| §   | Area                | Result | Note |
|-----|---------------------|--------|------|
| A   | Installer           | **Pass** | Clean install on an empty database; re-installation refused |
| B   | Auto-update         | **Pass** | 4 updates and 4 rollbacks against live GitHub; 8 defects found and fixed |
| C   | Multi-tenancy (R3)  | **Pass** | Fails closed; holds at 1,000 devices |
| D   | Networking (R1, R4, R5) | **Partial** | Server-side guarantees hold; **no data plane exists** (R7 unmet) |
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

Four complete updates were applied from the live GitHub repository, each
followed by a rollback:

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

The fourth drill is the one that matters, because by then every fix was in the
*installed* code rather than only in the repository:

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
| Protected paths untouched | Pass — `config/config.php`, `config/.env`, `install/install.lock` unchanged across all four drills |
| Maintenance mode cleared | Pass |

The two tables that differ are `notifications` (the rollback notifies
super-admins) and `update_settings` (last-check time and current commit). Both
are written *by* the rollback, after the restore, and so cannot match a
snapshot taken before it.

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

What is verified holds at the server, where it must:

| Check | Result |
|---|---|
| R1 — no default route in any agent config | Pass — asserted server-side, and over HTTP |
| R1 — no IPv6 default route either | Pass |
| R1 — config declares itself split-tunnel-only | Pass |
| R4 — a device is pending until an admin approves it | Pass — no token and no address are issued before that |
| R5 — no private key material in any response | Pass — the server stores public keys only |
| A revoked device's token stops working immediately | Pass |

**What is not verified, because it does not exist:** there is no agent, no
coordinator and no relay. `services/` is empty. No tunnel has ever been
established, no packet has ever been forwarded, and R1 has therefore never been
tested where it ultimately matters — in a real routing table on a real machine.

R7 says a UI without real networking is a failure. On R7 this build fails, and
no amount of green in the table above changes that.

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

## What this verification found

The single most important result is not in the table above. It is that **three
separate mechanisms for surviving a bad update were all broken at once, and all
three reported success**:

- the database backup could not be restored (generated columns, error 1906);
- the file rollback restored nothing (the journal was discarded at FINALISE);
- and a backup truncated by a full disk was reported as *verified*.

Each of these was invisible to inspection. The code was straightforward and the
tests were green. They surfaced only when a rollback was actually performed
against a real database, and when a disk was actually filled. That is the
argument for this kind of verification, and the reason the report leads with
what was run rather than what was written.

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

1. **The entire data plane.** No agent, no coordinator, no relay. `services/`
   is empty. This is Phase 2 of the delivery plan and was deferred
   deliberately, with the reasoning recorded in `PROGRESS.md` as the work
   went: a control plane that cannot survive its own update is not a
   foundation worth building a data plane on. That judgement was vindicated —
   see [What this verification found](#what-this-verification-found) — but the
   consequence stands: **R7 is unmet, and this is not a shippable product
   yet.** Nothing in this report should be read as claiming otherwise.

   Concretely, this means R1 (split tunnel), R2 (direct peer-to-peer first)
   and R6 (tunnels survive a panel outage) are verified only as far as the
   control plane can express them. R2 and R6 are not verified at all, because
   there is nothing yet that could hold a tunnel open.

2. **Billing and payment.** Plans and limits are enforced at the action; there
   is no payment provider, invoicing or dunning.

3. **Agent release distribution.** The `agent_releases` table exists and is
   empty, because there is no agent to release.

4. **Horizontal scale testing.** The session store is in the database and the
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

3. **Start the Go agent**, and with it the first end-to-end test that moves a
   packet. Until a tunnel carries traffic between two machines, R1, R2 and R6
   are claims rather than results. The agent configuration endpoint, the
   enrolment and approval flow, and the split-tunnel assertion are all in place
   and tested, so the control-plane side of that work is ready to be built
   against.

4. **Split `UpdateSteps.php`** along the same lines as `BackupManager` — the
   step machine and the individual steps are two different things.

5. **Sign a release and verify the signed path**, so the code that is already
   written is also exercised.

6. **Run the update pipeline once through the web UI**, not only the CLI. The
   two share `UpdateManager` and the CLI path is thoroughly exercised, but the
   step-per-request path has been reasoned about rather than run.
