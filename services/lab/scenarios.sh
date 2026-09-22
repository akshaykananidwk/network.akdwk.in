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

    case "$(lab::settled_path alpha 30)" in
        relay-udp|relay-https) ;;
        *)
            record "accounting/tunnel" FAIL "the pair is not relayed, so there is nothing to bill"
            return
            ;;
    esac

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

    local billed carried agent
    billed="$(lab::panel_relay_bytes "$TENANT")"
    agent="$(lab::panel_agent_bytes "$TENANT")"
    carried="$(lab::relay_reported_bytes "$TENANT")"

    # The billed figure must come from the relay, not from the agents. If the
    # two are identical the panel may simply be recording whichever arrived
    # last, so the agents' own figure is checked separately below.
    if [ "${billed:-0}" -le 0 ] && [ "${agent:-0}" -gt 0 ]; then
        record "accounting/source" FAIL "nothing was billed, but the agents reported ${agent} bytes — the relay is not the source of record"
        return
    fi
    record "accounting/source" PASS "the billed figure came from the relay (agents separately reported ${agent:-0} bytes)"

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

# The ACL scenarios live next door, so neither file outgrows what somebody can
# read in one sitting.
source "$LAB/scenarios-acl.sh"
source "$LAB/scenarios-enrol.sh"
source "$LAB/scenarios-gateway.sh"
source "$LAB/scenarios-dns.sh"
source "$LAB/scenarios-reconnect.sh"
source "$LAB/scenarios-resolved.sh"
source "$LAB/scenarios-fallback.sh"

# ---------------------------------------------------------------- CGNAT

# Two layers of carrier NAT, and a relay named the way DEPLOY says to name one.
#
# This is the Jio-hotspot case and the scenario that would have caught defect
# 16. The field failure was not that hole punching failed — behind a
# carrier-grade NAT it is supposed to fail — it was that the relay was never
# contacted at all. The coordinator logged that it had offered the relay to
# both devices; a twenty-second capture on the relay's own ports saw nothing.
#
# The lab could not have shown it, because the lab configured its relays by IP
# address and the agent's offer handler parsed only IP addresses. So the
# assertion that matters here is not "a tunnel formed" — the older relay
# scenarios already claim that — it is that the relay was *used*: its own log
# has to show a bind from both ends.
scenario_cgnat() {
    step "CGNAT — two layers of carrier NAT, relay named by hostname"
    # Beta symmetric too: with a cone peer the pair punches through from
    # alpha's side and never needs the relay, which is the product being right
    # and is a different scenario — see scenario_cgnat_direct.
    fixture cgnat symmetric

    if lab::wait_tunnel alpha "$BETA_IP" 120; then
        record "cgnat/tunnel" PASS "alpha pinged $BETA_IP from behind two layers of NAT"
    else
        record "cgnat/tunnel" FAIL "no tunnel formed within 120s"
        lab::tail_log relay-a 12
        return
    fi

    check "cgnat/path" "the path is a relay, as it must be" \
        lab::wait_path alpha relay 40

    # The assertion defect 16 needed. A relay that was offered and never
    # contacted looks identical from the coordinator's side.
    # grep -c prints 0 and exits 1 when it finds nothing, so "|| echo 0"
    # appends a second line and the comparison below then fails with "integer
    # expression expected" — which is what the first run of this did.
    local binds
    binds="$(grep -c 'bound ' "$LOGS/relay-a.log" 2>/dev/null)" || binds=0
    if [ "${binds:-0}" -ge 2 ]; then
        record "cgnat/relay-used" PASS "the relay bound $binds session(s); both ends reached it"
    else
        record "cgnat/relay-used" FAIL "the relay logged $binds bind(s) — it was offered and not contacted"
        lab::tail_log alpha-up 12
    fi

    # And the reason it works: the offer carried a name, and the agent resolved
    # it. A regression that reverts to IP-only parsing fails here loudly.
    if grep -qE "relay .* (resolved to|offered)" "$LOGS/alpha-up.log" 2>/dev/null \
        || ! grep -q 'is unusable' "$LOGS/alpha-up.log" 2>/dev/null; then
        record "cgnat/relay-name" PASS "the agent accepted a relay named $RELAY_HOST"
    else
        record "cgnat/relay-name" FAIL "the agent refused the relay endpoint as unusable"
        grep 'unusable' "$LOGS/alpha-up.log" | head -3 | sed 's/^/      /'
    fi

    assert_split_tunnel alpha "cgnat/R1"
}

# ----------------------------------------------------------- late joiner

# A device approved after another is already connected.
#
# Every scenario before this one approved both devices before either agent
# started, so the coordinator learned a complete peer set on the first hello
# and an already-running agent never had to learn about a newcomer. That is
# not how a customer site grows: the first machine is installed, and the
# second is installed later — sometimes minutes later, sometimes a week.
#
# In the field the already-running device never found out. It held the empty
# allowed set it was given when it announced alone, and logged
#
#   Failed to send handshake initiation: no known endpoint for peer
#
# every five seconds until somebody restarted its service. The fix has to work
# without touching the running agent, so this scenario never touches it.
scenario_late_joiner() {
    step "a device approved after another is already connected"

    # Behind CGNAT deliberately, and this took a wrong turn first.
    #
    # On an ordinary NAT this scenario passes even with every one of the fixes
    # reverted, because there are two independent ways for an agent to learn a
    # peer's address: the coordinator introduces it, and the panel publishes
    # `last_endpoint` in the configuration. On a plain NAT the second is enough
    # — the agent punches straight to it and never needs the first.
    #
    # Behind carrier-grade NAT it is not enough: punching to that address
    # cannot work, so the pair needs a relay, and the coordinator only grants a
    # relay to a pair it believes is allowed to talk. With a stale peer set it
    # refuses — "that peer is not permitted" — and the two machines are stuck
    # exactly as they were in the field.
    #
    # So this is the topology where the defect actually lives, and a scenario
    # that passes on the old code is a scenario that proves nothing.
    fixture_solo cgnat symmetric

    # Alpha is up, alone, and has been told about nobody. It must be healthy:
    # a device with no peers is not a broken device.
    if lab::wait_up alpha 60; then
        record "late/alone" PASS "alpha is up and running with no peers at all"
    else
        record "late/alone" FAIL "alpha never came up on its own"
        lab::tail_log alpha-up 12
        return
    fi

    # Long enough that alpha has settled into pinging rather than saying
    # hello. This is the state the defect lived in: a hello carries a token and
    # makes the coordinator re-ask the panel, and a ping does not.
    sleep 25

    local joined_at
    joined_at="$(date +%s)"

    join_late

    # Generous: the punch deadline, then a relay request, then a bind, then a
    # handshake. In the field this never completed at all.
    if lab::wait_tunnel alpha "$BETA_IP" 150; then
        local took=$(( $(date +%s) - joined_at ))
        record "late/reached" PASS "alpha reached the newcomer ${took}s after approval, without a restart"
    else
        record "late/reached" FAIL "alpha never reached the device approved after it"
        # The coordinator's own words are the diagnosis: "refused a relay
        # request … that peer is not permitted" means its stored peer set for
        # alpha is stale, which is the whole of defect 15.
        lab::tail_log alpha-up 15
        lab::tail_log coordinator 15
        return
    fi

    # The other direction too. The introduction is mutual, and the deadlock in
    # the field was that neither end could be told about the other.
    if lab::wait_tunnel beta "$ALPHA_IP" 90; then
        record "late/mutual" PASS "and the newcomer reached alpha"
    else
        record "late/mutual" FAIL "the newcomer could not reach alpha"
    fi

    # Nothing restarted alpha. If the agent had exited and been restarted by
    # anything, its log would start again — so the log still carrying its
    # first line is the proof that this was a live recovery.
    local ups
    ups="$(grep -c 'interface .* is up' "$LOGS/alpha-up.log" 2>/dev/null)" || ups=0
    if [ "${ups:-0}" -eq 1 ]; then
        record "late/no-restart" PASS "alpha brought its interface up exactly once"
    else
        record "late/no-restart" FAIL "alpha's interface came up $ups time(s); it was restarted"
    fi

    assert_split_tunnel alpha "late/R1"
}

# The other half of the CGNAT question, and the cheerful one.
#
# A customer behind carrier-grade NAT is not automatically condemned to a
# relay: if the machine at the other end is reachable inbound — a shop with a
# decent router — the CGNAT side can open the path from its own side and the
# pair goes direct, with no traffic through our servers and nothing on the
# bandwidth bill. The first run of the scenario above proved this by accident,
# which is a good enough reason to assert it on purpose.
scenario_cgnat_direct() {
    step "CGNAT to a reachable peer — a direct path is still possible"
    fixture cgnat cone

    if ! lab::wait_tunnel alpha "$BETA_IP" 120; then
        record "cgnat-direct/tunnel" FAIL "no tunnel formed within 120s"
        lab::tail_log alpha-up 12
        return
    fi

    local path
    path="$(lab::settled_path alpha 40)"

    if [ "$path" = "direct" ]; then
        record "cgnat-direct/path" PASS "direct from behind two layers of NAT; no relay needed"
    elif [ "${path#relay}" != "$path" ]; then
        # Not a failure — a relayed path is a working path — but it is a
        # regression in quality and worth seeing, because it is the difference
        # between a customer costing nothing to serve and costing bandwidth.
        record "cgnat-direct/path" FAIL "fell back to a relay although the peer was reachable inbound"
    else
        record "cgnat-direct/path" FAIL "traffic flows but the agent never reported a path"
    fi

    assert_split_tunnel alpha "cgnat-direct/R1"
}
