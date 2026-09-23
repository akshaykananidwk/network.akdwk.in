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

# The laptop's actual story, and the one the two scenarios above do not tell.
#
# They block UDP before the agent starts, so the agent has never seen a
# working socket and the switch is a cold start. The laptop had a working
# socket all morning on a phone hotspot and was then carried into an office
# where it did not. That transition has its own way of going wrong, and it
# did: the agent decides UDP is hopeless after four seconds, but the proof
# that UDP works is allowed to be forty-five seconds old — so for the
# difference between them the agent would open the fallback and then refuse to
# use it, because a stamp from the network it had left still said UDP was
# fine. Forty seconds of a thirty-second budget, on exactly the machine this
# release is for.
scenario_https_switch() {
    step "a working machine is carried onto a network that blocks UDP"

    fixture cgnat symmetric

    if ! lab::wait_tunnel alpha "$BETA_IP" 90; then
        record "https-switch/before" FAIL "the pair never connected on a working network"
        lab::tail_log alpha-up 15

        return
    fi

    local before
    before="$(lab::settled_path alpha 30)"
    case "$before" in
        relay-https)
            record "https-switch/before" FAIL "it was already on the fallback, so nothing switches"

            return
            ;;
        *)
            record "https-switch/before" PASS "connected over $before before anything changes"
            ;;
    esac

    # Settled: pinging rather than saying hello, which is the state a laptop
    # is in when somebody closes the lid and drives to the office.
    sleep 20

    local blocked_at
    blocked_at="$(date +%s)"
    lab::block_udp both

    # Traffic has to come back, on the fallback, inside the same budget a
    # cold start gets. Nothing is restarted and nothing is touched.
    if lab::wait_tunnel alpha "$BETA_IP" "$((FALLBACK_BUDGET + 20))"; then
        local took=$(( $(date +%s) - blocked_at ))
        if [ "$took" -le "$FALLBACK_BUDGET" ]; then
            record "https-switch/budget" PASS "back in ${took}s after the network changed under it"
        else
            record "https-switch/budget" FAIL "took ${took}s, over the ${FALLBACK_BUDGET}s budget"
        fi
    else
        record "https-switch/budget" FAIL \
            "traffic never came back within $((FALLBACK_BUDGET + 20))s after UDP was blocked"
        lab::tail_log alpha-up 20
    fi

    if lab::wait_path alpha relay-https 20; then
        record "https-switch/path" PASS "and it is on the HTTPS path, not a stale UDP one"
    else
        record "https-switch/path" FAIL "the agent reports '$(lab::path alpha)' on a network with no UDP"
    fi

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

# --------------------------------------------------- the lost relay offer
#
# A home laptop on its own ISP went from "Online · relay-udp" to
# "Online · connecting" by itself and never came back. The diagnostics bundle
# says why: one "asking for a relay" in the log, and then ten minutes of
# punching into a network that could not carry a direct path, with the
# coordinator answering the whole time and both peers reporting rx_bytes 0
# and no handshake ever.
#
# The agent asked once. The entry recording the request was written before the
# request was sent, the rebind loop skips a peer whose relay address is still
# zero, and nothing revisited it — so one lost datagram, the request going out
# or the offer coming back, stranded that pair until somebody restarted the
# service. It is not a rare shape: the request and the answer are single UDP
# packets, and that laptop had just been given a new socket by a configuration
# reload.
#
# Reproduced by dropping UDP at alpha's router across the window where the
# offer would arrive, then lifting it and leaving everything else alone.
# Nothing is restarted. Red before the fix — alpha never asks again — and
# green after.
scenario_relay_offer_lost() {
    step "a relay offer that never arrives must be asked for again"

    # CGNAT on alpha and symmetric on beta, so a direct path cannot be punched
    # and a relay is the only way these two can reach each other. It is also
    # the topology that has a customer-premises router to drop packets at,
    # which is where the loss has to happen: the agent's own sends must
    # succeed and only the silence afterwards say anything, exactly as on the
    # laptop this came from.
    fixture cgnat symmetric

    # Let the pair ask for a relay, and lose the answer. The window starts
    # before the punch deadline expires and covers the offer.
    lab::block_udp both
    sleep 25
    lab::unblock_udp

    # From here the network is perfect. Nothing is restarted, no address
    # changes, and the coordinator has been up and answering throughout.
    if lab::wait_tunnel alpha "$BETA_IP" 60; then
        record "offerlost/recovered" PASS "the pair connected after the offer was lost, with nothing restarted"
    else
        record "offerlost/recovered" FAIL \
            "the pair never connected: the relay was asked for once and the answer was lost"
        lab::tail_log alpha-up 20

        return
    fi

    local path
    path="$(lab::settled_path alpha 20)"
    case "$path" in
        relay*)
            record "offerlost/path" PASS "and it is on a relay, which is the only path these two have"
            ;;
        *)
            record "offerlost/path" FAIL "the path is '$path', so this is not testing what it says"
            ;;
    esac
}
