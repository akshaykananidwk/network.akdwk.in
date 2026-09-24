# Deploying AK Connect for real

Two machines, in this order:

1. **The panel**, on the aaPanel VPS, at `network.akdwk.in`, installed through
   `/install` exactly as a customer would.
2. **The coordinator and one relay**, on a public India VPS.

Then the field kit is pointed at both, and the shop / hotel / 4G test runs
against this rather than against the lab.

Work through it in order and do not skip a check. Where a step says **Expect**,
that is what I saw when I ran the same command; if yours differs, stop and send
me the difference rather than carrying on.

**What I could and could not verify.** Everything that runs on a Linux machine
without your credentials — the installer, its requirement checks, the services,
the systemd units, the port arithmetic, the sizing numbers — I ran here and the
output below is real. Everything that needs *your* VPS, *your* DNS and *your*
aaPanel — TLS, the vhost, the firewall, the public addresses — I could not, and
those steps say so.

---

## The short way: one command on a server of its own

Everything below this section is the staged deployment, for a machine that is
already serving something — a control panel, other people's websites, their
mail. That is the production shape at `network.akdwk.in`, and it is why the
steps are careful.

On a **server of its own**, with nothing else on it, there is a single command:

```bash
curl -fsSL https://raw.githubusercontent.com/akshaykananidwk/network.akdwk.in/release/1.9.7/deploy/getting-started.sh | sudo bash
```

It asks three questions — domain, administrator email, release channel — and
then installs and configures Caddy with automatic Let's Encrypt certificates,
PHP-FPM, MariaDB, the panel, the coordinator, a relay, the `/fallback` route to
the relay's websocket listener, the firewall, the systemd units, the
five-minute worker and the edge upgrade timer. It finishes with the panel's
address and a one-time link for setting the administrator password.

```
  --domain nb.akdwk.in --email you@example.com --channel edge
  --unattended        ask nothing (needs the three above)
  --branch <ref>      install a ref other than release/1.9.7
  --uninstall         remove everything it installed
```

It is safe to run again: nothing it creates is recreated, the database is not
touched, and the coordinator's keypair is never regenerated — doing that would
orphan every device already enrolled. That is tested rather than asserted:
`sudo services/lab/install-gate.sh` runs it after a first run that died before
the panel was installed and after one that died after it, twice in a row,
stopped part-way with a signal, through `--uninstall` and back, and over trees
and panels the previous release left — each time in its own namespaces with a
private database — and checks that nothing was loosened, regenerated or left
behind. The release gate runs it.

What a re-run does and does not change:

- **The panel is left as it is** once installed — its code, its configuration
  and the commit it records. The panel updates itself; a re-run does not.
- **Its coordinator settings are written again** if they differ, which is what
  repairs a panel an earlier release installed (before 1.9.7-dev.21 that step
  never worked, and the panel had no coordinator key).
- **The release channel** is written when you give one — `--channel`, or typed
  at the prompt — and on the first run that gets that far. Pressing Enter at the
  prompt of a later run leaves whatever the panel is set to.
- **A setup link** is printed when the administrator account has not been taken
  up yet — never signed in to, still on the password the installer gave it —
  for example after a first run that died after creating it. Once somebody has
  signed in, a re-run prints no link; `cli/setup-link.php` makes one on demand.

Every git command it runs, it runs as the owner of the repository — the panel
tree as the web user, the edge source as root. Nothing in it writes a
`safe.directory`, and none is needed. If a `git config --global --add
safe.directory …` was added by hand to get past a failed re-run, remove it:

```bash
sudo git config --file /root/.gitconfig --unset safe.directory /var/www/<your domain>
```

`--file /root/.gitconfig`, because that is where it went: the installer runs
under `sudo`, which gives root's home, so the workaround was written into
root's configuration. The same command without `sudo` edits your own, finds
nothing, and says nothing.

**It refuses to run on a shared machine.** It installs a web server, claims 80
and 443 and opens firewall rules; on a box already serving somebody, each of
those is their outage. It looks for aaPanel, cPanel, Plesk and a running
Apache or nginx, and stops if it finds any of them. Use the staged
instructions below there.

**Caddy does not read `.htaccess`.** The repository root is the webroot, so
`config/config.php` — database credentials and the app key — is a plain file
underneath it. The site file the script writes denies every directory
`.htaccess` denies, and a test in the verification suite fails if the two ever
drift apart.

### Replacing the coordinator's shared secret

```bash
sudo akconnect-rotate-secret            # the panel <-> coordinator secret
sudo akconnect-rotate-secret --relay    # and this server's relay secret
```

The shared secret is what the coordinator and `upgrade-edge.sh` sign every
call to the panel with. Treat it as a password with root on your devices
behind it: whoever holds it can publish a Windows installer or an agent
binary, and the panel signs an uploaded agent and offers it to every device as
an update. Up to 1.9.7-dev.21 the installer printed it. If it has been seen
anywhere — a chat, a screenshot, a terminal log — rotate it.

The command changes the panel first, then `/etc/akconnect/coordinator.env` and
`coordinator.secret.for-panel`, restarts the coordinator, and proves the panel
accepts the new secret and refuses the old one. It prints neither.

What it breaks: nothing on any agent — none of them holds this secret, and
none needs a restart, a re-enrolment or a new config. Direct and relayed pairs
keep carrying traffic. For 10-25 seconds after the restart, a device that has
to announce itself waits its turn. The upgrade timer is held while it runs.

`--relay` also replaces the secret this server's relay shares with the
coordinator, which was never printed. It restarts the relay: relayed pairs
through it pause 15-25 seconds while their agents fetch new tickets, by
themselves. Relays on other servers (`add-relay.sh`) each have their own
secret and are not touched.

It ends by listing what was published with the secret — the installer and
agent the panel serves, and every agent release registered in the last 30 days
(`--since` to go further back). An agent release NEWER than the panel was not
built by any edge: withdraw it before a device takes it,

```bash
sudo -u www-data php /var/www/<domain>/cli/edge-audit.php --withdraw=<version>
```

and republish from source with `sudo /opt/akconnect/src/deploy/upgrade-edge.sh --force`.

On the production host, where the panel was not installed by
getting-started.sh: `sudo /opt/akconnect/src/deploy/rotate-secret.sh --panel /www/wwwroot/network.akdwk.in`.

### The edge's release key: what the panel publishes

From 1.9.7-dev.23 the panel publishes a Windows installer or an agent update
only when the edge server's own **release key** signed it. The shared secret
alone is not enough: an upload with no edge signature is refused, and one
signed by a key the panel does not trust yet is **held** under Platform →
Coordinator, where no device and no customer is offered it until an
administrator approves it.

The key is made on the edge, once, by `upgrade-edge.sh`:
`/etc/akconnect/release-signing.key`, root's, mode 0600. It never leaves that
machine, and nothing prints it. To see its fingerprint:

```bash
sudo akconnect-coordinator release-key public /etc/akconnect/release-signing.key
```

Which key the panel trusts is decided in one of two ways, and never over the
network:

- **The panel is on the edge's machine** (a getting-started.sh install): the
  edge tells the panel directly, as the panel's own user. Nothing to approve.
- **Otherwise**: the first upload waits under Platform → Coordinator with the
  fingerprint of the key that signed it. Compare it with the command above and
  press *The fingerprint matches — trust this key and publish*. From then on
  that edge's uploads publish by themselves.

An upload held under a **different** key from the trusted one is shown in red.
Unless you replaced or reinstalled the edge server, somebody else signed it:
discard it and run `sudo akconnect-rotate-secret` on the edge.

On the production host, where the panel was not installed by
getting-started.sh but is on the same machine, either approve the first
upload in the panel or trust the key directly, once:

```bash
sudo /opt/akconnect/src/deploy/upgrade-edge.sh --panel-dir /www/wwwroot/network.akdwk.in
```

### Taking that panel down for a test

`akconnect-maintenance on|off|status`, installed by the same script. It is
`cli/maintenance.php` with the paths filled in — this site answers 503, and
nothing else on the machine is touched. The rule in the next section applies
there too: never stop the web server to take a panel down.

---

## What to buy

### The panel

The aaPanel VPS you already have. What it needs:

| | |
|---|---|
| PHP | 8.1 or newer, with `pdo_mysql`, `mbstring`, `openssl`, `curl`, `zip`, `sodium` |
| Database | MariaDB 10.4+ or MySQL 5.7+ |
| Disk | 2 GB free, plus room for backups — each one is around 45 MB |
| Memory | 1 GB is enough; the panel is PHP request-response and holds nothing between requests |

### The coordinator and relay VPS

Small. Both services are Go binaries that forward UDP and hold almost nothing.

| | Measured |
|---|---|
| Coordinator binary | 9.1 MB |
| Relay binary | 4.0 MB |
| Relay at rest | 5.2 MB resident |
| Relay per session | **2 KB in the process**, plus the kernel's own socket buffers, which are the larger half |

A session is two UDP sockets and two goroutines. The process cost is small
enough to ignore; what actually sizes the machine is the kernel's socket
buffers and the bandwidth.

**Buy: 1 vCPU, 1 GB RAM, 25 GB disk, and the largest bandwidth allowance you
can get cheaply.** Bandwidth is the thing that will run out, not CPU or memory.
Every relayed byte is counted twice — once in, once out — so a customer pulling
a 2 Mbit camera stream for eight hours costs about 14 GB of the allowance.

Put it in **Mumbai or Bangalore**. The relay is a fallback path, and its whole
value is latency; a relay in Singapore makes a Gujarat-to-Gujarat call go to
Singapore and back.

### Ports to open on the VPS firewall

Exactly these, and nothing else:

| Port | Protocol | What it is |
|---|---|---|
| 8443 | **UDP** | The coordinator. Agents seal their announcements to it |
| 9000 | **UDP** | The relay's control port, where agents ask for a session |
| 51900–52400 | **UDP** | The relay's data sockets |
| 443 | TCP | The panel — and, on `/fallback`, the path for networks that carry no UDP |
| 22 | TCP | ssh, if it is not open already |

443 is already open, because it serves the panel. Since 1.9.6 it carries one
more thing: `install-edge.sh` configures Apache to proxy `/fallback` to the
relay, which listens for it on loopback. That is what makes the product work on
a hotel network, a guest VLAN, or an office that lets UDP out and drops the
replies — on those, everything above this line is unavailable, the coordinator
included, so a device there cannot even ask for help. It opens an ordinary TLS
connection to 443 instead and carries both its control messages and its relayed
traffic over it.

Check it from any machine, including a customer's:

```bash
curl https://network.akdwk.in/fallback/health
```

**Expect:** `ok` and a count of sessions. Anything else — a login page, a 404,
a timeout — means the fallback is not reachable and devices on strict networks
will not connect. `upgrade-edge.sh` checks the same thing on every upgrade.

The data range is pinned deliberately. Left alone, the relay takes whatever
port the kernel hands out, which on Linux means 32768–60999 — most of the
unprivileged port space, and not something anybody should be asked to open.
`--data-ports 51900-52400` is in the systemd unit and gives room for 250
concurrent sessions.

---

## Stage 1 — The panel · 30 min

### 1a — DNS and the vhost

In aaPanel, add a site for `network.akdwk.in` and point the domain's A record
at the VPS. Then, in **Website → SSL**, issue a Let's Encrypt certificate and
turn **Force HTTPS** on.

**Expect:** `https://network.akdwk.in/` loads something — aaPanel's default
page is fine at this point.

**Do not continue until HTTPS works.** The agent refuses to send a device token
over plain HTTP, and the coordinator refuses a panel URL that is not https, so
everything after this will fail in ways that look like different problems.

*I could not verify this step. It is your DNS, your certificate and your
aaPanel.*

### 1b — Put the code there

```bash
cd /www/wwwroot/network.akdwk.in
git clone https://github.com/akshaykananidwk/network.akdwk.in.git .
chown -R www:www .
chmod -R 755 .
chmod -R 775 storage uploads config
```

In aaPanel, leave the site's **document root** at
`/www/wwwroot/network.akdwk.in` — the repository root. There is no `public/`
directory: this application serves from its root and keeps everything else out
of the browser with the `.htaccess` at that root, which blocks `app/`,
`config/`, `database/`, `storage/`, `services/`, `deploy/`, `docs/`, the `.git`
directory, and every `.md`, `.sh` and `.env` file.

**This matters more than it looks.** If those rules are not being read — the
document root is somewhere else, `AllowOverride` is `None`, or the site runs on
nginx without the equivalent rules — the configuration file, the database
credentials and the private keys are all reachable over the web.

**Check it, from your own machine:**

```bash
for p in config/config.php .env .git/config DEPLOY.md deploy/install-edge.sh uploads/.htaccess; do
  printf '%-28s %s\n' "$p" "$(curl -sS -o /dev/null -w '%{http_code}' https://network.akdwk.in/$p)"
done
```

**Expect:** `403` or `404` for every one of them. A `200` anywhere means the
rules are not in force; fix that before going further.

### 1c — URL rewriting

**On Apache** — which is what aaPanel installs by default, and what this was
deployed on — there is nothing to add: the root `.htaccess` has the rewrite
rules, the header rules and the hardening. It only needs to be read, so the
site's **AllowOverride** must be `All`. In aaPanel that is **Website → Config
→ Configuration file**; the directory block for the site must not say
`AllowOverride None`.

The same `.htaccess` also carries `CGIPassAuth On`. Apache does not pass the
`Authorization` header to PHP-FPM without it, and without that header every
agent call arrives looking unauthenticated — a working install that reports
itself as a revoked one.

**On nginx**, `.htaccess` is not read at all and you must add the equivalent
by hand. In **Website → Config**, inside the `server` block:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

# Everything the .htaccess blocks on Apache. Without these, the configuration
# file and the private keys are served to anyone who asks.
location ~ ^/(app|config|database|storage|cli|services|tests|deploy|docs)/ { deny all; }
location ~ /\.(git|env) { deny all; }
location ~* \.(md|sh|sql|log)$ { deny all; }
location ^~ /uploads/ { location ~ \.php$ { deny all; } }
```

Reload nginx, then run the 1b check again — it must still be `403`/`404` for
every path.

### 1d — The database

In aaPanel, **Database → Add**: name `akconnect`, user `akconnect`, and let it
generate the password. Write the password down.

### 1e — Install

Open `https://network.akdwk.in/install` in a browser and work through the
wizard. It asks for the database details from 1d, an administrator email and
password, and the coordinator's address — leave the coordinator fields alone
for now; Stage 3 fills them in.

The command-line equivalent, which is what I ran here:

```bash
php cli/install.php --print-template > answers.json
# edit answers.json
php cli/install.php --answers=answers.json
```

**Expect** — this is the real output, from a real run:

```
Checking requirements…
  ✗ URL rewriting: probe request failed (need working)
    Enable mod_rewrite and set "AllowOverride All" for this directory, or add the equivalent rule to your nginx config.
  ! URL rewriting could not be verified from the command line. Confirm it once the site is reachable.
  ✓ Requirements satisfied
Connecting to the database…
  ✓ Connected to 10.11.14-MariaDB-0ubuntu0.24.04.1.
Importing the schema…
  ✓ 27 tables created.
Creating the administrator…
  ✓ admin@akdwk.in
  ✓ GitHub updates configured for akshaykananidwk/network.akdwk.in
Writing the configuration…
  ✓ config/config.php, .env, install/install.lock

Installation complete.
```

**The rewriting line is expected from the command line** and not from the
browser: the installer probes its own URL, and there is no web server answering
when it runs as a CLI script. Through `/install` in a browser it should be a
tick. If it is a cross *there*, 1c did not take.

**27 tables** is the number for this release. Fewer means the schema import
stopped partway.

### 1f — The worker

```bash
crontab -u www -e
```

Add the line the installer printed — it has the right PHP binary and the right
paths already. On aaPanel that binary is under `/www/server/php`, not
`/usr/bin`:

```
*/5 * * * * /www/server/php/83/bin/php /www/wwwroot/network.akdwk.in/cli/worker.php >> /www/wwwroot/network.akdwk.in/storage/logs/cron.log 2>&1
```

Two things about that line:

- **`83` is the PHP version directory.** Confirm yours with
  `ls /www/server/php`. `/usr/bin/php8.1` does not exist on a stock aaPanel
  box, and a cron line that points at it fails silently every five minutes.
- **Run it as `www`, not as root.** `crontab -e` as root gives you a root
  cron; the worker then writes logs, cache and backup files owned by `root`
  inside `storage/`, and the web user cannot write them afterwards. Use
  `crontab -u www -e`, or aaPanel's **Cron** page with the user set to `www`.

```bash
crontab -u www -l | grep worker.php
```

**Do not delete the `install/` folder.** An earlier version of this document
said to, and that is how a production panel came to serve the installation
wizard for two minutes: `rm -rf install` takes the lock file with it, and the
next update restored `install/` from the release without the lock — because
the lock is a protected path and updates do not write those.

The installer locks itself. Since 1.9.2 it also refuses on the evidence of the
system rather than on that one file: a `config/config.php`, or a database with
an account in it, is enough, and reaching the wizard on a configured panel
writes the lock back.

**Check:**

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://network.akdwk.in/install/
curl -sS https://network.akdwk.in/install/ | grep -o 'already complete'
```

**Expect:** `403`, and `already complete`. A `200` with the word *Welcome* in
it means an unlocked installer is live on your panel — run
`php cli/install.php --answers=/dev/null` from the app root, which refuses and
restores the lock, and tell me.

### 1g — Sign in

Open `https://network.akdwk.in/login` and sign in with the administrator
account. Turn on two-factor authentication now, while you are thinking about
it: **Profile → Two-factor**.

---

## Stage 2 — The coordinator and relay · 20 min

On the India VPS, as root.

### 2a — Build or fetch the binaries

On a machine with Go 1.24:

```bash
cd services/coordinator && GOOS=linux GOARCH=amd64 go build -trimpath \
    -ldflags "-s -w -X main.version=$(cat ../../VERSION)" -o akconnect-coordinator ./cmd/akconnect-coordinator
cd ../relay && GOOS=linux GOARCH=amd64 go build -trimpath \
    -ldflags "-s -w -X main.version=$(cat ../../VERSION)" -o akconnect-relay ./cmd/akconnect-relay
```

Copy both to the VPS.

### 2b — Put them in place

```bash
install -m 755 akconnect-coordinator /usr/local/bin/
install -m 755 akconnect-relay       /usr/local/bin/
akconnect-coordinator --version
akconnect-relay --version
```

**Expect:** the version you built, on both.

### 2c — Install the services

From a copy of this repository's `deploy/` directory on the VPS:

```bash
./install-edge.sh \
    --panel https://network.akdwk.in \
    --relay-name mumbai-1 \
    --public-host <the VPS's public hostname or IP> \
    --region in
```

**Expect:**

```
── checking what is here
  both binaries are in /usr/local/bin

── creating the service account
  created the akconnect system user

── generating keys and secrets
  generated a coordinator keypair and two shared secrets

── installing the services
  akconnect-relay is running
  akconnect-coordinator is running

── what to do next
  …
```

It ends by printing the coordinator's **public key** and the **shared secret**
the panel needs. Keep that output.

It is safe to run twice. It will not regenerate the keys — regenerating the
coordinator's key would orphan every agent that already has its public half —
so to start over you delete `/etc/akconnect/coordinator.env` by hand first.

**What I verified here**, on a Linux box without systemd as pid 1, which is as
far as this can be taken without your VPS:

- the script runs to the point where it calls `systemctl`, and stops there with
  a clear error rather than half-configuring anything;
- it creates the `akconnect` system user with no shell and no home;
- it generates the keypair and both secrets, and derives the relay's secret
  variable name correctly (`mumbai-1` → `AKCONNECT_RELAY_SECRET_MUMBAI_1`);
- the files land with the right ownership and mode:

```
/etc/akconnect                              750 root:akconnect
/etc/akconnect/coordinator.env              640 root:akconnect
/etc/akconnect/relay.env                    640 root:akconnect
/etc/akconnect/coordinator.secret.for-panel 640 root:akconnect
/etc/akconnect/coordinator.pub              644 root:root
```

- both units pass `systemd-analyze verify` with no warnings;
- **both services start from those exact files**, which is the thing most
  likely to be quietly wrong:

```
[mumbai-1] relay control on 0.0.0.0:9000

coordinator listening on 0.0.0.0:8443
public key: BIKso32CKSI+h6OABJ4zyKiCFbRvwWdQVS9IyVdElTE=
```

- and the public key the coordinator prints is byte-for-byte the one in
  `coordinator.pub`, so the value you paste into the panel is the value the
  coordinator is actually running with.

*What I could not do is run it under systemd on your VPS, or open your
firewall.*

### 2d — Open the firewall

Whatever your provider gives you — a web console, `ufw`, `firewalld`. With
`ufw`:

```bash
ufw allow 8443/udp comment 'AKConnect coordinator'
ufw allow 9000/udp comment 'AKConnect relay control'
ufw allow 51900:52400/udp comment 'AKConnect relay data'
ufw allow 443/tcp comment 'AKConnect panel and HTTPS fallback'
ufw status numbered
```

**Expect:** four rules — three UDP and 443. **If your provider also has a
firewall in their control panel, the same four go there** — that one is in
front of the machine and `ufw` cannot see it. This catches people out.

The relay's own fallback listener is on `127.0.0.1:9443` and must **not** be
opened: Apache reaches it over loopback and terminates the TLS. Opening it
would serve the fallback unencrypted.

*I could not verify this. It is your provider's firewall.*

### 2e — Check it is listening

```bash
systemctl status akconnect-coordinator akconnect-relay --no-pager
ss -lunp | grep -E '8443|9000'
journalctl -u akconnect-coordinator -n 20 --no-pager
```

**Expect:** both `active (running)`, both ports bound, and the coordinator's log
ending with a line naming the relay it knows about.

---

## Stage 3 — Introducing them · 10 min

### 3a — Tell the panel about the coordinator

**Platform → Coordinator** in the sidebar (`/admin/coordinator`), using the values
`install-edge.sh` printed:

| Field | Value |
|---|---|
| Host | the VPS's public hostname |
| Port | 8443 |
| Public key | from `/etc/akconnect/coordinator.pub` |
| Shared secret | from `/etc/akconnect/coordinator.secret.for-panel` |

### 3b — Register the relay

**Platform → Relays** (`/admin/relays`), *Register a relay*:

| Field | Value |
|---|---|
| Name | `mumbai-1` |
| Region | `in` |
| Host | the VPS's public hostname |
| Control port (UDP) | 9000 |

The relay form asks for nothing else. It has no public key of its own — it
authenticates with `AKCONNECT_RELAY_SECRET` — and there is no TCP fallback to
configure.

### 3c — Check they are talking

On the VPS:

```bash
journalctl -u akconnect-coordinator -f
```

**Expect:** within a minute, lines showing the coordinator reaching the panel
successfully. A `401` or `403` means the shared secret in 3a does not match
`/etc/akconnect/coordinator.env`.

In the panel, **Platform → Relays** should show `mumbai-1` as healthy.

**The failure I expect you to hit here** is the firewall — either `ufw` or your
provider's. If the relay shows as unhealthy, check 2d before anything else.

---

## Stage 4 — Point the field kit at it · 5 min

The Windows pack and the Linux install command both have the panel's address
built in from the panel itself, so there is nothing to edit by hand: the join
code you copy out of the panel carries it.

Create the network and the first join code:

1. **Networks → New.** Name it, take the default range unless a site you are
   testing already uses it, and leave the search domain and the virtual prefix
   pool blank.
2. **Join code → Issue.** Copy it.

Then rebuild the Windows pack so its runbook and binaries match what is
deployed. `upgrade-edge.sh` does this, builds it outside every source tree, and
publishes it on the panel — so that is the command:

```bash
sudo /opt/akconnect/src/deploy/upgrade-edge.sh
```

Do not run `build-windows-pack.sh` by hand inside the panel's own directory.
It writes its output beside itself, and run as root there it leaves 25 MB of
root-owned files inside a tree the panel cannot then clean, which go into
every backup it takes.

**Expect:** a pack around 14 MB, containing `akconnect-setup.exe`.

---

## Stage 5 — The field test

This runs against the deployment above, not the lab. Three places, in order of
how much they will teach you:

1. **The shop.** Ordinary broadband, one Windows PC. Run Stage 15 of the
   Windows runbook — the installer — and nothing else. If a shopkeeper cannot
   be walked through it on the telephone, nothing else matters.
2. **The hotel.** One PC as a gateway for the camera network, and an NVR that
   has never had software installed on it. Stage 13.
3. **Jio 4G.** A laptop tethered to a phone, reaching the hotel. This is the
   one that will use the relay, and it is the only way to find out what
   fraction of real Indian connections cannot punch.

Run `collect.ps1` at each stage and send me the zip.

---

## Updating to 1.9.2 — three steps, in this order

1. **In the panel: System → Updates → Update Now.** This brings the PHP side to
   1.9.2 and runs its three migrations. Nothing else changes yet: the
   coordinator and the relay are still on the old release, and the panel will
   say so on **Platform → Coordinator**.

2. **On the VPS, once, as root:**

   ```bash
   sudo /opt/akconnect/src/deploy/upgrade-edge.sh
   ```

   This is where the two protocol fixes actually land — the coordinator is the
   thing that was freezing peer lists. It also builds a fresh
   `akconnect-setup.exe` stamped for your panel and prints the address it is
   served from. Copy that address; it is what the customer downloads. It ends
   in `PASS — N step(s), all clean.` or `FAIL — the edge is NOT upgraded.`, and
   nothing in between.

3. **On each Windows PC: double-click the new `akconnect-setup.exe`, type the
   join code, click OK.** Over an existing install this upgrades in place and
   keeps the device's identity — same key, same enrolment, same overlay
   address. No PowerShell, no restart, no reboot.

Within about a minute both machines should read **Online** in the panel, and
each should be able to ping the other's `10.50.x.x` address.

After this, step 3 is not needed again for a defect fix: 1.9.2 agents check
with the panel every six hours and install a release it has signed. Publishing
one is what step 2 does.

---

## Taking the panel down, without taking anything else down

This panel shares its server with more than thirty other people's websites and
their mail. **Never stop Apache to test the panel.** Doing so takes down every
site on the machine — and their mail — to answer a question about one virtual
host. No instruction in this repository should ever ask for it, and if you
find one, it is a defect.

```bash
php cli/maintenance.php on  "Upgrading the panel" --allow=<your ip>
php cli/maintenance.php status
php cli/maintenance.php off
```

It writes a flag file the panel's own middleware reads. Apache keeps running,
every other virtual host keeps serving, mail is untouched, and requests to
this panel alone get 503 with a `Retry-After`. `--allow` keeps your own
address working so you are not locked out of the thing you are testing.

**What this is for.** From 1.9.7-dev.14 a relayed pair carries traffic
straight through a panel outage: the coordinator keeps deciding from the last
answer the panel gave it, for up to a day, and usage that cannot be reported
is held and billed on the next successful report. That is R6 — the data plane
outlives the control plane — and this is how to check it on a live machine:

1. Get two devices onto a relayed path and start a `ping` between them.
2. `php cli/maintenance.php on "R6 check" --allow=<your ip>`
3. Wait three or four minutes. **The ping must not drop a packet**, and the
   panel must answer 503 while every other site on the server answers
   normally.
4. `php cli/maintenance.php off`
5. The ping is still running, and nothing was restarted anywhere.

---

## Updating the edge servers

The panel's updater updates the panel. The coordinator and the relay are Go
services on a different machine, so they are upgraded there — one command, as
root:

```bash
sudo /opt/akconnect/src/deploy/upgrade-edge.sh
```

It asks the panel which release it is on, builds both services from that exact
commit, keeps the previous binaries in `/var/backups/akconnect`, installs,
restarts, and then checks that both report the new version **and** are
listening. It builds the Windows installer stamped for your panel and
publishes it at a fixed address, which it prints. It never touches
`/etc/akconnect` or `config.php`.

It ends in one of two ways and nothing else:

```
  PASS — 12 step(s), all clean.
```
```
  FAIL — the edge is NOT upgraded. 1 step(s) failed.
```

When there is nothing to rebuild, it says so, and does only what an idle edge
still needs: it checks the HTTPS fallback (configuring Apache if you passed
`--configure-apache`) and tells the panel what is running, so the relay's
version and "Last heard" on Platform → Coordinator stay current.

```
  already current   PASS   coordinator, relay and installers are all 1.9.7-dev.21 — nothing rebuilt, nothing restarted
```

Before 1.9.7-dev.21 there was no such case. The hourly timer rebuilt both
services, restarted them and re-published the Windows installer on every tick,
whether or not the panel had moved — a few seconds' outage for every relayed
pair, once an hour, and within an hour of a real upgrade the "previous"
binaries in `/var/backups/akconnect` were the current ones. `--force` rebuilds
and restarts anyway.

### Who owns `/opt/akconnect/src`

Every git command runs as whoever owns the checkout — root, if root cloned it;
your login user, if you did. That is git's own rule (it refuses a repository
belonging to someone else, with "detected dubious ownership"), and it is
followed rather than switched off: nothing here writes a `safe.directory`.

**One case needs a person, once.** Up to 1.9.7-dev.20 the script ran git as
root whatever owned the checkout, and threw git's answer away: on a checkout
your login user cloned, the hourly timer failed every hour with `no git
checkout at /opt/akconnect/src`. The copy already on such a machine is that
old copy, and it fails at its preflight — before it fetches, so before it can
hand over to the fixed one. On an edge like that, run it once by hand:

```bash
sudo /opt/akconnect/src/deploy/upgrade-edge.sh
```

(`sudo` from the owning user works with the old copy, because git trusts
`SUDO_UID`.) From then on the timer runs the fixed copy. An edge whose
checkout root cloned — which is every one `deploy/getting-started.sh` set up —
never had the problem.

### Files the panel cannot write

The panel updates itself in place as the web server user (`www` on aaPanel,
`www-data` on Ubuntu), so every file in its tree has to be that user's. Up to
1.9.7-dev.20, any `php cli/…` command typed as root — `update.php`,
`backup.php`, `maintenance.php`, a root cron line for `worker.php` — left
root-owned files behind: logs the panel then silently could not write to for
the rest of the day, backups it reported as missing, a lock that switched
locking off, a maintenance flag that locked out the address it had allowed. An
update that met one of them rolled back with `ROLLBACK INCOMPLETE`.

From 1.9.7-dev.21 every panel CLI run as root becomes the owner of the panel's
files before it touches anything, and says so. What earlier root runs left
behind is not repaired by that. On a panel that has had them, once:

```bash
chown -R www:www /www/wwwroot/network.akdwk.in   # aaPanel: the web user is www
```

The whole tree, not only `storage/`: an update run as root left every file it
added — anywhere in `app/`, `cli/`, `database/` — as root's, and an install run
as root left `config/config.php` itself root's. `deploy/getting-started.sh`
does this on every run of its own servers.

### The HTTPS fallback, and the one time you have to ask for it

From 1.9.6 the relay also serves the fallback for devices on networks that
carry no UDP. Apache has to proxy `/fallback` to it, and on the panel's own VPS
that Apache serves thirty other businesses' websites — so this step is the one
thing in the product that edits something you did not install, and it happens
only when you ask for it by name:

```bash
sudo /opt/akconnect/src/deploy/upgrade-edge.sh --configure-apache
```

Ordinary `upgrade-edge.sh` runs, and the hourly timer, **never** touch Apache.
The timer reports `Apache fallback not configured yet` and ends PARTIAL. It
refuses even if the flag is put in the timer unit, and it refuses if there is
no terminal to confirm on.

What it prints before it changes anything:

```
  This will add three lines to ONE virtual host:

    file : /www/server/panel/vhost/apache/network.akdwk.in.conf
    block: the <VirtualHost> opening at line 9, ServerName network.akdwk.in, TLS

        # BEGIN AK Connect HTTPS fallback — managed by deploy/upgrade-edge.sh
        IncludeOptional /etc/akconnect/apache/akconnect-fallback.conf
        # END AK Connect HTTPS fallback

  and write /etc/akconnect/apache/akconnect-fallback.conf, which holds the
  proxy directives.
  It may also uncomment LoadModule lines in /www/server/apache/conf/httpd.conf
  for: proxy, proxy_http.
  Loading a module changes no site's behaviour by itself.

  Nothing else on this server is edited. A copy of every file it touches is
  kept, and deploy/remove-apache-fallback.sh undoes all of it.

  Go ahead? [y/N]
```

**Read the file and the line number before typing anything.** They must be the
panel's own site. If that line names a customer's `.conf`, answer `n` and say
so — it is meant to refuse that case, and a refusal that had to be caught by
eye is a defect worth hearing about.

Then it asks every `ServerName` on the machine for `/` and for
`/fallback/health`, over TLS and over port 80, **before and after** the reload.
Any site that answers differently, or any name that appears or disappears,
puts every edited file back from its timestamped copy and fails the run. After
the reload the panel must answer `/fallback/health` **from the relay** — the
relay names itself in the reply, because a site with a front controller
answers 200 for paths it has never heard of — and no other name may answer it
at all, over TLS or in cleartext.

To undo it completely, at any time:

```bash
sudo /opt/akconnect/src/deploy/remove-apache-fallback.sh --panel https://network.akdwk.in
```

It removes the block from every file on the machine that carries it, removes
the generated configuration, and probes every site before and after the same
way — rolling back if anything moved. `--dry-run` shows what it would do.
`LoadModule` lines are left loaded, because another site may be proxying
through the same modules; it prints which of them this product enabled.

**Go.** The services need 1.24 or newer. The official tarball unpacks to
`/usr/local/go`, which is on nobody's `PATH` in a root shell — the script looks
there anyway, and refuses a toolchain too old to build with rather than failing
halfway through a compile.

**It updates itself.** Before doing anything to the services it fetches, takes
the release's own copy of this script out of git, and hands over to it — so the
script that runs is the one the release shipped, not the one that happened to
be in the checkout. Without that, a fix to this script only took effect the
*second* time it was run, and the run meant to deliver the fix was made by the
code the fix replaced.

The one exception is the first upgrade onto a release that carries this: the
copy already on the box predates the hand-over and cannot do it, so that time
the old script runs and behaves as it always did. Run it once more afterwards
if you want the new behaviour immediately.

**It builds outside the checkout.** The Windows installer is assembled in a
temporary directory, not in `services/kit`, and the pack is no longer a tracked
file. It used to be both, so a successful run left its own clone dirty and the
*next* run refused with "has local changes" — the tool that maintains the edge
could be run once per clone, and the second attempt looked like the operator's
mistake.

**It leaves the checkout usable.** `/opt/akconnect/src` is left on its branch
at the release's commit, not on a detached HEAD. Earlier versions detached it,
and the next `git pull` there answered "You are not currently on a branch" —
so the ordinary way to update a checkout stopped working on every edge server
this script had touched.

The installer it builds is published to the panel and served from there, which
is the URL to give a customer: it is stamped for your panel and comes from the
release the panel is actually running. There is no copy in the repository any
more.

**This already stops itself being a manual job.** Since 1.9.5 the script
installs a systemd timer on every run, which checks this panel hourly and
upgrades the edge whenever the panel moves to a new release. An edge that is
only upgraded when somebody remembers to log in runs an old coordinator for
months, and you find out when two customers cannot connect rather than when
the release is published.

To manage the scheduling yourself instead:

```bash
sudo /opt/akconnect/src/deploy/upgrade-edge.sh --no-timer
```

It then says so in its summary, as an INFO row, so a future you can see why
the edge is not keeping itself current.

### A second relay

One relay is a single point of failure for every pair that cannot punch
through their routers — a CGNAT customer, a hotel with a symmetric NAT — and
it is also one region. A relay in the city where the customers are is the
difference between a camera feed that plays and one that stutters.

On the NEW relay's server, as root:

```bash
sudo /opt/akconnect/src/deploy/add-relay.sh \
     --name mumbai-2 --public-host relay2.akdwk.in \
     --coordinator edge.akdwk.in:8443 --region in-west
```

It installs and starts the relay, then prints the two lines to add to
`/etc/akconnect/coordinator.env` on the EDGE server and what to type under
Platform → Relays. It cannot reach the edge or the panel itself: that machine
is given a secret and a name, and never a credential for anything else.

Open on the new server: UDP 9000, and UDP 51900–52400 for the data sockets.

### Why the panel's Update Now does not do this for you

To build and restart services on the edge, the panel would need root on that
machine, stored in the panel. The panel is a PHP application on the public
internet holding the customer database; the edge holds the coordinator's
private key and the relay secrets. One compromise of the panel would take the
data plane with it, and keeping those apart is the whole point of the
control-plane/data-plane split.

So the edge pulls and the panel never reaches in. What the panel does instead
is tell you: **Platform → Coordinator** shows the coordinator's and relay's
versions against its own, and when they are behind it prints the command
above.

---

## Which releases a panel installs

**System → Updates → Channel** decides, and from 1.9.3 it actually does:

| Channel | Installs | Use it when |
|---|---|---|
| **Stable** | the newest `vX.Y.Z` tag | always, on a panel with customers on it |
| **Beta** | the newest tag, release candidates included | you are helping test one |
| **Edge** | the head of the configured branch, finished or not | a fix is being written for you right now |

Before 1.9.3 the channel was stored and displayed and then ignored: every panel
followed the branch head, so any commit was offered as an update the moment it
was pushed. If your panel is on Stable, the only thing it will offer you now is
a tagged release.

A channel with no published release offers nothing and says so. It will not
quietly fall back to the branch.

---

## Updating a panel that is already running

Updates go through **System → Updates** in the panel, or `php cli/update.php`.
The pipeline takes a verified backup before it writes anything and rolls back
by itself if the health check fails.

What matters on a box that has been patched by hand — and 1.9.0's had to be:

| File | What the update does |
|---|---|
| `.htaccess` | **Overwritten.** 1.9.1's is the one with the guarded `php_flag`, the blocked directories and `CGIPassAuth`; a hand-edited copy is replaced by it |
| `app/Core/Crypto.php` | **Overwritten.** The Argon2 fix is in it |
| `uploads/.htaccess` | **Written by a post-update task.** `uploads/` is a protected path, so the updater itself will not write into it; the task installs the file and a rollback leaves it alone |
| `config/config.php` | **Left alone**, always. Values edited there by hand survive |
| `.env`, `storage/`, `uploads/*`, `*.local.php` | **Left alone** |

So a manual edit to `.htaccess` or `Crypto.php` is superseded, not merged — if
you added something of your own to either, re-apply it afterwards. A manual
edit to `config/config.php` survives, which is the reason the coordinator
settings moved out of it: **Platform → Coordinator** now stores them in the
database, where a later release cannot miss them and you can see them.

**After the update, re-run the 1b check.** Every path must still answer 403 or
404, and this one must not execute:

```bash
printf '<?php echo "EXECUTED";' > /www/wwwroot/network.akdwk.in/uploads/probe.php
curl -sS https://network.akdwk.in/uploads/probe.php
rm /www/wwwroot/network.akdwk.in/uploads/probe.php
```

**Expect:** a 403 page, or the source as plain text. The word `EXECUTED` on its
own means `uploads/.htaccess` is not in force — check it exists and that
`AllowOverride` permits it.

---

## When something is wrong

| What you see | Where to look |
|---|---|
| The panel loads but `/install` serves the wizard | 1.9.2 refuses this on its own and restores the lock. On an older release, recreate `install/install.lock` by hand — and do not delete `install/` |
| `config/config.php` returns 200 | The root `.htaccess` is not being read. Check the document root, and that the site's `AllowOverride` is `All` — 1b |
| Every page is HTTP 500 and the log says `php_flag` | An `.htaccess` with unguarded `php_flag`/`php_value` under PHP-FPM. 1.9.1 guards them; a hand-edited copy may not |
| `storage/logs` files are owned by `root` | The worker cron is root's, not `www`'s — 1f |
| Agents get "provide the device token as a Bearer token" with a valid token | Apache is not passing the `Authorization` header to FastCGI. 1.9.1 ships `CGIPassAuth` in the root `.htaccess`; confirm it is being read |
| Devices enrol but never connect | The relay is unreachable. 2d, then `journalctl -u akconnect-relay` |
| The relay shows unhealthy in the panel | Firewall, almost always — and often the provider's rather than `ufw` |
| The coordinator logs 401 from the panel | The shared secret in 3a does not match `/etc/akconnect/coordinator.env` |
| An agent says a route was not installed | The site already uses that range. Change the network's virtual prefix pool — it is on the network's edit page, and the device's page names the clash |
| Names do not resolve on Windows | NRPT was refused, and the device's page will say so. The agent will not edit the hosts file on Windows; that is deliberate |

Logs:

```bash
# panel
tail -f /www/wwwroot/network.akdwk.in/storage/logs/*.log

# edge
journalctl -u akconnect-coordinator -f
journalctl -u akconnect-relay -f
```

---

## What this deployment does not have yet

Said here rather than discovered later:

- **One relay is one point of failure.** Relay failover is built and drilled,
  and it needs a second relay to fail over *to*. A second VPS in a different
  city is the first thing to add once the pilot works.
- **No monitoring.** Nothing will tell you the relay has died except a customer.
  The panel's relay health page is the only signal and nobody is watching it at
  3am.
- **No backups off the VPS.** The panel backs itself up before every update,
  onto the same disk. `backup.offsite_driver` exists and only `none` is
  implemented.
- **Nothing is signed.** The Windows installer will show "Publisher: Unknown"
  and SmartScreen will warn. That is a cost-of-sale problem, not a technical
  one, and Stage 15a is where you find out how much it costs.
