#!/bin/bash
# Build the Apache + PHP-FPM target, install this working tree into it through
# the browser installer, and run the drill.
#
#   services/lab/webtarget/run.sh            build, install, drill, remove
#   services/lab/webtarget/run.sh --keep     leave the container running
#
# The container gets a copy of the tree, not a mount: the installer writes
# config/config.php and install/install.lock, and a drill that leaves those in
# the working tree would be a drill that breaks the next one.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
HERE="$ROOT/services/lab/webtarget"
IMAGE=akc-webtarget
NAME=akc-webtarget-run
KEEP=0

[ "${1:-}" = "--keep" ] && KEEP=1

if ! docker info >/dev/null 2>&1; then
    echo "  docker is not running; this target needs it" >&2
    exit 2
fi

cleanup() {
    [ "$KEEP" -eq 1 ] && { echo "  container $NAME left running (docker rm -f $NAME)"; return; }
    docker rm -f "$NAME" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "  building $IMAGE"
docker build -q -t "$IMAGE" "$HERE" >/dev/null

docker rm -f "$NAME" >/dev/null 2>&1 || true
docker run -d --name "$NAME" "$IMAGE" >/dev/null

# A fresh copy of the tree, minus everything an installed site owns. Anything
# left here would make the drill test a site that was already installed.
echo "  copying the tree in"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"; cleanup' EXIT
tar -C "$ROOT" \
    --exclude=.git \
    --exclude=config/config.php \
    --exclude='config/*.local.php' \
    --exclude=.env \
    --exclude=install/install.lock \
    --exclude='storage/logs/*' \
    --exclude='storage/cache/*' \
    --exclude='storage/backups/*' \
    --exclude='services/*/bin' \
    --exclude='node_modules' \
    -cf "$STAGE/tree.tar" .
docker cp "$STAGE/tree.tar" "$NAME:/tmp/tree.tar"
docker exec "$NAME" sh -c '
    mkdir -p /var/www/app &&
    tar -C /var/www/app -xf /tmp/tree.tar &&
    rm -f /tmp/tree.tar /var/www/app/config/config.php /var/www/app/install/install.lock &&
    mkdir -p /var/www/app/storage/logs /var/www/app/storage/cache /var/www/app/uploads &&
    chown -R www-data:www-data /var/www/app &&
    chmod -R 775 /var/www/app/storage /var/www/app/uploads /var/www/app/config'

# Apache came up against an empty document root, so it has to re-read the
# .htaccess that only now exists.
docker exec "$NAME" apachectl graceful >/dev/null 2>&1 || docker exec "$NAME" apachectl start >/dev/null 2>&1 || true
sleep 2

echo "  running the drill"
docker cp "$HERE/drill.sh" "$NAME:/usr/local/bin/drill.sh"
docker exec "$NAME" chmod +x /usr/local/bin/drill.sh
docker exec "$NAME" /usr/local/bin/drill.sh
