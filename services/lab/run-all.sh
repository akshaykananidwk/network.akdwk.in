#!/usr/bin/env bash
# The whole networking proof, in one command.
#
# Builds the namespaces, runs every scenario the product depends on, and
# prints one pass/fail table. This is the dogfood gate: a release that cannot
# get a clean table here does not ship.
#
#   ./run-all.sh              run everything
#   ./run-all.sh cone relay   run only the named scenarios
#   ./run-all.sh --list       show the scenario names
#
# The "resolved" scenario needs systemd-resolved on the host, because it proves
# the split-DNS path most of this product's Linux machines will use. Run
# ./setup-resolved.sh once if this machine does not have it.
#
# Logs, binaries and agent state land in services/lab/.run, which is
# gitignored and rebuilt each run.
set -uo pipefail

LAB="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$LAB/lib.sh"

ALPHA_IP=""
BETA_IP=""
TENANT=""
NETWORK=""
RESULTS=()
FAILED=0
LOSS_PCT=""

# How far the panel's billed figure may sit from what the relay says it
# carried. Stated here rather than buried in the check, because it is a
# commercial decision: it is the margin of error on an invoice.
ACCOUNTING_TOLERANCE=${ACCOUNTING_TOLERANCE:-10}
AGENT_PIDS=()
SERVER_PIDS=()

record() {
    local name=$1 status=$2 detail=$3
    RESULTS+=("$name|$status|$detail")
    case "$status" in
        PASS) printf '  \033[32m✓ %s\033[0m — %s\n' "$name" "$detail" ;;
        FAIL) printf '  \033[31m✗ %s\033[0m — %s\n' "$name" "$detail"; FAILED=$((FAILED + 1)) ;;
        *)    printf '  \033[33m• %s\033[0m — %s\n' "$name" "$detail" ;;
    esac
}

check() {
    local name=$1 detail=$2; shift 2
    if "$@"; then record "$name" PASS "$detail"; else record "$name" FAIL "$detail"; fi
}

# ------------------------------------------------------------------ fixture
#
# Every scenario starts from nothing: a fresh topology, fresh agent state and
# a fresh tenant. Reusing a tunnel between scenarios would let one scenario's
# success hide the next one's failure.

fixture() {
    local topology=("$@")

    lab::stop_servers
    lab::down_agents
    # Checked, because a topology that half-built is indistinguishable from a
    # product that cannot connect — and the scenario will blame the product.
    "$LAB_DIR/topology.sh" "${topology[@]}" >/dev/null \
        || die "could not build the ${topology[*]} topology"

    # The relay's name has to resolve inside the namespaces the topology just
    # created, and teardown removes the files with them — so this goes after
    # the build, every time.
    lab::relay_hostname

    rm -rf "$RUN/state"

    # Enrolment is throttled per address, and the drill enrols two devices per
    # scenario from the same two addresses. Nine scenarios in, the throttle —
    # not the network — is what fails, and the message reads like a product
    # fault.
    php "$LAB_DIR/lab-setup.php" unthrottle >/dev/null 2>&1

    local seed
    seed="$(php "$LAB_DIR/lab-setup.php" seed)" || die "could not seed a tenant"
    TENANT="$(awk -F= '/^TENANT=/ {print $2}' <<<"$seed")"
    NETWORK="$(awk -F= '/^NETWORK=/ {print $2}' <<<"$seed")"
    local code
    code="$(awk -F= '/^JOIN_CODE=/ {print $2}' <<<"$seed")"

    local uid_alpha uid_beta approved
    lab::enrol_start alpha "$code"
    lab::enrol_start beta "$code"
    uid_alpha="$(lab::enrol_uid alpha)" || die "alpha never registered"
    uid_beta="$(lab::enrol_uid beta)"   || die "beta never registered"

    approved="$(php "$LAB_DIR/lab-setup.php" approve "$uid_alpha" "$uid_beta")" \
        || die "could not approve the lab devices"

    lab::enrol_finish alpha || die "alpha never claimed its token (see $LOGS/alpha-enroll.log)"
    lab::enrol_finish beta  || die "beta never claimed its token (see $LOGS/beta-enroll.log)"
    ALPHA_IP="$(awk -F= -v k="$uid_alpha" '$1 == k {print $2}' <<<"$approved")"
    BETA_IP="$(awk -F= -v k="$uid_beta"  '$1 == k {print $2}' <<<"$approved")"
    [ -n "$ALPHA_IP" ] && [ -n "$BETA_IP" ] || die "the panel did not allocate overlay addresses"

    UID_ALPHA="$uid_alpha"
    UID_BETA="$uid_beta"

    lab::up alpha
    lab::up beta
}

# fixture_solo brings up ONE approved device, with a second enrolled and left
# waiting.
#
# That is the state every customer site is in between installing the first
# machine and the second, and it is the state nothing here used to test: the
# ordinary fixture approves both devices before either agent starts, so the
# coordinator learned a complete peer set on the very first hello and the
# question of what happens to an already-running device never arose.
#
# It arose in the field. The laptop announced while it was alone, held an empty
# allowed set for the life of the process, and could not reach the machine
# approved nine minutes later until somebody restarted its service.
fixture_solo() {
    local topology=("$@")
    [ ${#topology[@]} -eq 0 ] && topology=(nat)

    lab::stop_servers
    lab::down_agents
    "$LAB_DIR/topology.sh" "${topology[@]}" >/dev/null \
        || die "could not build the ${topology[*]} topology"
    lab::relay_hostname
    rm -rf "$RUN/state"

    php "$LAB_DIR/lab-setup.php" unthrottle >/dev/null 2>&1

    local seed
    seed="$(php "$LAB_DIR/lab-setup.php" seed)" || die "could not seed a tenant"
    TENANT="$(awk -F= '/^TENANT=/ {print $2}' <<<"$seed")"
    NETWORK="$(awk -F= '/^NETWORK=/ {print $2}' <<<"$seed")"
    JOIN_CODE="$(awk -F= '/^JOIN_CODE=/ {print $2}' <<<"$seed")"

    # Alpha first, alone, approved, running.
    local uid_alpha approved
    lab::enrol_start alpha "$JOIN_CODE"
    uid_alpha="$(lab::enrol_uid alpha)" || die "alpha never registered"

    approved="$(php "$LAB_DIR/lab-setup.php" approve "$uid_alpha")" \
        || die "could not approve alpha"

    lab::enrol_finish alpha || die "alpha never claimed its token"
    ALPHA_IP="$(awk -F= -v k="$uid_alpha" '$1 == k {print $2}' <<<"$approved")"
    [ -n "$ALPHA_IP" ] || die "the panel did not allocate alpha an address"
    UID_ALPHA="$uid_alpha"

    lab::up alpha

    # Beta enrols but is NOT approved. It sits in the waiting room, which is
    # where R4 says it belongs.
    lab::enrol_start beta "$JOIN_CODE"
    UID_BETA="$(lab::enrol_uid beta)" || die "beta never registered"
    BETA_IP=""
}

# join_late approves the waiting device and starts it, touching nothing about
# the one already running.
join_late() {
    local approved
    approved="$(php "$LAB_DIR/lab-setup.php" approve "$UID_BETA")" \
        || die "could not approve beta"

    lab::enrol_finish beta || die "beta never claimed its token"
    BETA_IP="$(awk -F= -v k="$UID_BETA" '$1 == k {print $2}' <<<"$approved")"
    [ -n "$BETA_IP" ] || die "the panel did not allocate beta an address"

    lab::up beta
}

cleanup() {
    # Everything below destroys shared state, so a cleanup that no longer owns
    # the lock stops after its own agents rather than tearing down a later
    # run's environment from underneath it.
    lab::stop_servers

    # KEEP=1 leaves the namespaces, the control plane and the tenant standing
    # so a failure can be looked at rather than reasoned about. A drill that
    # destroys the evidence on its way out makes every failure a guess.
    if [ "${KEEP:-0}" = "1" ]; then
        say "KEEP=1: leaving the lab up. Tear it down with ./topology.sh down"
        return
    fi

    if ! lab::owns; then
        lab::down_agents
        return
    fi

    lab::down_agents
    lab::stop_pid "${RELAY_PID:-}"
    lab::stop_pid "${RELAY_B_PID:-}"
    lab::stop_pid "${COORD_PID:-}"
    lab::stop_pid "${PANEL_PID:-}"
    [ -n "${TENANT:-}" ] && php "$LAB_DIR/lab-setup.php" teardown "$TENANT" >/dev/null 2>&1
    lab::secrets_clear
    "$LAB_DIR/topology.sh" down >/dev/null 2>&1
    lab::release
}

# The scenarios themselves are next door. This file is the harness — the
# fixture, the table and the gate — and keeping the two apart means a new
# scenario is an addition rather than an edit to the machinery around it.
source "$LAB/scenarios.sh"

# ------------------------------------------------------------------- table

print_table() {
    printf '\n\033[1m  Scenario results\033[0m\n'
    printf '  %-22s  %-6s  %s\n' "CHECK" "RESULT" "DETAIL"
    printf '  %-22s  %-6s  %s\n' "----------------------" "------" "------------------------------------"

    local row name status detail checks=0
    for row in "${RESULTS[@]}"; do
        IFS='|' read -r name status detail <<<"$row"
        printf '  %-22s  %-6s  %s\n' "$name" "$status" "$detail"
        case "$status" in PASS|FAIL) checks=$((checks + 1)) ;; esac
    done

    printf '\n'
    if [ "$FAILED" -eq 0 ]; then
        printf '  \033[32m%d checks, all passed.\033[0m The networking proof holds for this build.\n\n' "$checks"
    else
        printf '  \033[31m%d of %d checks FAILED.\033[0m This build does not ship.\n\n' "$FAILED" "$checks"
    fi
}

# -------------------------------------------------------------------- main

declare -A SCENARIOS=(
    [cone]=scenario_cone
    [relay]=scenario_relay
    [cone-sym]=scenario_mixed_cone_first
    [sym-cone]=scenario_mixed_sym_first
    [controller-down]=scenario_controller_down
    [relay-down]=scenario_relay_down
    [relay-failover]=scenario_relay_failover
    [accounting]=scenario_accounting
    [acl]=scenario_acl
    [acl-tamper]=scenario_acl_tamper
    [acl-srcport]=scenario_acl_source_port
    [enrol-throttle]=scenario_enrol_throttle
    [gateway]=scenario_gateway
    [gateway-clash]=scenario_gateway_clash
    [overlay-clash]=scenario_overlay_clash
    [gateway-tamper]=scenario_gateway_tamper
    [subnet-mapping]=scenario_subnet_mapping
    [cgnat]=scenario_cgnat
    [cgnat-direct]=scenario_cgnat_direct
    [late-joiner]=scenario_late_joiner
    [reconnect]=scenario_reconnect
    [reconnect-both]=scenario_reconnect_both
    [dns]=scenario_dns
    [resolved]=scenario_resolved
    [revocation]=scenario_revocation
)
ORDER=(cone relay cgnat cgnat-direct late-joiner reconnect reconnect-both cone-sym sym-cone controller-down relay-down relay-failover accounting acl acl-srcport acl-tamper enrol-throttle gateway gateway-clash overlay-clash gateway-tamper subnet-mapping dns resolved revocation)

if [ "${1:-}" = "--list" ]; then
    printf '%s\n' "${ORDER[@]}"
    exit 0
fi

WANTED=("$@")
[ ${#WANTED[@]} -eq 0 ] && WANTED=("${ORDER[@]}")

trap cleanup EXIT INT TERM

step "preflight"
lab::preflight
lab::build
lab::secrets

step "control plane"
lab::panel_start
lab::coord_start
lab::relay_start
lab::relay_b_start

for wanted in "${WANTED[@]}"; do
    handler="${SCENARIOS[$wanted]:-}"
    [ -n "$handler" ] || die "unknown scenario: $wanted (try --list)"
    "$handler"
done

print_table
exit $(( FAILED > 0 ? 1 : 0 ))
