<#
.SYNOPSIS
  Collects everything needed to judge whether this device is really connected,
  and how, into one file to send back.

.DESCRIPTION
  Run this on a Windows machine that has the agent installed and running.
  It gathers the agent's own view, the operating system's view, and enough
  network context to tell a direct path from a relayed one.

  It reads; it does not change anything.

.EXAMPLE
  .\collect-evidence.ps1 -PeerIP 10.99.0.3
#>
[CmdletBinding()]
param(
    # A peer's overlay address to ping and trace. Ask for it from the panel.
    [string]$PeerIP = "",
    # Where to write the report.
    [string]$OutFile = "akconnect-evidence-$env:COMPUTERNAME-$(Get-Date -Format yyyyMMdd-HHmmss).txt",
    # The agent executable, if it is not beside this script.
    [string]$Agent = ""
)

$ErrorActionPreference = "Continue"

function Section($title) {
    "`n=============================================================="
    "  $title"
    "=============================================================="
}

function Try-Run($label, $block) {
    Section $label
    try { & $block 2>&1 | Out-String } catch { "FAILED: $_" }
}

if (-not $Agent) {
    $local = Join-Path $PSScriptRoot "akconnect-agent-windows-amd64.exe"
    if (Test-Path $local) { $Agent = $local } else { $Agent = "akconnect-agent" }
}

$report = & {
    Section "AKConnect field evidence"
    "collected  : $(Get-Date -Format o)"
    "machine    : $env:COMPUTERNAME"
    "user       : $env:USERNAME"
    "elevated   : $(([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator))"
    "agent path : $Agent"
    "peer under test: $(if ($PeerIP) { $PeerIP } else { '(none given)' })"

    Try-Run "Windows version" { Get-ComputerInfo -Property OsName,OsVersion,OsBuildNumber,CsSystemType }

    # ---- what the agent itself believes -------------------------------------
    Try-Run "agent version" { & $Agent version }
    Try-Run "agent status" { & $Agent status }
    Try-Run "agent service state" { & $Agent service status }

    Section "agent runtime status file (machine readable)"
    $runtime = Join-Path $env:ProgramData "AKConnect\runtime.json"
    if (Test-Path $runtime) { Get-Content $runtime -Raw } else { "not present at $runtime" }

    # ---- what Windows believes ----------------------------------------------
    Try-Run "network adapters" { Get-NetAdapter | Format-Table -AutoSize Name,InterfaceDescription,Status,MacAddress,LinkSpeed }
    Try-Run "IP addresses" { Get-NetIPAddress | Where-Object { $_.AddressFamily -eq 'IPv4' } | Format-Table -AutoSize InterfaceAlias,IPAddress,PrefixLength,SkipAsSource }

    # The criterion: no default route through the tunnel adapter.
    Try-Run "route print (IPv4)" { route print -4 }
    Try-Run "default routes only" { Get-NetRoute -DestinationPrefix "0.0.0.0/0" | Format-Table -AutoSize InterfaceAlias,NextHop,RouteMetric,InterfaceMetric }

    Try-Run "which interface serves a public address" { Find-NetRoute -RemoteIPAddress 1.1.1.1 | Format-Table -AutoSize InterfaceAlias,IPAddress,NextHop }
    if ($PeerIP) {
        Try-Run "which interface serves the peer" { Find-NetRoute -RemoteIPAddress $PeerIP | Format-Table -AutoSize InterfaceAlias,IPAddress,NextHop }
    }

    # ---- reachability --------------------------------------------------------
    if ($PeerIP) {
        Try-Run "ping the peer over the overlay" { ping -n 10 $PeerIP }
        Try-Run "traceroute to the peer" { tracert -d -h 8 -w 2000 $PeerIP }
    }
    Try-Run "traceroute to a public address (must not enter the overlay)" { tracert -d -h 8 -w 2000 1.1.1.1 }

    # ---- environment ---------------------------------------------------------
    Try-Run "wintun.dll present" {
        $candidates = @((Join-Path (Split-Path $Agent) "wintun.dll"), (Join-Path $env:SystemRoot "System32\wintun.dll"))
        foreach ($c in $candidates) {
            if (Test-Path $c) { "FOUND $c  ($((Get-Item $c).VersionInfo.FileVersion))" } else { "missing $c" }
        }
    }
    Try-Run "key file ACL (icacls)" { icacls (Join-Path $env:ProgramData "AKConnect\device.key") }
    Try-Run "state directory ACL (icacls)" { icacls (Join-Path $env:ProgramData "AKConnect") }
    Try-Run "firewall rule" { netsh advfirewall firewall show rule name="AKConnect Agent (WireGuard UDP)" }
    Try-Run "listening UDP sockets" { Get-NetUDPEndpoint | Where-Object { $_.LocalPort -eq 51820 } | Format-Table -AutoSize LocalAddress,LocalPort,OwningProcess }
    Try-Run "recent agent events" { Get-EventLog -LogName Application -Source AKConnectAgent -Newest 25 -ErrorAction SilentlyContinue | Format-Table -AutoSize TimeGenerated,EntryType,Message }
    Try-Run "Defender detection history" { Get-MpThreatDetection -ErrorAction SilentlyContinue | Select-Object -First 10 | Format-Table -AutoSize InitialDetectionTime,ThreatID,Resources }

    Section "end of report"
}

$report | Out-File -FilePath $OutFile -Encoding utf8
Write-Host ""
Write-Host "  Report written to $OutFile"
Write-Host "  Send that file back. It contains no private keys and no device token."
Write-Host ""
