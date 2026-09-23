#!/usr/bin/env bash
# Install a SECOND relay, on a server that is not the edge.
#
# One relay is a single point of failure for every pair that cannot punch
# through their routers — a CGNAT customer, a hotel with a symmetric NAT — and
# it is also one region. A relay in the city where the customers are is the
# difference between a camera feed that plays and one that stutters.
#
# This runs on the NEW relay's server. It installs the binary, writes the
# relay's own secret, starts the service, and prints the two lines to add to
# the coordinator's environment on the EDGE server. Nothing here can reach the
# edge or the panel by itself, deliberately: this machine is given a secret and
# a name, and it is never given a credential for anything else.
#
#   ./add-relay.sh --name mumbai-2 --public-host relay2.akdwk.in \
#                  --coordinator edge.akdwk.in:8443 --region in-west
#
# The relay carries encrypted traffic it cannot read, between two peers whose
# tickets the coordinator signed. It holds no customer data and no panel
# credential.
set -euo pipefail

NAME=""
PUBLIC_HOST=""
COORDINATOR=""
REGION="default"
BIN_DIR="${AKCONNECT_BIN_DIR:-/usr/local/bin}"
ETC_DIR="${AKCONNECT_ETC:-/etc/akconnect}"
SRC_DIR="${AKCONNECT_SRC:-/opt/akconnect/src}"

die() { printf '\n  \033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
step() { printf '\n\033[1m── %s\033[0m\n' "$*"; }
say() { printf '  %s\n' "$*"; }

# shellcheck source=lib-edge-args.sh
. "$(cd "$(dirname "$0")" && pwd)/lib-edge-args.sh"

while [ $# -gt 0 ]; do
    case "$1" in
        --name) akconnect_need_value --name "$#"; NAME="$2"; shift 2 ;;
        --public-host) akconnect_need_value --public-host "$#"; PUBLIC_HOST="$2"; shift 2 ;;
        --coordinator) akconnect_need_value --coordinator "$#"; COORDINATOR="$2"; shift 2 ;;
        --region) akconnect_need_value --region "$#"; REGION="$2"; shift 2 ;;
        --src) akconnect_need_value --src "$#"; SRC_DIR="$2"; shift 2 ;;
        *) die "unknown option: $1
    Valid options are --name, --public-host, --coordinator, --region and --src." ;;
    esac
done

[ "$(id -u)" -eq 0 ] || die "run this as root: it installs a service."

[ -n "$NAME" ] || die "--name is required, e.g. --name mumbai-2
    It has to be unique across the fleet: the coordinator keys this relay's
    secret off it, and two relays with one name share one secret."
[ -n "$PUBLIC_HOST" ] || die "--public-host is required, e.g. --public-host relay2.akdwk.in
    This is the address AGENTS will send to. A private address here produces a
    relay that works from the edge server and from nowhere else."
[ -n "$COORDINATOR" ] || die "--coordinator is required, e.g. --coordinator edge.akdwk.in:8443
    The relay reports its usage there. Without it the traffic it carries is
    billed to nobody."

case "$NAME" in
    *[!a-zA-Z0-9-]*) die "--name may only contain letters, digits and hyphens: \"$NAME\".
    It becomes part of an environment variable name on the coordinator." ;;
esac

step "building"

[ -d "$SRC_DIR/services/relay" ] || die "no source at $SRC_DIR.
    Clone the repository there first, or pass --src."

command -v go >/dev/null 2>&1 || export PATH="/usr/local/go/bin:$PATH"
command -v go >/dev/null 2>&1 || die "Go is not installed and is not at /usr/local/go/bin."

( cd "$SRC_DIR/services/relay" && go build -trimpath -o "$BIN_DIR/akconnect-relay" ./cmd/akconnect-relay ) \
    || die "the relay would not build"

say "installed $BIN_DIR/akconnect-relay"

step "configuring"

id -u akconnect >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin akconnect
mkdir -p "$ETC_DIR"

# Reused if this is a re-run, because changing the secret here without changing
# it on the coordinator is how a relay stops accepting every ticket it is sent.
if [ -f "$ETC_DIR/relay.env" ] && grep -q '^AKCONNECT_RELAY_SECRET=' "$ETC_DIR/relay.env"; then
    RELAY_SECRET="$(sed -n 's/^AKCONNECT_RELAY_SECRET=//p' "$ETC_DIR/relay.env" | head -1)"
    say "keeping the existing secret for $NAME"
else
    RELAY_SECRET="$(openssl rand -hex 32)"
    say "generated a secret for $NAME"
fi

umask 077
cat > "$ETC_DIR/relay.env" <<ENV
# Written by add-relay.sh. root and the akconnect group only.
AKCONNECT_RELAY_SECRET=$RELAY_SECRET
AKCONNECT_RELAY_NAME=$NAME
AKCONNECT_COORDINATOR_ADDR=$COORDINATOR
ENV
umask 022

id -u akconnect >/dev/null 2>&1 && chown root:akconnect "$ETC_DIR/relay.env"
chmod 640 "$ETC_DIR/relay.env"

step "starting"

install -m 644 "$(dirname "$0")/systemd/akconnect-relay.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now akconnect-relay >/dev/null 2>&1 \
    || die "the relay would not start — journalctl -u akconnect-relay"

sleep 2
systemctl is-active --quiet akconnect-relay \
    || die "the relay started and stopped — journalctl -u akconnect-relay"

say "akconnect-relay is running as $NAME"

ENV_NAME="$(printf '%s' "$NAME" | tr '[:lower:]-' '[:upper:]_')"

cat <<DONE

  ────────────────────────────────────────────────────────────
  This relay is running. Two more things, neither on this machine.

  1. ON THE EDGE SERVER, add this relay to the coordinator's fleet.
     Append to $ETC_DIR/coordinator.env — keep the existing relays in
     AKCONNECT_RELAYS and add this one after a comma:

       AKCONNECT_RELAYS=<existing>,$NAME:$REGION:$PUBLIC_HOST:9000
       AKCONNECT_RELAY_SECRET_$ENV_NAME=$RELAY_SECRET

     then:  systemctl restart akconnect-coordinator

  2. IN THE PANEL, under Platform → Relays, add a relay named

       $NAME        host $PUBLIC_HOST        region $REGION

  The secret above is the only credential this machine holds. It is not the
  coordinator's secret and it is not the panel's: it verifies tickets for this
  relay and nothing else.

  Open on this server:  UDP 9000, and UDP 51900-52400 for the data sockets.
DONE
