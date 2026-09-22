#!/usr/bin/env bash
#
# Bring the edge servers up to the release the panel is running.
#
# One command, on the VPS, as root:
#
#   sudo /opt/akconnect/src/deploy/upgrade-edge.sh
#
# It asks the panel which release it is on, builds the coordinator and the
# relay from exactly that commit, installs them, restarts both, checks they
# report the new version and are listening, then builds the Windows installer
# stamped for that panel and publishes it there — printing the address a
# customer can be sent.
#
#   --install-timer   also install a systemd timer that does all of the above
#                     whenever the panel moves to a new release
#   --check           say whether an upgrade is needed and exit
#   --skip-pack       skip the Windows installer (faster; services only)
#
# It never touches /etc/akconnect and never touches the panel's config.php.
# Keys and secrets are written once, by install-edge.sh, and read here only to
# authenticate to the panel.
set -uo pipefail

SRC_DIR="${AKCONNECT_SRC:-/opt/akconnect/src}"
BIN_DIR="/usr/local/bin"
ETC_DIR="/etc/akconnect"
WINTUN_URL="https://www.wintun.net/builds/wintun-0.14.1.zip"
WINTUN_ZIP="${WINTUN_ZIP:-/var/cache/akconnect/wintun.zip}"

INSTALL_TIMER=0
CHECK_ONLY=0
SKIP_PACK=0

while [ $# -gt 0 ]; do
    case "$1" in
        --install-timer) INSTALL_TIMER=1; shift ;;
        --check)         CHECK_ONLY=1; shift ;;
        --skip-pack)     SKIP_PACK=1; shift ;;
        --src)           SRC_DIR="${2:-}"; shift 2 ;;
        *) printf '  unknown option: %s\n' "$1" >&2; exit 2 ;;
    esac
done

# ------------------------------------------------------------------ reporting

RESULTS=()
FAILED=0

pass() { RESULTS+=("PASS|$1|${2:-}"); }
fail() { RESULTS+=("FAIL|$1|${2:-}"); FAILED=$((FAILED + 1)); }
step() { printf '\n\033[1m── %s\033[0m\n' "$*"; }
say()  { printf '  %s\n' "$*"; }
die()  { printf '\n  \033[31m✗ %s\033[0m\n\n' "$*" >&2; summary; exit 1; }

summary() {
    printf '\n\033[1m  Edge upgrade\033[0m\n'
    printf '  %-34s %-6s %s\n' "STEP" "RESULT" "DETAIL"
    printf '  %-34s %-6s %s\n' "----------------------------------" "------" "------------------------------"

    local line name result detail
    for line in "${RESULTS[@]}"; do
        result="${line%%|*}"
        name="${line#*|}"; name="${name%%|*}"
        detail="${line##*|}"

        if [ "$result" = "PASS" ]; then
            printf '  \033[32m%-34s %-6s\033[0m %s\n' "$name" "PASS" "$detail"
        else
            printf '  \033[31m%-34s %-6s\033[0m %s\n' "$name" "FAIL" "$detail"
        fi
    done

    if [ "$FAILED" -gt 0 ]; then
        printf '\n  \033[31m%d step(s) FAILED.\033[0m The edge is not upgraded.\n\n' "$FAILED"
    else
        printf '\n  \033[32mAll steps passed.\033[0m\n\n'
    fi
}

# ------------------------------------------------------------------ preflight

step "preflight"

[ "$(id -u)" -eq 0 ] || die "run this as root: sudo $0"

for tool in systemctl curl git go openssl python3; do
    command -v "$tool" >/dev/null 2>&1 || die "$tool is not installed. $(
        case "$tool" in
            go) printf 'Install Go 1.22 or newer: apt install golang-go, or from https://go.dev/dl/' ;;
            *)  printf 'apt install %s' "$tool" ;;
        esac)"
done
pass "tools" "systemctl, curl, git, go, openssl, python3"

[ -d "$SRC_DIR/.git" ] || die "no git checkout at $SRC_DIR.
    Clone it once:  git clone <your repo> $SRC_DIR
    Or point this at an existing one:  --src /path/to/checkout"

[ -f "$ETC_DIR/coordinator.env" ] || die "$ETC_DIR/coordinator.env is missing.
    This machine has not been set up yet — run deploy/install-edge.sh first."

# Read the panel URL and the shared secret from the environment file
# install-edge.sh wrote. Nothing here writes to it.
PANEL="$(sed -n 's/^AKCONNECT_PANEL_URL=//p' "$ETC_DIR/coordinator.env" | head -1)"
SECRET="$(sed -n 's/^AKCONNECT_COORDINATOR_SECRET=//p' "$ETC_DIR/coordinator.env" | head -1)"

[ -n "$PANEL" ]  || die "AKCONNECT_PANEL_URL is not in $ETC_DIR/coordinator.env"
[ -n "$SECRET" ] || die "AKCONNECT_COORDINATOR_SECRET is not in $ETC_DIR/coordinator.env"

PANEL="${PANEL%/}"
say "panel: $PANEL"

# ------------------------------------------------------------------- the panel

# sign <body-file> prints the HMAC the panel expects: sha256 over
# "<timestamp>\n<body>" with the coordinator's shared secret. Same scheme the
# coordinator itself uses — see CoordinatorMiddleware.
TS=""
sign() {
    TS="$(date +%s)"
    { printf '%s\n' "$TS"; cat "$1"; } \
        | openssl dgst -sha256 -mac HMAC -macopt "key:$SECRET" -hex \
        | awk '{print $NF}'
}

panel_post() {
    local path=$1 body_file=$2 out=$3
    local sig
    sig="$(sign "$body_file")"

    curl -fsS --max-time 120 \
        -H 'Content-Type: application/json' \
        -H "X-Coordinator-Timestamp: $TS" \
        -H "X-Coordinator-Signature: $sig" \
        --data-binary "@$body_file" \
        -o "$out" \
        "$PANEL$path"
}

panel_get() {
    local path=$1 out=$2
    local sig empty
    empty="$(mktemp)"
    : > "$empty"
    sig="$(sign "$empty")"
    rm -f "$empty"

    curl -fsS --max-time 60 \
        -H 'Accept: application/json' \
        -H "X-Coordinator-Timestamp: $TS" \
        -H "X-Coordinator-Signature: $sig" \
        -o "$out" \
        "$PANEL$path"
}

json() { python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['data'].get(sys.argv[2],''))" "$1" "$2"; }

# publish_artifact hands a built file to the panel and prints the address it
# will be served from. The chunked, signed upload itself is in
# publish-artifact.py — see the comment there for why it is not in shell.
publish_artifact() {
    local kind=$1 file=$2

    AKCONNECT_COORDINATOR_SECRET="$SECRET" \
        python3 "$SRC_DIR/deploy/publish-artifact.py" "$PANEL" "$kind" "$TARGET_VERSION" "$file"
}

# install_timer makes this script the thing that keeps the edge current.
#
# The edge pulls; the panel is never given a way to push. That is the whole
# reason Update Now does not do this itself: for the panel to build and restart
# services here it would need root on this machine, stored in a PHP
# application on the public internet — and this machine holds the
# coordinator's private key and the relay secrets. One compromise would take
# both. So the direction is inverted, and the trust stays where it already was:
# this machine already trusts the repository it builds from, and already
# authenticates to the panel.
install_timer() {
    local self="$SRC_DIR/deploy/upgrade-edge.sh"

    cat > /etc/systemd/system/akconnect-upgrade.service <<UNIT
[Unit]
Description=Bring the AKConnect edge up to the panel's release
Documentation=https://github.com/akshaykananidwk/network.akdwk.in/blob/main/DEPLOY.md
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
# Root, because it installs binaries into /usr/local/bin and restarts
# services. Nothing else here runs as root.
User=root
ExecStart=$self
# A build is slow on a small VPS, and a timer unit that gives up halfway
# through one leaves a half-installed edge.
TimeoutStartSec=1800
UNIT

    cat > /etc/systemd/system/akconnect-upgrade.timer <<UNIT
[Unit]
Description=Check hourly whether the AKConnect panel has moved to a new release

[Timer]
# Hourly, with a spread: an operator who installs this on several edge servers
# should not have all of them building at the same minute.
OnBootSec=10min
OnUnitActiveSec=1h
RandomizedDelaySec=15min
Persistent=true

[Install]
WantedBy=timers.target
UNIT

    systemctl daemon-reload || return 1
    systemctl enable --now akconnect-upgrade.timer || return 1

    return 0
}

step "asking the panel which release it is on"

RELEASE_JSON="$(mktemp)"
trap 'rm -f "$RELEASE_JSON"' EXIT

if ! panel_get "/api/v1/edge/release" "$RELEASE_JSON"; then
    fail "panel reachable" "$PANEL did not answer, or the shared secret does not match"
    die "could not ask the panel what to build.
    Check that $PANEL is reachable from here and that the secret in
    $ETC_DIR/coordinator.env matches Platform → Coordinator in the panel."
fi

TARGET_VERSION="$(json "$RELEASE_JSON" version)"
TARGET_COMMIT="$(json "$RELEASE_JSON" commit)"
TARGET_BRANCH="$(json "$RELEASE_JSON" branch)"

[ -n "$TARGET_VERSION" ] || die "the panel did not say which version it is running."
pass "panel reachable" "$PANEL is on $TARGET_VERSION"
say "target: $TARGET_VERSION (${TARGET_COMMIT:0:12} on ${TARGET_BRANCH:-main})"

CURRENT_COORD="$("$BIN_DIR/akconnect-coordinator" version 2>/dev/null | awk '{print $NF}')"
CURRENT_RELAY="$("$BIN_DIR/akconnect-relay" version 2>/dev/null | awk '{print $NF}')"
say "here:   coordinator ${CURRENT_COORD:-none}, relay ${CURRENT_RELAY:-none}"

if [ "$CHECK_ONLY" -eq 1 ]; then
    if [ "$CURRENT_COORD" = "$TARGET_VERSION" ] && [ "$CURRENT_RELAY" = "$TARGET_VERSION" ]; then
        pass "up to date" "both services are on $TARGET_VERSION"
        summary
        exit 0
    fi

    fail "up to date" "coordinator ${CURRENT_COORD:-none}, relay ${CURRENT_RELAY:-none}, panel $TARGET_VERSION"
    summary
    exit 1
fi

# --------------------------------------------------------------- the source

step "fetching the source"

cd "$SRC_DIR" || die "cannot enter $SRC_DIR"

if [ -n "$(git status --porcelain 2>/dev/null)" ]; then
    die "$SRC_DIR has local changes. This script checks out a specific commit and
    will not discard your work. Commit or stash it, or point --src elsewhere."
fi

REF="${TARGET_COMMIT:-${TARGET_BRANCH:-main}}"

fetched=0
for attempt in 1 2 3 4; do
    if git fetch --quiet --tags origin "${TARGET_BRANCH:-main}" 2>/dev/null || git fetch --quiet origin 2>/dev/null; then
        fetched=1
        break
    fi
    say "fetch failed; retrying in $((attempt * 2))s"
    sleep $((attempt * 2))
done
[ "$fetched" -eq 1 ] || die "could not fetch from the repository."

git checkout --quiet --detach "$REF" 2>/dev/null \
    || git checkout --quiet --detach "origin/${TARGET_BRANCH:-main}" \
    || die "cannot check out $REF"

BUILT_FROM="$(git rev-parse --short HEAD)"
REPO_VERSION="$(tr -d '[:space:]' < VERSION 2>/dev/null)"

if [ "$REPO_VERSION" != "$TARGET_VERSION" ]; then
    fail "source matches the panel" "the checkout says $REPO_VERSION, the panel says $TARGET_VERSION"
    die "the commit the panel reported does not carry the version it reported.
    Has the panel been updated from a different branch? Check Platform → Updates."
fi
pass "source matches the panel" "$REPO_VERSION at $BUILT_FROM"

# ----------------------------------------------------------------- the build

step "building the coordinator and the relay"

BUILD_DIR="$(mktemp -d)"
trap 'rm -f "$RELEASE_JSON"; rm -rf "$BUILD_DIR"' EXIT

build_one() {
    local svc=$1 out=$2
    ( cd "$SRC_DIR/services/$svc" \
        && CGO_ENABLED=0 go build -trimpath \
            -ldflags "-s -w -X main.version=$TARGET_VERSION" \
            -o "$out" "./cmd/akconnect-$svc" )
}

if ! build_one coordinator "$BUILD_DIR/akconnect-coordinator"; then
    fail "build" "the coordinator would not compile"
    die "the coordinator did not build. Nothing has been changed on this machine."
fi

if ! build_one relay "$BUILD_DIR/akconnect-relay"; then
    fail "build" "the relay would not compile"
    die "the relay did not build. Nothing has been changed on this machine."
fi

pass "build" "both binaries built from $BUILT_FROM"

# The new binaries are asked their version before anything is installed: a
# build that does not answer is not one to restart a service onto.
for svc in coordinator relay; do
    got="$("$BUILD_DIR/akconnect-$svc" version 2>/dev/null | awk '{print $NF}')"
    [ "$got" = "$TARGET_VERSION" ] || die "the new $svc reports '$got', not $TARGET_VERSION."
done
pass "new binaries report the version" "$TARGET_VERSION"

# ---------------------------------------------------------------- installing

step "installing and restarting"

# Kept, so a service that will not come up can be put back by hand.
mkdir -p /var/backups/akconnect
for svc in coordinator relay; do
    if [ -x "$BIN_DIR/akconnect-$svc" ]; then
        cp -f "$BIN_DIR/akconnect-$svc" "/var/backups/akconnect/akconnect-$svc.previous"
    fi
done
say "previous binaries kept in /var/backups/akconnect"

for svc in coordinator relay; do
    # install(1) replaces the file atomically, which matters: a running
    # service holds its inode, so this cannot corrupt the process that is
    # still serving while we work.
    install -m 0755 "$BUILD_DIR/akconnect-$svc" "$BIN_DIR/akconnect-$svc" \
        || die "could not install akconnect-$svc into $BIN_DIR"
done
pass "installed" "$BIN_DIR/akconnect-{coordinator,relay}"

for svc in coordinator relay; do
    systemctl restart "akconnect-$svc" || die "systemctl restart akconnect-$svc failed.
    Look at: journalctl -u akconnect-$svc -n 50"
done

# Given a moment before being judged: a service that is still binding is not
# a service that failed.
sleep 3

for svc in coordinator relay; do
    if systemctl is-active --quiet "akconnect-$svc"; then
        pass "akconnect-$svc running" "$(systemctl show -p ActiveEnterTimestamp --value "akconnect-$svc")"
    else
        fail "akconnect-$svc running" "$(systemctl is-active "akconnect-$svc")"
        say "journalctl -u akconnect-$svc -n 30:"
        journalctl -u "akconnect-$svc" -n 30 --no-pager | sed 's/^/    /'
    fi
done

# Listening, not merely running. A process that started and then failed to
# bind is the failure mode this catches.
check_listening() {
    local label=$1 port=$2
    if ss -lun 2>/dev/null | grep -q ":$port " || ss -ltn 2>/dev/null | grep -q ":$port "; then
        pass "$label listening" "udp/$port"
    else
        fail "$label listening" "nothing is bound to udp/$port"
    fi
}

COORD_PORT="$(sed -n 's/.*--listen[= ]:\?\([0-9]*\).*/\1/p' \
    /etc/systemd/system/akconnect-coordinator.service 2>/dev/null | head -1)"
RELAY_PORT="$(sed -n 's/.*--control[= ]:\?\([0-9]*\).*/\1/p' \
    /etc/systemd/system/akconnect-relay.service 2>/dev/null | head -1)"

check_listening "coordinator" "${COORD_PORT:-8443}"
check_listening "relay" "${RELAY_PORT:-9000}"

# What the running processes say about themselves, which is the only version
# that matters now.
NEW_COORD="$("$BIN_DIR/akconnect-coordinator" version 2>/dev/null | awk '{print $NF}')"
NEW_RELAY="$("$BIN_DIR/akconnect-relay" version 2>/dev/null | awk '{print $NF}')"

[ "$NEW_COORD" = "$TARGET_VERSION" ] \
    && pass "coordinator version" "$NEW_COORD" \
    || fail "coordinator version" "${NEW_COORD:-unknown}, wanted $TARGET_VERSION"

[ "$NEW_RELAY" = "$TARGET_VERSION" ] \
    && pass "relay version" "$NEW_RELAY" \
    || fail "relay version" "${NEW_RELAY:-unknown}, wanted $TARGET_VERSION"

# ------------------------------------------------------------- windows pack

PACK_URL=""

if [ "$SKIP_PACK" -eq 1 ]; then
    say "skipping the Windows installer (--skip-pack)"
else
    step "building the Windows installer for $PANEL"

    if [ ! -f "$WINTUN_ZIP" ]; then
        mkdir -p "$(dirname "$WINTUN_ZIP")"
        say "fetching wintun (once; cached at $WINTUN_ZIP)"
        curl -fsSL --max-time 120 -o "$WINTUN_ZIP.part" "$WINTUN_URL" \
            && mv "$WINTUN_ZIP.part" "$WINTUN_ZIP" \
            || say "could not fetch wintun"
    fi

    if [ ! -f "$WINTUN_ZIP" ]; then
        fail "windows installer" "wintun is not available; fetch $WINTUN_URL to $WINTUN_ZIP"
    elif ! WINTUN_ZIP="$WINTUN_ZIP" "$SRC_DIR/services/kit/build-windows-pack.sh" \
            "$TARGET_VERSION" "$PANEL" >"$BUILD_DIR/pack.log" 2>&1; then
        fail "windows installer" "the build failed; see $BUILD_DIR/pack.log"
        tail -12 "$BUILD_DIR/pack.log" | sed 's/^/    /'
    else
        SETUP_EXE="$(find "$SRC_DIR/services/kit" -name 'akconnect-setup.exe' -newermt '-10 minutes' | head -1)"
        [ -n "$SETUP_EXE" ] || SETUP_EXE="$SRC_DIR/services/kit/pack/akconnect-setup.exe"

        if [ ! -f "$SETUP_EXE" ]; then
            fail "windows installer" "the build reported success but no akconnect-setup.exe was found"
        else
            pass "windows installer" "$(du -h "$SETUP_EXE" | cut -f1), stamped for $PANEL"

            step "publishing it on the panel"

            if PACK_URL="$(publish_artifact "windows-setup" "$SETUP_EXE")"; then
                pass "published" "$PACK_URL"
            else
                fail "published" "the panel would not accept the upload"
            fi
        fi
    fi
fi

# ------------------------------------------------------------------ reporting

step "telling the panel what is running here"

REPORT="$(mktemp)"
cat > "$REPORT" <<JSON
{"coordinator_version":"${NEW_COORD:-}","relay_version":"${NEW_RELAY:-}","host":"$(hostname -s)"}
JSON

if panel_post "/api/v1/edge/report" "$REPORT" /dev/null; then
    pass "panel told" "coordinator ${NEW_COORD:-?}, relay ${NEW_RELAY:-?}"
else
    fail "panel told" "the report was not accepted"
fi
rm -f "$REPORT"

if [ "$INSTALL_TIMER" -eq 1 ]; then
    step "installing the upgrade timer"

    if install_timer; then
        pass "upgrade timer" "checks hourly; upgrades when the panel moves"
    else
        fail "upgrade timer" "could not install the systemd units"
    fi
fi

summary

if [ -n "$PACK_URL" ]; then
    printf '  \033[1mSend the customer this, with a join code:\033[0m\n'
    printf '    %s\n\n' "$PACK_URL"
fi

[ "$FAILED" -eq 0 ]
