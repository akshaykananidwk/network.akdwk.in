# Changelog

Notable changes per release. This project follows
[Semantic Versioning](https://semver.org): a breaking release sets
`"breaking": true` in `update.json`, and the panel warns before applying it.

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
