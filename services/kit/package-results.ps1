<#
  Collects every evidence file and every note into one zip to send back.
#>
[CmdletBinding()]
param([string]$OutFile = "")

$ErrorActionPreference = "Continue"

if (-not $OutFile) {
    $OutFile = Join-Path $PSScriptRoot "akconnect-results-$env:COMPUTERNAME-$(Get-Date -Format yyyyMMdd-HHmmss).zip"
}

$files = @()
$files += Get-ChildItem -Path $PSScriptRoot -Filter "evidence-*.txt" -ErrorAction SilentlyContinue
$files += Get-ChildItem -Path $PSScriptRoot -Filter "stage*.txt" -ErrorAction SilentlyContinue

if ($files.Count -eq 0) {
    Write-Host ""
    Write-Host "  No evidence files found in $PSScriptRoot."
    Write-Host "  Run collect.ps1 at least once first."
    Write-Host ""
    exit 1
}

# Last-chance check that nothing secret is being sent. The collector is built
# not to include these, but a note written by hand might.
$suspicious = @()
foreach ($f in $files) {
    $content = Get-Content $f.FullName -Raw -ErrorAction SilentlyContinue
    if ($content -match 'device_token"\s*:\s*"[A-Za-z0-9+/=]{16,}') { $suspicious += "$($f.Name): looks like a device token" }
    if ($content -match '(?m)^[A-Za-z0-9+/]{42}=$')                 { $suspicious += "$($f.Name): looks like a bare private key" }
}

if ($suspicious.Count -gt 0) {
    Write-Host ""
    Write-Host "  Stopping: something in these files looks like a secret."
    $suspicious | ForEach-Object { Write-Host "    $_" }
    Write-Host "  Remove it and run this again."
    Write-Host ""
    exit 1
}

Compress-Archive -Path $files.FullName -DestinationPath $OutFile -Force

Write-Host ""
Write-Host "  Packaged $($files.Count) file(s):"
Write-Host "    $OutFile"
Write-Host "  Send that zip back."
Write-Host ""
