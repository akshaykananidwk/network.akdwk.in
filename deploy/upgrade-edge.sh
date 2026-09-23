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
#   --force           rebuild and restart even when already on the release
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
SCRIPT_REVISION=3

# On by default since 1.9.5. --no-timer turns it off for an operator who
# manages their own scheduling.
INSTALL_TIMER=1
CHECK_ONLY=0
SKIP_PACK=0
# Rebuild, reinstall and restart even when everything is already on the
# panel's release. Without it a run that finds nothing to do says so and stops.
FORCE=0

# Kept so the script can hand them to its own replacement. See "re-exec" below.
# The directory too: the replacement is started from it, so a relative --src
# means what the operator meant and not a path inside the checkout.
ORIGINAL_ARGS=("$@")
ORIGINAL_PWD="$PWD"

# Options this copy does not know. Not fatal until the hand-over has been
# decided: an option a newer release added is refused by the older copy that
# the operator runs, before it could hand over to the copy that knows it —
# `--force` on an edge still carrying the previous release's script.
UNKNOWN_OPTIONS=()


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
#   - REACHED_END is set only where a run deliberately ends — the --check
#     report, "already current", and the last line — each followed at once by
#     the exit, and the EXIT trap treats any other exit as a failure whatever
#     the table says;
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

# UNATTENDED is whether nobody is watching this run.
#
# It decides one thing and it is the important one: an unattended run never
# edits Apache. The server this is deployed on has thirty-odd live websites on
# the same Apache, and a timer that reaches into their web server once an hour
# — to repair something, to re-assert something, for any reason at all — is
# not a thing to have. First-time setup is a decision a person makes, once,
# with the output in front of them.
#
# Detected two ways because both are needed. The timer unit passes
# --unattended, which is exact; and INVOCATION_ID is set by systemd for every
# unit it starts, which covers the window between this release landing and the
# unit being refreshed — an hourly timer installed by 1.9.5 would otherwise
# get one free pass at somebody's production Apache.
UNATTENDED=0
[ -n "${INVOCATION_ID:-}" ] && UNATTENDED=1

# CONFIGURE_APACHE is unset until an option or the line below decides. Empty
# means "decide from whether anybody is watching".
CONFIGURE_APACHE=""

# HELD counts steps deliberately not taken, which end the run as PARTIAL
# rather than as a pass — a run that skipped the thing it was asked about must
# not read as a run that did it.
HELD=0

pass() { RESULTS+=("PASS|$1|${2:-}"); }
fail() { RESULTS+=("FAIL|$1|${2:-}"); FAILED=$((FAILED + 1)); }
# info records something an operator should see that is neither a success nor
# a failure — a step deliberately not taken. It must never be printed red: the
# summary printer used to treat anything that was not PASS as FAIL, so a row
# like this would have shown as a failure the count knew nothing about, which
# is a table that contradicts its own summary line.
info() { RESULTS+=("INFO|$1|${2:-}"); }
# hold records a step this run deliberately did not take and which somebody
# has to take by hand. Amber like info, and unlike info it makes the verdict
# PARTIAL, because "not done" and "not applicable" are different answers.
hold() { RESULTS+=("INFO|$1|${2:-}"); HELD=$((HELD + 1)); }
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

    if [ "$HELD" -gt 0 ]; then
        # Everything that ran, ran cleanly — and something was deliberately not
        # run and is waiting for a person. Calling that a pass would be the
        # false-pass class this whole script is built against, and calling it a
        # failure would have an hourly timer reporting one for a decision
        # nobody asked it to make.
        #
        # Exit 0, because nothing is broken and nothing needs undoing. The
        # verdict line and the amber row are what carry it.
        printf '\n  \033[33mPARTIAL\033[0m — %d step(s) clean, %d waiting for you.' \
            "$(( ${#RESULTS[@]} - HELD ))" "$HELD"
        printf '\n  The table above says which, and what to run.\n\n'

        return 0
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

# Handed over to: the temporary copy that is running is ours to remove, from
# the first moment — the copy that handed over has emptied its own list and
# gone, and a die() anywhere before the end would otherwise leave it in /tmp,
# once an hour under the timer.
#
# Only a path this script's own hand-over makes, and only the one that is
# running: its directory from revision 3, the bare file an older copy wrote.
# Anything else — the gate sets AKCONNECT_REEXEC=1 to stop a hand-over — is not
# ours to delete.
if [ -n "${AKCONNECT_REEXEC:-}" ] && [ "$AKCONNECT_REEXEC" = "$0" ]; then
    case "$AKCONNECT_REEXEC" in
        /tmp/akconnect-upgrade-edge.*/upgrade-edge.sh) keep "${AKCONNECT_REEXEC%/upgrade-edge.sh}" ;;
        /tmp/akconnect-upgrade-edge.*.sh)              keep "$AKCONNECT_REEXEC" ;;
    esac
fi

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
#
# akconnect_need_value is written out here, not sourced from lib-edge-args.sh
# beside this file as the other deploy scripts do. This script is the one that
# runs from somewhere else: an older copy hands over to the release's copy by
# writing it to a temporary file, and a copy up to revision 2 wrote it straight
# into /tmp — so "beside this file" was /tmp/lib-edge-args.sh, and root sourced
# whatever any local user had put there. Nothing in this file may be loaded
# from next to it. A test holds that line.
akconnect_need_value() {
    [ "${2:-0}" -ge 2 ] && return 0

    printf '\n  \033[31m✗ %s needs a value, e.g. %s <value>\033[0m\n\n' "$1" "$1" >&2
    exit 2
}

while [ $# -gt 0 ]; do
    case "$1" in
        # Kept so an existing runbook, cron entry or muscle memory does not
        # start failing on an unknown option.
        --install-timer) INSTALL_TIMER=1; shift ;;
        --no-timer)      INSTALL_TIMER=0; shift ;;
        --check)         CHECK_ONLY=1; shift ;;
        --configure-apache)    CONFIGURE_APACHE=1; shift ;;
        --no-configure-apache) CONFIGURE_APACHE=0; shift ;;
        # Set by the timer unit. See UNATTENDED below.
        --unattended)    UNATTENDED=1; shift ;;
        --skip-pack)     SKIP_PACK=1; shift ;;
        --force)         FORCE=1; shift ;;
        --src) akconnect_need_value --src "$#"; SRC_DIR="$2"; shift 2 ;;
        # Refused after the hand-over is decided, not here — see
        # UNKNOWN_OPTIONS. Unless this copy was handed over to: it is the
        # release's own, and there is nobody newer to know the option.
        *)
            [ -n "${AKCONNECT_REEXEC:-}" ] && die "unknown option: $1
    Valid options are --check, --skip-pack, --force, --no-timer,
    --install-timer, --configure-apache, --no-configure-apache,
    --unattended and --src <path>."
            UNKNOWN_OPTIONS+=("$1"); shift ;;
    esac
done

# Absolute, once. The script changes into the checkout further down, and a
# relative --src was then looked for inside itself. Logical, not resolved: a
# symlinked /opt/akconnect/src stays that path in the timer it installs.
if [ -d "$SRC_DIR" ]; then
    SRC_DIR="$(cd "$SRC_DIR" && pwd)"
fi

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

# No VCS stamps, for every go build this run starts — the release's own pack
# builder included. A panel behind this script ships an older builder without
# -buildvcs=false, and go build as root on a checkout someone else owns runs
# git, is refused, and fails as "error obtaining VCS status". The version is
# stamped explicitly; the stamp is worth nothing here.
#
# Added to what Go would use anyway — `go env GOFLAGS`, which includes a value
# set with `go env -w` — because an environment GOFLAGS replaces that file's
# value outright, and taking only the environment's would have dropped it.
GOFLAGS="$(env -u GOFLAGS "$GO_BIN" env GOFLAGS 2>/dev/null)${GOFLAGS:+ $GOFLAGS}"
export GOFLAGS="${GOFLAGS:+$GOFLAGS }-buildvcs=false"

# The services declare go 1.24, and a toolchain older than that fails deep
# inside the build with a message about a language feature. Said here instead,
# because `apt install golang-go` on an older Debian is exactly how somebody
# ends up with 1.19 and no idea why the build stopped.
GO_MAJOR="$(printf '%s' "${GO_VERSION#go}" | cut -d. -f1)"
GO_MINOR="$(printf '%s' "${GO_VERSION#go}" | cut -d. -f2)"

case "$GO_MAJOR.$GO_MINOR" in
    .|.*|*.|*[!0-9.]*)
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

# src_git <arguments...>: git in the checkout, as whoever owns it.
#
# git refuses a repository that belongs to someone else — "detected dubious
# ownership" — and this script runs as root. On a checkout a login user
# cloned, every git call here used to fail, and every failure was sent to
# /dev/null: the preflight said "no git checkout at /opt/akconnect/src" about
# a directory holding a perfectly good one, a refused `git status` read as a
# clean tree, a refused fetch read as a network fault. An interactive sudo
# happened to work, because git trusts SUDO_UID; the hourly timer, which has
# none, failed every hour with the wrong message.
#
# Being the owner is the fix, not telling git to look away: nothing here
# writes a safe.directory. It also keeps a login user's clone theirs — root
# writing .git/FETCH_HEAD into it is what broke their next `git pull`.
SRC_OWNER=""
src_git() {
    if [ -z "$SRC_OWNER" ] || [ "$SRC_OWNER" = "root" ]; then
        git -C "$SRC_DIR" "$@"
    else
        sudo -H -u "$SRC_OWNER" git -C "$SRC_DIR" "$@"
    fi
}

# Asked of git rather than by looking for a .git directory: in a worktree
# (git worktree add) .git is a FILE, so the directory test refused a perfectly
# good checkout — and refused it at the preflight, which made a drill built on
# a worktree pass every later assertion without running any of them.
[ -e "$SRC_DIR" ] || die "no git checkout at $SRC_DIR.
    Clone it once:  git clone <your repo> $SRC_DIR
    Or point this at an existing one:  --src /path/to/checkout"

# -L: a symlink to a login user's clone is that user's clone. Without it stat
# named the link's owner — root — and git ran as root on the user's repository
# and was refused.
SRC_UID="$(stat -L -c %u "$SRC_DIR" 2>/dev/null)"
SRC_OWNER="$(stat -L -c %U "$SRC_DIR" 2>/dev/null)"

# An owner with no account — a deleted login user, or a tree unpacked with
# numeric ids — cannot be become: sudo refuses an unknown user. stat calls it
# "UNKNOWN", and that went on to "sudo: unknown user UNKNOWN".
if [ -n "$SRC_UID" ] && [ "$SRC_UID" != "0" ] && ! getent passwd "$SRC_UID" >/dev/null 2>&1; then
    die "$SRC_DIR belongs to uid $SRC_UID, which has no account on this machine.
    git works in a checkout only as its owner, and there is no such user to be.
    Give it to root, which is who runs this:
        sudo chown -R root:root $SRC_DIR
    then run this again."
fi

# Given back first. Every run before 1.9.7-dev.21 ran git here as root, and a
# sudo from the owner was let through (git trusts SUDO_UID) — leaving root's
# .git/FETCH_HEAD, index and object directories inside the owner's checkout.
# Running git as the owner then fails on the first of them: "cannot open
# .git/FETCH_HEAD: Permission denied", on every run, the timer's included —
# on exactly the edges the owner rule exists for. Only what is not already
# the owner's is touched.
if [ -n "$SRC_OWNER" ] && [ "$SRC_OWNER" != "root" ]; then
    # -H: through a symlinked checkout to the clone it names.
    REPAIRED="$(find -H "$SRC_DIR" ! -user "$SRC_OWNER" -print 2>/dev/null | wc -l)"
    if [ "$REPAIRED" -gt 0 ]; then
        find -H "$SRC_DIR" ! -user "$SRC_OWNER" -exec chown "$SRC_OWNER" {} + 2>/dev/null
        say "gave $REPAIRED file(s) in $SRC_DIR back to $SRC_OWNER, which earlier runs had left as root's"
    fi

    # A worktree keeps its git directory outside the checkout — .git is a file
    # naming it — and FETCH_HEAD, the file a root run leaves behind, lives
    # there. Only root's files are given back outside the checkout itself.
    if [ -f "$SRC_DIR/.git" ]; then
        WT_GITDIR="$(sed -n 's/^gitdir: //p' "$SRC_DIR/.git" | head -1)"
        case "$WT_GITDIR" in /*) ;; ?*) WT_GITDIR="$SRC_DIR/$WT_GITDIR" ;; esac
        WT_COMMON=""
        if [ -n "$WT_GITDIR" ] && [ -f "$WT_GITDIR/commondir" ]; then
            WT_COMMON="$(head -1 "$WT_GITDIR/commondir")"
            case "$WT_COMMON" in /*) ;; ?*) WT_COMMON="$WT_GITDIR/$WT_COMMON" ;; esac
        fi
        for gitdir in "$WT_GITDIR" "$WT_COMMON"; do
            [ -n "$gitdir" ] && [ -d "$gitdir" ] || continue
            REPAIRED="$(find -H "$gitdir" -uid 0 -print 2>/dev/null | wc -l)"
            if [ "$REPAIRED" -gt 0 ]; then
                find -H "$gitdir" -uid 0 -exec chown "$SRC_OWNER" {} + 2>/dev/null
                say "gave $REPAIRED file(s) in $gitdir back to $SRC_OWNER, which earlier runs had left as root's"
            fi
        done
    fi
fi

# What git said, not a guess at it. The directory exists; if git will not
# treat it as a checkout, the reason is the useful part.
if ! GIT_SAYS="$(src_git rev-parse --git-dir 2>&1)"; then
    die "$SRC_DIR is not usable as a git checkout (owned by ${SRC_OWNER:-nobody}). git said:
$(printf '%s\n' "$GIT_SAYS" | sed 's/^/        /')
    Or point this at another checkout:  --src /path/to/checkout"
fi

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
# The checkout this was installed from. Without it a run given --src installed
# a timer that fell back to /opt/akconnect/src every hour, and failed there.
Environment=AKCONNECT_SRC=$SRC_DIR
# --unattended is what stops the timer editing Apache. First-time setup of the
# proxy is a decision a person makes once, with the output in front of them:
# this machine's web server is serving other people's websites.
ExecStart=$self --unattended
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

# A refused `git status` prints nothing, and nothing used to read as clean.
# Standard output only: that is git's answer. sudo writes its own warnings to
# standard error — "unable to resolve host", on a machine whose name is not in
# /etc/hosts — and when they were read as part of the answer, a clean checkout
# "had local changes" on every run.
if ! LOCAL_CHANGES="$(src_git status --porcelain 2>/dev/null)"; then
    die "could not read the state of $SRC_DIR. git said:
$(src_git status --porcelain 2>&1 | sed 's/^/        /')"
fi
if [ -n "$LOCAL_CHANGES" ]; then
    die "$SRC_DIR has local changes. This script checks out a specific commit and
    will not discard your work. Commit or stash it, or point --src elsewhere."
fi

WANT_BRANCH="${TARGET_BRANCH:-main}"

# With an explicit refspec. A clone made with --branch (as getting-started.sh
# makes it) or --depth is single-branch: `git fetch origin <other>` then updates
# FETCH_HEAD and nothing else, origin/<other> never exists, and every later
# step failed — while the table printed "source fetched" in green with an
# empty commit, because nothing checked the ref it had fallen back to.
#
# Tags with a forced refspec: `--tags` refuses a tag that has moved on origin
# ("would clobber existing tag"), and one re-pointed release tag would have
# failed every edge's fetch every hour until somebody deleted it by hand.
fetched=0
FETCH_SAYS=""
for attempt in 1 2 3 4; do
    if FETCH_SAYS="$(src_git fetch --quiet origin \
            "+refs/heads/$WANT_BRANCH:refs/remotes/origin/$WANT_BRANCH" \
            "+refs/tags/*:refs/tags/*" 2>&1)"; then
        fetched=1
        break
    fi
    # A branch that is not there is not a network fault; waiting will not
    # bring it back.
    printf '%s\n' "$FETCH_SAYS" | grep -q "couldn't find remote ref" && break
    say "fetch failed; retrying in $((attempt * 2))s"
    sleep $((attempt * 2))
done

# The branch can be gone while the release is not: a release branch is merged
# and deleted, and the panel's commit is on main. The panel names the commit,
# so that is what is fetched — by name, which is what it is built from anyway.
if [ "$fetched" -ne 1 ] && [ -n "$TARGET_COMMIT" ] \
        && printf '%s\n' "$FETCH_SAYS" | grep -q "couldn't find remote ref"; then
    say "$WANT_BRANCH is no longer on origin; fetching the panel's commit ${TARGET_COMMIT:0:12} by name"
    if src_git rev-parse --quiet --verify "$TARGET_COMMIT^{commit}" >/dev/null 2>&1 \
            || FETCH_SAYS="$(src_git fetch --quiet origin "$TARGET_COMMIT" 2>&1)"; then
        fetched=1
    fi
fi

[ "$fetched" -eq 1 ] || die "could not fetch $WANT_BRANCH from $(src_git remote get-url origin 2>/dev/null). git said:
$(printf '%s\n' "$FETCH_SAYS" | sed 's/^/        /')"

# The panel's own commit when it names one — fetched by name if the branch
# fetch did not bring it, which a shallow clone may not have.
if [ -n "$TARGET_COMMIT" ] && ! src_git rev-parse --quiet --verify "$TARGET_COMMIT^{commit}" >/dev/null 2>&1; then
    src_git fetch --quiet origin "$TARGET_COMMIT" >/dev/null 2>&1 || true
fi

REF=""
if [ -n "$TARGET_COMMIT" ] && src_git rev-parse --quiet --verify "$TARGET_COMMIT^{commit}" >/dev/null 2>&1; then
    REF="$TARGET_COMMIT"
elif src_git rev-parse --quiet --verify "origin/$WANT_BRANCH^{commit}" >/dev/null 2>&1; then
    REF="origin/$WANT_BRANCH"
    [ -n "$TARGET_COMMIT" ] && say "the panel's commit ${TARGET_COMMIT:0:12} is not in the repository; building the tip of $WANT_BRANCH"
else
    die "the panel says it is on $WANT_BRANCH${TARGET_COMMIT:+ at ${TARGET_COMMIT:0:12}}, and $(src_git remote get-url origin 2>/dev/null) has neither.
    A panel that says \"main\" and was never told otherwise is the usual cause:
    check Platform → Updates for the repository and branch it was installed from."
fi

pass "source fetched" "$(src_git rev-parse --short "$REF") is available locally"

# AKCONNECT_REEXEC stops this happening twice. One hop is always enough: the
# script it hands over to IS the target's, so that one has nothing newer to go
# to. Without the guard, a target whose copy differs from itself — which cannot
# happen, but a bug could make it look that way — would loop forever.
if [ -z "${AKCONNECT_REEXEC:-}" ]; then
    # In a directory of its own, mode 0700: whatever the copy handed to looks
    # for beside itself is then only ever what this script put there. A copy up
    # to revision 2 wrote the file straight into /tmp.
    THEIRS_DIR="$(mktemp -d /tmp/akconnect-upgrade-edge.XXXXXX)" && [ -d "$THEIRS_DIR" ] \
        || die "could not make a private directory in /tmp for the release's copy of this script"
    keep "$THEIRS_DIR"
    THEIRS="$THEIRS_DIR/upgrade-edge.sh"

    if src_git show "$REF:deploy/upgrade-edge.sh" > "$THEIRS" 2>/dev/null && [ -s "$THEIRS" ]; then
        # Read out of the file, not sourced: sourcing it would run it.
        THEIR_REVISION="$(sed -n 's/^SCRIPT_REVISION=\([0-9][0-9]*\)$/\1/p' "$THEIRS" | head -1)"
        THEIR_REVISION="${THEIR_REVISION:-0}"

        if [ "$THEIR_REVISION" -gt "$SCRIPT_REVISION" ]; then
            chmod +x "$THEIRS"
            say "the release carries a newer copy of this script (revision $THEIR_REVISION); handing over"

            # exec replaces this process, so the EXIT trap that removes this
            # copy's temporary files never runs. They are removed here; the
            # copy being handed to is the replacement's to clean up.
            for path in ${TEMP_PATHS[@]+"${TEMP_PATHS[@]}"}; do
                [ "$path" = "$THEIRS_DIR" ] || rm -rf "$path"
            done
            TEMP_PATHS=()

            # Zero arguments must arrive as zero arguments.
            #
            # This was "${ORIGINAL_ARGS[@]:-}", which expands to ONE EMPTY
            # STRING when the array is empty — so a plain `upgrade-edge.sh`
            # with no options handed its replacement an argument of "", and
            # the replacement answered "unknown option:" and stopped. The
            # :- was put there to satisfy set -u and is the wrong fix; the
            # right one is to not pass an array that has nothing in it.
            #
            # Through bash, not run as a program: on a /tmp mounted noexec the
            # direct exec failed with "Permission denied" and bash, exiting on
            # a failed exec, printed no summary at all. And from the directory
            # the operator started in, so a relative --src still resolves.
            cd "$ORIGINAL_PWD" 2>/dev/null || cd /
            if [ "${#ORIGINAL_ARGS[@]}" -gt 0 ]; then
                AKCONNECT_REEXEC="$THEIRS" exec bash "$THEIRS" "${ORIGINAL_ARGS[@]}"
            fi

            AKCONNECT_REEXEC="$THEIRS" exec bash "$THEIRS"
        fi

        if [ "$THEIR_REVISION" -lt "$SCRIPT_REVISION" ]; then
            say "the release's copy of this script is older (revision $THEIR_REVISION); keeping this one"
        fi
    fi

    rm -rf "$THEIRS_DIR"
else
    # Handed over to (its temporary copy was registered for removal at the
    # top). The line is worth printing because the operator typed a
    # different path.
    say "running the release's own copy of this script"
fi

# Options nobody took. Had the release carried a newer copy of this script,
# it would have been handed them, above; this copy is the one running, so an
# option it does not know is a mistake.
if [ "${#UNKNOWN_OPTIONS[@]}" -gt 0 ]; then
    die "unknown option: ${UNKNOWN_OPTIONS[0]}
    Valid options are --check, --skip-pack, --force, --no-timer,
    --install-timer, --configure-apache, --no-configure-apache,
    --unattended and --src <path>."
fi

CURRENT_COORD="$("$BIN_DIR/akconnect-coordinator" version 2>/dev/null | awk '{print $NF}')"
CURRENT_RELAY="$("$BIN_DIR/akconnect-relay" version 2>/dev/null | awk '{print $NF}')"
say "here:   coordinator ${CURRENT_COORD:-none}, relay ${CURRENT_RELAY:-none}"

# ------------------------------------------------------------ shared steps
#
# Run by a full upgrade, and by a run that finds the edge already current: an
# edge with nothing to rebuild still has a fallback to check and a panel to
# report to. The first "already current" skipped both, so --configure-apache
# on a current edge said "Nothing to do" and configured nothing, the hourly
# timer stopped reporting an unconfigured fallback, and a relay version the
# panel had missed stayed "behind" on the panel until the next release.

# The HTTPS fallback, which is how a device on a network that carries no UDP
# connects at all. Checked on every upgrade rather than assumed: an Apache
# package update can disable a module, and the failure is silent until a
# customer takes a laptop somewhere with a strict firewall.
check_fallback() {
    step "the HTTPS fallback"

    # Loaded from the checkout. A missing one used to be a "No such file" on
    # stderr and then "command not found" for every check in it.
    local lib
    for lib in apache vhost probe; do
        if [ ! -f "$SRC_DIR/deploy/lib-edge-$lib.sh" ]; then
            fail "HTTPS fallback" "$SRC_DIR/deploy/lib-edge-$lib.sh is missing, so it could not be checked"
            return
        fi
    done

    # shellcheck source=lib-edge-apache.sh
    . "$SRC_DIR/deploy/lib-edge-apache.sh"
    # shellcheck source=lib-edge-vhost.sh
    . "$SRC_DIR/deploy/lib-edge-vhost.sh"
    # shellcheck source=lib-edge-probe.sh
    . "$SRC_DIR/deploy/lib-edge-probe.sh"

    if ss -ltn 2>/dev/null | grep -q '127.0.0.1:9443 '; then
        pass "relay fallback listener" "127.0.0.1:9443"
    else
        fail "relay fallback listener" "nothing is bound to 127.0.0.1:9443"
    fi

    # Whether this run may edit Apache. See akconnect_may_configure_apache.
    CONFIGURE_APACHE="$(akconnect_may_configure_apache "$CONFIGURE_APACHE" "$UNATTENDED")"

    # Whether the fallback answers, asked once and used twice.
    #
    # It is the question that matters — a device on a UDP-blocked network needs
    # $PANEL/fallback to reach the relay, and nothing below cares which web server
    # carries it there. Asked before the Apache branch rather than after, because
    # a server running Caddy or nginx has a perfectly good fallback and used to be
    # told, once an hour, to go and configure Apache.
    FALLBACK_ANSWERS=0
    akconnect_fallback_reachable "$PANEL" && FALLBACK_ANSWERS=1

    if [ "$FALLBACK_ANSWERS" -eq 1 ] && ! akconnect_apache_configured "$PANEL"; then
        # Something is already proxying it, and it is not this script's Apache
        # stanza. Nothing to do, and nothing to say beyond what is true.
        pass "HTTPS fallback" "$PANEL/fallback answers; this server is not Apache, so nothing here to configure"
    elif akconnect_apache_configured "$PANEL"; then
        # Already in place. Verified, never re-applied: re-asserting a
        # configuration is still editing it, and there is nothing to repair.
        pass "Apache proxy" "already configured for $(akconnect_panel_host "$PANEL")"
    elif [ "$CONFIGURE_APACHE" -eq 1 ]; then
        akconnect_apache_fallback "$SRC_DIR/deploy/apache" "$PANEL" say
        case "$?" in
            0) pass "Apache proxy" "/fallback → 127.0.0.1:9443, on that site only" ;;
            1) info "Apache proxy" "no Apache here; proxy it wherever the panel is served" ;;
            3) fail "Apache proxy" "no TLS virtual host of its own is named $(akconnect_panel_host "$PANEL")" ;;
            4) hold "Apache proxy" "not configured — you said no" ;;
            *) fail "Apache proxy" "the change did not hold and has been undone" ;;
        esac
    else
        hold "Apache proxy" "not configured yet — run this by hand with --configure-apache"
        say "Apache fallback not configured yet."
        say "Nothing here edits a web server that is serving other people's sites unless"
        say "somebody asks it to, in person, at a terminal. Run it once by hand, with the"
        say "output in front of you — it shows the exact file and the exact lines first:"
        say ""
        say "    sudo $SRC_DIR/deploy/upgrade-edge.sh --configure-apache"
        say ""
        say "Until then, devices on networks that carry no UDP cannot connect."
    fi

    # Reachability is only a failure when the proxy is supposed to be there. A run
    # that deliberately did not configure it has already said so once, and saying
    # it again in red would make an hourly timer report a failure for something
    # nobody asked it to do.
    if [ "$FALLBACK_ANSWERS" -eq 1 ]; then
        pass "fallback reachable" "$PANEL/fallback/health"
    elif akconnect_apache_configured "$PANEL"; then
        fail "fallback reachable" "$PANEL/fallback/health did not answer"
    else
        info "fallback reachable" "not proxied yet, so nothing answers it"
    fi
}

# report_to_panel tells the panel what is running here: the relay's version
# reaches it no other way (the coordinator reports its own; the relay does
# not), and "Last heard" on Platform → Coordinator is this report's time.
report_to_panel() {
    step "telling the panel what is running here"

    REPORT="$(mktemp)"
    keep "$REPORT"
    printf '{"coordinator_version":"%s","relay_version":"%s","host":"%s"}\n' \
        "${NEW_COORD:-}" "${NEW_RELAY:-}" "$(hostname -s)" > "$REPORT"

    if panel_post "/api/v1/edge/report" "$REPORT" /dev/null; then
        pass "panel told" "coordinator ${NEW_COORD:-?}, relay ${NEW_RELAY:-?}"
    else
        fail "panel told" "the report was not accepted"
    fi
    rm -f "$REPORT"
}

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

# check_services: both services up, and each listening on its port — after a
# restart, and on an edge that needed none.
check_services() {
    local svc
    for svc in coordinator relay; do
        if systemctl is-active --quiet "akconnect-$svc"; then
            pass "akconnect-$svc running" "$(systemctl show -p ActiveEnterTimestamp --value "akconnect-$svc")"
        else
            fail "akconnect-$svc running" "$(systemctl is-active "akconnect-$svc")"
            say "journalctl -u akconnect-$svc -n 30:"
            journalctl -u "akconnect-$svc" -n 30 --no-pager | sed 's/^/    /'
        fi
    done

    COORD_PORT="$(sed -n 's/.*--listen[= ]:\?\([0-9]*\).*/\1/p' \
        /etc/systemd/system/akconnect-coordinator.service 2>/dev/null | head -1)"
    RELAY_PORT="$(sed -n 's/.*--control[= ]:\?\([0-9]*\).*/\1/p' \
        /etc/systemd/system/akconnect-relay.service 2>/dev/null | head -1)"

    check_listening "coordinator" "${COORD_PORT:-8443}"
    check_listening "relay" "${RELAY_PORT:-9000}"
}

# running_its_binary <svc>: the service is up, and its process is running the
# file that is on disk now — not one `install` replaced underneath it.
#
# The version is read from the file, and the file is not the process. A run
# stopped between installing the new binaries and restarting the services
# leaves the new version on disk and the old one serving; every hour after
# that looked "already current", and reported the new version to the panel
# while the old relay went on running until the next release or a reboot.
# A replaced file shows in /proc as "<path> (deleted)".
running_its_binary() {
    local svc=$1 pid exe
    systemctl is-active --quiet "akconnect-$svc" || return 1
    pid="$(systemctl show -p MainPID --value "akconnect-$svc" 2>/dev/null)"
    [ -n "$pid" ] && [ "$pid" != "0" ] || return 1
    exe="$(readlink "/proc/$pid/exe" 2>/dev/null)" || return 1
    [ "$exe" = "$(readlink -f "$BIN_DIR/akconnect-$svc")" ]
}

# Nothing to do, and said so.
#
# The timer runs this every hour, and every run used to rebuild both services,
# overwrite the rollback copies with the build that was already running,
# restart the coordinator and the relay, and cross-compile and re-upload the
# Windows installer — whether or not the panel had moved. Each restart costs
# every relayed pair a few seconds of traffic (the edge-upgrade drill measured
# three), so an idle edge was taking an outage once an hour, and within an
# hour of any real upgrade "previous binaries kept" held the current ones.
#
# Current means all of it: both services on the panel's version and running
# it, both of the panel's installers published for it, and the timer in
# place. A panel too old to say what it has published never looks current,
# and gets the full run.
#
# Decided here, acted on once the checkout is on the release, below: an edge
# with nothing to rebuild still checks its services and its fallback, with the
# release's own libraries, and tells the panel what is running.
PUBLISHED_SETUP="$(json "$RELEASE_JSON" published_setup)"
PUBLISHED_AGENT="$(json "$RELEASE_JSON" published_agent)"
UNITS_DIR="${AKCONNECT_SYSTEMD_DIR:-/etc/systemd/system}"

ALREADY_CURRENT=0
if [ "$CHECK_ONLY" -ne 1 ] && [ "$FORCE" -ne 1 ] \
        && [ "$CURRENT_COORD" = "$TARGET_VERSION" ] && [ "$CURRENT_RELAY" = "$TARGET_VERSION" ] \
        && { [ "$SKIP_PACK" -eq 1 ] || { [ "$PUBLISHED_SETUP" = "$TARGET_VERSION" ] \
                && [ "$PUBLISHED_AGENT" = "$TARGET_VERSION" ]; }; } \
        && { [ "$INSTALL_TIMER" -ne 1 ] || [ -f "$UNITS_DIR/akconnect-upgrade.timer" ]; } \
        && running_its_binary coordinator && running_its_binary relay; then
    ALREADY_CURRENT=1
fi

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

if ! CHECKOUT_SAYS="$(src_git checkout --quiet -B "$WANT_BRANCH" "$REF" 2>&1)"; then
    die "cannot put $SRC_DIR on $WANT_BRANCH at $REF. git said:
$(printf '%s\n' "$CHECKOUT_SAYS" | sed 's/^/        /')"
fi

BUILT_FROM="$(src_git rev-parse --short HEAD)"
ON_BRANCH="$(src_git rev-parse --abbrev-ref HEAD)"

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

if [ "$ALREADY_CURRENT" -eq 1 ]; then
    # On the release and running it, so a check that fails below is not "the
    # edge is NOT upgraded": that verdict sends an operator to undo work that
    # was fine, and --force would rebuild and restart for nothing.
    SERVICES_INSTALLED=1

    if [ "$SKIP_PACK" -eq 1 ]; then
        pass "already current" "coordinator and relay are both $TARGET_VERSION and running it (installers not checked: --skip-pack) — nothing rebuilt, nothing restarted"
    else
        pass "already current" "coordinator, relay and installers are all $TARGET_VERSION — nothing rebuilt, nothing restarted"
    fi

    check_services
    check_fallback

    NEW_COORD="$CURRENT_COORD"
    NEW_RELAY="$CURRENT_RELAY"
    report_to_panel

    [ "$FAILED" -eq 0 ] && say "Nothing to rebuild. --force rebuilds and restarts anyway."
    REACHED_END=1

    exit 0
fi

# ----------------------------------------------------------------- the build

step "building the coordinator and the relay"

BUILD_DIR="$(mktemp -d)"
keep "$BUILD_DIR"

# -buildvcs=false: the version is stamped explicitly, and go build otherwise
# runs git on the checkout itself — as root, whoever owns it — and a refusal
# comes out as "the coordinator would not compile", about code that compiles.
build_one() {
    local svc=$1 out=$2
    ( cd "$SRC_DIR/services/$svc" \
        && CGO_ENABLED=0 "$GO_BIN" build -trimpath -buildvcs=false \
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

check_services

check_fallback

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
        src_git checkout --quiet -- services/kit 2>/dev/null || true

        if STILL="$(src_git status --porcelain 2>/dev/null)" && [ -z "$STILL" ]; then
            pass "checkout left clean" "the old builder's output was removed"
        else
            fail "checkout left clean" \
                "$SRC_DIR is still dirty; the next run will refuse with 'has local changes'"
        fi
    fi
fi

# ------------------------------------------------------------------ reporting

report_to_panel

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

# The last line, and the one place a full run sets this (the other two are the
# --check report and "already current", each an exit of its own). The EXIT
# trap prints the summary and decides the exit status from here.
REACHED_END=1
