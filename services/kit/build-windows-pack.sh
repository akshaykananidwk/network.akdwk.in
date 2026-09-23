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
# The panel this pack belongs to. A join code does not carry it — it is a code
# issued by one panel and means nothing without knowing which — so the
# installer is stamped with it and a customer is asked one question rather than
# two. Passed as the second argument, or taken from the installed panel's own
# configuration.
PANEL_URL="${2:-}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WINTUN_ZIP="${WINTUN_ZIP:-/tmp/wintun-check/wintun.zip}"
# Where the build writes. Outside the source tree when the caller says so, and
# that is what deploy/upgrade-edge.sh does.
#
# It used to write into services/kit unconditionally, and the pack was tracked
# in git — so a successful edge upgrade left its own checkout dirty, and the
# NEXT run refused with "has local changes". An operator could run this exactly
# once per clone, and the tool that maintains the edge broke the checkout it
# maintains.
BUILD_ROOT="${AKCONNECT_BUILD_DIR:-$ROOT/services/kit}"
mkdir -p "$BUILD_ROOT"

STAGE="$BUILD_ROOT/pack"
OUT="$BUILD_ROOT/akconnect-windows-test-pack.zip"

# The exact artefacts this pack was verified against. A mismatch means the
# upstream file changed and its licence needs re-reading before we ship it.
WINTUN_ZIP_SHA="07c256185d6ee3652e09fa55c0b673e2624b565e02c4b9091c79ca7d2f24ef51"
WINTUN_AMD64_SHA="e5da8447dc2c320edc0fc52fa01885c103de8c118481f683643cacc3220dafce"
WINTUN_ARM64_SHA="f7ba89005544be9d85231a9e0d5f23b2d15b3311667e2dad0debd344918a3f80"

die() { echo "  ✗ $*" >&2; exit 1; }

if [ -z "$PANEL_URL" ] && [ -f "$ROOT/config/config.php" ]; then
    PANEL_URL="$(php -r '
        $c = require $argv[1];
        echo rtrim((string) ($c["app"]["url"] ?? ""), "/");
    ' "$ROOT/config/config.php" 2>/dev/null)"
fi

case "$PANEL_URL" in
    https://*) ;;
    "") die "no panel URL. Pass it: build-windows-pack.sh $VERSION https://network.akdwk.in
    Without it the installer cannot know which panel a join code belongs to, and
    a customer would be asked for the address as well as the code." ;;
    *) die "the panel URL must be https, not \"$PANEL_URL\".
    The agent will not send a device token over plain HTTP." ;;
esac

[ -f "$WINTUN_ZIP" ] || die "Wintun archive not found at $WINTUN_ZIP.
    Download it:  curl -sSLo $WINTUN_ZIP https://www.wintun.net/builds/wintun-0.14.1.zip"

echo "$WINTUN_ZIP_SHA  $WINTUN_ZIP" | sha256sum -c - >/dev/null \
    || die "Wintun archive checksum does not match the version this pack was verified against.
    Re-read docs/WINTUN-LICENSING.md before shipping a different build."

rm -rf "$STAGE" "$OUT"
mkdir -p "$STAGE/arm64"

# The brand, in one place. Everything that shows a name to a customer — Apps &
# features, the Start menu, the tray, the dialogs — is stamped from here rather
# than spelling it out in a dozen Go files.
BRAND_NAME="${BRAND_NAME:-AK Connect}"
ORG_NAME="${ORG_NAME:-AK Computer}"
BRAND_FLAGS="-X 'main.displayName=$BRAND_NAME' -X 'main.publisher=$ORG_NAME'"

echo "  building the agent"
(
    cd "$ROOT/services/agent"
    GOTOOLCHAIN=local GOOS=windows GOARCH=amd64 CGO_ENABLED=0 \
        go build -trimpath -buildvcs=false -ldflags "-s -w -X main.version=$VERSION" \
        -o "$STAGE/akconnect-agent.exe" ./cmd/akconnect-agent
    GOTOOLCHAIN=local GOOS=windows GOARCH=arm64 CGO_ENABLED=0 \
        go build -trimpath -buildvcs=false -ldflags "-s -w -X main.version=$VERSION" \
        -o "$STAGE/arm64/akconnect-agent.exe" ./cmd/akconnect-agent
)

echo "  building the tray icon"
# windowsgui here too: the tray is an icon, not a program with a window, and a
# console flashing up at every sign-in is what it must not do.
(
    cd "$ROOT/services/agent"
    GOTOOLCHAIN=local GOOS=windows GOARCH=amd64 CGO_ENABLED=0 \
        go build -trimpath -buildvcs=false -ldflags "-s -w -H=windowsgui -X main.version=$VERSION $BRAND_FLAGS" \
        -o "$STAGE/akconnect-tray.exe" ./cmd/akconnect-tray
    GOTOOLCHAIN=local GOOS=windows GOARCH=arm64 CGO_ENABLED=0 \
        go build -trimpath -buildvcs=false -ldflags "-s -w -H=windowsgui -X main.version=$VERSION $BRAND_FLAGS" \
        -o "$STAGE/arm64/akconnect-tray.exe" ./cmd/akconnect-tray
) || die "could not build the tray icon"

echo "  unpacking Wintun"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
unzip -q -o "$WINTUN_ZIP" -d "$TMP"

cp "$TMP/wintun/bin/amd64/wintun.dll" "$STAGE/wintun.dll"
cp "$TMP/wintun/bin/arm64/wintun.dll" "$STAGE/arm64/wintun.dll"
cp "$TMP/wintun/LICENSE.txt"          "$STAGE/LICENSE-wintun.txt"

echo "$WINTUN_AMD64_SHA  $STAGE/wintun.dll"       | sha256sum -c - >/dev/null || die "amd64 wintun.dll checksum mismatch"
echo "$WINTUN_ARM64_SHA  $STAGE/arm64/wintun.dll" | sha256sum -c - >/dev/null || die "arm64 wintun.dll checksum mismatch"

echo "  building the installer for $PANEL_URL"
#
# The payload goes into the source tree, because Go embeds at compile time and
# there is no way to hand it a file from outside. The placeholders are put back
# afterwards so a build never leaves real binaries committed by accident.
PAYLOAD="$ROOT/services/agent/cmd/akconnect-setup/payload"
restore_payload() {
    printf 'AKCONNECT-PLACEHOLDER-NOT-A-REAL-BINARY\n' > "$PAYLOAD/akconnect-agent.exe"
    printf 'AKCONNECT-PLACEHOLDER-NOT-A-REAL-BINARY\n' > "$PAYLOAD/wintun.dll"
    printf 'AKCONNECT-PLACEHOLDER-NOT-A-REAL-BINARY\n' > "$PAYLOAD/akconnect-tray.exe"
}
trap 'rm -rf "$TMP"; restore_payload' EXIT

cp "$STAGE/akconnect-agent.exe" "$PAYLOAD/akconnect-agent.exe"
cp "$STAGE/wintun.dll"          "$PAYLOAD/wintun.dll"
cp "$STAGE/akconnect-tray.exe"  "$PAYLOAD/akconnect-tray.exe"

(
    cd "$ROOT/services/agent"
    # windowsgui: double-clicking the installer must not open a console
    # window. Everything a customer sees is a dialog.
    GOTOOLCHAIN=local GOOS=windows GOARCH=amd64 CGO_ENABLED=0 \
        go build -trimpath -buildvcs=false \
        -ldflags "-s -w -H=windowsgui -X main.version=$VERSION -X main.defaultPanel=$PANEL_URL $BRAND_FLAGS" \
        -o "$STAGE/akconnect-setup.exe" ./cmd/akconnect-setup
) || die "could not build the installer"

cp "$STAGE/arm64/akconnect-agent.exe" "$PAYLOAD/akconnect-agent.exe"
cp "$STAGE/arm64/wintun.dll"          "$PAYLOAD/wintun.dll"
cp "$STAGE/arm64/akconnect-tray.exe"  "$PAYLOAD/akconnect-tray.exe"

(
    cd "$ROOT/services/agent"
    GOTOOLCHAIN=local GOOS=windows GOARCH=arm64 CGO_ENABLED=0 \
        go build -trimpath -buildvcs=false \
        -ldflags "-s -w -H=windowsgui -X main.version=$VERSION -X main.defaultPanel=$PANEL_URL $BRAND_FLAGS" \
        -o "$STAGE/arm64/akconnect-setup.exe" ./cmd/akconnect-setup
) || die "could not build the arm64 installer"

restore_payload

# The installer carries the agent inside it, so a build where the embedding
# silently did nothing would produce a file a few hundred kilobytes long that
# fails on a customer's machine. The agent alone is several megabytes.
grep -qa -- "$PANEL_URL" "$STAGE/akconnect-setup.exe" \
    || die "the panel URL is not in the built installer; the -X stamp did not take"
grep -qa -- "$PANEL_URL" "$STAGE/arm64/akconnect-setup.exe" \
    || die "the panel URL is not in the arm64 installer"

# The brand has to have taken too, or Apps & features lists the built-in
# default instead of the name on the invoice.
grep -qa -- "$BRAND_NAME" "$STAGE/akconnect-setup.exe" \
    || die "\"$BRAND_NAME\" is not in the built installer; the brand stamp did not take"
grep -qa -- "$BRAND_NAME" "$STAGE/akconnect-tray.exe" \
    || die "\"$BRAND_NAME\" is not in the built tray icon; the brand stamp did not take"

setup_size="$(stat -c%s "$STAGE/akconnect-setup.exe")"
agent_size="$(stat -c%s "$STAGE/akconnect-agent.exe")"
[ "$setup_size" -gt "$agent_size" ] \
    || die "akconnect-setup.exe ($setup_size bytes) is not larger than the agent it should contain ($agent_size)"

# Code signing, when a certificate is configured.
#
# Every one of these files is an unsigned executable today, which means
# SmartScreen shows "Windows protected your PC" the first time a customer runs
# it and the sentence under it names an unknown publisher. That is the single
# biggest thing standing between this and looking like a normal product, and it
# is bought, not built: an OV or EV code-signing certificate.
#
# So the pipeline is ready for one and says plainly when there is none. Set
# AKCONNECT_SIGN_CMD to a command taking one file:
#
#   export AKCONNECT_SIGN_CMD='osslsigncode sign -pkcs11module ... -in {} -out {}.signed'
#   export AKCONNECT_SIGN_CMD='signtool sign /fd sha256 /tr http://ts.example /td sha256 {}'
#
# Nothing is invented here: no key is generated, no certificate is created,
# and a signing command that fails fails the build rather than shipping
# something that looks signed and is not.
sign_artifacts() {
    local signed=0

    if [ -z "${AKCONNECT_SIGN_CMD:-}" ]; then
        echo "  ! unsigned: no AKCONNECT_SIGN_CMD is set."
        echo "    Customers will see SmartScreen's \"unknown publisher\" warning."

        return 0
    fi

    echo "  signing with \$AKCONNECT_SIGN_CMD"
    local file
    for file in "$STAGE/akconnect-setup.exe" "$STAGE/akconnect-agent.exe" \
                "$STAGE/akconnect-tray.exe" "$STAGE/arm64/akconnect-setup.exe" \
                "$STAGE/arm64/akconnect-agent.exe" "$STAGE/arm64/akconnect-tray.exe"; do
        [ -f "$file" ] || continue
        # shellcheck disable=SC2086
        ${AKCONNECT_SIGN_CMD//\{\}/$file} \
            || die "signing $file failed. Nothing ships half-signed."
        signed=$((signed + 1))
    done

    echo "  signed $signed file(s)"
}

sign_artifacts

# The MSI, for deployment tools. Optional: wixl is not on every build machine,
# and an edge upgrade must not fail for want of a deployment convenience.
echo "  building the MSI"
msi_status="not built"
if "$ROOT/services/kit/msi/build-msi.sh" "$STAGE/akconnect-setup.exe" "$VERSION" \
       "$STAGE/AKConnect.msi" x64; then
    "$ROOT/services/kit/msi/check-msi.sh" "$STAGE/AKConnect.msi" \
        || die "the MSI built but does not say what it must say; see the failures above"
    msi_status="built and checked"
else
    rc=$?
    [ "$rc" -eq 2 ] || die "the MSI build failed"
    msi_status="skipped (wixl is not installed)"
fi

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
echo "  msi: $msi_status"
echo
if [ -z "${AKCONNECT_SIGN_CMD:-}" ]; then
echo "  Contains an UNSIGNED agent and WireGuard LLC's signed wintun.dll,"
else
echo "  Contains a signed agent and WireGuard LLC's signed wintun.dll,"
fi
echo "  redistributed under clause 3(d) of its prebuilt binaries licence."
