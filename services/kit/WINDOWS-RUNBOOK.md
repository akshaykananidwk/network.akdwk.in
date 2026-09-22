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
| `akconnect-setup.exe` | **The installer a customer runs.** Double-click, type the join code, done |
| `akconnect-agent.exe` | The agent itself, 64-bit Intel/AMD. Stages 1–14 drive this directly |
| `wintun.dll` | The network driver. **Already here — nothing to download.** |
| `arm64\` | The same two programs for ARM machines (Surface Pro X and similar) |
| `LICENSE-wintun.txt` | Wintun's licence, which must stay with it |
| `collect.ps1` | Collects evidence. Run it when told to |
| `package-results.ps1` | Zips everything up at the end |
| `SHA256SUMS` | Checksums |

If this machine is ARM, copy both files out of `arm64\` over the ones in the
main folder before you start.

**Stages 1–14 use `akconnect-agent.exe` from a command prompt**, because that
is how you test the pieces. **Stage 15 uses `akconnect-setup.exe`**, because
that is what a customer will actually do, and it is the stage that decides
whether this can be sold. If you are short of time, do Stage 15 first.

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

## Stage 13 — Gateway mode · 20 min

**This stage needs two machines and one more device.** The gateway PC is a
Windows machine on the customer's LAN. The support laptop is the other end of
the tunnel. The third device is anything with an IP that cannot run our agent —
an NVR, a printer, a DVR, or just a second PC with the agent stopped.

Write down the LAN address of that third device before you start. Everywhere
below I write `192.168.1.50`, use that address instead.

### 13a — On the panel, before touching either machine

1. Open the gateway PC's device page.
2. **Advertise route** → enter the LAN range the PC is on, e.g. `192.168.1.0/24`.
3. Leave it **unapproved** for now.

### 13b — On the support laptop, with the route still unapproved

```powershell
Find-NetRoute -RemoteIPAddress 192.168.1.50 | Format-Table -AutoSize InterfaceAlias, NextHop
Test-NetConnection -ComputerName 192.168.1.50 -Port 554
.\collect.ps1 -Stage 13a-before-approval -LanIP 192.168.1.50
```

**Expect:** `TcpTestSucceeded : False`, and `Find-NetRoute` picks your physical
adapter, not AKConnect. An unapproved route must carry nothing (R4).

**The failure I am looking for:** it works before anyone approved it. That is a
finding and I want to know immediately.

### 13c — Approve it on the panel

Device page → the advertised route → **Approve**. Wait 30 seconds.

### 13d — On the gateway PC

```powershell
Get-NetIPInterface -AddressFamily IPv4 | Format-Table -AutoSize InterfaceAlias, Forwarding
Get-NetNat | Format-List Name, InternalIPInterfaceAddressPrefix, Active
.\collect.ps1 -Stage 13d-gateway-pc
```

**Expect:** `Forwarding` is `Enabled` on the AKConnect adapter, and one
`Get-NetNat` entry whose name starts with `AKConnect`.

**The failure I am looking for:** `New-NetNat` refusing because Internet
Connection Sharing or RRAS already owns NAT on this machine. Windows reports
this as a plain "The parameter is incorrect", which tells you nothing, so the
collector records the state of both services next to it. If you see it, tell
me — a hotel PC with ICS already on is a case I have to handle in code, not in
the runbook.

### 13e — On the support laptop, after approval

```powershell
.\akconnect-agent.exe status
Find-NetRoute -RemoteIPAddress 192.168.1.50 | Format-Table -AutoSize InterfaceAlias, NextHop
tracert -d -h 6 192.168.1.50
Test-NetConnection -ComputerName 192.168.1.50 -Port 554
Test-NetConnection -ComputerName 192.168.1.50 -Port 80
.\collect.ps1 -Stage 13e-through-gateway -LanIP 192.168.1.50
```

**Expect:** `Find-NetRoute` now picks the AKConnect adapter for
`192.168.1.50`. The traceroute shows the gateway PC's overlay address and then
the device. Port 554 connects. Port 80 does **not**, if the only ACL rule you
wrote names 554.

If you have written no ACL rule yet, both ports will connect and that is
correct — write the rule on the panel (`allow`, destination `192.168.1.50/32`,
`tcp`, port `554`), wait 30 seconds, and run the two `Test-NetConnection` lines
again.

**The failure I am looking for:** port 80 still connects after the rule is in
place. That means the ACL is not being applied to traffic going *through* the
gateway, only to the gateway itself, and it is a P1.

### 13f — The split tunnel must still hold

```powershell
Get-NetRoute -DestinationPrefix "0.0.0.0/0" | Format-Table -AutoSize InterfaceAlias, NextHop
tracert -d -h 6 1.1.1.1
.\collect.ps1 -Stage 13f-split-tunnel-after-gateway
```

**Expect:** unchanged from Stage 11. Gateway mode adds one LAN range, not the
internet (R1).

### 13g — Overlapping LAN ranges

**This is the stage I care about most, and the one most likely to be the real
test for you** — if your support laptop is on `192.168.1.0/24` and so is the
customer, which is the normal case here, this is what decides whether gateway
mode is usable at all.

The overlay does not carry the customer's real range. On the panel's route
list you will see two addresses side by side: the customer's real range, and
the range the overlay uses for it. The NVR at `192.168.1.50` on the customer's
LAN is reached at the matching address in that second range — same last number.
Write it down; it is `$MappedIP` below.

```powershell
Get-NetRoute -DestinationPrefix "192.168.1.0/24" | Format-Table -AutoSize InterfaceAlias, NextHop
Find-NetRoute -RemoteIPAddress 192.168.1.50 | Format-Table -AutoSize InterfaceAlias, NextHop
Test-NetConnection -ComputerName 10.128.0.50 -Port 554
.\collect.ps1 -Stage 13g-overlap -LanIP 192.168.1.50 -MappedIP 10.128.0.50
```

**Expect:** your own `192.168.1.0/24` still points at your physical adapter and
still reaches your own printer. The customer's NVR answers at the mapped
address on tcp/554. Neither has taken the other over.

**The failure I am looking for, and it looks exactly like success:** you connect
to `192.168.1.50` and something answers. That is almost certainly your *own*
machine at that address, not the customer's. Check what answered, not that
something did — open it in a browser, or look at the model name. If your own
device and the customer's are reachable at the same address, tell me
immediately; that is a P1 and it means the mapping is not being applied.

The agent does this rewriting itself rather than asking Windows to. Windows has
no one-to-one prefix NAT — `New-NetNat` masquerades many-to-one and
`Add-NetNatStaticMapping` forwards a single port — so there is nothing in
`netsh` or `Get-NetNat` that shows the mapping. `.\akconnect-agent.exe status`
lists it instead, and the collector captures that.

### 13h — Stop the agent on the gateway PC

```powershell
Stop-Service AKConnectAgent
Start-Sleep -Seconds 5
Get-NetNat | Format-List Name, Active
Get-NetIPInterface -AddressFamily IPv4 | Format-Table -AutoSize InterfaceAlias, Forwarding
.\collect.ps1 -Stage 13h-after-stop
```

**Expect:** the `AKConnect` NAT instance is gone. `Forwarding` may still read
`Enabled` — the agent deliberately does not turn it back off, because it may
have been on before we arrived and a NAS or a hypervisor on the same PC could
depend on it. A leftover **NAT instance** is a finding; leftover forwarding is
not.

Start it again before moving on: `Start-Service AKConnectAgent`.

---

## Stage 14 — Names · 15 min

**The claim being tested is narrow, and the narrow part is the point:** names
under your network's own domain resolve through the agent, and *every other
name this machine looks up goes exactly where it went before*. If anything in
this stage suggests otherwise, stop and tell me.

On the panel, the network's routes tab shows the domain — something like
`acme.internal` — and, under each advertised LAN, the name each machine
answers to. Write one down; it is `$Name` below. It will look like
`nvr.hotel-abc.acme.internal`.

### 14a — Before the agent is running

```powershell
Get-DnsClientNrptRule | Format-Table -AutoSize Namespace, NameServers, Comment
Get-DnsClientServerAddress -AddressFamily IPv4 | Format-Table -AutoSize InterfaceAlias, ServerAddresses
Get-Content "$env:SystemRoot\System32\drivers\etc\hosts"
.\collect.ps1 -Stage 14a-before-names
```

**Expect:** whatever this machine already had. Keep this file — the comparison
at 14d is against it.

### 14b — With the agent running

```powershell
.\akconnect-agent.exe status
Resolve-DnsName -Name nvr.hotel-abc.acme.internal
ping -n 4 nvr.hotel-abc.acme.internal
.\collect.ps1 -Stage 14b-names -Name nvr.hotel-abc.acme.internal
```

**Expect:** the name resolves to an address in the `10.x` range — the overlay
address, not the `192.168.x` one printed on the machine itself. The ping goes
through the tunnel.

`status` will say which mechanism is carrying it:

- **NRPT** — Windows' own split-DNS mechanism, and the one I expect on a normal
  Windows 10 or 11 machine.
- **the hosts file** — the fallback, used when NRPT is unavailable or locked
  down by policy. Both are correct; I want to know which one you got.

**The failure I am looking for:** the name resolving to `192.168.1.50`. That is
the address on the customer's own LAN, and on your machine it is either
nothing or *your* device at that address. Tell me immediately.

### 14c — The part that matters more

```powershell
Resolve-DnsName -Name www.microsoft.com
nslookup www.google.com
Get-DnsClientServerAddress -AddressFamily IPv4 | Format-Table -AutoSize InterfaceAlias, ServerAddresses
.\collect.ps1 -Stage 14c-public-dns
```

**Expect:** public names resolve exactly as they did before, and no adapter
lists `127.0.0.x` as its DNS server. The agent's resolver lives on loopback and
only the one domain is routed to it.

**The failure I am looking for:** an adapter whose DNS server has become
`127.0.0.54`, or `nslookup` reporting that server. That would mean the agent
had become the resolver for your whole machine, which it must never be. It is a
P1 and I want to know within the hour.

### 14d — Stop the agent

```powershell
Stop-Service AKConnectAgent
Start-Sleep -Seconds 5
Resolve-DnsName -Name nvr.hotel-abc.acme.internal -ErrorAction SilentlyContinue
Get-DnsClientNrptRule | Format-Table -AutoSize Namespace, NameServers, Comment
Get-Content "$env:SystemRoot\System32\drivers\etc\hosts"
.\collect.ps1 -Stage 14d-after-stop
```

**Expect:** the name no longer resolves, no NRPT rule of ours remains, and the
hosts file is byte-for-byte what 14a captured — no `AKConnect` block, and every
line that was there before still there.

**The failure I am looking for:** a leftover NRPT rule, a leftover hosts block,
or — worst — a hosts file that lost lines it had before. The agent writes only
between its own markers and replaces the file atomically, but this is the check
that proves it on your machine rather than on mine.

Start it again: `Start-Service AKConnectAgent`.

---

## Stage 15 — The installer · 20 min

**This is the stage that decides whether I can sell this.** Everything before it
tests the agent; this tests what a customer actually experiences. If any part
of it needs a command prompt, it has failed.

Use a machine that has never had AKConnect on it, or run Stage 15d first to
clean one.

### 15a — Install, as a customer would

Copy `akconnect-setup.exe` to the desktop. Double-click it. Nothing else.

**Expect, in order:**

1. A UAC prompt — "Do you want to allow this app to make changes?" — showing
   **Publisher: Unknown**. Say yes.
2. A box asking for the join code. Type the one from the panel and press OK.
3. A box saying it is installed and running, with the agent's status in it.

**Time it.** From double-click to the final box should be under a minute.

**The failures I am looking for, in the order they would hurt:**

- **No dialog appears at all.** Worst case: a customer sees the UAC prompt,
  says yes, and nothing happens. Tell me immediately.
- **A black console window flashes up.** It is built not to; if one appears,
  say at which step.
- **SmartScreen blocks it** with "Windows protected your PC". Expected while
  unsigned — click More info → Run anyway, and tell me it happened, because it
  is the single strongest argument for buying a certificate.
- **The box asks for anything except the join code.** It must not — in
  particular not for the panel address, which the installer is stamped with at
  build time. If it does ask, the pack was built without one and I need to
  know, because that is what failed the first time.

```powershell
.\collect.ps1 -Stage 15a-after-install
```

### 15b — Check what it actually did

```powershell
Get-Service AKConnectAgent | Format-List Name, Status, StartType
Get-ChildItem "$env:ProgramFiles\AKConnect"
netsh advfirewall firewall show rule name="AKConnect Agent (WireGuard UDP)"
Get-NetAdapter | Where-Object { $_.InterfaceDescription -like "*Wintun*" }
.\collect.ps1 -Stage 15b-installed
```

### 15c — The service says what it is doing

A service has no console, so until 1.9.1 everything the agent printed went
nowhere and a service that started and stopped did it in silence. Two places
now say why:

```powershell
.\akconnect-agent.exe status
Get-Content "$env:ProgramData\AKConnect\service.log" -Tail 30
Get-EventLog -LogName Application -Source AKConnectAgent -Newest 10 |
    Format-List TimeGenerated, EntryType, Message
```

**Expect:** `status` prints a `Service log:` line; the log itself has the
agent's own output; and the Event Log has at least one `AKConnect agent
started` entry.

**The failure to report:** the service shows as Stopped and *neither* of those
has anything in it. That is the 1.9.0 behaviour and it should not be possible
any more.

### 15d — Waiting for approval does not stop the service

Install on a machine whose device the panel has **not** approved yet, then:

```powershell
Get-Service AKConnectAgent
```

**Expect:** `Running`. It is waiting, not failing. Now approve the device in
the panel and wait up to a minute without touching the machine:

```powershell
.\akconnect-agent.exe status
.\collect.ps1 -Stage 15d-approved-while-waiting
```

**Expect:** the agent has picked the approval up on its own, has an overlay
address, and the adapter is up. **Nobody should have had to restart anything.**
On 1.9.0 the service exited within a second of starting and approving the
device changed nothing.

**Expect:** the service Running and StartType Automatic; `akconnect-agent.exe`,
`wintun.dll` and `uninstall.txt` in the folder and nothing else; one firewall
rule; one adapter.

**The failure I am looking for:** `wintun.dll` missing, or anywhere other than
beside the agent. It is carried inside the installer precisely so a customer
never downloads a driver themselves.

### 15e — Approve it, and check it works

Approve the device on the panel, wait 30 seconds, then:

```powershell
.kconnect-agent.exe status
ping -n 4 <a peer's overlay address>
.\collect.ps1 -Stage 15e-connected
```

**Expect:** a peer, a handshake, and a reply. This is the whole product working
from a customer's point of view: one file, one code, one approval.

### 15f — Uninstall, and prove nothing is left

```powershell
.kconnect-setup.exe -uninstall
```

Double-click works too; the flag is here so you can see it in a transcript.

**Expect:** a UAC prompt, then a box saying it has been removed and listing
what went. Then check, and this is the part that matters:

```powershell
Get-Service AKConnectAgent -ErrorAction SilentlyContinue
Test-Path "$env:ProgramFiles\AKConnect"
Test-Path "$env:ProgramData\AKConnect"
netsh advfirewall firewall show rule name="AKConnect Agent (WireGuard UDP)"
Get-NetAdapter | Where-Object { $_.InterfaceDescription -like "*Wintun*" }
Get-DnsClientNrptRule | Format-Table -AutoSize Namespace, NameServers, Comment
Get-PnpDevice -Class Net | Where-Object { $_.FriendlyName -like "*Wintun*" } | Format-Table -AutoSize FriendlyName, Status
.\collect.ps1 -Stage 15f-after-uninstall
```

**Expect every one of these to come back empty**: no service, neither folder,
"No rules match the specified criteria", no adapter, no NRPT rule, no device.

**The failures I am looking for.** Any leftover is a finding, and these three
are the ones that get noticed months later by somebody else's IT person:

- a **Wintun adapter** still in Network Connections;
- an **NRPT rule** still in the DNS policy table;
- the **ProgramData folder**, which holds this device's private key and its
  token. Leaving a credential on a machine somebody has just removed our
  software from is not acceptable, and I would treat it as a security finding
  rather than a tidiness one.

### 15g — Install again over the top

```powershell
.kconnect-setup.exe
```

**Expect:** it works. A customer who uninstalled and reinstalled, or who ran
the installer twice by accident, must not end up with two services, two
firewall rules or a file it could not replace because it was in use.

```powershell
Get-Service AKConnectAgent | Format-List Name, Status
netsh advfirewall firewall show rule name="AKConnect Agent (WireGuard UDP)" | Select-String "Rule Name"
.\collect.ps1 -Stage 15g-reinstall
```

**Expect:** one service, one rule.

---

## Stage 16 — The one-click test, on two PCs · 10 min

This is the only stage that needs two machines, and it is the one the product
is judged on. Run it on two PCs on **different** internet connections — ideally
one on a phone hotspot, which is carrier-grade NAT and the hard case.

Nothing in this stage uses PowerShell. That is the point of it.

1. On PC A: double-click `akconnect-setup.exe`, type the join code, click OK.
2. On PC B: the same, with the same code.
3. Wait one minute.

**Expect:** both PCs show **Online** in the panel, each with an overlay address
beginning `10.`.

Then, on PC A, in an ordinary Command Prompt — no administrator needed:

```
ping 10.50.0.3
```

substituting PC B's overlay address as the panel shows it, and the same in
reverse from PC B.

**Expect:** replies, both ways. Direct or relayed does not matter here; the
panel's device page says which, and either is a pass.

**If it fails**, this is the one thing worth collecting before anything else:

```powershell
.\collect.ps1 -Stage 16-oneclick
Get-Content "$env:ProgramData\AKConnectgent.log" -Tail 100
```

The log is where the answer is: "no known endpoint for peer" repeated means
the two machines were never introduced to each other, and "relay" appearing
nowhere at all means the relay was never tried.

---

## Stage 17 — Self-update · 10 min

Only once an administrator has published a newer agent from the panel.

```powershell
.kconnect-agent.exe update --check
```

**Expect:** it names the running version, the panel it asked, and either the
version offered or "Nothing newer is offered to this device."

```powershell
.kconnect-agent.exe update
```

**Expect:** each step printed — fetching, the byte count, verifying, then
`Verified : the digest is signed by this panel's controller key`, then
`Installed`. A release that is not signed by the panel's controller key must be
**refused**, and the agent must keep running the old binary.

```powershell
.kconnect-agent.exe version
.\collect.ps1 -Stage 17-selfupdate
```

**Expect:** the new version, and the service still running. This path has never
been run on Windows — if the restart does not happen, the new binary is on disk
and takes over at the next start, which is worth reporting exactly as it
behaves.

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
| 13 | Gateway mode: an agentless NVR reached through a site PC | 20 |
| 13g | Two LANs both on 192.168.1.0/24, both still reachable | (in 13) |
| 14 | Names resolve, and the machine's own DNS is untouched | 15 |
| 15 | **The installer: one file, one code, and a clean uninstall** | 20 |
| 16 | **Two PCs, two ISPs, double-click only — the acceptance test** | 10 |
| 17 | Self-update, and that an unsigned release is refused | 10 |
