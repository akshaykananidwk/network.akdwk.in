#!/usr/bin/env bash
#
# Install the coordinator and one relay on a public server.
#
# Run it on the VPS, as root. It does everything DEPLOY.md's Stage 2 describes,
# and prints what it did so the checklist's "expected output" is something you
# can compare against rather than take on trust.
#
#   ./install-edge.sh --panel https://network.akdwk.in --relay-name mumbai-1 \
#                     --public-host relay1.akdwk.in
#
# It is safe to run twice: everything it does is idempotent, and running it
# again after changing a flag is how you change a setting.
#
# It never prints a secret. The one the panel needs is in a root-only file,
# and this says which; the shared secret used to be printed here, into the
# terminal's scrollback and whatever captured the run, and from there it was
# pasted into a chat.
#
#   --panel-configured-by-caller   the caller writes the coordinator's settings
#                                  into the panel itself (getting-started.sh
#                                  does), so nothing is printed about doing it
#                                  by hand
set -uo pipefail

PANEL=""
RELAY_NAME=""
PUBLIC_HOST=""
REGION="in"
BIN_DIR="/usr/local/bin"
# Empty means nothing was asked for, which is not the same as being asked for
# no. See akconnect_may_configure_apache.
CONFIGURE_APACHE=""
ETC_DIR="/etc/akconnect"
PANEL_BY_CALLER=0

die()  { printf '\n  \033[31m✗ %s\033[0m\n\n' "$*" >&2; exit 1; }
say()  { printf '  %s\n' "$*"; }
step() { printf '\n\033[1m── %s\033[0m\n' "$*"; }

# shellcheck source=lib-edge-args.sh
. "$(cd "$(dirname "$0")" && pwd)/lib-edge-args.sh"

while [ $# -gt 0 ]; do
    case "$1" in
        --panel) akconnect_need_value --panel "$#"; PANEL="$2"; shift 2 ;;
        --configure-apache)    CONFIGURE_APACHE=1; shift ;;
        --no-configure-apache) CONFIGURE_APACHE=0; shift ;;
        --relay-name) akconnect_need_value --relay-name "$#"; RELAY_NAME="$2"; shift 2 ;;
        --public-host) akconnect_need_value --public-host "$#"; PUBLIC_HOST="$2"; shift 2 ;;
        --region) akconnect_need_value --region "$#"; REGION="$2"; shift 2 ;;
        --panel-configured-by-caller) PANEL_BY_CALLER=1; shift ;;
        *) die "unknown option: $1" ;;
    esac
done

[ "$(id -u)" -eq 0 ] || die "run this as root."
[ -n "$PANEL" ]       || die "--panel is required, e.g. --panel https://network.akdwk.in"
[ -n "$RELAY_NAME" ]  || die "--relay-name is required, e.g. --relay-name mumbai-1"
[ -n "$PUBLIC_HOST" ] || die "--public-host is required: the address agents will reach this server at"

case "$PANEL" in
    https://*) ;;
    *) die "--panel must be https. The coordinator sends the panel a shared secret on every call." ;;
esac

step "checking what is here"

for tool in systemctl curl; do
    command -v "$tool" >/dev/null 2>&1 || die "$tool is not installed."
done

for binary in akconnect-coordinator akconnect-relay; do
    [ -x "$BIN_DIR/$binary" ] || die "$BIN_DIR/$binary is missing. Copy the binaries there first — DEPLOY.md Stage 2b."
done
say "both binaries are in $BIN_DIR"

step "creating the service account"

if id akconnect >/dev/null 2>&1; then
    say "the akconnect user already exists"
else
    # No shell, no home, no login. It runs two network services and has no
    # business being able to do anything else.
    useradd --system --no-create-home --shell /usr/sbin/nologin akconnect \
        || die "could not create the akconnect user"
    say "created the akconnect system user"
fi

step "generating keys and secrets"

mkdir -p "$ETC_DIR"
chmod 750 "$ETC_DIR"
chown root:akconnect "$ETC_DIR"

# Generated once and kept. Regenerating the coordinator's key would orphan
# every agent that has its public half, so this refuses to overwrite.
if [ -f "$ETC_DIR/coordinator.env" ]; then
    say "keeping the existing coordinator.env — delete it by hand to start over"
else
    keypair="$("$BIN_DIR/akconnect-coordinator" keygen)" || die "keygen failed"
    private="$(awk '/Private key/ {getline; print $1}' <<<"$keypair")"
    public="$(awk  '/Public key/  {getline; print $1}' <<<"$keypair")"
    [ -n "$private" ] && [ -n "$public" ] || die "could not read the generated keypair"

    coord_secret="$(openssl rand -hex 32)"
    relay_secret="$(openssl rand -hex 32)"
    relay_env_name="$(tr '[:lower:]-' '[:upper:]_' <<<"$RELAY_NAME")"

    umask 077
    cat > "$ETC_DIR/coordinator.env" <<ENV
# Written by install-edge.sh. root and the akconnect group only.
AKCONNECT_COORDINATOR_KEY=$private
AKCONNECT_COORDINATOR_SECRET=$coord_secret
AKCONNECT_PANEL_URL=$PANEL
AKCONNECT_RELAYS=$RELAY_NAME:$REGION:$PUBLIC_HOST:9000
AKCONNECT_RELAY_SECRET_$relay_env_name=$relay_secret
ENV

    cat > "$ETC_DIR/relay.env" <<ENV
# Written by install-edge.sh. root and the akconnect group only.
AKCONNECT_RELAY_SECRET=$relay_secret
AKCONNECT_RELAY_NAME=$RELAY_NAME
AKCONNECT_COORDINATOR_ADDR=127.0.0.1:8443
ENV
    umask 022

    # The public key is not a secret and is the one value that has to be typed
    # into the panel, so it goes somewhere easy to read.
    printf '%s\n' "$public" > "$ETC_DIR/coordinator.pub"
    printf '%s\n' "$coord_secret" > "$ETC_DIR/coordinator.secret.for-panel"
    chmod 640 "$ETC_DIR/coordinator.secret.for-panel"

    say "generated a coordinator keypair and two shared secrets"
fi

chown root:akconnect "$ETC_DIR"/*.env "$ETC_DIR"/coordinator.secret.for-panel 2>/dev/null
chmod 640 "$ETC_DIR"/*.env 2>/dev/null

step "installing the services"

install -m 644 "$(dirname "$0")/systemd/akconnect-coordinator.service" /etc/systemd/system/
install -m 644 "$(dirname "$0")/systemd/akconnect-relay.service"       /etc/systemd/system/

systemctl daemon-reload
systemctl enable --now akconnect-relay       >/dev/null 2>&1 || die "the relay would not start — journalctl -u akconnect-relay"
systemctl enable --now akconnect-coordinator >/dev/null 2>&1 || die "the coordinator would not start — journalctl -u akconnect-coordinator"

sleep 2

for unit in akconnect-relay akconnect-coordinator; do
    state="$(systemctl is-active "$unit" 2>/dev/null)"
    [ "$state" = "active" ] || die "$unit is $state — journalctl -u $unit -n 30"
    say "$unit is running"
done

step "the HTTPS fallback"

# Without this a device on a network that carries no UDP cannot connect at
# all — not to a relay, and not even to the coordinator to ask for one. It is
# configured here rather than written down for somebody to do later, because
# "somebody will add an Apache stanza" is how a customer ends up being asked
# to change their firewall.
# shellcheck source=lib-edge-apache.sh
. "$(dirname "$0")/lib-edge-apache.sh"
# shellcheck source=lib-edge-vhost.sh
. "$(dirname "$0")/lib-edge-vhost.sh"
# shellcheck source=lib-edge-probe.sh
. "$(dirname "$0")/lib-edge-probe.sh"

# Only when it is asked for, in as many words.
#
# This script is run by hand, so there is a person here — but "a person ran it"
# is not the same as "a person asked for their web server to be edited", and on
# the machine this was written for that web server carries thirty other
# people's websites. Editing Apache is the one thing in this installer that
# touches something the customer did not install, so it needs the flag, and
# then it still prints the file and the exact lines and waits for a yes.
#
# The proxy goes into the panel's own virtual host and nowhere else, and every
# other site on the machine is asked for a status code before and after.
if [ "$(akconnect_may_configure_apache "$CONFIGURE_APACHE" 0)" = "1" ]; then
    akconnect_apache_fallback "$(dirname "$0")/apache" "$PANEL" say
    apache_state=$?
else
    apache_state=5
fi

case "$apache_state" in
    0)
        if akconnect_fallback_reachable "$PANEL"; then
            say "and $PANEL/fallback/health answers, so the path works end to end"
            say ""
            say "In the panel, Settings → Coordinator, the HTTPS fallback address must read"
            say "exactly:  wss://$(akconnect_panel_host "$PANEL")/fallback"
            say "It is filled in from the panel's own address, so it should already. A"
            say "hostname that does not resolve fails silently on every device that needs it."
        else
            say "note: $PANEL/fallback/health did not answer. If the panel is on another"
            say "      server, install this configuration there instead — the fallback has"
            say "      to arrive on the address agents already trust."
        fi
        ;;
    1)
        say "install deploy/apache/akconnect-fallback.conf on whichever machine serves"
        say "$PANEL on 443, inside that site's own virtual host, and point it at this"
        say "server's 127.0.0.1:9443."
        ;;
    3)
        say "there is no TLS virtual host of its own for this panel's domain, so there is"
        say "nothing to add the fallback to. The message above says which case it is."
        ;;
    4)
        say "you said no, so Apache was not changed. Run this again, or"
        say "deploy/upgrade-edge.sh --configure-apache, whenever you want it."
        ;;
    5)
        if [ "$CONFIGURE_APACHE" = "0" ]; then
            # Said in as many words: whoever passed it carries /fallback some
            # other way (getting-started.sh: Caddy), and the paragraph below
            # would tell them devices cannot connect when they can.
            say "Apache was not touched (--no-configure-apache)."
        else
        say "Apache was not touched, because --configure-apache was not given."
        say ""
        say "Devices on networks that pass nothing but 443 will not connect until it is."
        say "When you want it: deploy/install-edge.sh --configure-apache (or"
        say "deploy/upgrade-edge.sh --configure-apache). It edits one virtual host —"
        say "the one whose ServerName is this panel's domain — shows you the file and"
        say "the exact lines first, and asks. deploy/remove-apache-fallback.sh undoes it."
        fi
        ;;
    *)
        die "Apache is here but would not take the fallback configuration, and everything
    it touched has been put back. Devices on networks that block UDP will not be
    able to connect until it does."
        ;;
esac

step "what to do next"

if [ "$PANEL_BY_CALLER" -eq 1 ]; then
    say "the panel's coordinator settings are written by the script that ran this"

    exit 0
fi

cat <<NEXT

  Open these in the VPS firewall, and only these:

    UDP  8443           the coordinator
    UDP  9000           the relay's control port
    UDP  51900-52400    the relay's data sockets
    TCP  443            the panel, and the HTTPS fallback on /fallback
    TCP  22             ssh, if it is not already

  Then put these values into the panel, under Settings → Coordinator:

    Public key      $(cat "$ETC_DIR/coordinator.pub" 2>/dev/null)
    Shared secret   not shown here. Copy it straight from the file into the field:
                        sudo cat $ETC_DIR/coordinator.secret.for-panel
                    It is a password: anyone who has it can talk to the panel as
                    the coordinator. Do not paste it anywhere else.
    Host            $PUBLIC_HOST
    Port            8443

    HTTPS fallback  wss://$(akconnect_panel_host "$PANEL")/fallback

  And register the relay, under Settings → Relays:

    Name            $RELAY_NAME
    Region          $REGION
    Host            $PUBLIC_HOST
    Port            9000

  DEPLOY.md Stage 3 checks that all of it worked.

NEXT
