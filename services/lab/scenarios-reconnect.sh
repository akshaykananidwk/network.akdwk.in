#!/usr/bin/env bash
# Reconnection after a reboot or an address change (defect 24).
#
# Sourced by scenarios.sh.
#
# Two real Windows PCs on 1.9.3 connected directly, were rebooted, and never
# got back. The coordinator log shows why it is worth a scenario of its own:
# everything up to the reconnection worked. The service auto-started, the
# heartbeat resumed, the agent said hello — and then said it again every twenty
# seconds for three minutes, while the panel showed both machines Online and
# the laptop's endpoint still reading the address it had before the reboot.
#
# The interesting part is what was NOT in that log: no endpoint push to the
# peer that stayed up, and no relay offer to the side that came back. The pair
# was introduced once, at a moment when only one of them was there, and nothing
# reconsidered it afterwards.
#
# Two shapes, because they fail differently:
#
#   reconnect      — B restarts on a NEW public address while A stays up. This
#                    is the mobile-IP case: A holds an address that is now
#                    somebody else's.
#   reconnect-both — both restart, B returning a minute after A. This is the
#                    power-cut case, and the one where a relay gets offered to
#                    a side whose partner has not arrived yet.
#
# Both assert the same thing, which is the customer's requirement: back to a
# working path within 30 seconds, with nothing typed.

# How long a reconnection may take. This is the product requirement, not a
# tuning knob — a laptop that takes two minutes to come back is a laptop the
# customer reports as broken.
RECONNECT_BUDGET=${RECONNECT_BUDGET:-30}

# Behind CGNAT, and the first version of this was not — which made it useless.
#
# On the ordinary two-NAT topology both scenarios passed on the defective code,
# with traffic back in one second. The reason is that the lab's "cone" NAT
# gives each side a static inbound DNAT on the agent's port, so the side that
# comes back can punch straight at its partner and be answered. Nothing the
# coordinator does is needed, and nothing it fails to do is noticed.
#
# The field case is a laptop behind carrier-grade NAT. It is not reachable
# inbound by anybody, so a punch from the returning side cannot land and the
# pair needs the coordinator to re-introduce them and grant a relay — which is
# precisely what did not happen. This is the same lesson the late-joiner
# scenario learned: put the drill where the defect lives, or it reports nothing
# and calls it a pass.
scenario_reconnect() {
    step "a peer restarts on a new address — the pair must find each other again"

    fixture cgnat symmetric

    if ! lab::wait_tunnel alpha "$BETA_IP" 90; then
        record "reconnect/paired" FAIL "the pair never connected in the first place"
        lab::tail_log alpha-up 12
        return
    fi
    record "reconnect/paired" PASS "alpha and beta are connected before anything is restarted"

    # Settled: both ends are pinging rather than saying hello, which is the
    # state the field was in when the reboot happened.
    sleep 20

    local before
    before="$(lab::settled_path alpha 10)"
    record "reconnect/before" INFO "the path before the restart is $before"

    # Beta goes away and comes back somewhere else. 10.0.0.11 → 10.0.0.21 is
    # the lab's version of a phone getting a different mobile IP: alpha now
    # holds an address that beta is no longer behind.
    lab::down_agent beta
    lab::renumber beta natgw-b 11 21 192.168.20 symmetric

    local restarted_at
    restarted_at="$(date +%s)"

    lab::up beta

    if ! lab::wait_up beta 60; then
        record "reconnect/restarted" FAIL "beta never came back up at all"
        lab::tail_log beta-up 12
        return
    fi
    record "reconnect/restarted" PASS "beta came back on 10.0.0.21, a different public address"

    if lab::wait_tunnel alpha "$BETA_IP" "$((RECONNECT_BUDGET + 15))"; then
        local took=$(( $(date +%s) - restarted_at ))
        if [ "$took" -le "$RECONNECT_BUDGET" ]; then
            record "reconnect/restored" PASS "traffic resumed ${took}s after the restart"
        else
            record "reconnect/restored" FAIL \
                "traffic resumed, but after ${took}s — the budget is ${RECONNECT_BUDGET}s"
        fi
    else
        record "reconnect/restored" FAIL "alpha never reached beta again after it moved"
        lab::tail_log alpha-up 15
        lab::tail_log coordinator 15
        return
    fi

    # The other direction as well. A pair where only one side can start the
    # conversation is half a network.
    if lab::wait_tunnel beta "$ALPHA_IP" 30; then
        record "reconnect/mutual" PASS "and beta reached alpha"
    else
        record "reconnect/mutual" FAIL "beta cannot reach alpha, though alpha can reach beta"
    fi

    # (d) The panel has to follow. An endpoint that still reads the old
    # address is what an administrator is looking at while they are told
    # everything is fine.
    # Waited for, not sampled once. The endpoint reaches the panel on a
    # heartbeat, which is slower than the data path recovering — the first
    # version of this check read it seconds after traffic resumed and called a
    # timing difference a defect. It is given the same budget as everything
    # else here: a page that is still wrong 30 seconds later is wrong.
    local endpoint
    endpoint="$(lab::wait_endpoint "$UID_BETA" 10.0.0.21 "$RECONNECT_BUDGET")"
    if [ "${endpoint%%:*}" = "10.0.0.21" ]; then
        record "reconnect/endpoint" PASS "the panel shows beta at its current address ($endpoint)"
    else
        record "reconnect/endpoint" FAIL \
            "the panel still shows beta at ${endpoint:-nothing}, not 10.0.0.21"
    fi

    assert_split_tunnel alpha "reconnect/R1"
}

scenario_reconnect_both() {
    step "both peers restart, one returning a minute after the other"

    fixture cgnat symmetric

    if ! lab::wait_tunnel alpha "$BETA_IP" 90; then
        record "both/paired" FAIL "the pair never connected in the first place"
        lab::tail_log alpha-up 12
        return
    fi
    record "both/paired" PASS "alpha and beta are connected before the power cut"

    sleep 20

    # Everything goes down, which is what a power cut does.
    lab::down_agent alpha
    lab::down_agent beta

    # Alpha comes back first and is alone for a while. In the field this is
    # where the coordinator offered a relay to the one machine that was there,
    # for a peer that could not possibly answer — and then never revisited it.
    lab::up alpha
    if ! lab::wait_up alpha 60; then
        record "both/first-back" FAIL "alpha never came back up"
        lab::tail_log alpha-up 12
        return
    fi
    record "both/first-back" PASS "alpha is back, with its peer still absent"

    # A minute, as reported. Long enough that everything the coordinator did
    # for alpha was done while beta did not exist.
    sleep 60

    lab::renumber beta natgw-b 11 22 192.168.20 symmetric

    local returned_at
    returned_at="$(date +%s)"

    lab::up beta
    if ! lab::wait_up beta 60; then
        record "both/second-back" FAIL "beta never came back up"
        lab::tail_log beta-up 12
        return
    fi
    record "both/second-back" PASS "beta is back a minute later, on a new address"

    if lab::wait_tunnel alpha "$BETA_IP" "$((RECONNECT_BUDGET + 15))"; then
        local took=$(( $(date +%s) - returned_at ))
        if [ "$took" -le "$RECONNECT_BUDGET" ]; then
            record "both/restored" PASS "traffic resumed ${took}s after the second machine returned"
        else
            record "both/restored" FAIL \
                "traffic resumed, but after ${took}s — the budget is ${RECONNECT_BUDGET}s"
        fi
    else
        record "both/restored" FAIL "the pair never reconnected after both restarted"
        lab::tail_log alpha-up 15
        lab::tail_log coordinator 20
        return
    fi

    # (c) The agent said hello every twenty seconds for three minutes in the
    # field, which is what it does when the coordinator's answer never
    # arrives. A settled agent sends hellos at helloInterval, five minutes
    # apart — so more than a handful over a short run means the same thing is
    # happening here.
    local hellos
    hellos="$(grep -c 'hello from' "$LOGS/coordinator.log" 2>/dev/null)" || hellos=0
    if [ "$hellos" -le 12 ]; then
        record "both/settled" PASS "the agents settled: $hellos hello(s) in total"
    else
        record "both/settled" FAIL \
            "$hellos hellos — an agent that is not being answered re-announces forever"
    fi

    assert_split_tunnel alpha "both/R1"
}

# Two PCs on one router, and a third somewhere else (defect 23).
#
# The commonest customer layout there is: a shop or a hotel with several PCs
# behind one broadband router. It had never been built here — every NAT
# topology in this lab put exactly one device behind each gateway — so none of
# what it breaks could be seen.
#
# What it breaks, from the field: both agents bind the same fixed UDP 51820, so
# only one of them can hold the public :51820 mapping. The laptop's hellos
# reached the coordinator from :51820 every twenty seconds and the replies went
# to the other PC; the laptop reported "Coordinator: not reachable" while its
# own log said it was announcing, and the pair sat at "connecting" until one
# machine was moved to a different ISP.
#
# Three assertions, because three things have to be true and the first is the
# one nobody thinks to check:
#
#   shared/acked   — BOTH devices are being answered by the coordinator.
#   shared/lan     — the two on one router reach each other, over the LAN.
#   shared/offsite — and both still reach the machine behind another router.
scenario_shared_router() {
    step "two PCs behind one router, and a third elsewhere"

    # `forward` adds the stale :51820 forward that made the field case fatal
    # rather than merely fragile.
    fixture_shared shared forward

    local ns ready=1
    for ns in alpha gamma beta; do
        lab::wait_up "$ns" 60 || { record "shared/up" FAIL "$ns never came up"; ready=0; }
    done
    [ "$ready" -eq 1 ] || { lab::tail_log alpha-up 12; return; }
    record "shared/up" PASS "all three agents are up"

    # Both machines on the shared router must be getting answers. An agent that
    # is not acked re-announces every twenty seconds for ever, which is exactly
    # what the field log showed — so the count of hellos is the tell.
    sleep 45

    local a_hellos g_hellos
    a_hellos="$(grep -c "hello from $UID_ALPHA" "$LOGS/coordinator.log" 2>/dev/null)" || a_hellos=0
    g_hellos="$(grep -c "hello from $UID_GAMMA" "$LOGS/coordinator.log" 2>/dev/null)" || g_hellos=0

    if [ "$a_hellos" -le 4 ] && [ "$g_hellos" -le 4 ]; then
        record "shared/acked" PASS \
            "both devices on the shared router are being answered (${a_hellos} and ${g_hellos} hellos)"
    else
        record "shared/acked" FAIL \
            "a device is re-announcing unanswered: alpha ${a_hellos}, gamma ${g_hellos} hello(s)"
    fi

    # The pair on one router. This is the case that must not go out to the
    # internet and back: they are on the same switch.
    if lab::wait_tunnel alpha "$GAMMA_IP" 30; then
        record "shared/lan" PASS "the two PCs on one router reach each other"
    else
        record "shared/lan" FAIL "two PCs on the same router cannot reach each other"
        lab::tail_log alpha-up 12
        lab::tail_log coordinator 12
    fi

    # And the branch office still works, in both directions, because a fix for
    # the LAN case that broke the remote case would be no fix at all.
    local offsite=0
    lab::wait_tunnel alpha "$BETA_IP" 30 || offsite=1
    lab::wait_tunnel gamma "$BETA_IP" 30 || offsite=1

    if [ "$offsite" -eq 0 ]; then
        record "shared/offsite" PASS "and both reach the device behind another router"
    else
        record "shared/offsite" FAIL "a device behind another router is unreachable from the shared site"
    fi

    assert_split_tunnel alpha "shared/R1"
}
