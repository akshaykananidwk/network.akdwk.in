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

## Rules for this file

An entry belongs here only if it is **measured**, **not a correctness bug**, and
**not blocking**. Anything that fails, corrupts, or misreports gets fixed when
it is found — that is what the eight defects in `VERIFICATION_REPORT.md` were,
and none of them was ever a candidate for this list.

## B3 — Enrolment throttling blocks a bulk rollout

**Found:** 21 September 2026, by the lab gate running out of enrolments.

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
