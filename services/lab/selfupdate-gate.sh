#!/usr/bin/env bash
# Does the agent actually replace itself, and does it refuse when it should?
#
# §14 says a defect must not mean visiting every machine. That claim is only
# worth making if the swap has been watched happening, so this drill watches
# it: a real panel, a real enrolled device, a real signed release, and a real
# binary on disk that is a different version afterwards.
#
# The refusals matter more than the install. An agent that replaces its own
# executable because an HTTP response told it to is a remote code execution
# path into every customer machine, so two of the four checks here publish a
# release that must NOT be installed — one signed by the wrong key, one whose
# advertised digest the file does not have — and assert the old binary is
# still running afterwards.
#
# Windows is not covered. The verify-and-swap is the same code, but the
# restart afterwards is Windows-specific and has never run; stage 17 of the
# field runbook is where that evidence comes from.
#
#   ./services/lab/selfupdate-gate.sh
set -uo pipefail

LAB="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$LAB/../.." && pwd)"

PORT="${SELFUPDATE_GATE_PORT:-8097}"
PANEL="http://127.0.0.1:$PORT"
WORK="$LAB/.run/selfupdate"
LOCAL_CONFIG="$REPO/config/config.local.php"

OLD_VERSION="1.0.0-gate"
NEW_VERSION="9.9.9-gate"
# A third version for the drill that matters most: the one nobody runs a
# command for. See "a running agent updates itself" below.
AUTO_VERSION="9.9.10-gate"

PASS=0
FAIL=0
SERVER_PID=""
AGENT_PID=""
TENANT=""
WROTE_CONFIG=0

pass() { printf '  \033[32m✓\033[0m %s\n' "$1"; PASS=$((PASS + 1)); }
fail() { printf '  \033[31m✗\033[0m %s\n' "$1"; [ $# -gt 1 ] && printf '      %s\n' "$2"; FAIL=$((FAIL + 1)); }
step() { printf '\n\033[1m── %s\033[0m\n' "$1"; }
die()  { printf '\n  \033[31m✗ %s\033[0m\n\n' "$1"; FAIL=$((FAIL + 1)); exit 1; }

cleanup() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null
    [ -n "$AGENT_PID" ] && kill "$AGENT_PID" 2>/dev/null
    php "$LAB/publish-agent.php" --withdraw "$NEW_VERSION" >/dev/null 2>&1
    php "$LAB/publish-agent.php" --withdraw "$AUTO_VERSION" >/dev/null 2>&1
    [ -n "$TENANT" ] && php "$LAB/lab-setup.php" teardown "$TENANT" >/dev/null 2>&1
    # Only ours goes. An operator's own pin must survive a drill.
    [ "$WROTE_CONFIG" = "1" ] && rm -f "$LOCAL_CONFIG"

    printf '\n  ────────────────────────────────────────────────────────────\n'
    if [ "$FAIL" -eq 0 ]; then
        printf '  \033[32m%d passed, 0 failed\033[0m\n\n' "$PASS"
    else
        printf '  \033[31m%d passed, %d failed\033[0m\n\n' "$PASS" "$FAIL"
    fi
}
trap cleanup EXIT

# ------------------------------------------------------------------ preflight

step "preflight"

command -v go >/dev/null   || die "go is not installed"
command -v jq >/dev/null   || die "jq is not installed"
php -r 'exit(0);'          || die "php is not usable"

# The gate pins app.url at its own server, because the panel puts an absolute
# download address in the offer and the agent refuses one that is not on the
# panel it enrolled with — which is the behaviour being relied on, not worked
# around.
if [ -f "$LOCAL_CONFIG" ]; then
    die "config/config.local.php already exists; this drill would overwrite an operator's pin"
fi

cat > "$LOCAL_CONFIG" <<PHPCONF
<?php
// Written by services/lab/selfupdate-gate.sh; removed when it exits.
return ['app' => ['url' => '$PANEL']];
PHPCONF
WROTE_CONFIG=1
pass "app.url pinned at $PANEL for the duration"

rm -rf "$WORK"
mkdir -p "$WORK/state"

step "building two versions of the agent"

( cd "$REPO/services/agent" \
    && GOTOOLCHAIN=local CGO_ENABLED=0 go build \
        -ldflags "-X main.version=$OLD_VERSION" -o "$WORK/akconnect-agent" ./cmd/akconnect-agent ) \
    || die "could not build the old agent"

( cd "$REPO/services/agent" \
    && GOTOOLCHAIN=local CGO_ENABLED=0 go build \
        -ldflags "-X main.version=$NEW_VERSION" -o "$WORK/new-agent" ./cmd/akconnect-agent ) \
    || die "could not build the new agent"

( cd "$REPO/services/agent" \
    && GOTOOLCHAIN=local CGO_ENABLED=0 go build \
        -ldflags "-X main.version=$AUTO_VERSION" -o "$WORK/auto-agent" ./cmd/akconnect-agent ) \
    || die "could not build the agent for the automatic drill"

AGENT="$WORK/akconnect-agent"
export AKCONNECT_STATE_DIR="$WORK/state"

[ "$("$AGENT" version)" = "akconnect-agent $OLD_VERSION" ] \
    || die "the old binary reports $("$AGENT" version)"
pass "the installed binary is $OLD_VERSION"

# ---------------------------------------------------------------- the panel

step "a panel with a device enrolled against it"

php -S "127.0.0.1:$PORT" -t "$REPO" "$REPO/tests/dev-server.php" >"$WORK/panel.log" 2>&1 &
SERVER_PID=$!

# Quiet while it is starting: "connection refused" on the first try is the
# expected state, not news, and printing it makes a clean run look broken.
for _ in $(seq 1 20); do
    curl -s -o /dev/null "$PANEL/" 2>/dev/null && break
    sleep 0.5
done
curl -sS -o /dev/null "$PANEL/" 2>/dev/null \
    || die "the panel never answered on $PANEL (see $WORK/panel.log)"
pass "panel answering on $PANEL"

php "$LAB/lab-setup.php" unthrottle >/dev/null 2>&1

SEED="$(php "$LAB/lab-setup.php" seed)" || die "could not seed a tenant"
TENANT="$(awk -F= '/^TENANT=/ {print $2}' <<<"$SEED")"
CODE="$(awk -F= '/^JOIN_CODE=/ {print $2}' <<<"$SEED")"
[ -n "$CODE" ] || die "the seed produced no join code"

"$AGENT" enroll --panel "$PANEL" --join-code "$CODE" --name selfupdate-gate --wait \
    >"$WORK/enroll.log" 2>&1 &

UID_DEV=""
for _ in $(seq 1 30); do
    if [ -f "$WORK/state/state.json" ]; then
        UID_DEV="$(jq -r '.device_uid // empty' "$WORK/state/state.json" 2>/dev/null)"
        [ -n "$UID_DEV" ] && break
    fi
    sleep 1
done
[ -n "$UID_DEV" ] || die "the agent never registered (see $WORK/enroll.log)"

php "$LAB/lab-setup.php" approve "$UID_DEV" >/dev/null || die "could not approve the device"

for _ in $(seq 1 60); do
    jq -e -r '.device_token // empty' "$WORK/state/state.json" >/dev/null 2>&1 && break
    sleep 1
done
jq -e -r '.device_token // empty' "$WORK/state/state.json" >/dev/null 2>&1 \
    || die "the agent never claimed its token (see $WORK/enroll.log)"
pass "device $UID_DEV enrolled and approved"

# --------------------------------------------------------------- the drills
#
# running_version reads the binary rather than the log, because the whole
# question is what is on disk.
running_version() { "$AGENT" version 2>/dev/null | awk '{print $2}'; }

step "with nothing published, nothing is offered"

OUT="$("$AGENT" update --check 2>&1)"
if grep -q "Nothing newer is offered" <<<"$OUT"; then
    pass "update --check says there is nothing"
else
    fail "update --check invented an offer" "$(tr '\n' ' ' <<<"$OUT" | cut -c1-160)"
fi

step "a release signed by the wrong key is refused"

php "$LAB/publish-agent.php" "$NEW_VERSION" "$WORK/new-agent" linux amd64 --tamper=sig >/dev/null \
    || die "could not publish the wrongly-signed release"

OUT="$("$AGENT" update 2>&1)"
STATUS=$?

if [ "$STATUS" -ne 0 ] && grep -q "does not verify" <<<"$OUT"; then
    pass "refused, and says the signature does not verify"
else
    fail "a release signed by another key was not refused" "exit $STATUS · $(tr '\n' ' ' <<<"$OUT" | cut -c1-160)"
fi

if [ "$(running_version)" = "$OLD_VERSION" ]; then
    pass "and the old binary is still the one on disk"
else
    fail "the binary was replaced anyway" "now $(running_version)"
fi

step "a release whose bytes do not match its digest is refused"

php "$LAB/publish-agent.php" "$NEW_VERSION" "$WORK/new-agent" linux amd64 --tamper=digest >/dev/null \
    || die "could not publish the mismatched release"

OUT="$("$AGENT" update 2>&1)"
STATUS=$?

if [ "$STATUS" -ne 0 ] && grep -q "checksum" <<<"$OUT"; then
    pass "refused, and says the checksum does not match"
else
    fail "a mismatched download was not refused" "exit $STATUS · $(tr '\n' ' ' <<<"$OUT" | cut -c1-160)"
fi

if [ "$(running_version)" = "$OLD_VERSION" ]; then
    pass "and the old binary is still the one on disk"
else
    fail "the binary was replaced anyway" "now $(running_version)"
fi

step "a properly signed release is installed"

php "$LAB/publish-agent.php" "$NEW_VERSION" "$WORK/new-agent" linux amd64 >/dev/null \
    || die "could not publish the signed release"

OUT="$("$AGENT" update 2>&1)"
STATUS=$?

if [ "$STATUS" -eq 0 ]; then
    pass "the update ran to completion"
else
    fail "the update failed" "exit $STATUS · $(tr '\n' ' ' <<<"$OUT" | cut -c1-200)"
fi

grep -q "signed by this panel's controller key" <<<"$OUT" \
    && pass "and said what it verified" \
    || fail "it installed without saying the signature verified" "$(tr '\n' ' ' <<<"$OUT" | cut -c1-160)"

if [ "$(running_version)" = "$NEW_VERSION" ]; then
    pass "the binary on disk is now $NEW_VERSION"
else
    fail "the binary on disk is still $(running_version)" "the swap did not happen"
fi

# The old binary is kept rather than deleted: on Windows it cannot be deleted
# while it is running, and it is the only thing to put back if the new one
# does not start.
if [ -f "$AGENT.old" ] && [ "$("$AGENT.old" version | awk '{print $2}')" = "$OLD_VERSION" ]; then
    pass "the previous binary was kept beside it"
else
    fail "the previous binary was not kept" "nothing to roll back to"
fi

step "and the next start cleans up after it"

# "up" is the path that runs CleanPrevious, and it is the only one: a CLI
# invocation while the service is running would be trying to delete the image
# the service is executing, which on Windows cannot be done. It is run for a
# few seconds and killed — bringing a tunnel up is not what is being tested.
timeout 8 "$AGENT" up --iface akc-gate --port 51999 >"$WORK/up.log" 2>&1
sleep 1

if [ ! -f "$AGENT.old" ]; then
    pass "the previous binary was removed at the next start"
else
    fail "the previous binary is still there" "CleanPrevious did not run; see $WORK/up.log"
fi

grep -q "removed the previous binary" "$WORK/up.log" \
    && pass "and it said so in the log" \
    || fail "it removed the binary without saying so" "an unexplained version change is a support call"

# ------------------------------------------------- the drill that matters
#
# Everything above runs `agent update`, which is a person typing a command.
# Nobody typed a command on the all-in-one that stayed on 1.9.3 for days after
# 1.9.4 was published: the automatic path is a different function, runs inside
# the poll loop, and had nothing proving it at all.
#
# So this one publishes a release and then does nothing but wait. The only
# thing that happens is an administrator pressing "Update now" in the panel,
# which is the product's own path and the one the owner actually pressed.

step "a running agent updates itself, with nobody typing anything"

php "$LAB/publish-agent.php" "$AUTO_VERSION" "$WORK/auto-agent" linux amd64 >/dev/null \
    || die "could not publish the release for the automatic drill"

php "$LAB/lab-setup.php" update-now "$UID_DEV" >/dev/null \
    || die "could not ask the panel to update the device"
pass "the panel is asked to update $UID_DEV, the way the button does"

# Run it as the service runs it: a process that stays up and polls. Nothing
# below invokes `update`.
"$AGENT" up --iface akc-gate2 --port 51998 >"$WORK/auto.log" 2>&1 &
AGENT_PID=$!

INSTALLED=""
for _ in $(seq 1 60); do
    if [ "$(running_version)" = "$AUTO_VERSION" ]; then
        INSTALLED=yes
        break
    fi
    sleep 2
done

if [ -n "$INSTALLED" ]; then
    pass "the binary on disk became $AUTO_VERSION without any command being run"
else
    fail "the running agent never updated itself" \
        "still $(running_version); see $WORK/auto.log"
fi

# And the half that makes a stalled fleet visible. A machine that never
# checked and a machine that refused a bad signature both look like a version
# number that has not moved; only this tells them apart.
STATE=""
for _ in $(seq 1 30); do
    STATE="$(php "$LAB/lab-setup.php" update-state "$UID_DEV" 2>/dev/null)"
    case "$STATE" in installed\|*) break ;; esac
    sleep 2
done

case "$STATE" in
    "installed|$AUTO_VERSION|")
        pass "and the panel shows it installed $AUTO_VERSION"
        ;;
    installed\|*)
        fail "the panel shows the wrong version installed" "$STATE"
        ;;
    *)
        fail "the panel was never told what the device did" "reported: ${STATE:-nothing}"
        ;;
esac

kill "$AGENT_PID" 2>/dev/null
AGENT_PID=""

# ---------------------------------------------- the channel that hid six builds
#
# A panel deliberately running development builds offered its own devices
# nothing, for six releases, while every publish on the edge reported success.
# The agent sends no channel — it has no way to know one and should not, since
# a fleet's release channel is one decision on the panel — so the panel
# defaulted to stable and every device was treated as a Stable device.
#
# This drill could never have caught it: until now publish-agent.php wrote
# channel 'stable' for everything, so the dev path did not exist in the lab at
# all. A gate that cannot reach a path cannot defend it.
step "a panel on Edge offers development builds to its own devices"

DEV_VERSION="9.9.20-dev.1"
php "$LAB/publish-agent.php" "$DEV_VERSION" "$WORK/new-agent" linux amd64 >/dev/null \
    || die "could not publish the development build"

# A panel on Stable must NOT be offered it: publishing a dev build to every
# customer is the opposite mistake and just as bad.
php "$LAB/lab-setup.php" set-channel stable >/dev/null 2>&1
OFFER="$(php "$LAB/lab-setup.php" offered "$UID_DEV" 2>/dev/null)"

case "$OFFER" in
    *"$DEV_VERSION"*)
        fail "a Stable panel was offered a development build" \
            "publishing a dev build at every customer is the opposite mistake"
        ;;
    *)
        pass "a Stable panel is not offered a development build"
        ;;
esac

# And a panel on Edge must be.
php "$LAB/lab-setup.php" set-channel edge >/dev/null 2>&1
OFFER="$(php "$LAB/lab-setup.php" offered "$UID_DEV" 2>/dev/null)"

case "$OFFER" in
    *"$DEV_VERSION"*)
        pass "a panel on Edge is offered $DEV_VERSION"
        ;;
    *)
        fail "a panel on Edge was offered nothing" \
            "this is the defect that hid six releases from a live fleet; got '$OFFER'"
        ;;
esac

php "$LAB/lab-setup.php" set-channel stable >/dev/null 2>&1
php "$LAB/publish-agent.php" --withdraw "$DEV_VERSION" >/dev/null 2>&1
