#!/usr/bin/env bash
# The network that carries nothing but what a browser uses.
#
# Sourced by scenarios.sh.
#
# A customer's laptop on an office Wi-Fi announced itself to the coordinator
# every five to ten seconds for an entire afternoon and was never once
# answered. The coordinator's log shows the announcements arriving. The relay's
# log shows the offer being made and the laptop never binding. The same laptop
# on a phone hotspot that morning connected directly in seconds.
#
# The office allowed outbound UDP and dropped the replies. Nothing in the
# product could work around that, because everything — including asking the
# coordinator for help — needed UDP to come back. The only honest answers were
# "change your firewall", which no customer will do, or a path on the port a
# browser already uses.
#
# Two shapes, because they are different faults that present identically:
#
#   https-fallback — ALL UDP dropped, in and out. The hotel, the guest VLAN,
#                    the locked-down site. Nothing UDP ever leaves or arrives.
#   https-inbound  — outbound UDP allowed, inbound dropped. The exact office
#                    case: from inside it looks like everything is working.
#
# and a third that proves it is a fallback rather than a destination:
#
#   https-recover  — the block is lifted, and the pair must go back to UDP
#                    without anybody restarting anything.

# The budget. A customer plugging a laptop in does not wait longer than this
# before calling it broken, and it is the number the requirement names.
FALLBACK_BUDGET=${FALLBACK_BUDGET:-30}

# The router in front of alpha, which is where the block goes.
#
# Not inside alpha itself, and the difference is the whole point. A DROP rule
# in a machine's own OUTPUT chain makes its sends fail with an error, which is
# a Windows-Firewall-shaped fault and a different one from this. The office
# that caused all this allowed the packets out of the laptop perfectly well
# and killed them at the building's edge — so from inside the machine every
# send succeeded and only the silence afterwards said anything was wrong.
#
# Blocking at the customer premises router reproduces that exactly. The agent
# is separately made to fall back when its own sends are refused, which is the
# other shape; see Unreachable() in the discovery client.
ALPHA_ROUTER=natgw-alpha
ALPHA_LAN_IP=192.168.10.2

# lab::block_udp drops UDP at alpha's router.
#
#   lab::block_udp both     nothing UDP crosses in either direction
#   lab::block_udp inbound  it leaves, the replies never come back
lab::block_udp() {
    local direction=${1:-both}

    ip netns exec "$ALPHA_ROUTER" true 2>/dev/null \
        || die "$ALPHA_ROUTER does not exist; this scenario needs the cgnat topology"

    # Towards the machine. This alone is the office case.
    ip netns exec "$ALPHA_ROUTER" iptables -I FORWARD 1 -p udp -d "$ALPHA_LAN_IP" -j DROP \
        || die "could not block inbound UDP at $ALPHA_ROUTER"

    if [ "$direction" = "both" ]; then
        ip netns exec "$ALPHA_ROUTER" iptables -I FORWARD 1 -p udp -s "$ALPHA_LAN_IP" -j DROP \
            || die "could not block outbound UDP at $ALPHA_ROUTER"
    fi
}

lab::unblock_udp() {
    ip netns exec "$ALPHA_ROUTER" iptables -D FORWARD -p udp -d "$ALPHA_LAN_IP" -j DROP 2>/dev/null || true
    ip netns exec "$ALPHA_ROUTER" iptables -D FORWARD -p udp -s "$ALPHA_LAN_IP" -j DROP 2>/dev/null || true
}

# The two hooks the fixture runs before any agent starts. They have to be
# functions with no arguments, because that is what FIXTURE_HOOK is.
lab::block_alpha_all_udp()     { lab::block_udp both; }
lab::block_alpha_inbound_udp() { lab::block_udp inbound; }

# fallback_carried reports how many sessions the relay has taken over HTTPS.
#
# Read from the relay's own log rather than from the agent, because the whole
# question is whether the traffic really left the machine by that path. An
# agent can believe anything.
lab::fallback_binds() {
    grep -c "over the HTTPS fallback" "$LOGS/relay-a.log" 2>/dev/null || true
}

# assert_fallback is the body both blocked scenarios share.
#
#   $1  the scenario name, for the result rows
#   $2  what was blocked, for the detail text
assert_fallback() {
    local name=$1 blocked=$2 started took path binds

    started="$(date +%s)"

    if ! lab::wait_tunnel alpha "$BETA_IP" "$((FALLBACK_BUDGET + 20))"; then
        record "$name/connected" FAIL "no path to the peer at all with $blocked"
        lab::tail_log alpha-up 20
        record "$name/budget" FAIL "never connected, so it cannot have been inside ${FALLBACK_BUDGET}s"

        return
    fi

    took=$(( $(date +%s) - started ))
    record "$name/connected" PASS "traffic flows with $blocked"

    if [ "$took" -le "$FALLBACK_BUDGET" ]; then
        record "$name/budget" PASS "connected in ${took}s, inside the ${FALLBACK_BUDGET}s budget"
    else
        record "$name/budget" FAIL "took ${took}s, over the ${FALLBACK_BUDGET}s budget"
    fi

    # Connected is not enough: it has to be connected the way this scenario
    # claims. A pair that somehow found a UDP path would pass the ping and
    # prove nothing about the fallback.
    path="$(lab::settled_path alpha 20)"
    if [ "$path" = "relay-https" ]; then
        record "$name/path" PASS "the agent reports relay-https, not a UDP path"
    else
        record "$name/path" FAIL "the agent reports '$path' on a network with $blocked"
    fi

    binds="$(lab::fallback_binds)"
    if [ "${binds:-0}" -ge 1 ]; then
        record "$name/relay" PASS "the relay logged $binds bind(s) over the HTTPS fallback"
    else
        record "$name/relay" FAIL "the relay never logged a fallback bind"
        lab::tail_log relay-a 15
    fi
}

# ALL UDP dropped, in and out. The hotel and the locked-down office.
scenario_https_fallback() {
    step "a device whose network carries no UDP at all must still connect"

    FIXTURE_HOOK=lab::block_alpha_all_udp
    fixture cgnat symmetric
    FIXTURE_HOOK=""

    assert_fallback "https-fallback" "all UDP dropped in both directions"

    lab::unblock_udp
}

# The field case, and the harder one to see: outbound UDP leaves the machine
# normally, so every local test says the network is fine.
scenario_https_inbound_blocked() {
    step "outbound UDP allowed, replies dropped — the office Wi-Fi case"

    FIXTURE_HOOK=lab::block_alpha_inbound_udp
    fixture cgnat symmetric
    FIXTURE_HOOK=""

    assert_fallback "https-inbound" "inbound UDP replies dropped"

    lab::unblock_udp
}

# And the other half of the requirement: it is a fallback, not a destination.
scenario_https_recover() {
    step "when UDP starts working again the pair must leave the fallback by itself"

    FIXTURE_HOOK=lab::block_alpha_all_udp
    fixture cgnat symmetric
    FIXTURE_HOOK=""

    if ! lab::wait_tunnel alpha "$BETA_IP" "$((FALLBACK_BUDGET + 20))"; then
        record "https-recover/connected" FAIL "never connected over the fallback to begin with"
        lab::tail_log alpha-up 20

        return
    fi

    if [ "$(lab::settled_path alpha 20)" != "relay-https" ]; then
        record "https-recover/connected" FAIL "did not start on the fallback, so there is nothing to recover from"

        return
    fi
    record "https-recover/connected" PASS "connected over HTTPS while UDP was blocked"

    # The network is fixed — a guest VLAN swapped for the real one, a rule
    # withdrawn. Nothing on the machine changes and nobody restarts anything.
    local lifted_at
    lifted_at="$(date +%s)"
    lab::unblock_udp

    # Long enough for the agent's next announcements to be answered, the peer
    # to be re-introduced and a punch to land. The agent keeps sending on UDP
    # the whole time it is on the fallback, which is what makes this possible
    # without a restart.
    local deadline=$(( lifted_at + 90 )) now
    while [ "$(date +%s)" -lt "$deadline" ]; do
        now="$(lab::path alpha)"
        case "$now" in
            direct|relay-udp)
                record "https-recover/upgraded" PASS \
                    "back to $now $(( $(date +%s) - lifted_at ))s after the block was lifted, with nothing restarted"

                if lab::ping alpha "$BETA_IP" 3; then
                    record "https-recover/traffic" PASS "traffic still flows on the recovered path"
                else
                    record "https-recover/traffic" FAIL "the path changed but traffic stopped"
                fi

                return
                ;;
        esac
        sleep 2
    done

    record "https-recover/upgraded" FAIL "still on the fallback 90s after UDP was working again"
    record "https-recover/traffic" INFO "not measured: the path never changed"
}
