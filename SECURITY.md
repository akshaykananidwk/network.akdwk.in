# Security

## Reporting a vulnerability

Email the address configured as `brand.support_email` (by default
`support@<your-domain>`). Please include what you did, what happened, and
what you expected. Do not open a public issue for an unpatched flaw.

> Citations below are `file::symbol` rather than line numbers, so they stay
> correct as the code moves. `tests/DocumentationTests.php` fails the build if
> any symbol named here stops existing.

---

## OWASP Top 10 (2021) — where each control lives

| | Risk | Control | Where |
|---|---|---|---|
| A01 | Broken access control | One place decides whose rows a request may see, and it **fails closed**: a tenant-scoped query with no resolvable tenant throws rather than running unfiltered | `app/Middleware/TenantScope.php::currentTenantId` |
| | | Every tenant-scoped read and write has the predicate appended; there is no path around it | `app/Middleware/TenantScope.php::constrain`, `app/Models/Model.php::buildWhere` |
| | | A static test fails the build if a tenant-owned model stops declaring itself scoped | `tests/StaticAnalysisTests.php::tenantScopeDeclarations` |
| | | A row belonging to another tenant returns **404, not 403** — a 403 confirms the id exists | `app/Models/Model.php::findOrFail` |
| | | Belt-and-braces check for rows fetched by a globally unique key | `app/Middleware/TenantScope.php::assertOwned` |
| | | Route-level permission gate, plus explicit checks in controllers | `app/Middleware/RbacMiddleware.php::handle`, `app/Core/Auth.php::authorize` |
| | | A tenant-scoped user can never hold a platform permission, whatever their role string says; impersonation drops platform powers | `app/Core/Auth.php::can` |
| | | Mass assignment is blocked by an allow-list, so a crafted field cannot set `tenant_id`, `status` or `role` | `app/Models/Model.php::filterFillable` |
| A02 | Cryptographic failures | AES-256-GCM (authenticated) for secrets at rest; a tampered ciphertext fails rather than decrypting to garbage | `app/Core/Crypto.php::encrypt`, `::decrypt` |
| | | Argon2id password hashing with a bcrypt fallback, and opportunistic rehash when cost parameters rise | `app/Core/Crypto.php::hashPassword`, `app/Core/Auth.php::attempt` |
| | | Device tokens, API keys and reset tokens are stored only as SHA-256 hashes | `app/Models/Device.php::issueToken`, `app/Models/ApiKey.php::issue` |
| | | Ed25519 for the controller identity and for signed update manifests | `app/Core/Crypto.php::sign`, `::verifySignature` |
| | | HSTS, but only when the request is genuinely over TLS | `app/Middleware/SecurityHeadersMiddleware.php::apply` |
| A03 | Injection | Prepared statements only; no method here accepts an interpolated value | `app/Core/DB.php::run` |
| | | Column names are validated as identifiers and **rejected**, not escaped | `app/Models/Model.php::safeColumn` |
| | | Sort direction is an allow-list of exactly two values | `app/Models/Model.php::safeDirection` |
| | | `LIKE` wildcards in a search term are escaped, so a search for `%` is literal | `app/Models/Model.php::buildWhere` |
| | | A static test fails the build on interpolated or concatenated SQL; the few identifier concatenations carry an `@sql-identifier` annotation saying why they are safe | `tests/StaticAnalysisTests.php::noInterpolatedSql` |
| | | Output escaped by default, and a static test proves every view expression is escaped, cast or literal | `app/Core/helpers.php::e`, `tests/StaticAnalysisTests.php::viewsEscapeOutput` |
| | | Email headers are stripped of CR/LF before sending | `app/Core/Mailer.php::encodeHeader` |
| A04 | Insecure design | Enrolment returns `pending` with no address and no token, so an unapproved device holds nothing usable (R4) | `app/Services/DeviceService.php::enroll` |
| | | The server strips `0.0.0.0/0` from agent configuration before sending it — R1 is a server refusal, not an agent convention | `app/Services/DeviceService.php::assertSplitTunnel` |
| | | The update pipeline takes a **verified** backup before writing anything, and rolls back automatically from `APPLY` onwards | `app/Updater/UpdateManager.php::handleFailure` |
| | | A rollback that cannot finish leaves maintenance mode **on** rather than serving a half-updated site, and prints exact recovery commands | `app/Updater/RollbackManager.php::rollback`, `::recoveryInstructions` |
| A05 | Security misconfiguration | `display_errors` off in production; traces go only to `storage/logs` | `app/bootstrap.php` |
| | | CSP with a per-request nonce, no `unsafe-eval`, no inline script | `app/Middleware/SecurityHeadersMiddleware.php::nonce` |
| | | `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` on every response | `app/Middleware/SecurityHeadersMiddleware.php::apply` |
| | | Application directories denied by per-directory `.htaccess` **and** a root rewrite rule; `deploy/`, `docs/`, `.git`, `*.md` and `*.sh` too, after a production install served them | `.htaccess` |
| | | `uploads/` executes nothing: `ExecCGI` off, the PHP handler removed, `SetHandler none` under FastCGI, and a final `Require all denied` | `uploads/.htaccess` |
| | | The updater may write that one rule into the otherwise-protected `uploads/`, and no release may delete it | `app/Updater/PathGuard.php::isWriteBlocked` |
| | | `php_flag` / `php_value` are guarded by `<IfModule mod_php*.c>`; unguarded they are HTTP 500 on every page under PHP-FPM | `.htaccess` |
| | | Apache is told to pass the `Authorization` header to FastCGI, and the application reads it wherever a rewrite leaves it | `.htaccess`, `app/Core/Request.php::authorizationHeader` |
| | | A real Apache + PHP-FPM install, driven through the browser installer, is a release gate | `services/lab/webtarget/drill.sh` |
| | | Credentials live in `config/`, denied as a whole directory, not in a web-root `.env` guarded by a rule nginx ignores | `install/Installer.php::writeEnv` |
| | | `config/config.php` written `0640` | `install/Installer.php::writeConfig` |
| | | `mod_rewrite` is proved with a real request, not inferred | `install/Installer.php::probeRewrite` |
| A06 | Vulnerable components | No Composer dependencies at all — nothing to audit beyond PHP itself | `THIRD_PARTY_LICENSES.md` |
| | | The updater refuses a release whose `min_php` or `min_mysql` this server does not meet | `app/Updater/Manifest.php::checkRequirements` |
| A07 | Identification and authentication failures | Progressive lockout — 1m, 2m, 4m … capped — after a threshold of failures | `app/Core/Auth.php::lockoutSeconds` |
| | | Identical message for wrong password, unknown address, disabled and locked accounts | `app/Controllers/AuthController.php::login` |
| | | Password reset responds identically whether or not the address exists | `app/Controllers/AuthController.php::sendResetLink` |
| | | TOTP two-factor (RFC 6238, verified against the published vectors), mandatory for platform admins | `app/Core/Totp.php::verify`, `app/Middleware/AuthMiddleware.php::handle` |
| | | Single-use recovery codes, stored hashed | `app/Core/Totp.php::consumeRecoveryCode` |
| | | A pending 2FA challenge is not a session; middleware treats it as unauthenticated | `app/Middleware/AuthMiddleware.php::handle` |
| | | Session id regenerated on login, on 2FA, and on starting or stopping impersonation | `app/Core/Auth.php::login`, `::startImpersonation` |
| | | A password reset, role change or disable ends every other session for that user | `app/Core/DbSessionHandler.php::destroyForUser` |
| A08 | Software and data integrity failures | Archives rejected for traversal, absolute paths, symlinks and zip-bomb expansion | `app/Updater/PathGuard.php::validateArchiveEntry`, `app/Updater/ArchiveExtractor.php::assertNotZipBomb` |
| | | Manifest checksums verified and every staged PHP file parsed before anything goes live | `app/Updater/ArchiveExtractor.php::verifyChecksums`, `::verifyPhpSyntax` |
| | | Protected paths are never written or deleted, even when a manifest asks | `app/Updater/PathGuard.php::isProtected`, `app/Updater/Manifest.php::deletions` |
| | | Optional ed25519 manifest signatures; with verification on, an unsigned release is refused | `app/Updater/Manifest.php::verifySignature` |
| | | Agent binaries carry a sha256 **and** a signature; the agent verifies both before swapping | `app/Models/AgentRelease.php::latestFor` |
| A09 | Logging and monitoring failures | Structured JSON logs per channel, daily rotation, retention pruning | `app/Core/Logger.php::log` |
| | | Audit trail for login, user and role changes, network and device lifecycle, ACL and route changes, API keys, settings, updates, backups and impersonation | `app/Services/AuditService.php::log` |
| | | Every logged value passes through redaction — see below | `app/Core/Logger.php::redact` |
| | | Auditing never breaks the action it records; a failure is logged instead | `app/Services/AuditService.php::log` |
| A10 | Server-side request forgery | The updater only ever calls the configured GitHub API host; no user-supplied URL is fetched | `app/Updater/GithubClient.php::requestRaw` |
| | | TLS peer verification on for every outbound request | `app/Updater/GithubClient.php::requestRaw` |

### Also covered

| Control | Where |
|---|---|
| CSRF on every state-changing request, double-submit with a per-session secret | `app/Core/Csrf.php::verify`, `app/Middleware/CsrfMiddleware.php::handle` |
| Token-authenticated callers are exempt from CSRF; session-authenticated ones never are, including on `/api` | `app/Middleware/CsrfMiddleware.php::handle` |
| Sliding-window rate limiting, Redis or MySQL | `app/Core/RateLimit.php::enforce` |
| Open-redirect protection on "back" redirects | `app/Core/Kernel.php::isOwnUrl` |

---

## Secret handling

The GitHub access token is the sharpest edge in the system — it can read your
source — so it is worth stating exactly how it is handled.

* **At rest** — AES-256-GCM, keyed from `APP_KEY`. `app/Models/UpdateSetting.php::setToken`
* **In the UI** — the field is write-only. Submitting the form empty keeps the
  stored value; the real token is never rendered back into the page.
  `app/Controllers/Admin/UpdateController.php::saveSettings`
* **In the API** — the settings projection returns `token_masked`
  (`ghp_****abcd`) and never the ciphertext. `app/Models/UpdateSetting.php::toArray`
* **In logs** — every value written passes through redaction, which replaces
  values under secret-ish keys wholesale *and* masks known token shapes inside
  free text, including exception messages and curl errors.
  `app/Core/Logger.php::redact`, `::redactString`
* **In the audit trail** — snapshots are redacted, and a fixed deny-list of
  columns is dropped entirely. `app/Services/AuditService.php::encode`
* **In update logs** — the per-run log is redacted line by line.
  `app/Updater/UpdateLog.php::write`

The test suite asserts that realistic tokens in several shapes survive none of
these paths — see the *Logger — secret redaction* group in
`tests/UnitTests.php`.

Separate keys, deliberately:

| Key | Protects | Why it is separate |
|---|---|---|
| `app.key` | Settings, tokens, 2FA secrets | — |
| `backup.encryption_key` | Off-site backups | A leaked application key must not also decrypt the backups |
| `coordinator.signing_key` | Agent configuration signatures | Compromising the panel should not let an attacker forge peer lists |
| `security.update_public_key` | Release manifests | Public half only; the secret lives on the release machine |

---

## Rate limiting

Sliding window, Redis when configured and MySQL otherwise, so a single-box
install still gets real limiting rather than none.

| Bucket | Default | Scope |
|---|---|---|
| `login` | 20 / 15 min | per IP |
| `reset` | 5 / 15 min | per IP |
| `signup` | 5 / hour | per IP |
| `enroll` | 60 / hour | per IP |
| API key | 120 / min | per key |
| Device | 120 / min | per device |

`X-Forwarded-For` is honoured **only** when the immediate peer is a configured
trusted proxy. Otherwise any client could spoof its address and defeat both the
rate limiter and the maintenance-mode allowlist. `app/Core/Request.php::ip`

---

## Known limitations

These are real, and worth knowing before deploying.

* **No brute-force protection across many source addresses.** Lockout is per
  account and rate limiting is per IP. A distributed attack on one account is
  slowed by the lockout but not by the limiter.
* **Sessions are not bound to an IP or user agent.** A stolen session cookie
  works until it expires. Binding to an IP breaks mobile users on cellular
  networks, so the trade was deliberate; 2FA and a short idle timeout are the
  mitigation.
* **The SMTP client does not implement DKIM.** Sign at the MTA, or use a
  provider that does.
* **No CSP reporting endpoint.** Violations are blocked but not reported back.
* **`unsafe-inline` remains in `style-src`.** Component state sets inline
  styles (progress bars, usage meters). `script-src` has no such allowance.
* **Local backups are not encrypted at rest.** `backup.encryption_key` is
  generated and stored, but the local archive is written plain; encryption
  applies to the off-site drivers. Protect `storage/backups` with filesystem
  permissions.
* **Phase 2 is not built**, so the data-plane guarantees (R2, R6) are not
  demonstrated yet. R1, R4 and R5 are enforced and tested in the control plane
  today. See [VERIFICATION_REPORT.md](VERIFICATION_REPORT.md).
