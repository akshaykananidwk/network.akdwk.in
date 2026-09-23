#!/bin/bash
# Build a PHP whose Argon2 comes from libsodium, and run the real Crypto class
# against it.
#
#   services/lab/argon2target/run.sh
#
# The first run compiles PHP, which takes a few minutes; afterwards the image
# is cached and the check is instant. The source tarball is fetched here rather
# than inside the build, so the build needs no network.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
HERE="$ROOT/services/lab/argon2target"
IMAGE=akc-argon2target
PHP_VERSION="${PHP_VERSION:-8.3.26}"

if ! docker info >/dev/null 2>&1; then
    echo "  docker is not running; this target needs it" >&2
    exit 2
fi

if [ ! -f "$HERE/php.tar.xz" ]; then
    echo "  fetching php-$PHP_VERSION"
    curl -fsSL "https://www.php.net/distributions/php-$PHP_VERSION.tar.xz" -o "$HERE/php.tar.xz.part"
    mv "$HERE/php.tar.xz.part" "$HERE/php.tar.xz"
fi

echo "  building $IMAGE (first run compiles PHP)"
docker build -q --build-arg "PHP_VERSION=$PHP_VERSION" -t "$IMAGE" "$HERE" >/dev/null

# Read-only: this target must not be able to change the tree it is judging.
docker run --rm -v "$ROOT:/app:ro" "$IMAGE"
