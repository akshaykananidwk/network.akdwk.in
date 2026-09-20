# Field test runbook

Two things are being tested, and they are independent. Do **Windows first** —
if the agent does not run on Windows, the CGNAT result does not matter yet.

Everything here is a read-only test except installing the agent itself.
Nothing changes your existing network, and the agent never touches your
default route — proving that is part of the test.

**The binaries in this kit are not code-signed.** Windows will say so, loudly.
That is expected; see [What Windows will say](#what-windows-will-say) before
you start, so you can tell our warning from a real one. Check the files
against `SHA256SUMS` first.

---

## Machines and roles

| Role | Machine | What it needs |
|---|---|---|
| **Panel + coordinator** | Your server running the panel | Already set up. The coordinator's UDP port must be reachable from the internet. |
| **Site A** | Shop — a Windows PC | The Windows test, then one end of the CGNAT test |
| **Site B** | Hotel — a Windows PC on a different ISP | The other end |
| **Site C** | Any laptop tethered to Jio 4G | The CGNAT case that matters most |

Before anything else, confirm the coordinator is reachable from outside:
from any machine that is *not* on the server's network, run

```
Test-NetConnection -ComputerName <panel-host> -Port 8443 -InformationLevel Detailed
```

UDP is not testable this way, so this only proves the host resolves and is up.
If the port is closed at the firewall or the router, every test below fails for
that reason alone and nothing else will make sense.

---

## Part 1 — Windows (do this first, on Windows 10 and again on Windows 11)

### 1. Prepare

1. Copy the kit folder to the machine.
2. Download `wintun.dll` from <https://www.wintun.net>, pick the `amd64` (or
   `arm64`) build, and put it **next to `akconnect-agent-windows-amd64.exe`**.
   *The agent will refuse to start without it and tell you so.*
3. Open PowerShell **as Administrator**.

### 2. Run without admin rights first — this is a test, not a mistake

Open a **normal** PowerShell (not elevated) and run:

```
.\akconnect-agent-windows-amd64.exe up
```

**Expected:** it refuses, and says it needs administrator rights and why.
**Record:** the exact text. If it instead crashes, hangs, or half-starts,
that is a defect — capture the output.

### 3. Enrol and connect

Elevated PowerShell:

```
.\akconnect-agent-windows-amd64.exe enroll --panel https://<panel-host> --join-code <CODE> --wait
```

Approve the device in the panel when it appears. The command finishes once
approved and prints the overlay address.

```
.\akconnect-agent-windows-amd64.exe up --verbose
```

**Record:** the first thirty lines, and whether a Windows Firewall prompt
appears (see below).

### 4. Install as a service

Stop the console agent (Ctrl-C), then:

```
.\akconnect-agent-windows-amd64.exe service install
.\akconnect-agent-windows-amd64.exe service start
.\akconnect-agent-windows-amd64.exe service status
```

Check `services.msc` shows **AKConnect Agent**, running, automatic.

**Then test each of these and record what happens:**

| Test | How | What should happen |
|---|---|---|
| Stop | `service stop` | Stops within a few seconds; adapter disappears |
| Start | `service start` | Comes back; adapter reappears |
| Reboot | Restart Windows | Connects on its own, before anyone logs in |
| **Hard kill** | Task Manager → End task on the agent | Service restarts within ~5s; **check whether a stale adapter is left behind** |
| Uninstall | `service uninstall` | Service and firewall rule both gone |
| Reinstall | `service install` again | Succeeds — a failure here means the previous delete did not complete |

### 5. Key storage — the part most likely to be wrong

```
icacls C:\ProgramData\AKConnect\device.key
icacls C:\ProgramData\AKConnect
```

**Expected:** only `SYSTEM` and `BUILTIN\Administrators`. No `Users`, no
`Authenticated Users`, no `Everyone`, and no `(I)` inherited entries.

Then, from a **normal user account** (not an administrator):

```
Get-Content C:\ProgramData\AKConnect\device.key
```

**Expected:** access denied. If a normal user can read that file, stop and
tell me — that is the whole protection.

### 6. DPAPI survival

The key is sealed to this machine. Two things must not break it:

1. **Reboot** — restart, confirm the agent reconnects without re-enrolling.
2. **Password change** — change the Windows password of the account you
   normally log in with, reboot, and confirm it still reconnects.

*Why this matters:* the key is sealed at machine scope precisely so a user
password change cannot orphan it. If a password change does break it, the
scope is wrong and I need to know.

**Also record:** copy `device.key` to the other Windows machine and try to
start the agent there with it. It **should** fail to unseal — that proves the
blob is bound to the machine and not portable.

### 7. Routing — the split-tunnel guarantee

```
route print -4
Get-NetRoute -DestinationPrefix "0.0.0.0/0"
Find-NetRoute -RemoteIPAddress 1.1.1.1
tracert -d -h 8 1.1.1.1
```

**Expected:** the default route is on your normal adapter, never on the
AKConnect adapter. `Find-NetRoute` for a public address picks the physical
adapter. The traceroute's first hop is your own router.

### 8. Collect

```
.\collect-evidence.ps1 -PeerIP <overlay IP of the other machine>
```

Send me the file it writes. **It contains no private key and no device token.**

---

## What Windows will say

Because the binaries are unsigned, expect all of this. None of it means
something is wrong with the file — but verify against `SHA256SUMS` anyway.

| You will see | Why | What to do |
|---|---|---|
| **SmartScreen**: "Windows protected your PC" | No Authenticode signature and no reputation | *More info* → *Run anyway*. **Record the exact wording and whether it differs between Win10 and Win11.** |
| **Defender** may quarantine the .exe | Unsigned Go binary that opens a network adapter is a common heuristic hit | Note the detection name in Defender's history. Do not add an exclusion silently — I need to know the detection name. |
| **Firewall prompt** on first console run | The agent listens on UDP 51820 | Allow on **Private**; note whether it also asks for Public. |
| **No firewall prompt** when run as a service | A service has no desktop to show it on | This is why `service install` creates the rule itself. **Confirm the rule exists** with the `netsh` line in the evidence script. |

Code signing is a separate piece of work. What I need from this round is
exactly what each version of Windows does, in its own words.

---

## Part 2 — CGNAT, on real internet

Only after Part 1 works.

Three machines, each enrolled into the **same network** in the panel:

1. **Shop** (Site A) — fixed broadband
2. **Hotel** (Site B) — different ISP
3. **4G laptop** (Site C) — Jio tethering. This is the important one:
   Indian mobile carriers are almost always CGNAT, and often symmetric.

On each, with the agent running:

```
# Windows
.\collect-evidence.ps1 -PeerIP <another site's overlay IP>

# Linux
sudo ./collect-evidence.sh <another site's overlay IP>
```

Run it **on each machine, naming a different one each time**, so every pair is
covered:

| Run on | `-PeerIP` | Tests |
|---|---|---|
| Shop | hotel's overlay IP | broadband ↔ broadband |
| Hotel | 4G laptop's overlay IP | broadband ↔ CGNAT |
| 4G laptop | shop's overlay IP | CGNAT ↔ broadband |

**What I am looking for in the results**

- `"path": "direct"` in the runtime status, and an `endpoint` that is a public
  address — that is hole punching working.
- `"path": "connecting"` that never becomes `direct` — that is the pair which
  needs the relay, and knowing *which* pair tells me how much of your estate
  will depend on it.
- The reflexive address each agent reports. If the 4G laptop's reported
  address changes between runs, its carrier NAT is symmetric, and no amount
  of hole punching will fix it — that is a relay case by definition.

A failure here is a **result**, not a problem. I expect at least the 4G pair
to fail, and that expectation is why the relay is being built next.

---

## If something goes wrong

Send the evidence file plus:

- `akconnect-agent status` output
- the last 50 lines of the agent's console output, or the Application event
  log entries from source **AKConnectAgent**

Do **not** send `device.key`. Nothing I need is in it.
