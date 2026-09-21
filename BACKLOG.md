# Backlog

Known, measured, and deliberately not being worked on yet. Each entry says how
to reproduce it and what "done" would look like, so picking it up does not mean
rediscovering it.

Nothing here blocks Phase 2. Nothing here is a correctness bug — those get
fixed when found, not filed.

---

## B1 — Address allocation costs a fixed toll set by CIDR width

**Found:** verification of 1.0.8, §F of `VERIFICATION_REPORT.md`.
**Severity:** performance only. Every address allocated was unique; nothing was
handed out twice.

Approving a device costs about 4 ms into a `/24` and an order of magnitude more
into a `/16`. Filling a `/16` while timing a single claim gives a flat line:

```
      1 already allocated -> next claim took  190.4 ms
    250 already allocated -> next claim took  236.0 ms
   1000 already allocated -> next claim took  226.9 ms
   4000 already allocated -> next claim took  228.5 ms
```

So it is not a degradation that creeps up on a growing customer. It is a fixed
toll, paid on every approval, set by how wide the network's CIDR is.

**Cause:** `IpAllocation::claimNext()` runs
`UPDATE ... WHERE device_id IS NULL AND reserved = 0 ORDER BY ip_numeric LIMIT 1`.
MariaDB reports `Extra: Using where; Using buffer` — it materialises the ordered
free set before taking one row from it, and that set is the whole pool.

**Why it is parked:** approval is a once-per-device action taken by a human at
human speed. It is not on any hot path. A thousand-device fleet enrolling from
scratch takes about two and a half minutes, which is tolerable, and no customer
is enrolling a thousand devices a second.

**Done looks like:** the `ORDER BY` no longer forces a buffer — a per-network
"next free" cursor, or an index the optimiser can walk in order without sorting.

**Measure it:** fill a `/16` and time a claim at 1, 1,000 and 4,000 allocations.
Require the result to be flat *and* close to the `/24` figure, not merely flat —
flat is what it already is.

---

## B2 — Ten files exceed the ~400-line guideline

**Found:** verification of 1.0.8, known limitation 7.
**Severity:** maintainability only.

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

`UpdateSteps.php` is the clear offender: it holds a step machine *and* eleven
steps. `BackupManager` was split this way during 1.0.7 (517 → 417 plus a
240-line `ArchiveStore`) and that is the pattern to follow.

**Why it is parked:** refactoring working, verified code before the product
exists is churn. These files are covered by the suite; splitting them buys
readability, not correctness, and every edit risks a regression in the one
subsystem that has already proved it can fail silently.

**Done looks like:** no file over ~400 lines, with the suite still at zero
failures and the update/rollback drills still passing.

---

---

## B3 — Enrolment throttling blocks a bulk rollout — **DONE in 1.6.0**

**Found:** 21 September 2026, by the lab gate running out of enrolments.
**Fixed:** 21 September 2026. Pulled forward out of the backlog as a launch
blocker. Failures are limited strictly (15 per 15 minutes per address, recorded
after the attempt); volume is limited generously (1,200 an hour, a flood
ceiling rather than an abuse control); join codes carry their own use limit.

Drilled in `enrol-throttle`: 50 devices from one address all enrol, and 20 bad
join codes from that same address get throttled. Both in the same run, so
neither result can be an artefact of the other.

The original entry follows, because the reasoning is what made the fix right.

Enrolment and claim share a limit of 60 requests per hour per IP address
(`security.enroll_rate_per_hour`). A customer installing the agent on forty
machines in one office comes from one public address: forty enrolments plus
their claim polls is well past sixty, and the installer sees "Too many
requests" partway through the afternoon.

**Do not just raise the number.** The thing worth limiting tightly is a
*failed* enrolment — that is the join-code brute-force vector. A successful
enrolment, and a claim poll from a device the panel has already issued a uid
to, are not. Counting the two separately is the fix: strict on failures,
generous on successes.

**Cost of leaving it:** the first multi-seat installation fails partway, in
front of the customer, with a message that sounds like our fault because it is.

---

## B4 — Relay recovery takes 15–20 seconds

**Measured**, repeatedly, in `services/lab/run-all.sh`:

| Event | Loss over a 40-second window | Outage |
|---|---|---|
| Relay killed and restarted, 20-second rebind | 37% | ~15s |
| Relay killed and restarted, 5-second rebind | 36.5%, 37% | ~15s |
| Relay killed for good, traffic moves to another | 49%, 49.5%, 50% | ~20s |

Shortening the rebind interval from twenty seconds to five did **not** make a
restart cheaper — the two figures are the same — so something else dominates
that recovery, most likely the WireGuard handshake backoff already in progress
by the time the relay returns. The shorter interval earns its place by making
*failover* possible in about fifteen seconds instead of a minute.

Fifteen seconds is a floor for the current design: nothing tells an agent a
relay has died except the absence of a reply, and three missed five-second
rebinds is how long that takes to establish.

**Two ways to beat it, neither built:**

1. Watch the data path rather than the rebind acknowledgement. Traffic stopping
   is a faster signal than a keepalive going unanswered, but it needs care not
   to mistake an idle conversation for a dead relay.
2. Have the coordinator health-check its own fleet and push a new offer the
   moment a relay stops answering it, rather than waiting for agents to
   notice independently.

**Cost of leaving it:** twenty seconds of silence when a relay dies. Tolerable
for a shop's CCTV, visibly worse than what Tailscale and ZeroTier manage, and
the kind of thing a customer notices once and remembers.

Deferred deliberately, 21 September 2026.

---

## B5 — One agent install belongs to exactly one customer

**Found:** 21 September 2026, while building subnet-router mode.
**Severity:** architectural. Nothing is broken; a thing AK Support needs does
not exist.

`devices.network_id` is a single column, so a support laptop that services
twenty customers needs twenty enrolments, and one agent install holds one. The
overlay works perfectly for each customer in turn and cannot hold two at once.

Two changes, and the second is the harder one:

1. **Membership.** A device belongs to many networks: a join table, a peer set
   per network, and an identity per network or one shared across them — that
   choice has consequences for revocation, because revoking a laptop from one
   customer must not touch the others.
2. **Routing.** Twenty hotels all on 192.168.1.0/24 collide in one routing
   table however good the membership model is. Reaching two of them at once
   needs per-network routing — policy routing with a table per network on
   Linux, and on Windows a mechanism that does not obviously exist. It may be
   that the honest answer is "one customer connected at a time, switched from
   the tray", which is a product decision rather than an engineering one.

**Cost of leaving it:** a technician disconnects from one customer to reach
another. Annoying, not blocking, and every competitor has the same problem with
duplicate private ranges.

## B6 — A zero-file update does not exercise the paths that broke before

**Found:** 21 September 2026, dogfooding 1.6.0.

The release is built on the machine it is installed on, so APPLY had no files
to write and the file-writing and file-removing paths were skipped. Those are
the paths the 1.3.x P1 lived in — FINALISE pruning its own rollback journal,
leaving new files on an old schema and reporting success.

**What done looks like:** a second installation, checked out at the previous
release with a database of its own, updated forward to the release being
shipped, and then rolled back — with a checksum manifest either side proving
the files genuinely changed and genuinely came back. Attempted for 1.6.0 and
not completed: the drill needs a scratch database, which this environment does
not let a script create.

**Cost of leaving it:** a regression in APPLY or in the rollback journal would
not be caught by the release gate, only by a customer.

---

## Rules for this file

An entry belongs here only if it is **measured**, **not a correctness bug**, and
**not blocking**. Anything that fails, corrupts, or misreports gets fixed when
it is found — that is what the eight defects in `VERIFICATION_REPORT.md` were,
and none of them was ever a candidate for this list.
