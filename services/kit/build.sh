#!/usr/bin/env bash
# Build the field-test kit: agent and coordinator for every platform we
# support, plus checksums.
#
# These binaries are NOT code-signed. On Windows, SmartScreen will warn and
# Defender may quarantine; see RUNBOOK.md, which tells the tester what to
# expect rather than leaving them to guess whether the warning is real.
set -euo pipefail

VERSION="${1:-dev}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="$ROOT/services/kit/dist"

rm -rf "$OUT"
mkdir -p "$OUT"

build() {
    local module=$1 binary=$2 goos=$3 goarch=$4
    local name="$binary-$goos-$goarch"
    [ "$goos" = "windows" ] && name="$name.exe"

    echo "  building $name"
    (
        cd "$ROOT/services/$module"
        GOTOOLCHAIN=local GOOS="$goos" GOARCH="$goarch" CGO_ENABLED=0 \
            go build -trimpath -ldflags "-s -w -X main.version=$VERSION" \
            -o "$OUT/$name" "./cmd/$binary"
    )
}

for arch in amd64 arm64; do
    build agent       akconnect-agent       windows "$arch"
    build agent       akconnect-agent       linux   "$arch"
    build coordinator akconnect-coordinator linux   "$arch"
done

cp "$ROOT/services/kit/RUNBOOK.md" "$OUT/"
cp "$ROOT/services/kit/collect-evidence.ps1" "$OUT/"
cp "$ROOT/services/kit/collect-evidence.sh" "$OUT/"
chmod +x "$OUT/collect-evidence.sh"

(cd "$OUT" && sha256sum * > SHA256SUMS)

echo
echo "  kit built in $OUT"
echo "  version: $VERSION"
echo
echo "  These binaries are unsigned. Windows SmartScreen will warn."
echo "  Verify them with SHA256SUMS before running anything."
