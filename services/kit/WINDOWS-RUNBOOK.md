# Windows test pack — runbook

**Time: about 75 minutes per machine**, including two reboots. Run it on a
Windows 10 box and again on a Windows 11 box. Use a spare you can wipe.

Every step below is a command to copy and paste, and what you should see. If
what you see differs, **that is the result** — carry on to the next step
anyway. The collector records failures as failures; it does not stop.

You never need to improvise. If a step is genuinely impossible, note it and
move on.

## What you need before starting

- The panel URL, e.g. `https://net.akdwk.in`
- A join code from the panel
- A second device already on the same overlay network, and its overlay IP
  (looks like `10.x.x.x`). For the first machine there is no peer yet — leave
  `-PeerIP` off, and come back to the peer steps once the second machine is up.

## Contents of this pack

| File | What it is |
|---|---|
| `akconnect-agent.exe` | The agent, 64-bit Intel/AMD |
| `arm64\akconnect-agent.exe` | The agent, ARM (Surface Pro X and similar) |
| `wintun.dll` | The network driver. **Already here — nothing to download.** |
| `LICENSE-wintun.txt` | Its licence, which must stay with it |
| `collect.ps1` | Collects evidence. Run it when told to |
| `package-results.ps1` | Zips everything up at the end |
| `SHA256SUMS` | Checksums |

If this machine is ARM, copy `arm64\akconnect-agent.exe` over the one in the
main folder before you start.

---

## Stage 0 — Unpack and verify · 5 min

Put the folder at `C:\akconnect-test`. Open **PowerShell as Administrator**
(right-click Start → Terminal (Admin)) and run:

```powershell
cd C:\akconnect-test
Get-FileHash .\akconnect-agent.exe -Algorithm SHA256
Get-FileHash .\wintun.dll -Algorithm SHA256
Get-Content .\SHA256SUMS
```

**Expect:** the two hashes appear in `SHA256SUMS`. If they do not, stop and
tell me — do not run the binary.

Allow the scripts to run in this window only:

```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass -Force
```

Take a baseline:

```powershell
.\collect.ps1 -Stage 00-baseline
```

---

## Stage 1 — Run without admin rights · 3 min

**This is a test, not a mistake.** Open a **normal** PowerShell — not
Administrator — and run:

```powershell
cd C:\akconnect-test
.\akconnect-agent.exe selftest
```

**Expect:** it runs and reports `FAIL administrator rights` with an
explanation, and keeps going through the other checks.

**Record:** copy the whole output into a file called `stage1-no-admin.txt` in
the pack folder. If it instead crashes, hangs, or appears to start normally,
that is important — say so.

Close this non-admin window. Everything from here on is in the **Administrator**
window.

---

## Stage 2 — SmartScreen and Defender · 5 min

The binary is **not code-signed**. Windows will object. I need its exact words.

If you have not already seen a SmartScreen dialog, trigger one: open File
Explorer, go to `C:\akconnect-test`, and double-click `akconnect-agent.exe`.

**Expect:** a blue "Windows protected your PC" box.

**Record**, into `stage2-smartscreen.txt`:
1. The exact title and body text.
2. Whether "Run anyway" is behind a "More info" link.
3. Whether Windows 10 and Windows 11 word it differently.
4. Take a photo or screenshot if easier.

Then click **More info → Run anyway** (a console window will flash and close —
that is fine, it printed usage and exited).

Check whether Defender took the file:

```powershell
.\collect.ps1 -Stage 02-smartscreen
```

**Expect:** the `Defender threat history` section is empty. If Defender
quarantined the exe, **note the detection name** — do not add an exclusion
without telling me what it was.

---

## Stage 3 — Self-test before enrolling · 5 min

```powershell
.\akconnect-agent.exe selftest
```

**Expect** all six checks to pass:

```
  ok    administrator rights               elevated
  ok    tunnel driver present              found
  ok    keystore round trip                C:\ProgramData\AKConnect\device.key (DPAPI machine scope, SYSTEM and Administrators only)
  ok    stored identity unseals            none stored yet (not enrolled)
  ok    state directory writable           C:\ProgramData\AKConnect\state.json
  ok    create and remove an adapter       akc-selftest created and removed
```

The two that matter most:

- **`keystore round trip`** — this is DPAPI sealing and unsealing for real.
- **`create and remove an adapter`** — this is Wintun loading and creating a
  real adapter, then removing it.

```powershell
.\collect.ps1 -Stage 03-selftest
```

---

## Stage 4 — Enrol · 5 min

```powershell
.\akconnect-agent.exe enroll --panel https://PANEL-URL --join-code YOUR-CODE --wait
```

Replace both placeholders. It will print your public key and then wait.

**Now approve the device in the panel.** The command finishes by itself and
prints your overlay address.

**Expect:**

```
  Generated a new device identity.
  Private key: C:\ProgramData\AKConnect\device.key (DPAPI machine scope, SYSTEM and Administrators only) — it does not leave this machine.
  Public key : <base64>
  Enrolled as dev_...
  Status     : pending

  This device is waiting for an administrator to approve it.
  Nothing connects until they do.

  Waiting for approval....
  Approved. Address on the overlay: 10.x.x.x
```

**Write down the overlay address.** The other machine needs it.

---

## Stage 5 — Connect from the console · 5 min

```powershell
.\akconnect-agent.exe up --verbose
```

**Expect** within a few seconds:

```
interface AKConnect is up at 10.x.x.x (revision N, M peer(s))
split tunnel: only 10.x.x.0/24 and 0 configured route(s) go through it
discovery: announcing to the coordinator at ...
discovery: our public address is ...
```

**Watch for a Windows Firewall prompt.** Record, in `stage5-firewall.txt`:
- Did one appear? Exact wording?
- Which networks were ticked — Private, Public, Domain?
- Tick **Private** and allow.

Leave this window running. Open a **second Administrator PowerShell** for the
next steps.

```powershell
cd C:\akconnect-test
.\collect.ps1 -Stage 05-console-up -PeerIP 10.x.x.x
```

Use the *other* machine's overlay IP, or omit `-PeerIP` if it is not up yet.

Now stop it with **Ctrl-C** in the first window.

**Expect:** it shuts down and the adapter disappears. Confirm:

```powershell
Get-NetAdapter | Where-Object { $_.InterfaceDescription -like "*Wintun*" }
```

**Expect:** nothing. If an adapter is left behind, that is a finding.

---

## Stage 6 — Install as a service · 10 min

```powershell
.\akconnect-agent.exe service install
.\akconnect-agent.exe service start
.\akconnect-agent.exe service status
```

**Expect:** `Service: running`, a firewall rule created, and in `services.msc`
an entry **AKConnect Agent**, Running, Automatic.

```powershell
Get-Service AKConnectAgent | Format-List Name, Status, StartType
.\collect.ps1 -Stage 06-service-running -PeerIP 10.x.x.x
```

Stop and start it:

```powershell
.\akconnect-agent.exe service stop
Get-NetAdapter | Where-Object { $_.InterfaceDescription -like "*Wintun*" }
.\akconnect-agent.exe service start
Start-Sleep -Seconds 10
.\akconnect-agent.exe status
```

**Expect:** the adapter goes when stopped and comes back when started, and
`status` shows the tunnel up again.

---

## Stage 7 — Hard kill · 5 min

This is the one most likely to find something.

1. Open **Task Manager** → **Details** tab.
2. Find `akconnect-agent.exe`. Right-click → **End task**.
3. Wait 15 seconds — the service is set to restart itself.

```powershell
Start-Sleep -Seconds 20
Get-Service AKConnectAgent | Format-List Status
Get-NetAdapter | Where-Object { $_.InterfaceDescription -like "*Wintun*" } | Format-Table Name, Status
.\akconnect-agent.exe status
.\collect.ps1 -Stage 07-after-hard-kill -PeerIP 10.x.x.x
```

**Expect:** the service restarted, exactly **one** adapter exists, and the
tunnel is up.

**The failure I am looking for:** two adapters, or an adapter that exists but
carries no traffic, or the service stuck restarting. Any of those, say so.

---

## Stage 8 — Reboot · 5 min

```powershell
Restart-Computer -Force
```

After it comes back, **log in and wait 60 seconds**, then, in an
Administrator PowerShell:

```powershell
cd C:\akconnect-test
.\akconnect-agent.exe status
.\akconnect-agent.exe selftest
.\collect.ps1 -Stage 08-after-reboot -PeerIP 10.x.x.x
```

**Expect:** connected without re-enrolling, and `stored identity unseals`
showing the same public key as Stage 4. **This is the DPAPI-across-reboot
test.** If it says the key cannot be unsealed, that is a major finding.

---

## Stage 9 — Windows password change · 10 min

The key is sealed to the machine, not to your account, so changing your
password must not break it.

1. `Ctrl-Alt-Del` → **Change a password** → change it. (Or Settings →
   Accounts → Sign-in options → Password.)
2. Reboot: `Restart-Computer -Force`
3. Log in with the **new** password, wait 60 seconds.

```powershell
cd C:\akconnect-test
.\akconnect-agent.exe selftest
.\akconnect-agent.exe status
.\collect.ps1 -Stage 09-after-password-change -PeerIP 10.x.x.x
```

**Expect:** same public key, still connected. If the key no longer unseals,
the DPAPI scope is wrong and I need to know immediately.

---

## Stage 10 — Key custody · 5 min

```powershell
icacls C:\ProgramData\AKConnect
icacls C:\ProgramData\AKConnect\device.key
```

**Expect:** only `NT AUTHORITY\SYSTEM` and `BUILTIN\Administrators`, both
`(F)`. **No** `Users`, `Authenticated Users` or `Everyone`, and no `(I)`
inherited entries.

Now prove a normal user cannot read it. If you do not have a standard account,
make one:

```powershell
net user testlimited "Test-Passw0rd!" /add
```

Then, **as that user** (Start → your avatar → switch user, or
`runas /user:testlimited powershell`):

```powershell
Get-Content C:\ProgramData\AKConnect\device.key
```

**Expect: Access is denied.**

**If a normal user can read that file, stop and tell me.** That file is the
device's identity.

Back in the Administrator window:

```powershell
.\collect.ps1 -Stage 10-key-custody
net user testlimited /delete
```

---

## Stage 11 — Routing · 5 min

```powershell
route print -4
Get-NetRoute -DestinationPrefix "0.0.0.0/0" | Format-Table -AutoSize InterfaceAlias, NextHop, RouteMetric
Find-NetRoute -RemoteIPAddress 1.1.1.1 | Format-Table -AutoSize InterfaceAlias, NextHop
tracert -d -h 6 1.1.1.1
```

**Expect:** the default route is on your Wi-Fi or Ethernet adapter, **never**
on AKConnect. `Find-NetRoute` for `1.1.1.1` picks the physical adapter. The
traceroute's first hop is your own router.

If a peer is up:

```powershell
ping -n 10 10.x.x.x
Find-NetRoute -RemoteIPAddress 10.x.x.x | Format-Table -AutoSize InterfaceAlias, NextHop
.\collect.ps1 -Stage 11-routing -PeerIP 10.x.x.x
```

**Expect:** the peer is reachable and its route is on the AKConnect adapter.

---

## Stage 12 — Uninstall · 5 min

```powershell
.\akconnect-agent.exe service uninstall
Get-Service AKConnectAgent -ErrorAction SilentlyContinue
Get-NetAdapter | Where-Object { $_.InterfaceDescription -like "*Wintun*" }
netsh advfirewall firewall show rule name="AKConnect Agent (WireGuard UDP)"
.\collect.ps1 -Stage 12-after-uninstall
```

**Expect:** service gone, no adapter left, firewall rule gone ("No rules match
the specified criteria").

**The failure I am looking for:** a leftover adapter or a leftover firewall
rule. Both are findings.

---

## Finish — send the results · 2 min

```powershell
.\package-results.ps1
```

This zips every `evidence-*.txt` and every `stage*.txt` you wrote, and prints
the path. Send me that one zip.

**It contains no private key and no device token** — the collector is built not
to include them, and I check for that.

---

## Quick reference

| Stage | What it proves | Time |
|---|---|---|
| 0 | Files are what I built | 5 |
| 1 | Refuses politely without admin | 3 |
| 2 | What SmartScreen and Defender say | 5 |
| 3 | DPAPI and Wintun work at all | 5 |
| 4 | Enrolment, and R4 (no access before approval) | 5 |
| 5 | A real tunnel, and the firewall prompt | 5 |
| 6 | Service lifecycle | 10 |
| 7 | Survives a hard kill without leaking an adapter | 5 |
| 8 | DPAPI survives a reboot | 5 |
| 9 | DPAPI survives a password change | 10 |
| 10 | A normal user cannot steal the identity | 5 |
| 11 | Split tunnel holds on Windows | 5 |
| 12 | Uninstall leaves nothing behind | 5 |
