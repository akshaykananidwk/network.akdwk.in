<#
  AKConnect evidence collector.

  Run it at each stage of the runbook. It writes one file per stage and never
  stops early: if the service will not start, if Wintun will not load, if DPAPI
  fails, every remaining check still runs and the failure is recorded in place.
  A collector that only works when everything works would be useless.

  It reads. The only thing it writes is its own report.

  .\collect.ps1 -Stage 01-before-install
  .\collect.ps1 -Stage 07-connected -PeerIP 10.99.0.3
  .\collect.ps1 -Stage 13-gateway -LanIP 192.168.1.50
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$Stage,
    [string]$PeerIP = "",
    [string]$LanIP  = "",
    [string]$Agent  = ""
)

# Never stop on error: a failing check is data, not a reason to abandon the run.
$ErrorActionPreference = "Continue"
$ProgressPreference    = "SilentlyContinue"

if (-not $Agent) {
    $candidate = Join-Path $PSScriptRoot "akconnect-agent.exe"
    $Agent = if (Test-Path $candidate) { $candidate } else { "akconnect-agent" }
}

$stamp   = Get-Date -Format "yyyyMMdd-HHmmss"
$OutFile = Join-Path $PSScriptRoot "evidence-$Stage-$env:COMPUTERNAME-$stamp.txt"
# Guarded because these environment variables are always set on Windows and
# never set anywhere else, and a null here would throw at script scope, before
# a single check had run.
$ProgramData = if ($env:ProgramData) { $env:ProgramData } else { "C:\ProgramData" }
$SystemRoot  = if ($env:SystemRoot)  { $env:SystemRoot }  else { "C:\Windows" }
$DataDir     = Join-Path $ProgramData "AKConnect"

# Written incrementally, so even a hard crash leaves everything collected so far.
function Emit($text) { $text | Out-File -FilePath $OutFile -Append -Encoding utf8 }

function Section($title) {
    Emit ""
    Emit "=============================================================="
    Emit "  $title"
    Emit "=============================================================="
}

# Check runs a block, records its output, and records a failure as a failure
# rather than letting it end the script.
function Check($title, $block) {
    Section $title
    try {
        $out = & $block 2>&1 | Out-String
        if ([string]::IsNullOrWhiteSpace($out)) { Emit "(no output)" } else { Emit $out.TrimEnd() }
    } catch {
        Emit "CHECK FAILED: $($_.Exception.Message)"
        Emit ($_.ScriptStackTrace | Out-String)
    }
}

Emit "AKConnect evidence"
Emit "stage      : $Stage"
Emit "collected  : $(Get-Date -Format o)"
Emit "machine    : $env:COMPUTERNAME"
Emit "user       : $env:USERDOMAIN\$env:USERNAME"
try {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    $elevated = ([Security.Principal.WindowsPrincipal]$id).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
} catch { $elevated = "unknown" }
Emit "elevated   : $elevated"
Emit "agent      : $Agent"
Emit "peer       : $(if ($PeerIP) { $PeerIP } else { '(none)' })"

Check "Windows version" {
    Get-CimInstance Win32_OperatingSystem |
        Select-Object Caption, Version, BuildNumber, OSArchitecture | Format-List
}

# ---- the agent's own verdict ------------------------------------------------
Check "agent version" { & $Agent version }
Check "agent selftest (the important one)" { & $Agent selftest }
Check "agent status" { & $Agent status }
Check "service state" { & $Agent service status }

# Every block in this script goes through Check, without exception. This one
# was not, and it was the only place an error could escape to the console.
Check "runtime status file" {
    $runtime = Join-Path $DataDir "runtime.json"
    if (Test-Path $runtime) { Get-Content $runtime -Raw }
    else { "not present - the agent is not running, or has never run" }
}

# ---- key custody -------------------------------------------------------------
Check "icacls on the data directory" { icacls $DataDir }
Check "icacls on the key file" { icacls (Join-Path $DataDir "device.key") }
Check "key file attributes" {
    $k = Join-Path $DataDir "device.key"
    if (Test-Path $k) { Get-Item $k | Format-List Name, Length, CreationTime, LastWriteTime }
    else { "device.key not present (not enrolled yet)" }
}

# ---- driver and adapter -------------------------------------------------------
Check "wintun.dll" {
    # Split-Path returns null when the agent was found on PATH rather than by
    # a full path, which would otherwise throw here and lose the check.
    $agentDir = try { Split-Path $Agent -Parent -ErrorAction Stop } catch { $null }
    if (-not $agentDir) { $agentDir = $PSScriptRoot }

    foreach ($c in @((Join-Path $agentDir "wintun.dll"), (Join-Path $SystemRoot "System32\wintun.dll"))) {
        if (Test-Path $c) {
            $h = (Get-FileHash $c -Algorithm SHA256).Hash
            "FOUND  $c"
            "  sha256 $h"
            "  version $((Get-Item $c).VersionInfo.FileVersion)"
        } else { "absent $c" }
    }
}
Check "network adapters" { Get-NetAdapter | Format-Table -AutoSize Name, InterfaceDescription, Status, ifIndex }
Check "AKConnect adapter detail" {
    Get-NetAdapter | Where-Object { $_.InterfaceDescription -like "*Wintun*" -or $_.Name -like "*AKConnect*" -or $_.Name -like "akc*" } |
        Format-List Name, InterfaceDescription, Status, MacAddress, ifIndex
}
Check "IPv4 addresses" {
    Get-NetIPAddress -AddressFamily IPv4 | Format-Table -AutoSize InterfaceAlias, IPAddress, PrefixLength
}

# ---- routing: the split-tunnel guarantee ---------------------------------------
Check "route print -4" { route print -4 }
Check "default routes only" {
    Get-NetRoute -DestinationPrefix "0.0.0.0/0" | Format-Table -AutoSize InterfaceAlias, NextHop, RouteMetric, InterfaceMetric
}
Check "interface chosen for a public address" {
    Find-NetRoute -RemoteIPAddress 1.1.1.1 | Format-Table -AutoSize InterfaceAlias, IPAddress, NextHop
}
if ($PeerIP) {
    Check "interface chosen for the peer" {
        Find-NetRoute -RemoteIPAddress $PeerIP | Format-Table -AutoSize InterfaceAlias, IPAddress, NextHop
    }
}

# ---- reachability ---------------------------------------------------------------
if ($PeerIP) {
    Check "ping the peer (10 packets)" { ping -n 10 $PeerIP }
    Check "traceroute to the peer" { tracert -d -h 8 -w 2000 $PeerIP }
}
Check "traceroute to a public address (must not enter the overlay)" { tracert -d -h 8 -w 2000 1.1.1.1 }

# ---- gateway / subnet-router mode ---------------------------------------------------
#
# Only meaningful on the PC that acts as the site's gateway, but every check is
# safe to run anywhere: on a machine that is not a gateway they simply report
# nothing, and "nothing" is itself the answer to "did the agent turn this
# machine into a router behind my back?".
Check "IP forwarding on each interface" {
    Get-NetIPInterface -AddressFamily IPv4 |
        Format-Table -AutoSize InterfaceAlias, Forwarding, ConnectionState, InterfaceMetric
}
Check "NAT instances (New-NetNat)" {
    Get-NetNat -ErrorAction SilentlyContinue |
        Format-List Name, InternalIPInterfaceAddressPrefix, ExternalIPInterfaceAddressPrefix, Active
}
Check "NAT session mappings" {
    Get-NetNatSession -ErrorAction SilentlyContinue | Select-Object -First 20 |
        Format-Table -AutoSize NatName, Protocol, InternalSourceAddress, InternalSourcePort, ExternalSourceAddress
}
Check "routes that are not the overlay and not the default route" {
    Get-NetRoute -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object { $_.DestinationPrefix -ne "0.0.0.0/0" -and $_.DestinationPrefix -notlike "127.*" } |
        Sort-Object InterfaceAlias |
        Format-Table -AutoSize DestinationPrefix, InterfaceAlias, NextHop, RouteMetric
}
Check "RRAS, in case something else on this machine already routes" {
    Get-Service RemoteAccess -ErrorAction SilentlyContinue | Format-List Name, Status, StartType
}
Check "Internet Connection Sharing, which conflicts with New-NetNat" {
    Get-Service SharedAccess -ErrorAction SilentlyContinue | Format-List Name, Status, StartType
}
if ($LanIP) {
    # The point of the whole feature: a machine with no agent on it, reached
    # across the tunnel. Run this one from the SUPPORT laptop, not the gateway.
    Check "interface chosen for the LAN device behind the gateway" {
        Find-NetRoute -RemoteIPAddress $LanIP -ErrorAction SilentlyContinue |
            Format-Table -AutoSize InterfaceAlias, IPAddress, NextHop
    }
    Check "ping the LAN device behind the gateway" { ping -n 6 $LanIP }
    Check "traceroute to the LAN device (should be gateway then device)" {
        tracert -d -h 6 -w 2000 $LanIP
    }
    Check "TCP 554 to the LAN device — the port the rule allows" {
        Test-NetConnection -ComputerName $LanIP -Port 554 -WarningAction SilentlyContinue |
            Format-List ComputerName, RemotePort, TcpTestSucceeded, PingSucceeded
    }
    Check "TCP 80 to the LAN device — the port the rule does not allow" {
        Test-NetConnection -ComputerName $LanIP -Port 80 -WarningAction SilentlyContinue |
            Format-List ComputerName, RemotePort, TcpTestSucceeded, PingSucceeded
    }
}

# ---- firewall and sockets ---------------------------------------------------------
Check "firewall rule" { netsh advfirewall firewall show rule name="AKConnect Agent (WireGuard UDP)" }
Check "firewall profiles" { netsh advfirewall show allprofiles state }
Check "UDP 51820 listener" {
    Get-NetUDPEndpoint -LocalPort 51820 -ErrorAction SilentlyContinue | Format-Table -AutoSize LocalAddress, LocalPort, OwningProcess
}

# ---- what Windows thought of our unsigned binary -------------------------------------
Check "Authenticode signature on the agent" { Get-AuthenticodeSignature $Agent | Format-List Status, StatusMessage, SignerCertificate }
Check "Defender threat history" {
    Get-MpThreatDetection -ErrorAction SilentlyContinue | Select-Object -First 10 |
        Format-List InitialDetectionTime, ThreatID, Resources, ActionSuccess
}
Check "Defender exclusions in force" { (Get-MpPreference).ExclusionPath }
Check "SmartScreen policy" {
    Get-ItemProperty "HKLM:\SOFTWARE\Policies\Microsoft\Windows\System" -ErrorAction SilentlyContinue |
        Select-Object EnableSmartScreen | Format-List
}

# ---- logs ---------------------------------------------------------------------------
Check "agent event log (last 40)" {
    Get-WinEvent -FilterHashtable @{ LogName = 'Application'; ProviderName = 'AKConnectAgent' } -MaxEvents 40 -ErrorAction SilentlyContinue |
        Format-Table -AutoSize TimeCreated, LevelDisplayName, Message
}
Check "service control manager events for our service" {
    Get-WinEvent -FilterHashtable @{ LogName = 'System'; ProviderName = 'Service Control Manager' } -MaxEvents 60 -ErrorAction SilentlyContinue |
        Where-Object { $_.Message -like "*AKConnect*" } | Format-Table -AutoSize TimeCreated, LevelDisplayName, Message
}

Section "end of report"

Write-Host ""
Write-Host "  Written: $OutFile"
Write-Host "  It contains no private key and no device token."
Write-Host ""
