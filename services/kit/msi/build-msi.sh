#!/usr/bin/env bash
# Wrap a built akconnect-setup.exe in an MSI, for deployment tools.
#
# Optional by design. wixl is not installed on every machine that builds a
# pack, and an edge upgrade must not fail because a deployment convenience is
# missing — so a missing wixl is reported and skipped, never fatal.
set -euo pipefail

SETUP_EXE="${1:?usage: build-msi.sh <setup.exe> <version> <output.msi> [arch]}"
VERSION="${2:?}"
OUTPUT="${3:?}"
ARCH="${4:-x64}"

BRAND_NAME="${BRAND_NAME:-AK Connect}"
ORG_NAME="${ORG_NAME:-AK Computer}"

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v wixl >/dev/null 2>&1; then
    echo "  ! no MSI built: wixl is not installed (apt-get install wixl)." >&2
    echo "    The .exe installer is unaffected; only msiexec deployment is." >&2
    exit 2
fi

[ -f "$SETUP_EXE" ] || { echo "  ✗ $SETUP_EXE does not exist" >&2; exit 1; }

# An MSI ProductVersion is three numbers and nothing else. "1.9.5-rc1" is
# rejected by Windows, so a prerelease suffix is dropped rather than shipped in
# a file that will not install.
MSI_VERSION="$(printf '%s' "$VERSION" | sed 's/^v//; s/[-+].*$//')"
case "$MSI_VERSION" in
    [0-9]*.[0-9]*.[0-9]*) ;;
    [0-9]*.[0-9]*)        MSI_VERSION="$MSI_VERSION.0" ;;
    *)
        # A development build — the release gate builds the pack as "dev" —
        # still gets an MSI, because the point of building one in the gate is
        # to read its tables back. It is numbered 0.0.0 so nothing can mistake
        # it for something to ship, and this is a note rather than a failure:
        # refusing here made the whole release gate red for a build nobody was
        # going to publish.
        echo "  ! $VERSION is not a version an MSI can carry; numbering this one 0.0.0"
        MSI_VERSION="0.0.0"
        ;;
esac

wixl -a "$ARCH" \
    -D "Version=$MSI_VERSION" \
    -D "DisplayName=$BRAND_NAME" \
    -D "Publisher=$ORG_NAME" \
    -D "SetupExe=$SETUP_EXE" \
    -o "$OUTPUT" "$HERE/akconnect.wxs"

[ -s "$OUTPUT" ] || { echo "  ✗ wixl produced nothing" >&2; exit 1; }

echo "  msi: $OUTPUT ($(stat -c%s "$OUTPUT") bytes, version $MSI_VERSION)"
