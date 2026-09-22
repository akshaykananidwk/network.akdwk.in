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
# It also installs a systemd timer that does all of the above whenever the
# panel moves to a new release. That is the default from 1.9.5, because an
# edge that is only upgraded when somebody remembers to log in is an edge that
# runs an old coordinator for months — and the operator finds out when two
# customers cannot connect, not when the release is published.
#
#   --no-timer        do not install or enable the timer (it is left alone if
#                     it is already there — this does not remove one)
#   --install-timer   accepted and ignored; the timer is the default now
#   --check           say whether an upgrade is needed and exit
#   --skip-pack       skip the Windows installer (faster; services only)
#
# It never touches /etc/akconnect and never touches the panel's config.php.
# Keys and secrets are written once, by install-edge.sh, and read here only to
# authenticate to the panel.
set -uo pipefail

SRC_DIR="${AKCONNECT_SRC:-/opt/akconnect/src}"
# Overridable so the edge gate can run this end to end without writing into
# /usr/local/bin, and because a distribution that puts binaries elsewhere
# should not need this script patched.
BIN_DIR="${AKCONNECT_BIN_DIR:-/usr/local/bin}"
ETC_DIR="${AKCONNECT_ETC:-/etc/akconnect}"
WINTUN_URL="https://www.wintun.net/builds/wintun-0.14.1.zip"
WINTUN_ZIP="${WINTUN_ZIP:-/var/cache/akconnect/wintun.zip}"

# SCRIPT_REVISION is how this script decides whether another copy of itself is
# newer. It goes up whenever the script changes in a way that matters, and it
# is compared numerically — never by version string, file date or "they
# differ".
#
# It exists because "they differ" was the rule, and it handed a VPS whose
# checkout was ahead of the panel over to the PANEL's older copy — which still
# had the empty-timestamp defect, and which does not know the hand-over
# protocol, so it would not have handed back. A copy with no SCRIPT_REVISION
# line reads as 0 and is therefore never handed over to, which is exactly the
# property wanted: the guard and the revision were added together, so anything
# lacking the revision also lacks the guard.
SCRIPT_REVISION=2

# On by default since 1.9.5. --no-timer turns it off for an operator who
# manages their own scheduling.
INSTALL_TIMER=1
CHECK_ONLY=0
SKIP_PACK=0

# Kept so the script can hand them to its own replacement. See "re-exec" below.
ORIGINAL_ARGS=("$@")


# ------------------------------------------------------------------ reporting
#
# The summary is arranged so that a false pass is not something this script can
# produce. An early version could, and did: the preflight found no Go, printed
# "✗ go is not installed", and then — because die() summarised a results list
# that was still empty — finished with "All steps passed." That is the exact
# failure this whole project keeps finding in other people's code, printed by
# my own script, on the first thing an operator ran.
#
# Three separate reasons it cannot happen now:
#
#   - die() records a FAIL before it stops, so the thing that killed the run is
#     in the table;
#   - REACHED_END is set on the last line and nowhere else, and the EXIT trap
#     treats any other exit as a failure whatever the table says;
#   - a summary with no results at all is a failure, because a run that
#     checked nothing has proved nothing.

RESULTS=()
FAILED=0
REACHED_END=0
# SERVICES_INSTALLED records that the new binaries are in place and the
# services were restarted on them. It is what makes "the edge is NOT upgraded"
# either true or a lie, and the summary used to say it either way: a run where
# the coordinator and relay upgraded cleanly and only the Windows installer
# failed still ended with "FAIL — the edge is NOT upgraded", which sends an
# operator to undo work that was fine.
SERVICES_INSTALLED=0
STOPPED_BECAUSE=""
TEMP_PATHS=()

pass() { RESULTS+=("PASS|$1|${2:-}"); }
fail() { RESULTS+=("FAIL|$1|${2:-}"); FAILED=$((FAILED + 1)); }
# info records something an operator should see that is neither a success nor
# a failure — a step deliberately not taken. It must never be printed red: the
# summary printer used to treat anything that was not PASS as FAIL, so a row
# like this would have shown as a failure the count knew nothing about, which
# is a table that contradicts its own summary line.
info() { RESULTS+=("INFO|$1|${2:-}"); }
step() { printf '\n\033[1m── %s\033[0m\n' "$*"; }
say()  { printf '  %s\n' "$*"; }

# die stops the run and makes sure the reason is in the table. It does not
# print the summary itself — the EXIT trap does, exactly once.
die() {
    printf '\n  \033[31m✗ %s\033[0m\n' "$*" >&2
    STOPPED_BECAUSE="$*"
    exit 1
}

summary() {
    printf '\n\033[1m  Edge upgrade\033[0m\n'
    printf '  %-34s %-6s %s\n' "STEP" "RESULT" "DETAIL"
    printf '  %-34s %-6s %s\n' "----------------------------------" "------" "------------------------------"

    local line name result detail
    for line in ${RESULTS[@]+"${RESULTS[@]}"}; do
        result="${line%%|*}"
        name="${line#*|}"; name="${name%%|*}"
        detail="${line##*|}"

        case "$result" in
            PASS) printf '  \033[32m%-34s %-6s\033[0m %s\n' "$name" "PASS" "$detail" ;;
            INFO) printf '  \033[33m%-34s %-6s\033[0m %s\n' "$name" "INFO" "$detail" ;;
            *)    printf '  \033[31m%-34s %-6s\033[0m %s\n' "$name" "FAIL" "$detail" ;;
        esac
    done

    if [ "${#RESULTS[@]}" -eq 0 ]; then
        printf '  (nothing ran)\n'
    fi

    if [ "$FAILED" -gt 0 ] || [ "$REACHED_END" -ne 1 ] || [ "${#RESULTS[@]}" -eq 0 ]; then
        # Two different failures, and telling them apart is the whole point.
        # "The edge is NOT upgraded" is a statement about the coordinator and
        # the relay. When those are on the new release and something after
        # them failed — the Windows installer, the upload, the timer — saying
        # they are not upgraded is false, and it sends an operator to undo
        # work that was fine.
        if [ "$SERVICES_INSTALLED" -eq 1 ]; then
            printf '\n  \033[33mPARTIAL\033[0m — the coordinator and relay ARE upgraded to %s and running.' \
                "${TARGET_VERSION:-the target release}"
            if [ "$FAILED" -gt 0 ]; then
                printf '\n  %d later step(s) failed; the table above says which.' "$FAILED"
            else
                printf '\n  The run did not finish; the table above says how far it got.'
            fi
            printf '\n  Nothing needs undoing. Fix what failed and run this again.\n\n'

            return 1
        fi

        printf '\n  \033[31mFAIL\033[0m — the edge is NOT upgraded.'
        if [ "$FAILED" -gt 0 ]; then
            printf ' %d step(s) failed.' "$FAILED"
        fi
        printf '\n\n'

        return 1
    fi

    printf '\n  \033[32mPASS\033[0m — %d step(s), all clean.\n\n' "${#RESULTS[@]}"

    return 0
}

# One EXIT trap: it cleans up and it has the last word on the verdict.
#
# Anything that leaves this script other than the final line is a failure,
# including an error nobody thought to check for — which is the point. A
# summary is printed exactly once, from here.
on_exit() {
    local code=$?

    local path
    for path in ${TEMP_PATHS[@]+"${TEMP_PATHS[@]}"}; do
        rm -rf "$path"
    done

    if [ "$REACHED_END" -ne 1 ]; then
        fail "run completed" "${STOPPED_BECAUSE:-stopped before finishing (exit $code)}"
    fi

    summary || true

    if [ "$REACHED_END" -ne 1 ] || [ "$FAILED" -gt 0 ]; then
        [ "$code" -ne 0 ] && exit "$code"

        exit 1
    fi

    exit 0
}
trap on_exit EXIT

# keep registers a temporary path for the trap to remove.
keep() { TEMP_PATHS+=("$1"); }

# ------------------------------------------------------------------ options
#
# Parsed AFTER the trap is installed, deliberately. It used to come first, so
# an unrecognised option printed one line to stderr and exited 2 with no
# summary at all — no table, no PASS, no FAIL. An operator saw
#
#   unknown option:
#
# and nothing else, from a script whose entire contract is that it ends in one
# of two lines. A silent exit is the same false-pass class as a wrong PASS: in
# both cases the run did not do its job and did not say so.
#
# Now die() handles it, which records a FAIL and lets the trap print the table.
while [ $# -gt 0 ]; do
    case "$1" in
        # Kept so an existing runbook, cron entry or muscle memory does not
        # start failing on an unknown option.
        --install-timer) INSTALL_TIMER=1; shift ;;
        --no-timer)      INSTALL_TIMER=0; shift ;;
        --check)         CHECK_ONLY=1; shift ;;
        --skip-pack)     SKIP_PACK=1; shift ;;
        --src)           SRC_DIR="${2:-}"; shift 2 ;;
        *) die "unknown option: $1
    Valid options are --check, --skip-pack, --no-timer, --install-timer
    and --src <path>." ;;
    esac
done

# ------------------------------------------------------------------ preflight

step "preflight"

[ "$(id -u)" -eq 0 ] || die "run this as root: sudo $0"

# Go first, and by looking rather than by asking.
#
# The official tarball installs to /usr/local/go/bin, which is on nobody's PATH
# in a fresh root shell and certainly not under systemd — so `command -v go`
# says Go is missing on a machine where Go is installed and working. An
# operator who followed go.dev's own instructions got told to install what they
# had just installed.
find_go() {
    if command -v go >/dev/null 2>&1; then
        GO_BIN="$(command -v go)"

        return 0
    fi

    local candidate
    for candidate in /usr/local/go/bin/go /usr/lib/go/bin/go /usr/lib/go-*/bin/go \
                     /opt/go/bin/go "$HOME/go/bin/go" /snap/bin/go; do
        if [ -x "$candidate" ]; then
            GO_BIN="$candidate"
            # Exported, because the build below shells out and `go` itself
            # needs its own toolchain directory on PATH.
            PATH="$(dirname "$candidate"):$PATH"
            export PATH

            return 0
        fi
    done

    return 1
}

GO_BIN=""
find_go || die "Go is not installed, or is somewhere this script does not look.
    Looked on PATH and in: /usr/local/go/bin, /usr/lib/go/bin, /usr/lib/go-*/bin,
    /opt/go/bin, ~/go/bin, /snap/bin.
    Install it with:  apt install golang-go
    or from https://go.dev/dl/ — the tarball unpacks to /usr/local/go, which
    this script finds without PATH being set."

GO_VERSION="$("$GO_BIN" version 2>/dev/null | awk '{print $3}')"

# The services declare go 1.24, and a toolchain older than that fails deep
# inside the build with a message about a language feature. Said here instead,
# because `apt install golang-go` on an older Debian is exactly how somebody
# ends up with 1.19 and no idea why the build stopped.
GO_MAJOR="$(printf '%s' "${GO_VERSION#go}" | cut -d. -f1)"
GO_MINOR="$(printf '%s' "${GO_VERSION#go}" | cut -d. -f2)"

case "$GO_MAJOR.$GO_MINOR" in
    ''|*[!0-9.]*)
        say "could not read the Go version from '$GO_VERSION'; carrying on"
        ;;
    *)
        if [ "$GO_MAJOR" -lt 1 ] || { [ "$GO_MAJOR" -eq 1 ] && [ "$GO_MINOR" -lt 24 ]; }; then
            die "Go $GO_VERSION is too old — the services need 1.24 or newer.
    Found at: $GO_BIN
    The distribution package is often behind; https://go.dev/dl/ unpacks to
    /usr/local/go, which this script finds without PATH being set."
        fi
        ;;
esac

pass "go" "${GO_VERSION:-unknown} at $GO_BIN"

for tool in systemctl curl git openssl python3; do
    command -v "$tool" >/dev/null 2>&1 \
        || die "$tool is not installed. Install it with:  apt install $tool"
done
pass "tools" "systemctl, curl, git, openssl, python3"

# Asked of git rather than by looking for a .git directory: in a worktree
# (git worktree add) .git is a FILE, so the directory test refused a perfectly
# good checkout — and refused it at the preflight, which made a drill built on
# a worktree pass every later assertion without running any of them.
git -C "$SRC_DIR" rev-parse --git-dir >/dev/null 2>&1 || die "no git checkout at $SRC_DIR.
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

# sign <timestamp> <body-file> prints the HMAC the panel expects: sha256 over
# "<timestamp>\n<body>" with the coordinator's shared secret. Same scheme the
# coordinator itself uses — see CoordinatorMiddleware.
#
# The timestamp is an ARGUMENT, and that is the whole point. It used to be a
# global that sign() assigned:
#
#     TS=""
#     sign() { TS="$(date +%s)"; ... }
#     sig="$(sign "$file")"          # <-- a subshell
#
# `$( )` is a subshell, so the assignment never reached the caller. Every
# request went out with `X-Coordinator-Timestamp: ` — and curl silently drops a
# header with an empty value rather than sending an empty one, so the panel saw
# no timestamp at all, answered 401, and logged "missing signature headers".
# The signature was correct the whole time; nothing was there to check it
# against.
#
# A function that returns one value through stdout and another through a global
# is asking for this. Both values now come from the caller, where they are used.
sign() {
    { printf '%s\n' "$1"; cat "$2"; } \
        | openssl dgst -sha256 -mac HMAC -macopt "key:$SECRET" -hex \
        | awk '{print $NF}'
}

# send_signed <method> <path> <body-file|""> <out> [curl args...]
#
# One place that builds the headers, so there is one place for them to be
# wrong. The emptiness check is not paranoia: an empty header value is exactly
# what curl throws away without telling anyone, which is how this reached a
# customer as an unexplained 401.
send_signed() {
    local method=$1 path=$2 body_file=$3 out=$4
    shift 4

    local ts sig
    ts="$(date +%s)"
    sig="$(sign "$ts" "$body_file")"

    if [ -z "$ts" ] || [ -z "$sig" ]; then
        say "refusing to send an unsigned request to $path (timestamp='$ts', signature='${sig:+set}')"
        return 1
    fi

    # Not curl -f. With -f the body is discarded and the exit code is 22 for
    # every HTTP error alike, so a 401 that the panel explained in its answer
    # arrives here as "error 22" and the operator is sent to check a secret
    # that was never wrong. The status is read instead, and named.
    local code
    code="$(curl -sS \
        -X "$method" \
        -H "X-Coordinator-Timestamp: $ts" \
        -H "X-Coordinator-Signature: $sig" \
        -o "$out" \
        -w '%{http_code}' \
        "$@" \
        "$PANEL$path")" || return 1

    case "$code" in
        2*) return 0 ;;
        401|403)
            say "the panel refused this request (HTTP $code). The shared secret in"
            say "$ETC_DIR/coordinator.env must match Platform → Coordinator in the panel."
            return 1
            ;;
        000)
            say "$PANEL could not be reached at all."
            return 1
            ;;
        *)
            say "the panel answered HTTP $code for $path."
            return 1
            ;;
    esac
}

panel_post() {
    local path=$1 body_file=$2 out=$3

    send_signed POST "$path" "$body_file" "$out" \
        --max-time 120 \
        -H 'Content-Type: application/json' \
        --data-binary "@$body_file"
}

panel_get() {
    local path=$1 out=$2
    local empty status

    empty="$(mktemp)"
    : > "$empty"

    send_signed GET "$path" "$empty" "$out" \
        --max-time 60 \
        -H 'Accept: application/json'
    status=$?

    rm -f "$empty"

    return $status
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
    # Overridable so the gate can install the units into a temporary directory
    # and read them back. It is not a configuration option: an operator has no
    # reason to move these, and the default is the only place systemd looks.
    local units="${AKCONNECT_SYSTEMD_DIR:-/etc/systemd/system}"

    mkdir -p "$units" || return 1

    cat > "$units/akconnect-upgrade.service" <<UNIT
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
# systemd gives a unit a minimal PATH that does not include /usr/local/go/bin,
# which is where the official Go tarball puts itself. The script looks there
# anyway, and this is belt and braces for the same problem: a timer that could
# not find the toolchain would fail quietly once an hour.
Environment=PATH=/usr/local/go/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
Environment=HOME=/root
ExecStart=$self
# A build is slow on a small VPS, and a timer unit that gives up halfway
# through one leaves a half-installed edge.
TimeoutStartSec=1800
UNIT

    cat > "$units/akconnect-upgrade.timer" <<UNIT
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
keep "$RELEASE_JSON"

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

# ------------------------------------------------------------------ re-exec
#
# Fetch now, and hand over to the target's own copy of this script.
#
# Two things went wrong without this, and the second is the dangerous one.
#
# The operator ran the copy that happened to be in the checkout, which is the
# PREVIOUS release's copy — so a fix to this script did not take effect until
# somebody ran it twice, and the run that was supposed to deliver the fix was
# made by the code the fix replaced. That is how a release went out with the
# empty X-Coordinator-Timestamp still in it.
#
# Worse: the checkout below rewrites this file while bash is reading it. Bash
# reads a script incrementally, by byte offset, so replacing it mid-run makes
# the shell resume at an offset into different text — which produces a syntax
# error somewhere unrelated, or silently runs the wrong lines. It is the same
# failure that killed a release-gate run in this project when a lab script was
# edited while it executed.
#
# So: fetch (which touches no working file), take the target's copy out of git
# into a temporary file, and exec it from there. The replacement runs from a
# path nothing will rewrite, and it is the code the panel actually shipped.
step "fetching the source"

cd "$SRC_DIR" || die "cannot enter $SRC_DIR"

if [ -n "$(git status --porcelain 2>/dev/null)" ]; then
    die "$SRC_DIR has local changes. This script checks out a specific commit and
    will not discard your work. Commit or stash it, or point --src elsewhere."
fi

fetched=0
for attempt in 1 2 3 4; do
    if git fetch --quiet --tags origin "${TARGET_BRANCH:-main}" 2>/dev/null \
        || git fetch --quiet origin 2>/dev/null; then
        fetched=1
        break
    fi
    say "fetch failed; retrying in $((attempt * 2))s"
    sleep $((attempt * 2))
done
[ "$fetched" -eq 1 ] || die "could not fetch from the repository."

REF="${TARGET_COMMIT:-origin/${TARGET_BRANCH:-main}}"
git rev-parse --quiet --verify "$REF^{commit}" >/dev/null 2>&1 \
    || REF="origin/${TARGET_BRANCH:-main}"

pass "source fetched" "$(git rev-parse --short "$REF") is available locally"

# AKCONNECT_REEXEC stops this happening twice. One hop is always enough: the
# script it hands over to IS the target's, so that one has nothing newer to go
# to. Without the guard, a target whose copy differs from itself — which cannot
# happen, but a bug could make it look that way — would loop forever.
if [ -z "${AKCONNECT_REEXEC:-}" ]; then
    THEIRS="$(mktemp /tmp/akconnect-upgrade-edge.XXXXXX.sh)"

    if git show "$REF:deploy/upgrade-edge.sh" > "$THEIRS" 2>/dev/null && [ -s "$THEIRS" ]; then
        # Read out of the file, not sourced: sourcing it would run it.
        THEIR_REVISION="$(sed -n 's/^SCRIPT_REVISION=\([0-9][0-9]*\)$/\1/p' "$THEIRS" | head -1)"
        THEIR_REVISION="${THEIR_REVISION:-0}"

        if [ "$THEIR_REVISION" -gt "$SCRIPT_REVISION" ]; then
            chmod +x "$THEIRS"
            say "the release carries a newer copy of this script (revision $THEIR_REVISION); handing over"

            # Zero arguments must arrive as zero arguments.
            #
            # This was "${ORIGINAL_ARGS[@]:-}", which expands to ONE EMPTY
            # STRING when the array is empty — so a plain `upgrade-edge.sh`
            # with no options handed its replacement an argument of "", and
            # the replacement answered "unknown option:" and stopped. The
            # :- was put there to satisfy set -u and is the wrong fix; the
            # right one is to not pass an array that has nothing in it.
            if [ "${#ORIGINAL_ARGS[@]}" -gt 0 ]; then
                AKCONNECT_REEXEC="$THEIRS" exec "$THEIRS" "${ORIGINAL_ARGS[@]}"
            fi

            AKCONNECT_REEXEC="$THEIRS" exec "$THEIRS"
        fi

        if [ "$THEIR_REVISION" -lt "$SCRIPT_REVISION" ]; then
            say "the release's copy of this script is older (revision $THEIR_REVISION); keeping this one"
        fi
    fi

    rm -f "$THEIRS"
else
    # Handed over to. The temporary copy is ours to clean up, and the version
    # line is worth printing because the operator typed a different path.
    keep "${AKCONNECT_REEXEC}"
    say "running the release's own copy of this script"
fi

CURRENT_COORD="$("$BIN_DIR/akconnect-coordinator" version 2>/dev/null | awk '{print $NF}')"
CURRENT_RELAY="$("$BIN_DIR/akconnect-relay" version 2>/dev/null | awk '{print $NF}')"
say "here:   coordinator ${CURRENT_COORD:-none}, relay ${CURRENT_RELAY:-none}"

if [ "$CHECK_ONLY" -eq 1 ]; then
    if [ "$CURRENT_COORD" = "$TARGET_VERSION" ] && [ "$CURRENT_RELAY" = "$TARGET_VERSION" ]; then
        pass "up to date" "both services are on $TARGET_VERSION"
    else
        fail "up to date" "coordinator ${CURRENT_COORD:-none}, relay ${CURRENT_RELAY:-none}, panel $TARGET_VERSION"
    fi

    REACHED_END=1

    exit 0
fi

# --------------------------------------------------------------- the source
#
# Already fetched, above, before the hand-over. What is left is to put the
# working tree on the target commit.
#
# On the BRANCH at that commit, not detached. A detached HEAD is what this used
# to leave behind, and the next person to run `git pull` in the checkout got
# "You are not currently on a branch" — so the ordinary thing an operator does
# to update a checkout stopped working, silently, on every edge server this
# script had ever touched.
#
# -B moves the local branch to the target commit and stays on it. The commit
# came from the panel and is on that branch, so this is a fast-forward in every
# case that is not somebody having rewritten history; `git pull` afterwards
# behaves exactly as it would on a fresh clone.

step "putting the checkout on the release"

cd "$SRC_DIR" || die "cannot enter $SRC_DIR"

git checkout --quiet -B "${TARGET_BRANCH:-main}" "$REF" 2>/dev/null \
    || die "cannot put $SRC_DIR on ${TARGET_BRANCH:-main} at $REF"

BUILT_FROM="$(git rev-parse --short HEAD)"
ON_BRANCH="$(git rev-parse --abbrev-ref HEAD)"

if [ "$ON_BRANCH" = "HEAD" ]; then
    fail "checkout is on a branch" "the checkout is detached; git pull will refuse to run here"
else
    pass "checkout is on a branch" "$ON_BRANCH at $BUILT_FROM — git pull still works here"
fi
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
keep "$BUILD_DIR"

build_one() {
    local svc=$1 out=$2
    ( cd "$SRC_DIR/services/$svc" \
        && CGO_ENABLED=0 "$GO_BIN" build -trimpath \
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
SERVICES_INSTALLED=1

# The unit files come with the release, not only the binaries.
#
# They carry the flags a version needs, and 1.9.6 is the case that proves it:
# the relay's HTTPS fallback is switched on by --ws-listen, and an upgrade that
# replaced the binary and left a 1.9.5 unit behind would install the fallback
# and never start it. Nobody would notice until a device on a blocked network
# failed, which is months later and somewhere else.
units_changed=0
for svc in coordinator relay; do
    unit="$SRC_DIR/deploy/systemd/akconnect-$svc.service"
    [ -f "$unit" ] || continue

    if ! cmp -s "$unit" "/etc/systemd/system/akconnect-$svc.service"; then
        install -m 644 "$unit" "/etc/systemd/system/akconnect-$svc.service" \
            || die "could not install the akconnect-$svc unit"
        units_changed=1
    fi
done

if [ "$units_changed" -eq 1 ]; then
    systemctl daemon-reload || die "systemctl daemon-reload failed"
    pass "unit files" "refreshed from $BUILT_FROM"
else
    pass "unit files" "already current"
fi

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

# The HTTPS fallback, which is how a device on a network that carries no UDP
# connects at all. Checked on every upgrade rather than assumed: an Apache
# package update can disable a module, and the failure is silent until a
# customer takes a laptop somewhere with a strict firewall.
step "the HTTPS fallback"

# shellcheck source=lib-edge-apache.sh
. "$SRC_DIR/deploy/lib-edge-apache.sh"

if ss -ltn 2>/dev/null | grep -q '127.0.0.1:9443 '; then
    pass "relay fallback listener" "127.0.0.1:9443"
else
    fail "relay fallback listener" "nothing is bound to 127.0.0.1:9443"
fi

akconnect_apache_fallback "$SRC_DIR/deploy/apache" say
case "$?" in
    0) pass "Apache proxy" "/fallback → 127.0.0.1:9443" ;;
    1) say "no Apache here; the fallback must be proxied wherever the panel is served" ;;
    *) fail "Apache proxy" "Apache would not take the fallback configuration" ;;
esac

if akconnect_fallback_reachable "$PANEL"; then
    pass "fallback reachable" "$PANEL/fallback/health"
else
    fail "fallback reachable" "$PANEL/fallback/health did not answer"
fi

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

    # Where the target release's builder will put things.
    #
    # This script and the builder it runs come from two different releases
    # whenever the panel is behind: the script is whatever the operator has,
    # the builder is the target's. AKCONNECT_BUILD_DIR arrived in 1.9.4, so a
    # target older than that ignores it and writes into services/kit — and the
    # run then failed with "the build reported success but no
    # akconnect-setup.exe was found", having quietly dirtied the checkout on
    # the way, so the NEXT run refused as well.
    #
    # Asking the builder what it understands is the only honest way to know.
    PACK_BUILDER="$SRC_DIR/services/kit/build-windows-pack.sh"
    LEGACY_PACK=0

    if grep -q 'AKCONNECT_BUILD_DIR' "$PACK_BUILDER" 2>/dev/null; then
        PACK_OUT="$BUILD_DIR/kit"
    else
        # Its own default, which is inside the checkout. Cleaned up below,
        # because leaving it there is what breaks the next run.
        PACK_OUT="$SRC_DIR/services/kit"
        LEGACY_PACK=1
        say "this release's pack builder predates AKCONNECT_BUILD_DIR; using its own output path"
    fi

    if [ ! -f "$WINTUN_ZIP" ]; then
        fail "windows installer" "wintun is not available; fetch $WINTUN_URL to $WINTUN_ZIP"
    elif ! WINTUN_ZIP="$WINTUN_ZIP" AKCONNECT_BUILD_DIR="$PACK_OUT" \
            "$PACK_BUILDER" \
            "$TARGET_VERSION" "$PANEL" >"$BUILD_DIR/pack.log" 2>&1; then
        fail "windows installer" "the build failed; see $BUILD_DIR/pack.log"
        tail -12 "$BUILD_DIR/pack.log" | sed 's/^/    /'
    else
        SETUP_EXE="$PACK_OUT/pack/akconnect-setup.exe"

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

            # The bare agent as well, as the windows-agent kind. That upload is
            # what the panel signs and offers to already-installed devices
            # (§14), so publishing it is the difference between fixing a defect
            # once and visiting every PC to fix it.
            AGENT_EXE="$PACK_OUT/pack/akconnect-agent.exe"

            if [ ! -f "$AGENT_EXE" ]; then
                fail "self-update" "the pack build left no akconnect-agent.exe to publish"
            elif publish_artifact "windows-agent" "$AGENT_EXE" >/dev/null; then
                pass "self-update" "installed devices will be offered $TARGET_VERSION"
            else
                fail "self-update" "the panel would not accept the agent binary"
            fi
        fi
    fi

    # An old builder wrote inside the checkout, so the checkout has to be put
    # back. Not optional and not best-effort: leaving it dirty is exactly what
    # makes the next run refuse with "has local changes", which is how one
    # skew defect turns into a second one that looks unrelated.
    #
    # Targeted, never `git clean`: the operator's own files in that directory
    # are none of this script's business.
    if [ "$LEGACY_PACK" -eq 1 ]; then
        rm -rf "$PACK_OUT/pack" "$PACK_OUT/dist"
        rm -f "$PACK_OUT/akconnect-windows-test-pack.zip"

        # The zip was a tracked file in those releases, so removing it leaves a
        # deletion rather than a clean tree. git restores exactly that path.
        git -C "$SRC_DIR" checkout --quiet -- services/kit 2>/dev/null || true

        if [ -z "$(git -C "$SRC_DIR" status --porcelain 2>/dev/null)" ]; then
            pass "checkout left clean" "the old builder's output was removed"
        else
            fail "checkout left clean" \
                "$SRC_DIR is still dirty; the next run will refuse with 'has local changes'"
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
else
    # Recorded rather than silent: an operator who passed --no-timer months
    # ago and has forgotten should be able to see, in this table, why their
    # edge is not keeping itself current.
    info "upgrade timer" "not installed (--no-timer); this edge upgrades only when you run this"
fi

if [ -n "$PACK_URL" ] && [ "$FAILED" -eq 0 ]; then
    printf '\n  \033[1mSend the customer this, with a join code:\033[0m\n'
    printf '    %s\n' "$PACK_URL"
fi

# The last line, and the only place this is set. The EXIT trap prints the
# summary and decides the exit status from here.
REACHED_END=1
