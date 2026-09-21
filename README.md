# AK Connect — private overlay network platform

A multi-tenant control plane for software-defined overlay networks: connect
PCs, servers, NVRs, NAS boxes and printers across sites into one encrypted
private LAN, while every customer's ordinary internet traffic keeps going out
of their own connection.

This repository holds the **control plane** — the web dashboard, REST API,
billing, installer and the GitHub auto-update system. The data plane (agent,
coordinator, relay) is Go and lives under `services/`; see
[Architecture](#architecture) for why the split exists and
[PROGRESS.md](PROGRESS.md) for what is built today.

---

## What is working now

Phase 1 is complete and verified: **425 assertions, all passing**
(`php tests/run.php`). That covers the installer, the whole framework,
authentication with TOTP two-factor, role-based access control, multi-tenancy,
networks and devices, IP address management, the ACL engine, audit logging,
backups, and the GitHub auto-update pipeline with automatic rollback.

Phase 2 — the Go agent, coordinator and relay that carry actual packets — is
**not built yet**. Until it is, the panel manages networks and devices but no
traffic flows between them. [VERIFICATION_REPORT.md](VERIFICATION_REPORT.md)
is explicit about what has and has not been demonstrated.

---

## Requirements

| | Minimum | Recommended |
|---|---|---|
| PHP | 8.1 | 8.2+ |
| MySQL / MariaDB | 5.7 / 10.3 | 8.0 / 10.6+ |
| Web server | Apache with `mod_rewrite` | Apache or nginx |
| Extensions | `pdo_mysql openssl curl zip mbstring json fileinfo` | plus `sodium opcache intl` |
| Disk | 512 MB free | 2 GB+ (backups and staged updates) |

`sodium` is worth having: without it the installer cannot generate the
controller signing keypair, and signed update manifests cannot be verified.

There is **no Composer step and no npm step**. Upload the files and open the
installer.

---

## Installing

1. Copy the repository to your web root.
2. Make these writable by the web server user:

   ```
   chmod -R 775 storage uploads config
   ```

3. Open `https://your-domain/install` and follow the six steps.
4. Add the cron line the installer prints (see [Scheduler](#scheduler)).
5. Delete the installer: `rm -rf install`.

The installer checks the server before it touches anything, and every failing
row tells you what to do about it. `mod_rewrite` is tested with a real
request rather than inferred from `phpinfo()`, because plenty of hosts load
the module and then ignore `.htaccess`.

### Unattended install

```bash
php cli/install.php --print-template > answers.json
$EDITOR answers.json
php cli/install.php --answers=answers.json
```

### What the installer writes

| File | Purpose |
|---|---|
| `config/config.php` | Everything. Authoritative, `0640`, never overwritten by an update. |
| `config/.env` | Database settings for tooling that reads the environment. See `config/.env.sample`. |
| `install/install.lock` | Blocks the wizard from being run again. |

`config/.env` is inside `config/` on purpose. A `.env` in the web root is
protected only by a per-file `.htaccess` rule, which nginx ignores entirely
and Apache skips under `AllowOverride None`; `config/` is denied as a whole
directory instead.

---

## Updating

Once **System → Updates** knows your repository, updating is one click. There
is no second path to keep in sync — the web UI and `cli/update.php` drive the
same state machine.

```bash
php cli/update.php --check          # what is available
php cli/update.php --apply          # apply it
php cli/update.php --apply --yes    # unattended
php cli/update.php --status         # where a running update got to
php cli/update.php --rollback=12    # undo update #12
```

### What happens when you press Update

| Step | What it does |
|---|---|
| `PRECHECK` | Disk space, PHP and MySQL versions, extensions, writability; warns about locally edited files |
| `MAINTENANCE` | 503 for visitors; your IP and session keep access so you can watch |
| `BACKUP_FILES` | Application archive with a sha256 |
| `BACKUP_DB` | Verifies the dump actually restores-worthy before going further |
| `DOWNLOAD` | Fetches the archive for the exact commit |
| `STAGE` | Extracts with path-traversal protection, verifies checksums, parses every PHP file |
| `MIGRATE` | Runs pending migrations, recording each one |
| `APPLY` | Copies files, writing a per-file undo journal as it goes |
| `POST` | Runs post-update tasks, clears caches including opcache |
| `HEALTH` | Database, tables, routes, critical files, a surviving super admin |
| `FINALISE` | Lifts maintenance mode, prunes old backups, notifies |

Each step is a separate request that persists its progress, so a slow host
cannot time out half-way through.

**If something fails.** Before `APPLY` nothing on disk has changed, so the run
is simply marked failed and the site comes back. From `APPLY` onwards the
rollback is automatic: the file journal is replayed in reverse and the
database is restored from the pre-update dump. If the rollback itself cannot
finish, maintenance mode deliberately **stays on** and the update detail page
shows the exact commands to restore by hand. A half-updated site is never
left serving traffic.

**What an update never touches:** `config/config.php`, `.env`, `uploads/`,
`storage/`, `install/install.lock`, and anything matching `*.local.php`. A
release manifest cannot override this — if it asks to delete `config.php`,
the request is logged and ignored.

### Publishing a release

Put `update.json` in your repository root:

```json
{
  "version": "1.5.0",
  "released_at": "2026-09-20T10:00:00Z",
  "min_php": "8.1.0",
  "breaking": false,
  "notes": "Added ACL priority and relay failover",
  "migrations": ["2026_09_18_101500_add_acl_priority.sql"],
  "delete": ["app/Legacy/OldThing.php"],
  "post_update": ["cli/tasks/rebuild_acl_cache.php"],
  "checksums": { "app/Core/Router.php": "sha256:…" }
}
```

To sign it, so the panel refuses anything you did not publish:

```bash
php cli/keygen.php --update                      # once; store the secret half
php cli/keygen.php --sign=update.json --key=<secret>
```

Then enable *Require a signed manifest* under System → Updates.

---

## Scheduler

One cron line runs everything: update checks, the offline-device sweep,
scheduled backups, retention pruning, queued jobs and alerts.

```cron
*/5 * * * * /usr/bin/php /path/to/cli/worker.php >> /path/to/storage/logs/cron.log 2>&1
```

Run it by hand with `--verbose` to see what each task decided:

```bash
php cli/worker.php --verbose
```

---

## Backups

```bash
php cli/backup.php                      # take one now
php cli/backup.php --list               # what is stored
php cli/backup.php --verify=7           # re-check its checksums
php cli/backup.php --restore=7 --dry-run   # what a restore would do
php cli/backup.php --restore=7 --yes    # actually restore
php cli/backup.php --prune              # apply the retention policy
```

A backup is files plus database, each with a sha256 recorded at creation and
re-checked before any restore. `mysqldump` is used when the host allows it and
a complete pure-PHP dumper when it does not — a great many shared hosts
disable `shell_exec`.

If `uploads/` is too large to include, the backup records
`uploads_included = 0` **with a reason**, and the UI says so. Believing you
have a full backup when you do not is worse than knowing it is partial.

---

## Architecture

```
          CONTROL PLANE — PHP 8 + MySQL, this repository
          dashboard · REST API · billing · installer · auto-update
                          │                    │
              internal API (HMAC)         config pull (HTTPS)
                          │                    │
         ┌────────────────▼──────┐   ┌─────────▼───────────┐
         │  COORDINATOR (Go)     │   │   RELAY NODES (Go)  │
         │  device auth          │   │   encrypted         │
         │  endpoint exchange    │   │   passthrough only  │
         │  UDP hole punching    │   │   region failover   │
         └───────────┬───────────┘   └─────────┬───────────┘
                     │                         │
        ┌────────────▼─────────────────────────▼────────────┐
        │  DATA PLANE — AGENT (Go), Windows/Linux/macOS     │
        │  TUN · WireGuard Noise_IK · split-tunnel routes   │
        └───────────────────────────────────────────────────┘
```

**Why two languages.** PHP cannot hold a raw UDP socket open for NAT hole
punching, cannot run a TUN interface, and cannot sustain a long-lived
encrypted tunnel. Pretending otherwise would produce a dashboard that looks
finished and moves no packets. The control plane is PHP because it must
deploy onto ordinary shared hosting; the data plane is Go because that is what
the job requires.

No customer traffic ever passes through the control plane database.

### Rules the code enforces

| | Rule | Where |
|---|---|---|
| R1 | Split tunnel only — `0.0.0.0/0` never enters the tunnel | `DeviceService::assertSplitTunnel()` strips it server-side before config is sent |
| R2 | Direct peer-to-peer first, relay only as fallback | Coordinator (Phase 2) |
| R3 | No cross-tenant visibility | `TenantScope`, which fails closed |
| R4 | No device is trusted until an admin approves it | Enrolment returns `pending` with no address and no token |
| R5 | Private keys never leave the device | Only `public_key` exists in the schema |
| R6 | Control plane down ≠ tunnels down | Agents run from a signed cached config (Phase 2) |
| R7 | A UI without real networking is a failure | Stated plainly in PROGRESS.md and the verification report |

---

## Layout

```
index.php  health.php  .htaccess        front controller and health probe
assets/                                 CSS and JS, no build step
install/                                the wizard; self-locks after use
app/Core/                               framework
app/Models/                             data access, all tenant-scoped
app/Services/                           business logic
app/Updater/                            the auto-update pipeline
app/Controllers/  app/Views/            HTTP layer
config/                                 config.php, .env — denied to the web
database/schema.sql  migrations/        schema and migrations
storage/                                logs, cache, backups, staging
cli/                                    install, migrate, backup, update, worker
services/coordinator|relay|agent/       Go data plane (Phase 2)
tests/                                  verification suite
```

`app/`, `config/`, `database/`, `storage/`, `cli/`, `services/` and `tests/`
each ship an `.htaccess` denying web access, and the root `.htaccess` blocks
them again by rewrite rule. On nginx, deny those paths explicitly — see
[DEPLOY.md](DEPLOY.md).

---

## Tests

```bash
php tests/run.php            # everything available in this environment
php tests/run.php --unit     # no database, no server needed
php tests/run.php --db       # tenant isolation, IPAM, devices, limits
php tests/run.php --http     # end-to-end; needs a server (below)
```

For the HTTP tests:

```bash
php -S 127.0.0.1:8088 -t . tests/dev-server.php
php tests/run.php --http --url=http://127.0.0.1:8088
```

Database tests run inside a transaction that is always rolled back, so they
are safe against a live installation. HTTP tests create fixtures and delete
them in a `finally` block.

The Go services have their own tests, and they are run with the race detector
because the coordinator handles every packet in its own goroutine:

```bash
cd services/coordinator && go test -race ./...
```

### The networking gate

The tests above say nothing about whether two machines can actually reach each
other. That is what `services/lab/run-all.sh` is for. It builds Linux network
namespaces, brings up a real panel, coordinator and two real relays, enrols two
agents through the real approval flow, and runs every scenario the product
depends on — cone NAT, symmetric NAT, both mixed directions, controller down,
relay down, relay failover, usage accounting and revocation. It prints one
pass/fail table and exits non-zero on any failure.

```bash
sudo ./services/lab/run-all.sh          # everything, about twenty minutes
sudo ./services/lab/run-all.sh cone     # one scenario
./services/lab/run-all.sh --list        # the scenario names
```

**No release ships without a clean table.** It needs root, because it creates
network namespaces, and it leaves its binaries, logs and agent state in
`services/lab/.run`, which is gitignored.

---

## Troubleshooting

**Blank page after an update.** The health check should have caught it and
rolled back. If the site is in maintenance mode the rollback did not finish:
open `storage/logs/update-<id>.log` for the exact failure, and the update
detail page for the restore commands.

**"Database connection failed" right after installing.** Almost always
`localhost` versus `127.0.0.1`. Many panels only listen on the TCP loopback.

**The installer says rewriting is not working.** Apache needs
`AllowOverride All` for the directory; on nginx add the `try_files` rule from
DEPLOY.md.

**Updates say "not found" for a repository that exists.** It is private and
the token is missing or expired. The token needs the `repo` scope.

**Stuck in maintenance mode.** `rm storage/maintenance.flag`. Read the update
log first — the flag is usually still there for a reason.

**Nothing is happening on schedule.** Check cron is actually running the
worker: `php cli/worker.php --verbose` shows what each task decided.

---

## Documentation

| | |
|---|---|
| [DEPLOY.md](DEPLOY.md) | Apache and nginx vhosts, aaPanel, SSL, Docker for the Go services |
| [API.md](API.md) | REST API v1, with `docs/openapi.yaml` |
| [SECURITY.md](SECURITY.md) | Controls, OWASP Top 10 mapping, reporting a vulnerability |
| [PROGRESS.md](PROGRESS.md) | What is done, what is stubbed, what is next |
| [VERIFICATION_REPORT.md](VERIFICATION_REPORT.md) | Test results and evidence |
| [CHANGELOG.md](CHANGELOG.md) | Release history |
| [THIRD_PARTY_LICENSES.md](THIRD_PARTY_LICENSES.md) | Dependencies and their licences |
