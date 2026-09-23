#!/usr/bin/env bash
# Enrolment throttling.
#
# Sourced by scenarios.sh. One office is one public address, so the limit that
# protects enrolment has to tell a rollout apart from an attack. Volume cannot
# do that; failures can.

# enrol_attempt posts one enrolment straight to the panel.
#
# Deliberately not through the agent: the agent generates a key, writes state
# and polls for approval, and none of that is what is being measured here. What
# is being measured is how the panel answers fifty of these in a row.
lab::enrol_attempt() {
    local ns=$1 code=$2 key=$3
    ip netns exec "$ns" curl -s --noproxy '*' --max-time 10 \
        -o /dev/null -w '%{http_code}' \
        -H 'Content-Type: application/json' \
        -H 'Accept: application/json' \
        -d "{\"join_code\":\"$code\",\"public_key\":\"$key\",\"hostname\":\"bulk\",\"os\":\"linux\",\"arch\":\"amd64\"}" \
        "$PANEL_URL/api/v1/enroll"
}

# fakeKey produces a distinct, well-formed public key per attempt, so each
# enrolment is a different device rather than the same one repeating.
lab::fake_key() {
    head -c 32 /dev/urandom | base64
}

# §B3: fifty machines in one office must all get on, while a run of bad join
# codes from that same address is cut off.
scenario_enrol_throttle() {
    step "enrolment — a bulk rollout succeeds; guessing join codes does not"
    fixture nat

    local issued code
    issued="$(php "$LAB_DIR/lab-setup.php" joincode "$NETWORK" 60)" \
        || { record "enrol/setup" FAIL "could not issue a join code"; return; }
    code="$(awk -F= '/^JOIN_CODE=/ {print $2}' <<<"$issued")"

    # Guessing FIRST, on a clean counter.
    #
    # Running the bulk rollout first hid a real defect: fifty successful
    # enrolments took the volume ceiling to its limit, so the bad codes that
    # followed were throttled by *that* rather than by the failure counter,
    # and the check passed without the thing it tests ever being exercised.
    # Two controls that both say "429" are not interchangeable.
    local rejected=0 throttled=0 status i
    for i in $(seq 1 20); do
        status="$(lab::enrol_attempt alpha "BADCODE$(printf '%04d' "$i")" "$(lab::fake_key)")"
        case "$status" in
            429) throttled=$((throttled + 1)) ;;
            *)   rejected=$((rejected + 1)) ;;
        esac
    done

    if [ "$throttled" -eq 0 ]; then
        record "enrol/guessing" FAIL "20 bad join codes from one address, none throttled"
    elif [ "$rejected" -lt 10 ]; then
        # The failure limit is fifteen. Throttling much sooner means something
        # other than the failure counter did it — the volume ceiling, most
        # likely — and that is the false pass this ordering exists to catch.
        record "enrol/guessing" FAIL "throttled after only $rejected rejections; the failure counter is not what stopped it"
    else
        record "enrol/guessing" PASS "$throttled of 20 bad codes throttled after $rejected rejections"
    fi

    # The address stays blocked while the failure window holds, even with a
    # valid code — which is the point of throttling the guesser rather than
    # the code.
    status="$(lab::enrol_attempt alpha "$code" "$(lab::fake_key)")"
    if [ "$status" = "429" ]; then
        record "enrol/blocked-after" PASS "the address stays blocked while the failure window holds"
    else
        record "enrol/blocked-after" FAIL "a blocked address enrolled again immediately (HTTP $status)"
    fi

    # Now the rollout, on a cleared counter: fifty machines, one office, one
    # address, one code.
    php "$LAB_DIR/lab-setup.php" unthrottle >/dev/null 2>&1

    local ok=0 other=0
    throttled=0
    for i in $(seq 1 50); do
        status="$(lab::enrol_attempt alpha "$code" "$(lab::fake_key)")"
        case "$status" in
            201) ok=$((ok + 1)) ;;
            429) throttled=$((throttled + 1)) ;;
            *)   other=$((other + 1)) ;;
        esac
    done

    if [ "$ok" -eq 50 ]; then
        record "enrol/bulk" PASS "50 devices enrolled from one address, none throttled"
    else
        record "enrol/bulk" FAIL "only $ok of 50 enrolled ($throttled throttled, $other other)"
    fi
}
