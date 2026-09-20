# Changelog

Notable changes per release. This project follows
[Semantic Versioning](https://semver.org): a breaking release sets
`"breaking": true` in `update.json`, and the panel warns before applying it.

---

## [1.0.4] — 2026-09-20

### Fixed

**A rollback left the new version's migration files behind.** `MIGRATE` copies
a release's migration files into `database/migrations` before running them, so
the ledger and the disk agree even if a later step fails — but nothing recorded
which files were new. A rollback therefore reversed the migration in the
database and left its file in place, where `migrate.php --status` reported it
as pending, inviting an operator to re-apply a migration from the very version
they had just rolled back from. `app_updates.copied_migrations_json` records
the filenames, and the rollback removes exactly those.

**A lost transaction no longer buries the error that caused it.** MySQL commits
implicitly on any DDL, and on `LOCK TABLES`, `UNLOCK TABLES` and `TRUNCATE`.
The transaction ends and every savepoint under it is destroyed, which the
nesting counter cannot see, so the next `ROLLBACK TO SAVEPOINT` raised error
1305 on top of whatever the caller was actually reporting. `DB::commit()` and
`DB::rollback()` now check the connection rather than trusting the counter, and
log a warning — the work is already committed either way, and an outer rollback
that silently commits must not pass unnoticed.

**The verification suite no longer leaks rows into its own database.** Its
migration-ledger test runs the real migration runner, whose `ALTER TABLE`
committed the transaction the suite wraps itself in — so whenever a migration
was genuinely pending, every fixture created up to that point was committed for
real while the suite still reported success. Six stray tenants had accumulated
this way. The ledger test now runs before the transaction opens, and a new
check compares row counts across the whole suite so the guarantee is verified
instead of merely asserted in a comment.

**The HTTP suite reset its own rate limiter.** It deliberately trips the login
limiter, and enrolment has a limiter of its own; left behind, that state made
the next run fail with 429s that looked like broken endpoints.

---

## [1.0.3] — 2026-09-20

### Added

`app_backups.db_method` records which dumper wrote a backup's database dump.
A dump `mysqldump` produced for a schema with stored generated columns cannot
be replayed at all, and until 1.0.2 the panel could produce one. Those backups
are still on disk and look no different from good ones, so the recovery
instructions on an update's detail page now say plainly when the dump in front
of an operator is one that will fail, and point at a newer backup instead.

### Fixed

A rollback rewrote `VERSION` from the recorded previous version rather than
letting the journal's byte-exact copy stand, appending a trailing newline the
original did not have. Everything reads the file through `trim()`, so nothing
misbehaved — but the next update saw the file as locally modified. The rewrite
is now a fallback, for a rollback that never reached `APPLY` and so has no
journal entry to restore.

---

## [1.0.2] — 2026-09-20

Three fixes to the update system, each found by running a real rollback
against a live MariaDB rather than by reading the code. Every one of them
made a backup unrestorable, which is the same as having no backup.

### Fixed

**A dump no longer writes back generated columns.** `mysqldump` lists STORED
generated columns in its `INSERT` statements — with or without
`--complete-insert` — and the restore then fails outright with *"the value
specified for generated column ... has been ignored"* (error 1906). The
pure-PHP dumper had the same flaw, because it selected `*`. It now reads the
writable columns from `information_schema` and emits an explicit column list,
and a schema containing generated columns always takes the PHP path, since
`mysqldump` cannot be made to produce a restorable dump for one.

**A failed restore no longer strands the connection.** A dump that brackets
its tables in `LOCK TABLES` left the session holding those locks when a
statement in between threw, so every later query answered *"table ... was not
locked with LOCK TABLES"* — turning one restore failure into a rollback that
could not even record why it had failed. Locks are now released on the way
out, and only when they are actually held, because `UNLOCK TABLES` commits an
open transaction as a side effect.

**The rollback journal survives a successful update.** It was discarded at
`FINALISE`, so *Roll back to this point* in History could reverse migrations
and restore the database but silently put no files back, reporting *"nothing
to undo"*. The journal is now kept and pruned on the same retention count as
the backups it pairs with, and discarded only once a rollback has consumed it.

### Added

`tests/BackupTests.php` — a dump-and-restore suite that reproduces all three
faults against a live database: a probe table with a stored generated column
and values carrying quotes, semicolons, newlines, backslashes and multi-byte
UTF-8 is dumped, dropped, restored, and compared row for row.

---

## [1.0.1] — 2026-09-21

### Added

`devices.last_handshake_at`, recorded separately from the control-plane
heartbeat, so the dashboard can tell *"the agent called us"* from *"the tunnel
is up"*.

---

## [1.0.0] — 2026-09-20

First release. Phase 1 of the delivery plan: the complete control plane,
including the GitHub auto-update system.

### Added

**Framework** — router with typed placeholders, kernel with unified error
handling for both HTML and JSON callers, PDO layer that only prepares,
database-backed sessions (so the web tier is stateless behind a load
balancer), CSRF with a per-session secret, sliding-window rate limiting with a
MySQL fallback, structured JSON logging with redaction and rotation, and an
SMTP client with no external dependency.

**Multi-tenancy** — `TenantScope` as the single place that decides whose rows
a request may see. It fails closed: a tenant-scoped query with no resolvable
tenant throws rather than running unfiltered. A row belonging to another
customer returns 404, not 403.

**Authentication** — Argon2id passwords, TOTP two-factor with single-use
recovery codes, progressive lockout, audited impersonation that drops platform
powers for its duration, and five roles with a fixed permission matrix.

**Networks and devices** — networks with materialised IPv4 pools, device
enrolment via short-lived join codes, approval-gated activation, ACL authoring
and compilation, subnet routes, and the agent configuration endpoint.

**Auto-update** — an eleven-step resumable pipeline driven identically by the
web UI and the CLI, with a verified pre-update backup, a per-file rollback
journal, migration tracking, post-update health checks and automatic rollback.
Protected paths are never written, deleted or restored over. Archives are
checked for traversal, absolute paths, symlinks and zip-bomb expansion, and
every staged PHP file is parsed before it goes live. Optional ed25519 manifest
signatures.

**Backups** — file and database backups with checksums recorded at creation
and re-verified before any restore. `mysqldump` when the host allows it, a
complete pure-PHP dumper when it does not. When `uploads/` is too large to
include, the backup records that explicitly rather than implying completeness.

**Installer** — six-step wizard plus an unattended CLI equivalent.
Requirements are probed rather than inferred, and every failing row says what
to do about it.

**Interface** — responsive dashboard, dark and light themes, live updates over
Server-Sent Events with polling fallback, keyboard-accessible forms, English
and Gujarati translations, and a PWA manifest. No build step: no npm, no
Composer.

**Verification** — 425 assertions covering tenant isolation, split-tunnel
enforcement, plan limits, RBAC, SQL injection, CSRF, rate limiting and the
agent endpoints, plus static analysis that fails the build on unscoped models,
interpolated SQL, unescaped view output or a stale documentation citation.

### Fixed during development

Each of these was found by a test written before the fix, and each is now
covered permanently.

* A composite `UNIQUE` over a nullable `tenant_id` did not constrain platform
  rows, because SQL treats every `NULL` as distinct — so platform settings
  duplicated on every upsert. Resolved with a stored generated column.
* `TenantScope::acrossAllTenants()` never actually lifted the tenant filter
  when an actor was signed in, making it a no-op exactly where the platform
  dashboards need it.
* `Validator` could not express a value casting to boolean `false`: pass/fail
  and the cast value shared one return channel, so `false` looked like a
  validation failure.
* The protected-path check stripped the leading dot from a dotfile, so `.env`
  was not recognised as protected.
* PDO with native prepares rejects a named placeholder used twice in one
  statement; one query did. A static test now fails the build on any such
  query.
* The QR encoder placed format information in reverse bit order, skipped
  alignment patterns centred on the timing row or column, and omitted version
  information for versions 7 and up. Now verified by decoding its output with
  an independent decoder across versions 1–10.
* `.env` sat in the web root, protected only by a per-file `.htaccess` rule
  that nginx ignores and Apache skips under `AllowOverride None`. It now lives
  in `config/`, which is denied as a whole directory.

### Not in this release

The Go data plane — agent, coordinator and relay — is Phase 2 and is not
built. Until it lands the panel manages networks and devices but no traffic
flows between them. [PROGRESS.md](PROGRESS.md) and
[VERIFICATION_REPORT.md](VERIFICATION_REPORT.md) say exactly what has and has
not been demonstrated.
