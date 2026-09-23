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

# ------------------------------------------------ the ticket that expired
#
# A relayed pair went dead eight to nine minutes after each bind, twice, and
# stayed dead until the service was restarted. The ticket is the relay's whole
# authorisation model and it expires after ten minutes; nothing renewed it.
#
# The agent kept presenting the expired one, the relay refused it silently —
# indistinguishable from a relay that has stopped running — and the agent
# concluded its relay was dead and asked the coordinator for a different one
# every twenty seconds. Those requests are counted as complaints, enough of
# them take a relay out of rotation for the whole fleet, and on a deployment
# with ONE relay that left nothing to offer. No answer ever came.
#
# Proved here with a sixty-second ticket: the pair must stay up for five times
# its life, with nothing restarted. That is four renewals it has to get right,
# in five minutes rather than the hour a production ticket would need.
scenario_ticket_renewal() {
    step "a relayed pair must outlive its relay ticket"

    # Sixty-second tickets for this scenario only. The fixture restarts the
    # control plane, so it is set before and cleared after.
    LAB_TICKET_SECONDS=60
    export LAB_TICKET_SECONDS

    # Symmetric on both sides so a relay is the only path they have, and the
    # ticket therefore matters for every packet.
    fixture cgnat symmetric

    # Proof the setting arrived, not just that it was exported. Everything
    # below is timed against this lifetime; against the ten-minute default it
    # would run out of drill before the first renewal and pass without ever
    # testing one.
    local in_force
    in_force="$(lab::ticket_seconds_in_force || true)"
    if [ "$in_force" != "60s" ] && [ "$in_force" != "1m0s" ]; then
        record "renewal/ticket" FAIL \
            "the coordinator is minting ${in_force:-ten-minute} tickets, so this drill would not reach a renewal"
        unset LAB_TICKET_SECONDS

        return
    fi


    if ! lab::wait_tunnel alpha "$BETA_IP" 90; then
        record "ticket/paired" FAIL "the pair never connected, so there is no ticket to outlive"
        lab::tail_log alpha-up 20
        unset LAB_TICKET_SECONDS

        return
    fi
    record "ticket/paired" PASS "connected over a relay with a 60s ticket"

    # Five times the ticket's life. Nothing is restarted and nothing about the
    # network changes: the only thing that happens is time.
    local deadline=$(( $(date +%s) + 300 )) lost=0 checks=0
    while [ "$(date +%s)" -lt "$deadline" ]; do
        sleep 20
        checks=$((checks + 1))

        if ! lab::ping alpha "$BETA_IP" 2; then
            lost=$((lost + 1))
        fi
    done

    if [ "$lost" -eq 0 ]; then
        record "ticket/survived" PASS \
            "still carrying traffic after 5 ticket lifetimes ($checks checks, nothing restarted)"
    else
        record "ticket/survived" FAIL \
            "traffic stopped on $lost of $checks checks across 5 ticket lifetimes"
        lab::tail_log alpha-up 25
    fi

    # And it must have renewed rather than failed over: a renewal keeps the
    # relay, a failover complains about it.
    if grep -aq "renewing the relay ticket" "$LOGS/alpha-up.log" 2>/dev/null; then
        record "ticket/renewed" PASS "the agent renewed its ticket before it expired"
    else
        record "ticket/renewed" FAIL "no renewal in the log; it survived for some other reason"
    fi

    if grep -aq "stopped answering" "$LOGS/alpha-up.log" 2>/dev/null; then
        record "ticket/no-complaint" FAIL \
            "the agent reported its relay dead, which is what takes the only relay out of rotation"
    else
        record "ticket/no-complaint" PASS "and never reported the relay dead"
    fi

    unset LAB_TICKET_SECONDS
}

# -------------------------------------------- both directions, for two lives
#
# A pair bound on a relay, both ends, and carried nothing: zero bytes received
# and no handshake ever. Every check the lab had was satisfied — both agents
# reported a relay path, the relay reported two sides bound — because nothing
# required a packet to make the round trip.
#
# The cause was an acknowledgement the agent dropped rather than applied, and
# the shape of it matters for the drill: the relay and the agents update on
# separate schedules, so a version gap between them is an ordinary state and
# not an exotic one.
#
# So this requires traffic BOTH WAYS, repeatedly, across two ticket lifetimes.
# One direction proves a path exists; two prove the relay is forwarding for
# both sides, which is the thing that was broken.
scenario_relay_both_ways() {
    step "a relayed pair must carry traffic in both directions, and keep doing it"

    LAB_TICKET_SECONDS=60
    export LAB_TICKET_SECONDS

    fixture cgnat symmetric

    # Proof the setting arrived, not just that it was exported. Everything
    # below is timed against this lifetime; against the ten-minute default it
    # would run out of drill before the first renewal and pass without ever
    # testing one.
    local in_force
    in_force="$(lab::ticket_seconds_in_force || true)"
    if [ "$in_force" != "60s" ] && [ "$in_force" != "1m0s" ]; then
        record "bothways/ticket" FAIL \
            "the coordinator is minting ${in_force:-ten-minute} tickets, so this drill would not reach a renewal"
        unset LAB_TICKET_SECONDS

        return
    fi


    if ! lab::wait_tunnel alpha "$BETA_IP" 90; then
        record "bothways/up" FAIL "the pair never connected at all"
        lab::tail_log alpha-up 20
        unset LAB_TICKET_SECONDS

        return
    fi
    record "bothways/up" PASS "alpha reached beta"

    # The direction nothing checked. A relay that forwards one way and not the
    # other looks identical from the side that works.
    if lab::wait_tunnel beta "$ALPHA_IP" 60; then
        record "bothways/reverse" PASS "and beta reached alpha"
    else
        record "bothways/reverse" FAIL \
            "beta cannot reach alpha: the relay forwards one way only"
        lab::tail_log beta-up 20
        unset LAB_TICKET_SECONDS

        return
    fi

    # Two ticket lifetimes, both directions, throughout.
    local deadline=$(( $(date +%s) + 120 )) fwd=0 rev=0 rounds=0
    while [ "$(date +%s)" -lt "$deadline" ]; do
        sleep 15
        rounds=$((rounds + 1))

        lab::ping alpha "$BETA_IP" 2  || fwd=$((fwd + 1))
        lab::ping beta  "$ALPHA_IP" 2 || rev=$((rev + 1))
    done

    if [ "$fwd" -eq 0 ] && [ "$rev" -eq 0 ]; then
        record "bothways/sustained" PASS \
            "both directions carried traffic for 2 ticket lifetimes ($rounds rounds)"
    else
        record "bothways/sustained" FAIL \
            "lost traffic on $fwd forward and $rev reverse checks of $rounds"
        lab::tail_log alpha-up 20
    fi

    unset LAB_TICKET_SECONDS
}

# ------------------------------------------- the panel goes away for a while
#
# Pressing Update Now on the panel cut a working relayed pair that had carried
# seventy megabytes, and it did not recover when the panel came back.
#
# The panel answered 503 for about ninety seconds while it redeployed. The
# coordinator treated "the panel did not answer" as "the panel has not said
# yes": it stopped refreshing both devices, their registry entries expired at
# the presence TTL, and the pair stopped being introduced to anybody. When the
# panel returned, one end asked for a relay and was offered one; the offer to
# the other end found nothing registered and returned in silence. Both
# machines sat on "connecting" with nothing wrong with either of them.
#
# R6 says the data plane outlives the control plane. This is the drill for it:
# the panel is stopped for longer than the presence TTL AND longer than a
# ticket lifetime, so both the registry entry and the relay ticket have to
# survive on what the coordinator already knew.
scenario_panel_maintenance() {
    step "a panel that goes away must not interrupt traffic"

    # Sixty-second tickets. Set before the coordinator is restarted below,
    # which is where it is read — an earlier version set it here and the
    # coordinator was already running with the ten-minute default, so the
    # drill it was meant to sharpen ran blunt.
    LAB_TICKET_SECONDS=60
    export LAB_TICKET_SECONDS

    fixture cgnat symmetric

    # Proof the setting arrived, not just that it was exported. Everything
    # below is timed against this lifetime; against the ten-minute default it
    # would run out of drill before the first renewal and pass without ever
    # testing one.
    local in_force
    in_force="$(lab::ticket_seconds_in_force || true)"
    if [ "$in_force" != "60s" ] && [ "$in_force" != "1m0s" ]; then
        record "maint/ticket" FAIL \
            "the coordinator is minting ${in_force:-ten-minute} tickets, so this drill would not reach a renewal"
        unset LAB_TICKET_SECONDS

        return
    fi


    if ! lab::wait_tunnel alpha "$BETA_IP" 90; then
        record "maint/paired" FAIL "the pair never connected, so there is nothing to protect"
        lab::tail_log alpha-up 20
        unset LAB_TICKET_SECONDS

        return
    fi

    # Asserted, not assumed. A direct pair survives anything the control
    # plane does, so a drill that let the pair go direct would pass on a
    # build with none of this fixed — and the first version of this one did
    # not check, which is how it passed on the build it was written to fail.
    if ! lab::wait_path alpha relay 30; then
        record "maint/paired" FAIL "the pair is not relayed, so this proves nothing about relays"
        lab::tail_log alpha-up 20
        unset LAB_TICKET_SECONDS

        return
    fi
    record "maint/paired" PASS "connected over a relay before the panel goes down"

    # The panel goes away, and the coordinator restarts — which is what
    # pressing Update Now does, because upgrade-edge.sh puts the panel into
    # maintenance and then restarts both services.
    #
    # The restart is the essential part, and leaving it out is why an earlier
    # version of this drill was green on the build that failed in the field.
    # Without it the agents are settled: the coordinator keeps acknowledging
    # their pings from a registry it never lost, no Hello is ever sent, and
    # the code path that asks the panel is never entered. A coordinator that
    # starts empty ignores pings from keys it does not know, so both agents
    # must re-introduce themselves — with a full Hello, carrying a token, to
    # a panel that cannot answer. That is the moment R6 is actually about.
    lab::panel_stop

    say "restarting the coordinator and the relay with no panel, as Update Now does"
    lab::stop_pid "${COORD_PID:-}"
    lab::coord_start
    lab::stop_pid "${RELAY_PID:-}"
    lab::relay_start

    local lost=0 checks=0 deadline=$(( $(date +%s) + 300 ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        sleep 20
        checks=$((checks + 1))

        lab::ping alpha "$BETA_IP" 2 || lost=$((lost + 1))
    done

    # One lost check is allowed, and only one: the relay restart above costs
    # up to a rebind interval, and a check that lands inside it is the
    # upgrade's own few seconds rather than a pair that did not recover.
    if [ "$lost" -le 1 ]; then
        record "maint/during" PASS \
            "traffic survived 5 minutes with no panel and a restarted coordinator ($lost of $checks checks lost)"
    else
        record "maint/during" FAIL \
            "traffic stopped on $lost of $checks checks while the panel was down"
        lab::tail_log alpha-up 25
        lab::tail_log coordinator 25
    fi

    # And it comes back, with nothing restarted on either PC.
    lab::panel_start

    sleep 30

    if lab::ping alpha "$BETA_IP" 3; then
        record "maint/after" PASS "still carrying traffic once the panel returned, no agent restarted"
    else
        record "maint/after" FAIL \
            "the pair did not recover after the panel returned"
        lab::tail_log alpha-up 25
        lab::tail_log coordinator 25
    fi

    unset LAB_TICKET_SECONDS
}

# An edge upgrade restarts the coordinator and the relay, under live traffic.
#
# upgrade-edge.sh does exactly this, in this order, on every deploy:
#
#     for svc in coordinator relay; do systemctl restart "akconnect-$svc"; done
#
# Both registries are in memory. The coordinator forgets every device; the
# relay forgets every binding. The question this answers is the one an
# operator actually has — does pressing Update Now on the panel cut the pairs
# that are carrying traffic, and do they come back on their own?
#
# Nothing is restarted on the agent side, which is the point: a pair that
# needs an agent restart to recover is stranded, because nobody is at the PC.
#
# Sixty-second tickets, and the drill keeps pinging for several lifetimes
# after the restart. A ticket that outlives the coordinator only gets the pair
# through the first few minutes; the claim being tested is that renewal works
# afterwards too, which needs the agent to have re-announced itself to a
# coordinator that has never heard of it.
scenario_edge_upgrade() {
    step "restarting the coordinator and relay must not strand a relayed pair"

    LAB_TICKET_SECONDS=60
    export LAB_TICKET_SECONDS

    fixture cgnat symmetric

    if ! lab::wait_tunnel alpha "$BETA_IP" 90; then
        record "upgrade/paired" FAIL "the pair never connected, so there is nothing to interrupt"
        lab::tail_log alpha-up 20
        unset LAB_TICKET_SECONDS

        return
    fi
    if ! lab::wait_path alpha relay 30; then
        record "upgrade/paired" FAIL "the pair is not relayed, so the restart proves nothing about relays"
        lab::tail_log alpha-up 20
        unset LAB_TICKET_SECONDS

        return
    fi
    record "upgrade/paired" PASS "connected over a relay before the restart"

    # A packet every 200ms for 40 seconds, started before the restart and left
    # running through it. The gap is measured rather than sampled: a 20-second
    # poll would call a five-second outage zero.
    local pinglog="$LOGS/upgrade-ping.txt"
    ( ip netns exec alpha ping -i 0.2 -W 1 -w 40 "$BETA_IP" >"$pinglog" 2>&1 ) &
    local ping_pid=$!

    sleep 5

    # What upgrade-edge.sh does, in its order.
    say "restarting the coordinator and the relay, as an edge upgrade does"
    lab::stop_pid "${COORD_PID:-}"
    lab::coord_start
    lab::stop_pid "${RELAY_PID:-}"
    lab::relay_start

    wait "$ping_pid" 2>/dev/null || true

    local sent lost gap
    sent="$(sed -n 's/^\([0-9]\+\) packets transmitted.*/\1/p' "$pinglog")"
    lost="$(sed -n 's/.*transmitted, \([0-9]\+\) received.*/\1/p' "$pinglog")"
    sent="${sent:-0}"
    lost=$(( sent - ${lost:-0} ))

    # The longest unbroken run of missing sequence numbers, which is the
    # number an operator actually feels. A total says 73% lost and cannot
    # tell one thirty-second outage from thirty one-second ones, and those
    # are different faults with different causes.
    gap="$(awk -v sent="$sent" -F'icmp_seq=' '/icmp_seq=/ {
        split($2, f, " "); seq = f[1] + 0
        if (first == 0) first = seq
        if (last != "" && seq - last - 1 > run) run = seq - last - 1
        last = seq
    } END {
        # The tail counts too. A pair that went down and never came back
        # before the ping ended leaves no later packet to measure against,
        # and reading only the gaps BETWEEN received packets called that a
        # clean recovery — which is the opposite of what it is.
        if (last != "" && sent - last > run) run = sent - last
        if (first > 1 && first - 1 > run) run = first - 1
        printf "%d", run
    }' "$pinglog")"

    # A ceiling, not a target. Rebinding is attempted every five seconds, so
    # the relay coming back costs at most one interval — 25 packets at this
    # rate. Fifty is that with room for a slow start, and still small enough
    # that a pair which silently stayed down for the rest of the run fails.
    # Judged on the longest single outage, not the total: a restart that
    # costs one short gap and recovers is the thing being asked about.
    # Fifty packets is ten seconds at this rate — the relay is rebound every
    # five, and a pair should be back within one or two of those intervals.
    if [ "$sent" -gt 0 ] && [ "$gap" -le 50 ]; then
        record "upgrade/gap" PASS \
            "longest outage $((gap / 5))s ($gap packets), $lost of $sent lost in all, recovered on its own"
    else
        record "upgrade/gap" FAIL \
            "longest outage $((gap / 5))s ($gap packets), $lost of $sent lost in all"
        lab::tail_log alpha-up 25
        lab::tail_log coordinator 25
    fi

    # Several ticket lifetimes past the restart, with nothing restarted on
    # either PC. This is the part that needs the agents to have found their
    # way back into a coordinator that started empty: without that, the first
    # renewal after the ticket runs out has nobody to ask.
    local lost_after=0 checks=0 deadline=$(( $(date +%s) + 210 ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        sleep 15
        checks=$((checks + 1))

        lab::ping alpha "$BETA_IP" 2 || lost_after=$((lost_after + 1))
    done

    if [ "$lost_after" -eq 0 ]; then
        record "upgrade/renewal" PASS \
            "still up through $((210 / 60)) ticket lifetimes after the restart ($checks checks, no agent restarted)"
    else
        record "upgrade/renewal" FAIL \
            "traffic stopped on $lost_after of $checks checks after the restart"
        lab::tail_log alpha-up 25
        lab::tail_log coordinator 25
    fi

    unset LAB_TICKET_SECONDS
}
