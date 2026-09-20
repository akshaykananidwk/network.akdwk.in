#!/usr/bin/env bash
# Assemble the Windows test pack: one zip a tester downloads and runs.
#
# wintun.dll is bundled deliberately. Telling a tester to fetch a driver from
# a third-party site themselves is how you get the wrong architecture, an
# unverified file, or a person who gives up. Clause 3(d) of Wintun's prebuilt
# licence permits redistribution alongside software that uses only its
# documented API, which is what we do; see docs/WINTUN-LICENSING.md.
set -euo pipefail

VERSION="${1:-dev}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WINTUN_ZIP="${WINTUN_ZIP:-/tmp/wintun-check/wintun.zip}"
STAGE="$ROOT/services/kit/pack"
OUT="$ROOT/services/kit/akconnect-windows-test-pack.zip"

# The exact artefacts this pack was verified against. A mismatch means the
# upstream file changed and its licence needs re-reading before we ship it.
WINTUN_ZIP_SHA="07c256185d6ee3652e09fa55c0b673e2624b565e02c4b9091c79ca7d2f24ef51"
WINTUN_AMD64_SHA="e5da8447dc2c320edc0fc52fa01885c103de8c118481f683643cacc3220dafce"
WINTUN_ARM64_SHA="f7ba89005544be9d85231a9e0d5f23b2d15b3311667e2dad0debd344918a3f80"

die() { echo "  ✗ $*" >&2; exit 1; }

[ -f "$WINTUN_ZIP" ] || die "Wintun archive not found at $WINTUN_ZIP.
    Download it:  curl -sSLo $WINTUN_ZIP https://www.wintun.net/builds/wintun-0.14.1.zip"

echo "$WINTUN_ZIP_SHA  $WINTUN_ZIP" | sha256sum -c - >/dev/null \
    || die "Wintun archive checksum does not match the version this pack was verified against.
    Re-read docs/WINTUN-LICENSING.md before shipping a different build."

rm -rf "$STAGE" "$OUT"
mkdir -p "$STAGE/arm64"

echo "  building the agent"
(
    cd "$ROOT/services/agent"
    GOTOOLCHAIN=local GOOS=windows GOARCH=amd64 CGO_ENABLED=0 \
        go build -trimpath -ldflags "-s -w -X main.version=$VERSION" \
        -o "$STAGE/akconnect-agent.exe" ./cmd/akconnect-agent
    GOTOOLCHAIN=local GOOS=windows GOARCH=arm64 CGO_ENABLED=0 \
        go build -trimpath -ldflags "-s -w -X main.version=$VERSION" \
        -o "$STAGE/arm64/akconnect-agent.exe" ./cmd/akconnect-agent
)

echo "  unpacking Wintun"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
unzip -q -o "$WINTUN_ZIP" -d "$TMP"

cp "$TMP/wintun/bin/amd64/wintun.dll" "$STAGE/wintun.dll"
cp "$TMP/wintun/bin/arm64/wintun.dll" "$STAGE/arm64/wintun.dll"
cp "$TMP/wintun/LICENSE.txt"          "$STAGE/LICENSE-wintun.txt"

echo "$WINTUN_AMD64_SHA  $STAGE/wintun.dll"       | sha256sum -c - >/dev/null || die "amd64 wintun.dll checksum mismatch"
echo "$WINTUN_ARM64_SHA  $STAGE/arm64/wintun.dll" | sha256sum -c - >/dev/null || die "arm64 wintun.dll checksum mismatch"

echo "  adding the runbook and scripts"
cp "$ROOT/services/kit/WINDOWS-RUNBOOK.md"  "$STAGE/RUNBOOK.md"
cp "$ROOT/services/kit/collect.ps1"         "$STAGE/"
cp "$ROOT/services/kit/package-results.ps1" "$STAGE/"

(cd "$STAGE" && find . -type f ! -name SHA256SUMS -printf '%P\n' | sort | xargs sha256sum > SHA256SUMS)

(cd "$STAGE" && zip -q -r "$OUT" .)

echo
echo "  pack: $OUT"
echo "  size: $(du -h "$OUT" | cut -f1)"
echo "  sha256: $(sha256sum "$OUT" | cut -d' ' -f1)"
echo
echo "  Contains an unsigned agent and WireGuard LLC's signed wintun.dll,"
echo "  redistributed under clause 3(d) of its prebuilt binaries licence."
