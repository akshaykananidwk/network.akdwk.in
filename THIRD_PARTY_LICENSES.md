# Third-party licences

## The short version

**The control plane in this repository has no third-party dependencies.**

No Composer, no npm, no vendored libraries. Everything under `app/`,
`install/`, `cli/`, `assets/` and `tests/` was written for this project and
carries this project's licence. There is nothing to `composer audit`.

That is a deliberate constraint from the brief — the platform has to deploy by
copying files onto shared hosting — and it has a pleasant side effect: the
supply-chain attack surface is PHP itself and nothing else.

---

## What is used instead of a library

| Need | Usual dependency | What this project does |
|---|---|---|
| Routing | a framework | `app/Core/Router.php` — pattern compiler with typed placeholders |
| Database | an ORM | `app/Core/DB.php` and `app/Models/Model.php` — PDO, prepared statements only |
| Templating | Twig, Blade | Plain PHP views with a mandatory escaping helper |
| SMTP | PHPMailer, Symfony Mailer | `app/Core/Mailer.php` — EHLO / STARTTLS / AUTH / DATA |
| TOTP | a 2FA package | `app/Core/Totp.php` — RFC 6238, verified against the published vectors |
| QR codes | a JS library from a CDN | `assets/js/qr.js` — ISO/IEC 18004 encoder, verified against a real decoder for versions 1–10 |
| CSS | Tailwind with a build step | `assets/css/app.css` — hand-written token layer |
| Database dump | `mysqldump` only | `app/Updater/DatabaseDumper.php` — uses `mysqldump` when the host allows it, complete pure-PHP dumper when it does not |
| Testing | PHPUnit | `tests/TestCase.php` — assertions, grouping, an exit code |

The QR encoder deserves a note. The obvious approach is a CDN script, but the
Content-Security-Policy forbids external scripts, and sending a TOTP secret to
a third-party QR service to be turned into an image would be indefensible. So
it is implemented here: Reed–Solomon over GF(256), the eight mask patterns
with the standard penalty scoring, and format and version information. It is
tested by rendering and then **decoding** the result with an independent
decoder across every supported version.

---

## PHP extensions

These ship with PHP or are packaged by the distribution. Each is licensed
under the PHP License 3.01 unless the underlying library says otherwise.

| Extension | Required | Underlying library and licence |
|---|---|---|
| `pdo_mysql` | yes | mysqlnd — PHP License 3.01 |
| `openssl` | yes | OpenSSL — Apache License 2.0 |
| `curl` | yes | libcurl — curl licence (MIT/X derivative) |
| `zip` | yes | libzip — BSD 3-Clause |
| `mbstring` | yes | Oniguruma — BSD 2-Clause |
| `json` | yes | PHP License 3.01 |
| `fileinfo` | yes | libmagic — BSD 2-Clause |
| `sodium` | recommended | libsodium — ISC |
| `opcache` | recommended | PHP License 3.01 |
| `intl` | optional | ICU — Unicode License |
| `redis` | optional | phpredis — PHP License 3.01 |

Nothing here is GPL or AGPL, so distributing the platform commercially
requires no source disclosure.

---

## Phase 2 — the Go data plane

Not written yet. The licences are recorded now because the choice of
dependency is exactly where a commercial product gets this wrong, and the
constraint should be visible before the code exists rather than after.

| Dependency | Purpose | Licence | Commercial distribution |
|---|---|---|---|
| `golang.zx2c4.com/wireguard` | The tunnel — Noise_IK, userspace | **MIT** | Fine |
| `golang.zx2c4.com/wireguard/wgctrl` | Configuring kernel WireGuard | **MIT** | Fine |
| `golang.zx2c4.com/wintun` | Windows TUN driver binding | **MIT** (driver itself: proprietary, redistributable) | Fine |
| `golang.org/x/crypto` | Curve25519, ChaCha20-Poly1305 | **BSD 3-Clause** | Fine |
| `golang.org/x/sys` | Platform syscalls | **BSD 3-Clause** | Fine |
| `github.com/vishvananda/netlink` | Linux interface and route control | **Apache 2.0** | Fine |
| `github.com/pion/stun` | STUN for reflexive address discovery | **MIT** | Fine |
| `github.com/redis/go-redis` | Coordinator presence store | **BSD 2-Clause** | Fine |

### What is deliberately excluded

* **Anything GPL or AGPL in the agent.** The agent is distributed to
  customers' machines; a copyleft dependency would oblige us to publish the
  source. Permissive licences only.
* **ZeroTier's source, protocol internals, branding or wording.** ZeroTier is
  BSL-licensed and its protocol is its own. This project implements a similar
  *concept* on WireGuard's protocol, which is permissively licensed and
  designed to be embedded. No ZeroTier code, identifiers or copy appear here.
* **Tailscale's client.** Likewise: a fine product, and not ours to reuse.

WireGuard is a registered trademark of Jason A. Donenfeld. This project uses
the WireGuard protocol via the MIT-licensed `wireguard-go`; it is not
endorsed by or affiliated with the WireGuard project, and the trademark is not
used in branding.

---

## Development-only tools

Used to verify the build; not shipped, not linked, not required to run the
platform.

| Tool | Purpose | Licence |
|---|---|---|
| `segno` (Python) | Cross-checking the QR encoder against a reference | BSD 3-Clause |
| `zxing-cpp` (Python) | Decoding generated QR codes to prove they scan | Apache 2.0 |
| MariaDB | Running the database tests | GPL 2.0 — a *server* the platform talks to over a protocol, not a linked library |

---

## This project

Copyright © the project owner. All rights reserved unless a licence file
says otherwise.
