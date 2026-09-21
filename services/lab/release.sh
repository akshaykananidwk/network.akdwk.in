#!/usr/bin/env bash
#
# Everything that has to be true before a release ships, in one command.
#
# Three gates, in the order that fails cheapest first:
#
#   1. the test suites — PHP and Go, including -race
#   2. the networking gate — run-all.sh, every scenario
#   3. the update gate — dogfood.sh, a real cross-version install,
#      update and rollback in a scratch database
#
# Any one of them red means the release does not ship. The third is the newest
# and exists because 1.6.0's dogfood wrote zero files: the release was built on
# the machine it was installed on, so the tree already matched and the
# file-writing path — where the 1.3.x P1 lived — was never entered.
#
#   ./services/lab/release.sh
set -uo pipefail

LAB="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$LAB/../.." && pwd)"

FAILED=()
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
run_gate "networking gate"          "$LAB/run-all.sh"
run_gate "update gate"              "$LAB/dogfood.sh"

printf '\n\033[1m════ release verdict\033[0m\n\n'
if [ ${#FAILED[@]} -gt 0 ]; then
    printf '  \033[31m%d gate(s) FAILED: %s\033[0m\n' "${#FAILED[@]}" "${FAILED[*]}"
    printf '  This build does not ship.\n\n'
    exit 1
fi

printf '  \033[32mAll four gates clean.\033[0m This build may ship.\n\n'
