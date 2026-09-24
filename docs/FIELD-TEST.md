# Field test — nb.akdwk.in

The test server is a machine of its own: `109.123.247.239`, `nb.akdwk.in`,
fresh Ubuntu 24.04, nothing else on it. **Anything is allowed here** — stop
services, kill processes, reboot, reinstall.

The production VPS (`network.akdwk.in`) is unchanged and its rules still hold.
Nothing in this file is to be run there.

Work through in order. Each test says the exact command or click, and what
PASS looks like. Where a test can fail in a way that is a product defect
rather than a mistake, it says what to collect.

---

## 0. Install

```bash
curl -fsSL https://raw.githubusercontent.com/akshaykananidwk/network.akdwk.in/release/1.9.7/deploy/getting-started.sh | sudo bash
```

Answer: `nb.akdwk.in`, your email, `edge`.

**PASS** — it ends with:

```
  AK Connect is installed.

  Panel        https://nb.akdwk.in
  Channel      edge
  Version      1.9.7-dev.17

  Set the administrator password here — the link works once…
    https://nb.akdwk.in/reset-password?token=…
```

Open the link, set a password, sign in. If the certificate is not ready yet,
`journalctl -u caddy -f` shows Let's Encrypt issuing it; it is usually seconds.

Then enrol both Windows PCs from **Devices → Add device** — this is the one
time `setup.exe` is used. Everything after this is expected to happen without
touching the PCs.

---

## 1. R6 — the panel goes away, traffic does not

The claim: the data plane outlives the control plane. Pressing Update Now, a
database restore, a certificate renewal — none of them may cut a working
tunnel.

1. In the panel, open both device pages and confirm one pair is on a relay:
   the peer row says **relay-udp** (not *direct*). If it says direct, this
   test proves much less — a direct pair survives anything the panel does.
   Force a relay by putting one PC on a phone hotspot.
2. On the laptop, start a ping that does not stop, and leave it visible:

   ```powershell
   ping -t 10.128.0.2          # the other PC's overlay address
   ```

3. On the server:

   ```bash
   akconnect-maintenance on "R6 drill" --allow=<your own public ip>
   ```

4. Wait **five minutes**. In a browser (from an address that is not
   `--allow`ed) `https://nb.akdwk.in` must answer **503**.
5. On the server:

   ```bash
   akconnect-maintenance off
   ```

**PASS**

- The ping window shows **zero** `Request timed out` across the whole five
  minutes.
- The panel answered 503 while it was on, and loads normally after `off`.
- Neither PC was touched, and neither agent restarted — check with
  `Get-Service AKConnectAgent | Select Status,StartType` and the top of
  `C:\ProgramData\AKConnect\service.log`, which must not show a fresh start.

**FAIL** — copy the ping output, and from both PCs
`C:\ProgramData\AKConnect\service.log`, plus `journalctl -u akconnect-coordinator -n 200`
from the server. This is the drill that was red on 1.9.7-dev.12 and green
from dev.15; a failure here is a regression, not a mistake.

---

## 2. Agent restart, sleep, and a change of network

Three separate things, each of which happens to a real PC every day.

**2a — restart.** On one PC, as administrator:

```powershell
Restart-Service AKConnectAgent
```

**PASS** — the device is **Online** in the panel again within 60 seconds, and
the ping from test 1 resumes. It must never settle on "connecting".

**2b — sleep.** Close the laptop lid, leave it 5 minutes, open it.

**PASS** — Online again within 60 seconds of the network coming back, with
nothing clicked. Note how long it actually took; that number matters more than
the pass.

**2c — change of network.** With the laptop connected on wifi, turn wifi off
and connect to a phone hotspot instead.

**PASS** — the device goes Online again within about two minutes, and the peer
row shows a path (it may change from *direct* to *relay-udp*, which is
correct). A tunnel that needs the agent restarted to survive a network change
is a defect.

For each of the three, record the time taken. If any one of them ends in
"connecting" and stays there for more than three minutes, collect
`C:\ProgramData\AKConnect\service.log` and say which of the three it was.

---

## 3. Update Now — every PC updates itself

This is the requirement: **one click updates everything**, no `setup.exe`, no
visits.

1. On the server, publish a newer build:

   ```bash
   sudo /opt/akconnect/src/deploy/upgrade-edge.sh
   ```

   **PASS** — the table it prints ends with every row green, including
   `self-update`, `fallback reachable` and `coordinator version`.

2. In the panel, **Devices**, note the version each PC reports. Press
   **Update all** (or **Update now** on each device page).
3. Wait. Do not touch either PC.

**PASS**

- Both PCs report the new version within **10 minutes**, on their own.
- `C:\ProgramData\AKConnect\service.log` on each shows the download, the
  signature check and the restart.
- No `setup.exe` was run, and nobody logged into either PC.

**FAIL** — this is a defect to report, not something to fix by hand. Collect
from each PC that did not update:

```powershell
Get-Content C:\ProgramData\AKConnect\service.log -Tail 200
Get-Content C:\ProgramData\AKConnect\runtime.json
```

and from the panel, the device page's **update status** line — it says which
of "nothing published on this channel", "published unsigned", "held back by
rollout" or "already current" applies.

---

## 4. The gateway — reaching 192.168.10.0/24

**4a — from the other Windows PC (1.9.7-dev.25).**

The gateway PC needs nothing enabled: no WinNAT, no RRAS, no Hyper-V, no IP
forwarding, no route on the router. The agent translates the traffic itself.

1. On the gateway PC's device page, **Share this computer's network** →
   `192.168.10.0/24`, and approve it. The page shows the overlay range it is
   reached at — `10.128.0.0/24` below; use the one your page shows.
2. From the laptop, pairs "via server" and "direct" alike:

   ```powershell
   ping -n 20 10.128.0.1
   curl.exe -m 5 http://10.128.0.1/
   ```

3. On the gateway's device page press **Test** beside the share.

**PASS** — 20/20 replies, the router's login page, and within a minute the
share reads **working — proven … from <laptop> to 10.128.0.1**. It must never
read "live". Any **NOT working** carries its reason.

**If it fails — where the packet dies, hop by hop.** Run these while the
laptop runs `ping -t 10.128.0.1`, and send the output of every step.

*Laptop* (Administrator PowerShell):

```powershell
& "$env:ProgramFiles\AKConnect\akconnect-agent.exe" status --out $env:TEMP\akc-status.txt
Find-NetRoute -RemoteIPAddress 10.128.0.1 | ft InterfaceAlias,DestinationPrefix
# Bytes sent to the gateway peer over 10 s: should grow by ~1 KB per ping.
$p={(gc C:\ProgramData\AKConnect\runtime.json|ConvertFrom-Json).peers|?{$_.virtual_ip -eq '10.50.0.2'}}
$a=&$p; Start-Sleep 10; $b=&$p; "tx +$($b.tx_bytes-$a.tx_bytes)  rx +$($b.rx_bytes-$a.rx_bytes)  path $($b.path)"
Select-String C:\ProgramData\AKConnect\service.log -Pattern 'acl: dropped|refusing|not installed' | select -Last 20
```

- No route on AKConnect, or `tx` not growing: the laptop never sends it —
  the share is not in its configuration (the page says why).
- `tx` grows, `rx` does not: the packet left; look at the server and the
  gateway.

*Server* (nb.akdwk.in, the relay — only while the pair is "via server"):

```sh
journalctl -u akconnect-relay --since '-15 min' | grep -E 'bound|refused'
timeout 20 tcpdump -ni any -c 40 'udp and greater 1000'   # laptop: ping -l 1000 -t 10.128.0.1
```

Datagrams in from the laptop and out to the gateway, and the same size back:
the relay did its part.

*Gateway* (Administrator PowerShell):

```powershell
& "$env:ProgramFiles\AKConnect\akconnect-agent.exe" status --out $env:TEMP\akc-status.txt
Start-Sleep 10
& "$env:ProgramFiles\AKConnect\akconnect-agent.exe" status --out $env:TEMP\akc-status2.txt
Select-String C:\ProgramData\AKConnect\service.log -Pattern 'gateway' | select -Last 20
ping -n 4 192.168.10.1
```

`status` has a **Gateway** section. Compare the two runs:

| What changes between the two | Where the packet is |
|---|---|
| `arrived for the LAN` does not rise | it never reached the gateway through the tunnel: laptop or server |
| `arrived` rises, `pings answered` rises | the router answered the gateway; the reply is lost on the way back: send both status files |
| `arrived` rises, `unanswered` rises | the router did not answer this PC either — compare with `ping 192.168.10.1` from the gateway itself |
| `Gateway : NOT working — …` | the reason is the fault |

**4b — from Android: cannot be tested yet.**

There is no Android client, and the panel has no plain WireGuard
configuration or QR export, so there is nothing to install on a phone. Skip
this half rather than trying it.

The smallest thing that would make it testable is a per-device WireGuard
config export the standard WireGuard Android app can import. That is a
separate piece of work; it is not in 1.9.7-dev.17.

---

## 5. Uninstall and reinstall

Worth doing on this server precisely because it is allowed to break.

```bash
curl -fsSL https://raw.githubusercontent.com/akshaykananidwk/network.akdwk.in/release/1.9.7/deploy/getting-started.sh | sudo bash -s -- --uninstall
```

It asks you to type the domain to confirm.

**PASS** — afterwards, all of these are true:

```bash
systemctl status akconnect-coordinator      # not found
systemctl status akconnect-relay            # not found
ls /var/www/nb.akdwk.in /opt/akconnect /etc/akconnect   # no such file
mysql -e "SHOW DATABASES" | grep akconnect  # nothing
curl -I https://nb.akdwk.in                 # no response, or a certificate error
```

Then run the installer again, the same way as test 0.

**PASS** — it completes, the panel loads, and a new setup link works.

**Expected, and not a failure:** the enrolled Windows PCs do **not** come
back. The uninstall removed the coordinator's keypair, so every device that
had the old public key is talking to a coordinator that cannot decrypt it.
They have to be re-enrolled with a fresh `setup.exe`. This is the one place
`setup.exe` is still needed, and it is why the installer refuses to
regenerate that keypair when it is re-run.

---

## 6. Speed

Numbers, not a pass mark — but a relayed path that is dramatically slower than
the same link without the tunnel is a finding.

Install `iperf3` on both PCs (`winget install iperf3`, or the zip from
iperf.fr). On the PC being measured *to*:

```powershell
iperf3 -s
```

On the other:

```powershell
iperf3 -c 10.128.0.2 -t 30
iperf3 -c 10.128.0.2 -t 30 -R      # the other direction
```

Record four numbers:

| | up | down |
|---|---|---|
| direct (peer row says *direct*) | | |
| relayed (peer row says *relay-udp*) | | |

To force the relayed case, put one PC on a phone hotspot and wait for the
peer row to change.

**What to expect.** A direct path should be close to the slower of the two
internet connections. A relayed path goes through this server twice, so it is
bounded by the server's own link and will be lower — how much lower is the
number worth having, because it is what decides whether a relayed site is
usable for the CCTV traffic it exists to carry.

Also record the RTT the panel shows for each peer, and whether it agrees with
`ping`.
