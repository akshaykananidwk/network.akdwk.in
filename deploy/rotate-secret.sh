#!/usr/bin/env bash
#
# akconnect-rotate-secret — replace the coordinator's shared secret everywhere
# it lives, in one step.
#
#   sudo akconnect-rotate-secret                 the panel <-> coordinator secret
#   sudo akconnect-rotate-secret --relay         and this server's relay secret
#   sudo akconnect-rotate-secret --panel /www/wwwroot/network.akdwk.in
#
# The shared secret is the one value the panel and the coordinator both hold:
# the coordinator signs every call it makes to the panel with it, and so does
# upgrade-edge.sh. It lives in three places — /etc/akconnect/coordinator.env,
# /etc/akconnect/coordinator.secret.for-panel and the panel's settings — and a
# rotation that misses one is worse than none: the coordinator is refused by
# the panel, or the next installer re-run puts the old value back.
#
# Anyone who has it can do more than read. The panel accepts a Windows
# installer or an agent binary from whoever holds it, signs an uploaded agent
# with its own key, and offers it to every device as an update. So this ends
# by listing what has been published with it (cli/edge-audit.php).
#
# What it changes, in order, and why that order:
#
#   1. the panel's copy. The coordinator still running with the old one is
#      refused for the few seconds until step 3 — and serves every device it
#      already knows from its memory of the panel's last answer meanwhile.
#      The other order restarts the coordinator into an empty registry that
#      the panel then refuses to fill.
#   2. the two files on this server, each replaced whole, root:akconnect 0640.
#   3. a restart of the coordinator. Nothing else restarts.
#   4. a signed request to the panel with the new secret, which must be
#      accepted, and one with the old, which must be refused.
#
# Agents are not touched: none of them has ever held this secret. Direct pairs
# and relayed pairs keep carrying traffic through the restart; for about ten
# to twenty-five seconds after it, a device that has to announce itself waits
# its turn.
#
# --relay also replaces the secret this server's relay shares with the
# coordinator (relay.env and the matching line in coordinator.env), and
# restarts the relay. That one was never printed by anything; rotating it
# costs every relayed pair on this relay 15-25 seconds while its agents fetch
# new tickets, which they do by themselves. Relays on other servers
# (deploy/add-relay.sh) each have their own secret and are not touched.
#
# The secret is never printed and never put on a command line (ps shows
# every process's arguments to every user): it travels on standard input and
# in the environment of the one process that needs it.
set -uo pipefail

ETC_DIR="${AKCONNECT_ETC:-/etc/akconnect}"
STATE_FILE="$ETC_DIR/getting-started.state"
COORD_ENV="$ETC_DIR/coordinator.env"
RELAY_ENV="$ETC_DIR/relay.env"
FOR_PANEL="$ETC_DIR/coordinator.secret.for-panel"

PANEL_DIR=""
ROTATE_RELAY=0
ASSUME_YES=0
SINCE=""

RED=''; GREEN=''; YELLOW=''; BOLD=''; RESET=''
if [ -t 1 ]; then
    RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; BOLD=$'\033[1m'; RESET=$'\033[0m'
fi
say()  { printf '  %s\n' "$*"; }
ok()   { printf '  %s✓%s %s\n' "$GREEN" "$RESET" "$*"; }
warn() { printf '  %s!%s %s\n' "$YELLOW" "$RESET" "$*"; }
step() { printf '\n%s── %s%s\n' "$BOLD" "$*" "$RESET"; }
die()  { printf '\n  %s✗ %s%s\n\n' "$RED" "$*" "$RESET" >&2; exit 1; }

usage() {
    cat <<USAGE
akconnect-rotate-secret — replace the coordinator's shared secret everywhere it lives.

  --relay              also replace this server's relay secret (relayed pairs pause 15-25 s)
  --panel <dir>        the panel's directory (read from the install state when omitted)
  --since <YYYY-MM-DD> how far back the audit of published builds looks (default: 30 days)
  --yes                do not ask before starting
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --relay) ROTATE_RELAY=1; shift ;;
        --yes) ASSUME_YES=1; shift ;;
        --panel) [ $# -ge 2 ] || die "--panel needs a directory"; PANEL_DIR="$2"; shift 2 ;;
        --panel=*) PANEL_DIR="${1#--panel=}"; shift ;;
        --since) [ $# -ge 2 ] || die "--since needs a date"; SINCE="$2"; shift 2 ;;
        --since=*) SINCE="${1#--since=}"; shift ;;
        -h|--help) usage; exit 0 ;;
        *) usage >&2; die "unknown option: $1" ;;
    esac
done

[ "$(id -u)" -eq 0 ] || die "run this as root: sudo akconnect-rotate-secret"
[ -z "$SINCE" ] || printf '%s' "$SINCE" | grep -Eq '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' \
    || die "--since must be a date, e.g. --since 2026-09-01"

# ------------------------------------------------------------------ checks

step "checking this server"

# Read, not sourced: nothing in a state file should run as root.
state() { sed -n "s/^$1=//p" "$STATE_FILE" 2>/dev/null | head -1; }

[ -n "$PANEL_DIR" ] || PANEL_DIR="$(state AKCONNECT_PANEL_DIR)"
[ -n "$PANEL_DIR" ] || die "which panel? Pass --panel /path/to/panel (no $STATE_FILE here to say)."
[ -f "$PANEL_DIR/config/config.php" ] || die "$PANEL_DIR is not an installed panel (no config/config.php)."
SRC_DIR="$(state AKCONNECT_SRC_DIR)"
SRC_DIR="${SRC_DIR:-/opt/akconnect/src}"

WEB_USER="$(stat -c %U "$PANEL_DIR" 2>/dev/null)"
[ -n "$WEB_USER" ] && [ "$WEB_USER" != "root" ] \
    || die "$PANEL_DIR belongs to ${WEB_USER:-nobody}, not to the user the panel runs as."

PHP_BIN="$(state AKCONNECT_PHP)"
[ -x "${PHP_BIN:-}" ] || PHP_BIN="$(command -v php 2>/dev/null)"
[ -x "${PHP_BIN:-}" ] || die "php is not on this server's PATH"

for tool in openssl python3 curl systemctl awk; do
    command -v "$tool" >/dev/null 2>&1 || die "$tool is not installed"
done

[ -f "$COORD_ENV" ] || die "$COORD_ENV is missing — the coordinator is not installed on this server."
OLD_SECRET="$(sed -n 's/^AKCONNECT_COORDINATOR_SECRET=//p' "$COORD_ENV" | head -1)"
[ -n "$OLD_SECRET" ] || die "$COORD_ENV has no AKCONNECT_COORDINATOR_SECRET line."
PANEL_URL="$(sed -n 's/^AKCONNECT_PANEL_URL=//p' "$COORD_ENV" | head -1)"
PANEL_URL="${PANEL_URL%/}"

# The panel's helpers: its own copy, which matches it; or this release's, from
# the edge source, aimed at it — a panel older than 1.9.7-dev.22 has neither
# edge-audit.php nor, before dev.21, edge-settings.php.
helper_path() {
    if [ -f "$PANEL_DIR/cli/$1" ] && [ -f "$PANEL_DIR/cli/_root.php" ]; then
        printf '%s\n' "$PANEL_DIR/cli/$1"
    elif [ -f "$SRC_DIR/cli/$1" ] && [ -f "$SRC_DIR/cli/_root.php" ]; then
        printf '%s\n' "$SRC_DIR/cli/$1"
    fi
}

# panel_php <script> [args]: as the panel's user, umask 022, aimed at the panel.
panel_php() {
    local script=$1
    shift
    sudo -H -u "$WEB_USER" -- /bin/sh -c 'umask 022 && exec "$@"' panel_php \
        "$PHP_BIN" "$script" --root="$PANEL_DIR" "$@"
}

SETTINGS_HELPER="$(helper_path edge-settings.php)"
AUDIT_HELPER="$(helper_path edge-audit.php)"
[ -n "$SETTINGS_HELPER" ] || die "neither $PANEL_DIR/cli nor $SRC_DIR/cli has edge-settings.php.
    Update the panel (Platform → Updates), then run this again."

# An edge upgrade in flight read the old secret when it started, and would be
# refused half-way through publishing. And the hourly timer is held for the
# minute this takes.
if systemctl is-active --quiet akconnect-upgrade.service 2>/dev/null; then
    die "an edge upgrade is running now (akconnect-upgrade.service). Wait for it to finish:
        journalctl -u akconnect-upgrade -f"
fi
TIMER_HELD=0
if systemctl is-active --quiet akconnect-upgrade.timer 2>/dev/null; then
    systemctl stop akconnect-upgrade.timer >/dev/null 2>&1 && TIMER_HELD=1
fi

release_timer() {
    [ "$TIMER_HELD" -eq 1 ] && systemctl start akconnect-upgrade.timer >/dev/null 2>&1
    TIMER_HELD=0
}

# However this ends, the timer comes back and no secret stays in a variable.
on_exit() {
    release_timer
    OLD_SECRET=""; NEW_SECRET=""; OLD_RELAY=""; NEW_RELAY=""
}
trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

OLD_RELAY=""
NEW_RELAY=""
RELAY_NAME=""
RELAY_LINE=""
if [ "$ROTATE_RELAY" -eq 1 ]; then
    # The coordinator's last-resort relay pick signed tickets with an empty
    # key until 1.9.7-dev.22: on a one-relay server, a relay-secret rotation
    # could leave relayed pairs dead instead of 25 seconds late.
    have="$(/usr/local/bin/akconnect-coordinator version 2>/dev/null | awk '{print $NF}')"
    base="${have%%-*}"
    fixed=1
    if [ "$(printf '%s\n%s\n' "$base" "1.9.7" | sort -V | head -1)" != "1.9.7" ]; then
        fixed=0
    elif [ "$base" = "1.9.7" ] && [ "$have" != "1.9.7" ]; then
        case "$have" in
            1.9.7-dev.*) [ "${have#1.9.7-dev.}" -ge 22 ] 2>/dev/null || fixed=0 ;;
            *) fixed=0 ;;
        esac
    fi
    [ "$fixed" -eq 1 ] || die "the coordinator here is ${have:-unknown}. Rotating the relay secret needs 1.9.7-dev.22
    or later — earlier coordinators could leave relayed pairs unable to reconnect.
    Update the panel and let the edge follow it, or rotate without --relay."

    [ -f "$RELAY_ENV" ] || die "$RELAY_ENV is missing — there is no relay on this server to rotate."
    RELAY_NAME="$(sed -n 's/^AKCONNECT_RELAY_NAME=//p' "$RELAY_ENV" | head -1)"
    OLD_RELAY="$(sed -n 's/^AKCONNECT_RELAY_SECRET=//p' "$RELAY_ENV" | head -1)"
    [ -n "$RELAY_NAME" ] && [ -n "$OLD_RELAY" ] || die "$RELAY_ENV does not name its relay and secret."
    RELAY_LINE="AKCONNECT_RELAY_SECRET_$(printf '%s' "$RELAY_NAME" | tr '[:lower:]-' '[:upper:]_')"
    # Already different is a fault to fix by hand, not a state to rotate from:
    # relayed pairs are down, and it is not clear which side is right.
    [ "$(sed -n "s/^$RELAY_LINE=//p" "$COORD_ENV" | head -1)" = "$OLD_RELAY" ] \
        || die "the relay's secret in $RELAY_ENV and $RELAY_LINE in $COORD_ENV already differ.
    Relayed pairs through $RELAY_NAME cannot work like that. Make them equal by hand first."
fi

ok "panel $PANEL_DIR (runs as $WEB_USER), coordinator at ${PANEL_URL:-?}"
[ "$ROTATE_RELAY" -eq 1 ] && ok "and relay $RELAY_NAME on this server"

# ----------------------------------------------------------------- confirm

if [ "$ASSUME_YES" -ne 1 ]; then
    printf '\n'
    say "This replaces the coordinator's shared secret in the panel and on this server,"
    say "and restarts the coordinator. Agents are not touched and do not need anything."
    say "Direct and relayed pairs keep carrying traffic; for 10-25 seconds after the"
    say "restart, a device that has to announce itself waits."
    [ "$ROTATE_RELAY" -eq 1 ] && say "The relay restarts too: relayed pairs through $RELAY_NAME pause 15-25 seconds."
    printf '\n'
    reply=""
    if { exec 3<>/dev/tty; } 2>/dev/null; then
        printf '  Rotate now? [y/N] ' >&3
        IFS= read -r reply <&3 || reply=""
        exec 3>&-
    fi
    case "$reply" in
        y|Y|yes|YES) ;;
        *) die "nothing was changed." ;;
    esac
fi

# ------------------------------------------------------------ the rotation

step "rotating"

NEW_SECRET="$(openssl rand -hex 32)"
[ "${#NEW_SECRET}" -eq 64 ] || die "could not generate a new secret (openssl rand)"
if [ "$ROTATE_RELAY" -eq 1 ]; then
    NEW_RELAY="$(openssl rand -hex 32)"
    [ "${#NEW_RELAY}" -eq 64 ] || die "could not generate a new relay secret (openssl rand)"
fi

# printf is a shell builtin: the secret goes down the pipe, not onto any
# process's command line.
panel_takes() {
    printf '{"coordinator":{"shared_secret":"%s"}}\n' "$1" | panel_php "$SETTINGS_HELPER" >/dev/null
}

# 1. The panel.
panel_takes "$NEW_SECRET" || die "the panel would not take the new secret — nothing has been changed.
    Run it by hand to see why:  printf '{}' | sudo -u $WEB_USER $PHP_BIN $SETTINGS_HELPER --root=$PANEL_DIR"
ok "the panel has the new secret"

# replace_line <file> <VARIABLE> <env var holding the value>: the file with
# that one line replaced, written beside it and moved into place, keeping the
# owner and the mode. The value comes from the environment of awk, not its
# arguments.
replace_line() {
    local file=$1 name=$2 from=$3 tmp
    tmp="$(mktemp "$file.XXXXXX")" || return 1
    if ! AKCONNECT_NAME="$name" AKCONNECT_FROM="$from" awk '
            BEGIN { name = ENVIRON["AKCONNECT_NAME"]; value = ENVIRON[ENVIRON["AKCONNECT_FROM"]]; done = 0 }
            index($0, name "=") == 1 && !done { print name "=" value; done = 1; next }
            { print }
            END { if (!done) exit 3 }' "$file" > "$tmp"; then
        rm -f "$tmp"
        return 1
    fi
    chown --reference="$file" "$tmp" && chmod --reference="$file" "$tmp" && mv -f "$tmp" "$file"
}

# Put the panel back if the server's side cannot follow: a panel with the new
# secret and a coordinator with the old is the one state worse than not
# rotating at all.
undo_panel() {
    panel_takes "$OLD_SECRET" && warn "the panel was put back on the old secret; nothing else changed"
}

# 2. This server's files.
export AKCONNECT_NEW_SECRET="$NEW_SECRET"
if ! replace_line "$COORD_ENV" AKCONNECT_COORDINATOR_SECRET AKCONNECT_NEW_SECRET; then
    undo_panel
    die "could not rewrite $COORD_ENV"
fi
if ! { tmp="$(mktemp "$FOR_PANEL.XXXXXX")" && printf '%s\n' "$NEW_SECRET" > "$tmp" \
        && chown --reference="$COORD_ENV" "$tmp" && chmod 640 "$tmp" && mv -f "$tmp" "$FOR_PANEL"; }; then
    warn "could not rewrite $FOR_PANEL — the next installer re-run would put the old secret back"
fi
ok "coordinator.env and coordinator.secret.for-panel have the new secret"

if [ "$ROTATE_RELAY" -eq 1 ]; then
    export AKCONNECT_NEW_RELAY="$NEW_RELAY"
    if ! replace_line "$COORD_ENV" "$RELAY_LINE" AKCONNECT_NEW_RELAY \
            || ! replace_line "$RELAY_ENV" AKCONNECT_RELAY_SECRET AKCONNECT_NEW_RELAY; then
        # The coordinator's own secret is already new in both places; the relay
        # line must not be left different from relay.env.
        export AKCONNECT_OLD_RELAY="$OLD_RELAY"
        replace_line "$COORD_ENV" "$RELAY_LINE" AKCONNECT_OLD_RELAY
        replace_line "$RELAY_ENV" AKCONNECT_RELAY_SECRET AKCONNECT_OLD_RELAY
        warn "could not rewrite the relay secret; it was left as it was"
        ROTATE_RELAY=0
    else
        ok "relay.env and $RELAY_LINE have a new relay secret"
    fi
    unset AKCONNECT_NEW_RELAY AKCONNECT_OLD_RELAY
fi
unset AKCONNECT_NEW_SECRET

# 3. The restart. The relay first when its secret changed, so the coordinator
# comes up issuing tickets the relay already accepts.
if [ "$ROTATE_RELAY" -eq 1 ]; then
    systemctl restart akconnect-relay || die "the relay would not restart: journalctl -u akconnect-relay -n 50"
fi
systemctl restart akconnect-coordinator \
    || die "the coordinator would not restart: journalctl -u akconnect-coordinator -n 50"
for _ in 1 2 3 4 5 6 7 8 9 10; do
    systemctl is-active --quiet akconnect-coordinator && break
    sleep 1
done
systemctl is-active --quiet akconnect-coordinator \
    || die "the coordinator is not running after the restart: journalctl -u akconnect-coordinator -n 50"
ok "coordinator restarted${RELAY_NAME:+ (and relay $RELAY_NAME)}"

# 4. Proof, from outside: the panel accepts a request signed with the new
# secret and refuses one signed with the old. The key reaches python in its
# environment; the signature on curl's command line is only good for this
# timestamp.
panel_answers() {
    local key_var=$1 ts sig
    ts="$(date +%s)"
    sig="$(AKCONNECT_SIGN_FROM="$key_var" python3 -c '
import hashlib, hmac, os, sys
key = os.environ[os.environ["AKCONNECT_SIGN_FROM"]].encode()
print(hmac.new(key, (sys.argv[1] + "\n").encode(), hashlib.sha256).hexdigest())' "$ts")"
    curl -s -o /dev/null -w '%{http_code}' --max-time 20 \
        -H "X-Coordinator-Timestamp: $ts" -H "X-Coordinator-Signature: $sig" \
        -H 'Accept: application/json' "$PANEL_URL/api/v1/edge/release" 2>/dev/null
}

step "checking"

VERIFIED=0
if [ -n "$PANEL_URL" ]; then
    new_code="$(AKCONNECT_KEY_NEW="$NEW_SECRET" panel_answers AKCONNECT_KEY_NEW)"
    old_code="$(AKCONNECT_KEY_OLD="$OLD_SECRET" panel_answers AKCONNECT_KEY_OLD)"
    if [ "$new_code" = "200" ] && [ "$old_code" = "401" ]; then
        ok "the panel accepts the new secret and refuses the old one"
        VERIFIED=1
    elif [ "$new_code" = "401" ]; then
        die "the panel refuses the new secret (401). The coordinator cannot reach it until this is fixed:
        journalctl -u akconnect-coordinator -n 30"
    else
        warn "could not confirm over HTTPS (new: ${new_code:-no answer}, old: ${old_code:-no answer})."
        say "Watch the coordinator for 'panel returned 401':  journalctl -u akconnect-coordinator -f"
    fi
else
    warn "$COORD_ENV names no panel URL, so the change could not be checked from outside"
fi

OLD_SECRET=""
NEW_SECRET=""
OLD_RELAY=""
NEW_RELAY=""
release_timer

# ------------------------------------------------------------------ audit

step "what was published with the secret"

if [ -n "$AUDIT_HELPER" ]; then
    panel_php "$AUDIT_HELPER" ${SINCE:+"--since=$SINCE"}
    audit=$?
    [ "$audit" -eq 2 ] && warn "the audit found builds this edge did not make — see above"
else
    warn "no cli/edge-audit.php in $PANEL_DIR or $SRC_DIR; update the panel and run it:"
    say "    sudo -u $WEB_USER $PHP_BIN $PANEL_DIR/cli/edge-audit.php"
fi

step "done"

say "The old secret no longer works anywhere. Nothing on any agent needed to change."
[ "$ROTATE_RELAY" -eq 1 ] && say "The relay secret is new too; relayed pairs pick up new tickets by themselves."
say "It was in these places, which still hold the old value and should be cleaned up:"
say "  - wherever it was pasted (the chat), and anything that logged this server's"
say "    terminal while it was printed (1.9.7-dev.21 and earlier printed it)"
say "  - /var/log/auth.log, if it was installed with 1.9.7-dev.20 (root-only)"
say "It cannot be used to reach the panel any more."
[ "$VERIFIED" -eq 1 ] || exit 2
exit 0
