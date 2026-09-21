#!/usr/bin/env bash
# The scenarios themselves.
#
# Sourced by run-all.sh, which provides the fixture, the record/check helpers
# and the table. Each function here answers one question about the product and
# records a verdict; none of them owns any setup.

# --------------------------------------------------------------- scenarios

# R1 is checked inside every scenario that gets a tunnel up, not once: a
# split-tunnel violation that only appears on the relay path is still a
# violation, and only per-scenario checking would catch it.
assert_split_tunnel() {
    local ns=$1 name=$2
    local default_dev via_tunnel
    # A check that cannot read the routing table has not passed — it has not
    # run. An earlier version returned an empty device name when the namespace
    # had gone and recorded PASS, which is the worst thing a drill can produce:
    # a green tick for a test that never happened.
    if ! ip netns exec "$ns" ip route show default >/dev/null 2>&1; then
        record "$name" FAIL "could not read $ns's routing table — the check did not run"
        return
    fi

    default_dev="$(ip netns exec "$ns" ip route show default 2>/dev/null | awk '{print $5}' | head -1)"
    if [ -z "$default_dev" ]; then
        record "$name" FAIL "$ns has no default route at all, so there is nothing to check"
        return
    fi

    via_tunnel="$(ip netns exec "$ns" ip route get 1.1.1.1 2>/dev/null | grep -c 'dev ak' || true)"

    if [ "$via_tunnel" -eq 0 ] && [ "${default_dev#ak}" = "$default_dev" ]; then
        record "$name" PASS "default route stays on $default_dev; 1.1.1.1 does not enter the tunnel"
    else
        record "$name" FAIL "the tunnel captured the default route (dev $default_dev)"
    fi
}

scenario_cone() {
    step "cone NAT — both sides punchable, expect a direct path"
    fixture nat

    if lab::wait_tunnel alpha "$BETA_IP" 60; then
        record "cone/tunnel" PASS "alpha pinged $BETA_IP across two separate NATs"
    else
        record "cone/tunnel" FAIL "no tunnel formed within 60s"
        return
    fi

    check "cone/direct" "path is direct, not relayed" lab::wait_path alpha direct 30
    assert_split_tunnel alpha "cone/R1"
}

scenario_relay() {
    step "symmetric NAT — punching cannot work, expect the relay"
    fixture symmetric

    if lab::wait_tunnel alpha "$BETA_IP" 90; then
        record "relay/tunnel" PASS "alpha pinged $BETA_IP with hole punching impossible"
    else
        record "relay/tunnel" FAIL "no tunnel formed within 90s"
        return
    fi

    check "relay/path" "path is relay" lab::wait_path alpha relay 30
    assert_split_tunnel alpha "relay/R1"
}

# The two mixed scenarios differ only in which side sends first. That is the
# whole question: a symmetric NAT's mapping towards a cone peer is usable, but
# only if it is created before the cone peer tries to use it.
scenario_mixed_cone_first() {
    step "cone → symmetric — the cone side initiates"
    fixture mixed cone symmetric

    if lab::wait_tunnel alpha "$BETA_IP" 90; then
        local path
        path="$(lab::settled_path alpha 30)"
        if [ "$path" = "unknown" ]; then
            record "cone-sym/tunnel" FAIL "traffic flows but the agent never reported a path"
        else
            record "cone-sym/tunnel" PASS "connected via $path"
        fi
        assert_split_tunnel alpha "cone-sym/R1"
    else
        record "cone-sym/tunnel" FAIL "no tunnel formed within 90s"
    fi
}

scenario_mixed_sym_first() {
    step "symmetric → cone — the symmetric side initiates"
    fixture mixed symmetric cone

    if lab::wait_tunnel alpha "$BETA_IP" 90; then
        local path
        path="$(lab::settled_path alpha 30)"
        if [ "$path" = "unknown" ]; then
            record "sym-cone/tunnel" FAIL "traffic flows but the agent never reported a path"
        else
            record "sym-cone/tunnel" PASS "connected via $path"
        fi
        assert_split_tunnel alpha "sym-cone/R1"
    else
        record "sym-cone/tunnel" FAIL "no tunnel formed within 90s"
    fi
}

# R6: the control plane is not the data plane. A coordinator outage must not
# take customer traffic with it.
scenario_controller_down() {
    step "controller down — an established tunnel must survive it"
    fixture nat

    if ! lab::wait_tunnel alpha "$BETA_IP" 60; then
        record "ctrl-down/tunnel" FAIL "no tunnel to test with"
        return
    fi

    local before after loss
    lab::settled_path alpha 30 >/dev/null
    before="$(lab::handshake_age alpha)"
    lab::loss_during alpha "$BETA_IP" 20 'lab::stop_pid "$COORD_PID"; COORD_PID='
    loss="$LOSS_PCT"
    after="$(lab::handshake_age alpha)"

    if [ "$loss" = "0" ]; then
        record "ctrl-down/traffic" PASS "coordinator killed mid-ping; 0% loss (handshake $before → $after)"
    else
        record "ctrl-down/traffic" FAIL "coordinator killed mid-ping; ${loss}% loss"
    fi

    lab::coord_start
}

# Failover proper: kill the relay the pair is actually using and require the
# traffic to appear on the other one. A fleet of one could only ever show that
# a relay coming back is used again, which is a much weaker claim — so this
# starts from two and checks the port the tunnel ends up pointed at.
scenario_relay_failover() {
    step "relay failover — traffic must move to the other relay"
    fixture symmetric

    if ! lab::wait_tunnel alpha "$BETA_IP" 90; then
        record "failover/tunnel" FAIL "no relayed tunnel to test with"
        return
    fi

    local before after loss victim
    before="$(lab::relay_name alpha)"
    if [ "$before" = "-" ] || [ -z "$before" ]; then
        record "failover/recovery" FAIL "the agent does not report which relay it is on"
        return
    fi

    # Kill whichever relay the pair actually chose, not whichever one is
    # convenient: killing the idle one would prove nothing at all.
    if [ "$before" = "lab-a" ]; then
        victim='lab::stop_pid "$RELAY_PID"; RELAY_PID='
    else
        victim='lab::stop_pid "$RELAY_B_PID"; RELAY_B_PID='
    fi

    lab::loss_during alpha "$BETA_IP" 40 "$victim"
    loss="$LOSS_PCT"

    if ! lab::wait_relay_change alpha "$before" 60; then
        record "failover/recovery" FAIL "still on $before after it was killed (${loss}% loss over 40s)"
        return
    fi

    after="$(lab::relay_name alpha)"
    if lab::wait_tunnel alpha "$BETA_IP" 30; then
        record "failover/recovery" PASS "moved from $before to $after and traffic resumed (${loss}% loss over 40s)"
    else
        record "failover/recovery" FAIL "moved from $before to $after but traffic did not resume (${loss}% loss)"
    fi

    # Put the fleet back. Leaving a relay dead made the next scenario fail for
    # this one's reasons — which did find a real defect, but a gate whose
    # results depend on what ran before it cannot be read.
    [ -z "${RELAY_PID:-}" ]   && lab::relay_start >/dev/null
    [ -z "${RELAY_B_PID:-}" ] && lab::relay_b_start >/dev/null

    return 0
}

# A relay dying is not a hypothetical: it is a box in a datacentre. What
# matters is how long the traffic is gone for, so this measures rather than
# asserting a binary.
scenario_relay_down() {
    step "relay down — traffic must come back on another path"
    fixture symmetric

    if ! lab::wait_tunnel alpha "$BETA_IP" 90; then
        record "relay-down/tunnel" FAIL "no relayed tunnel to test with"
        return
    fi

    local loss
    lab::loss_during alpha "$BETA_IP" 40 \
        'lab::stop_pid "$RELAY_PID"; RELAY_PID=; sleep 2; lab::relay_start >/dev/null'
    loss="$LOSS_PCT"

    if lab::wait_tunnel alpha "$BETA_IP" 60; then
        record "relay-down/recovery" PASS "traffic resumed after the relay was killed mid-stream (${loss}% loss over 40s)"
    else
        record "relay-down/recovery" FAIL "traffic never resumed after the relay was killed (${loss}% loss)"
    fi
}

# Billing. A number that becomes an invoice has to be checked against
# something that did not produce it, so this compares the panel's metered
# figure against the relay's own count of what it forwarded.
#
# The tolerance is not slack for the accounting to be wrong in. It covers the
# traffic the drill does not control: WireGuard handshakes and keepalives cross
# the relay too, and the two counters are sampled a few seconds apart.
scenario_accounting() {
    step "accounting — the billed figure must match what the relay carried"
    fixture symmetric

    if ! lab::wait_tunnel alpha "$BETA_IP" 90; then
        record "accounting/tunnel" FAIL "no relayed tunnel to meter"
        return
    fi

    if [ "$(lab::settled_path alpha 30)" != "relay" ]; then
        record "accounting/tunnel" FAIL "the pair is not relayed, so there is nothing to bill"
        return
    fi

    local payload=1000 packets=400 received
    received="$(lab::push_bytes alpha "$BETA_IP" "$payload" "$packets")"
    if [ "${received:-0}" -lt "$packets" ]; then
        record "accounting/load" FAIL "only ${received:-0} of $packets packets made it; the figures would be meaningless"
        return
    fi
    record "accounting/load" PASS "pushed $packets packets of ${payload}B each, all received"

    # Both counters are sampled periodically — the agents on their heartbeat,
    # the relay on its 30-second housekeeping tick. Reading either too early
    # measures the sampling, not the accounting.
    sleep 45

    local billed carried
    billed="$(lab::panel_relay_bytes "$TENANT")"
    carried="$(lab::relay_reported_bytes "$TENANT")"

    if [ "${carried:-0}" -le 0 ]; then
        record "accounting/agree" FAIL "the relay reported carrying nothing, so there is nothing to compare against"
        return
    fi
    if [ "${billed:-0}" -le 0 ]; then
        record "accounting/agree" FAIL "the relay carried $carried bytes and the panel billed nothing"
        return
    fi

    # Integer percentage difference, because this runs in bash and the answer
    # only needs to be right to the nearest point.
    local diff spread
    diff=$(( billed > carried ? billed - carried : carried - billed ))
    spread=$(( diff * 100 / carried ))

    if [ "$spread" -le "$ACCOUNTING_TOLERANCE" ]; then
        record "accounting/agree" PASS "panel billed $billed bytes, relay carried $carried — within ${spread}% (tolerance ${ACCOUNTING_TOLERANCE}%)"
    else
        record "accounting/agree" FAIL "panel billed $billed bytes, relay carried $carried — ${spread}% apart (tolerance ${ACCOUNTING_TOLERANCE}%)"
    fi
}

# R4 has a second half: approval can be taken away, and when it is, the
# traffic has to stop. Ten seconds is the number in the requirement.
scenario_revocation() {
    step "revocation — a revoked device loses traffic within 10 seconds"
    fixture nat

    if ! lab::wait_tunnel alpha "$BETA_IP" 60; then
        record "revoke/tunnel" FAIL "no tunnel to revoke"
        return
    fi

    php "$LAB_DIR/lab-setup.php" revoke "$UID_ALPHA" >/dev/null || die "revocation failed"
    local started elapsed=0
    started="$(date +%s)"
    while [ "$elapsed" -lt 20 ]; do
        lab::ping alpha "$BETA_IP" 1 || break
        sleep 1
        elapsed=$(( $(date +%s) - started ))
    done

    if [ "$elapsed" -le 10 ]; then
        record "revoke/cutoff" PASS "traffic stopped ${elapsed}s after revocation"
    else
        record "revoke/cutoff" FAIL "traffic still flowing ${elapsed}s after revocation (limit is 10s)"
    fi
}

