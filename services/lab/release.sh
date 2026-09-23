#!/usr/bin/env bash
#
# Everything that has to be true before a release ships, in one command.
#
# Five gates, in the order that fails cheapest first:
#
#   0. the edge script — does upgrade-edge.sh fail honestly when it fails;
#      the one-command installer — run after a failed first run, run twice,
#      uninstalled and run again, in namespaces with a private database;
#      and the agent's self-update: a real swap, and two refusals
#   1. the test suites — PHP and Go, including -race
#   2. the web gate — Apache + PHP-FPM + MariaDB, installed through the
#      browser installer exactly as a customer would
#   3. the Argon2 gate — a PHP whose Argon2 comes from libsodium
#   4. the networking gate — run-all.sh, every scenario
#   5. the update gate — dogfood.sh, a real cross-version install,
#      update and rollback in a scratch database
#
# plus the Windows pack build, which is the part of the customer installer that
# can be checked without a Windows machine.
#
# The edge-script gate is first because it is the cheapest and because of what
# it is for: upgrade-edge.sh shipped with a failed preflight printing "All
# steps passed", which is precisely the false pass every other gate here
# exists to prevent. A deployment script is code and gets gated like code.
#
# Any one of them red means the release does not ship.
#
# The update gate exists because 1.6.0's dogfood wrote zero files: the release
# was built on the machine it was installed on, so the tree already matched and
# the file-writing path — where the 1.3.x P1 lived — was never entered.
#
# The web and Argon2 gates exist because 1.9.0 shipped ten defects that a real
# aaPanel deployment found in one evening and that this lab could not have
# found at all: everything here ran on PHP's built-in server, which reads no
# .htaccess, has no mod_php, passes every header through, and is built against
# libargon2. Between them those two gates would have caught six of the ten.
#
# Both need Docker. Without it they are skipped, loudly — a gate that quietly
# does nothing is worse than no gate.
#
#   ./services/lab/release.sh
set -uo pipefail

LAB="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$LAB/../.." && pwd)"

FAILED=()
SKIPPED=()
run_gate() {
    local name=$1
    shift

    printf '\n\033[1m════ %s\033[0m\n\n' "$name"
    if "$@"; then
        printf '\n\033[32m  %s: clean\033[0m\n' "$name"
    else
        printf '\n\033[31m  %s: FAILED\033[0m\n' "$name"
        FAILED+=("$name")
    fi
}

php_suite() {
    ( cd "$REPO" && php tests/run.php "$@" )
}

go_suites() {
    local svc ok=0
    for svc in shared coordinator relay agent; do
        [ -d "$REPO/services/$svc" ] || continue
        ( cd "$REPO/services/$svc" && go vet ./... && go test -race ./... ) || ok=1
    done

    # The agent has to keep compiling for the machines most customers have.
    ( cd "$REPO/services/agent" && GOOS=windows go build ./... ) || ok=1

    return $ok
}

run_gate "test suites (PHP)"        php_suite "$@"
run_gate "test suites (Go, -race)"  go_suites
web_gate() {
    if ! docker info >/dev/null 2>&1; then
        printf '  \033[33mSKIPPED\033[0m — docker is not running, so Apache and PHP-FPM cannot be built.\n'
        printf '  This gate covers six of the ten defects 1.9.0 shipped. Do not ship without it.\n'
        SKIPPED+=("web gate")

        return 0
    fi

    "$LAB/webtarget/run.sh"
}

argon2_gate() {
    if ! docker info >/dev/null 2>&1; then
        printf '  \033[33mSKIPPED\033[0m — docker is not running.\n'
        SKIPPED+=("Argon2 gate")

        return 0
    fi

    "$LAB/argon2target/run.sh"
}

# The Windows installer's own build. Running the .exe needs Windows, which
# this lab does not have; what can be checked here is the half that failed in
# the field — a build that did not know which panel to join, and stopped after
# it had already copied its files.
windows_pack() {
    "$REPO/services/kit/build-windows-pack.sh" >/dev/null
}

# The deployment script's own honesty. It shipped with a failed preflight
# printing "All steps passed", which is the class of defect every other gate
# here exists to find.
run_gate "the edge script fails honestly"  "$LAB/edge-script-gate.sh"
run_gate "the installer is safe to run again" "$LAB/install-gate.sh"
run_gate "Windows pack builds and is stamped" windows_pack
run_gate "the agent replaces itself, and refuses when it should" "$LAB/selfupdate-gate.sh"
run_gate "web gate (Apache + PHP-FPM)" web_gate
run_gate "Argon2 gate (libsodium)"     argon2_gate
run_gate "networking gate"          "$LAB/run-all.sh"
run_gate "update gate"              "$LAB/dogfood.sh"

printf '\n\033[1m════ release verdict\033[0m\n\n'
if [ ${#FAILED[@]} -gt 0 ]; then
    printf '  \033[31m%d gate(s) FAILED: %s\033[0m\n' "${#FAILED[@]}" "${FAILED[*]}"
    printf '  This build does not ship.\n\n'
    exit 1
fi

if [ ${#SKIPPED[@]} -gt 0 ]; then
    printf '  \033[33m%d gate(s) SKIPPED: %s\033[0m\n' "${#SKIPPED[@]}" "${SKIPPED[*]}"
    printf '  Nothing failed, but this is not a clean run. Start Docker and do it again.\n\n'
    exit 1
fi

printf '  \033[32mAll gates clean.\033[0m This build may ship.\n\n'
