#!/usr/bin/env bash
# Split DNS (§18).
#
# Sourced by scenarios.sh.
#
# Two claims, and the second is the one worth being careful about:
#
#   1. Names under the network's zone resolve — devices to their overlay
#      address, machines behind a gateway to their *mapped* address.
#   2. Every other name the machine looks up goes where it went before.
#
# The first can be proved by asking our resolver. The second cannot: it is
# proved by watching the customer's resolver and seeing the public lookups
# still arrive there, and by checking that nothing rewrote the machine's
# resolver configuration.

# lab::dig sends one query to a specific server and prints the answer address,
# or the rcode when there is no answer.
#
# dig is not installed everywhere; this uses the agent's own query tool so the
# drill does not depend on a package the lab may not have.
lab::dig() {
    local ns=$1 server=$2 name=$3
    ip netns exec "$ns" php "$LAB_DIR/dns-query.php" "$server" "$name" 2>/dev/null
}

scenario_dns() {
    step "names — the network's zone resolves, and nothing else is touched"
    fixture collision

    if ! lab::wait_tunnel alpha "$BETA_IP" 60; then
        record "dns/tunnel" FAIL "no tunnel, so there is nothing to name"
        return
    fi

    # The customer's own resolver, in the namespace that stands in for their
    # ISP. Whatever the machine looks up that is not ours has to arrive here.
    local isp_log="$RUN/isp-queries.log"
    : > "$isp_log"
    setsid ip netns exec natgw-a php "$LAB_DIR/dns-isp.php" 53 "$isp_log" >/dev/null 2>&1 &
    SERVER_PIDS+=("$!")
    sleep 2

    if ! lab::dig alpha 192.168.10.1 probe.example.test >/dev/null 2>&1; then
        record "dns/setup" FAIL "the stand-in ISP resolver never came up"
        lab::stop_servers
        return
    fi
    record "dns/setup" PASS "a stand-in ISP resolver is answering in the natgw namespace"

    # Advertise the customer's LAN and name the NVR inside it, the way an
    # operator would: by the address printed on the recorder.
    local advertised routeId mapped
    advertised="$(php "$LAB_DIR/lab-setup.php" route "$NETWORK" 192.168.1.0/24 "$UID_BETA")" \
        || { record "dns/setup" FAIL "could not advertise the route"; lab::stop_servers; return; }
    routeId="$(awk -F= '/^ROUTE=/ {print $2}' <<<"$advertised")"
    mapped="$(awk -F= '/^MAPPED=/ {print $2}' <<<"$advertised")"
    php "$LAB_DIR/lab-setup.php" route-approve "$routeId" >/dev/null \
        || { record "dns/setup" FAIL "could not approve the route"; lab::stop_servers; return; }
    php "$LAB_DIR/lab-setup.php" host "$routeId" nvr 192.168.1.50 >/dev/null \
        || { record "dns/setup" FAIL "could not name the NVR"; lab::stop_servers; return; }

    local zone resolver
    zone="$(php "$LAB_DIR/lab-setup.php" zone "$NETWORK")" \
        || { record "dns/setup" FAIL "could not read the network's zone"; lab::stop_servers; return; }

    # Wait for the agent to pick the configuration up and start its resolver.
    local deadline
    deadline=$(( $(date +%s) + 60 ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        resolver="$(lab::runtime alpha | jq -r '.names.resolver // empty')"
        [ -n "$resolver" ] && [ "$(lab::runtime alpha | jq -r '.names.records // 0')" -gt 0 ] && break
        sleep 3
    done

    if [ -z "$resolver" ]; then
        record "dns/resolver" FAIL "the agent never started a resolver"
        lab::stop_servers
        return
    fi
    record "dns/resolver" PASS "alpha serves $zone at $resolver ($(lab::runtime alpha | jq -r '.names.records') name(s))"

    # The claim that matters most, and the one that cannot be proved by asking
    # our own resolver: everything else still goes where it went before.
    local resolv_before resolv_after
    resolv_before="$(ip netns exec alpha cat /etc/resolv.conf 2>/dev/null | md5sum)"

    # A device, by name.
    local answer
    answer="$(lab::dig alpha "${resolver%:*}" "beta.$zone")"
    if [ "$answer" = "$BETA_IP" ]; then
        record "dns/device" PASS "beta.$zone resolves to $BETA_IP"
    else
        record "dns/device" FAIL "beta.$zone answered '${answer:-nothing}', expected $BETA_IP"
    fi

    # The NVR, which must resolve to the MAPPED address. Answering with
    # 192.168.1.50 would send a technician to whatever sits at that address on
    # their own LAN — which in this topology is their own office machine.
    local nvr_overlay
    nvr_overlay="$(lab::mapped_host "$mapped" 50)"
    answer="$(lab::dig alpha "${resolver%:*}" "nvr.beta.$zone")"
    if [ "$answer" = "$nvr_overlay" ]; then
        record "dns/lan-host" PASS "nvr.beta.$zone resolves to $nvr_overlay, not to 192.168.1.50"
    else
        record "dns/lan-host" FAIL "nvr.beta.$zone answered '${answer:-nothing}', expected $nvr_overlay"
    fi

    # The guarantee. A public name must be REFUSED, and no lookup attempted.
    answer="$(lab::dig alpha "${resolver%:*}" www.google.com)"
    if [ "$answer" = "REFUSED" ]; then
        record "dns/refused" PASS "www.google.com is refused by our resolver, not looked up"
    else
        record "dns/refused" FAIL "www.google.com got '${answer:-nothing}' instead of REFUSED"
    fi

    # And an unknown name inside the zone is NXDOMAIN, authoritatively.
    answer="$(lab::dig alpha "${resolver%:*}" "printer.$zone")"
    if [ "$answer" = "NXDOMAIN" ]; then
        record "dns/nxdomain" PASS "an unknown name in the zone is NXDOMAIN, not a lookup elsewhere"
    else
        record "dns/nxdomain" FAIL "printer.$zone got '${answer:-nothing}' instead of NXDOMAIN"
    fi

    resolv_after="$(ip netns exec alpha cat /etc/resolv.conf 2>/dev/null | md5sum)"
    if [ "$resolv_before" = "$resolv_after" ] && [ -n "$resolv_before" ]; then
        record "dns/untouched" PASS "alpha's own resolver configuration is byte-identical; the agent did not write to it"
    else
        record "dns/untouched" FAIL "alpha's /etc/resolv.conf changed while the agent was running"
    fi

    # And the other half: an ordinary lookup, made the way anything on the
    # machine makes one, still arrives at the customer's resolver.
    : > "$isp_log"
    ip netns exec alpha getent hosts www.example.com >/dev/null 2>&1
    ip netns exec alpha getent hosts news.bbc.co.uk >/dev/null 2>&1
    sleep 1

    if grep -q "www.example.com" "$isp_log" 2>/dev/null; then
        record "dns/public" PASS "public lookups arrived at the ISP resolver: $(tr '\n' ' ' < "$isp_log")"
    else
        record "dns/public" FAIL "no public lookup reached the ISP resolver; something intercepted it"
    fi

    # Our resolver must have seen none of that. It is on loopback and nothing
    # points at it, so the count is the check.
    local ours_refused
    ours_refused="$(lab::runtime alpha | jq -r '.names.refused // 0')"
    if [ "$ours_refused" -le 1 ]; then
        record "dns/not-in-path" PASS "our resolver was asked about no public name (refused: $ours_refused, from the drill's own probe)"
    else
        record "dns/not-in-path" FAIL "our resolver saw $ours_refused queries it should never have been sent"
    fi

    local routed
    routed="$(lab::runtime alpha | jq -r '.names.routed_by // ""')"
    if [ -n "$routed" ]; then
        record "dns/os-routing" PASS "the operating system resolves $zone through $routed"
    else
        record "dns/os-routing" FAIL "nothing is resolving $zone on this machine: $(lab::runtime alpha | jq -r '.names.note // "no reason given"')"
        lab::stop_servers
        return
    fi

    # The check that decides whether any of this is usable: an ordinary lookup,
    # made the way any program on the machine makes one, with no server named.
    local system_answer
    system_answer="$(ip netns exec alpha getent hosts "nvr.beta.$zone" 2>/dev/null | awk '{print $1}')"
    if [ "$system_answer" = "$nvr_overlay" ]; then
        record "dns/system" PASS "getent resolves nvr.beta.$zone to $nvr_overlay with no server named"
    else
        record "dns/system" FAIL "the system resolver answered '${system_answer:-nothing}' for nvr.beta.$zone"
    fi

    system_answer="$(ip netns exec alpha getent hosts "beta.$zone" 2>/dev/null | awk '{print $1}')"
    if [ "$system_answer" = "$BETA_IP" ]; then
        record "dns/system-device" PASS "and beta.$zone to $BETA_IP"
    else
        record "dns/system-device" FAIL "the system resolver answered '${system_answer:-nothing}' for beta.$zone"
    fi

    # Stopping the agent has to take the names with it. A machine left
    # resolving a zone whose addresses no longer route is worse than one that
    # never resolved it.
    lab::down_agents
    sleep 3

    system_answer="$(ip netns exec alpha getent hosts "nvr.beta.$zone" 2>/dev/null | awk '{print $1}')"
    if [ -z "$system_answer" ]; then
        record "dns/cleanup" PASS "stopping the agent removed the names; nothing was left behind"
    else
        record "dns/cleanup" FAIL "nvr.beta.$zone still resolves to $system_answer with the agent stopped"
    fi

    # And the machine's own resolution has to survive all of it.
    if ip netns exec alpha getent hosts localhost >/dev/null 2>&1; then
        record "dns/localhost" PASS "localhost still resolves; the machine's own hosts file is intact"
    else
        record "dns/localhost" FAIL "the machine can no longer resolve localhost"
    fi

    lab::stop_servers
}
