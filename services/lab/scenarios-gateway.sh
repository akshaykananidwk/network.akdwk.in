#!/usr/bin/env bash
# Subnet-router mode.
#
# Sourced by scenarios.sh. §16–17 and the case that sells the product: a
# machine that cannot run our agent — an NVR, a printer, a DVR — reached
# through a PC at the site that can.

# §16–17: alpha reaches an NVR that runs no agent, through beta, on the port
# the ACL names and no other.
#
# If the nvr namespace can be reached without our software on it, subnet-router
# mode is real. If it cannot, the feature does not exist however much code it
# has.
scenario_gateway() {
    step "gateway — an agentless machine behind a site PC, on one port only"
    fixture gateway

    if ! lab::wait_tunnel alpha "$BETA_IP" 60; then
        record "gw/tunnel" FAIL "no tunnel between the support laptop and the site PC"
        return
    fi

    # Two services on the NVR: the one support is allowed to use, and one it
    # is not. Both on a machine with no agent and no overlay address.
    if ! lab::serve_tcp nvr 554 || ! lab::serve_tcp nvr 8080; then
        record "gw/setup" FAIL "the NVR's test listeners never came up"
        lab::stop_servers
        return
    fi

    # Advertise the site LAN through beta, and approve it the way an
    # administrator would. Unapproved, it must not reach agents at all.
    local advertised
    advertised="$(php "$LAB_DIR/lab-setup.php" route "$NETWORK" 192.168.77.0/24 "$UID_BETA")" \
        || { record "gw/advertise" FAIL "could not advertise the route"; lab::stop_servers; return; }

    sleep 12
    if lab::tcp_connect alpha 192.168.77.50 554; then
        record "gw/approval" FAIL "an unapproved route was already carrying traffic"
        lab::stop_servers
        return
    fi
    record "gw/approval" PASS "an unapproved route carries nothing"

    local routeId
    routeId="$(awk -F= '/^ROUTE=/ {print $2}' <<<"$advertised")"
    php "$LAB_DIR/lab-setup.php" route-approve "$routeId" >/dev/null \
        || { record "gw/advertise" FAIL "could not approve the route"; lab::stop_servers; return; }

    # An ACL that names the NVR by its LAN address, not by any device.
    php "$LAB_DIR/lab-setup.php" acl-cidr "$NETWORK" allow "$UID_ALPHA" 192.168.77.50/32 tcp 554 >/dev/null \
        || { record "gw/advertise" FAIL "could not author the LAN rule"; lab::stop_servers; return; }

    if ! lab::wait_reachable alpha 192.168.77.50 554 45; then
        record "gw/reach" FAIL "the NVR is unreachable through the gateway"
        lab::stop_servers
        return
    fi
    record "gw/reach" PASS "alpha reached the agentless NVR at 192.168.77.50:554 through beta"

    if ! lab::serving nvr 8080; then
        record "gw/denied" FAIL "the NVR's second listener died, so a refused connection proves nothing"
        lab::stop_servers
        return
    fi

    if lab::tcp_connect alpha 192.168.77.50 8080; then
        record "gw/denied" FAIL "the NVR's tcp/8080 was reachable; the rule named 554 only"
    else
        record "gw/denied" PASS "tcp/8080 on the NVR is refused; the rule named 554 and meant it"
    fi

    # The NVR must not become a way into the overlay. Its default route points
    # at the gateway, so the gateway is what has to refuse.
    if lab::tcp_connect nvr "$ALPHA_IP" 8080 3; then
        record "gw/no-reverse" FAIL "the NVR reached into the overlay through the gateway"
    else
        record "gw/no-reverse" PASS "the NVR cannot reach the overlay; forwarding is one-way"
    fi

    lab::stop_servers
}

# §16–17: a support laptop whose own LAN is the same range the customer
# advertises.
#
# This is not a corner case. Nearly every consumer router hands out
# 192.168.0.0/24 or 192.168.1.0/24, so a technician sitting on one hotel's LAN
# will routinely be offered a route to another hotel's identical range. The
# agent cannot serve both, and the one it must not break is the network the
# machine is physically on: taking that route would cut the laptop off from the
# printer next to it, and often from its own default gateway.
#
# Refuse, say which prefix clashed, and leave the local LAN alone.
scenario_gateway_clash() {
    step "gateway — an advertised LAN that collides with the technician's own"
    fixture gateway

    if ! lab::wait_tunnel alpha "$BETA_IP" 60; then
        record "clash/tunnel" FAIL "no tunnel between the support laptop and the site PC"
        return
    fi

    # Give the support laptop a second network card on the very range the
    # customer is about to advertise, the way a technician plugged into the
    # customer's own switch would have.
    # A veth pair rather than a dummy interface: the kernels this runs on do
    # not all carry the dummy module, and both halves staying in alpha gives a
    # link that is operationally up, which is what makes the kernel install the
    # connected route we are about to defend.
    if ! ip netns exec alpha ip link add name lan-local type veth peer name lan-local-far \
        || ! ip netns exec alpha ip address add 192.168.77.1/24 dev lan-local \
        || ! ip netns exec alpha ip link set dev lan-local up \
        || ! ip netns exec alpha ip link set dev lan-local-far up; then
        record "clash/setup" FAIL "could not give alpha a colliding local LAN"
        return
    fi

    local before
    before="$(ip netns exec alpha ip -o route show exact 192.168.77.0/24)"
    if [[ "$before" != *"dev lan-local"* ]]; then
        record "clash/setup" FAIL "alpha's own LAN route was not there to begin with: ${before:-none}"
        return
    fi

    # Now advertise and approve the customer's identical range.
    local advertised routeId
    advertised="$(php "$LAB_DIR/lab-setup.php" route "$NETWORK" 192.168.77.0/24 "$UID_BETA")" \
        || { record "clash/advertise" FAIL "could not advertise the route"; return; }
    routeId="$(awk -F= '/^ROUTE=/ {print $2}' <<<"$advertised")"
    php "$LAB_DIR/lab-setup.php" route-approve "$routeId" >/dev/null \
        || { record "clash/advertise" FAIL "could not approve the route"; return; }

    # Long enough for the revision to reach alpha and be applied.
    local deadline after
    deadline=$(( $(date +%s) + 60 ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        grep -q "not installed: this machine is already on that network" \
            "$LOGS/alpha-up.log" 2>/dev/null && break
        sleep 3
    done

    if ! grep -q "not installed: this machine is already on that network" "$LOGS/alpha-up.log" 2>/dev/null; then
        record "clash/refused" FAIL "the agent never reported the collision"
    else
        record "clash/refused" PASS "the agent refused the colliding route and named the prefix"
    fi

    after="$(ip netns exec alpha ip -o route show exact 192.168.77.0/24)"
    if [[ "$after" == *"dev lan-local"* ]]; then
        record "clash/local-lan" PASS "the technician's own LAN route survived the advertisement"
    else
        record "clash/local-lan" FAIL "alpha's own LAN route was taken over: ${after:-none}"
    fi

    # The overlay itself must still work. Refusing one route is not a reason to
    # lose the network.
    if ip netns exec alpha ping -c 3 -W 2 "$BETA_IP" >/dev/null 2>&1; then
        record "clash/overlay" PASS "the overlay still carries traffic after the refusal"
    else
        record "clash/overlay" FAIL "refusing one route took the whole tunnel down"
    fi
}

# §16–17 and §36: the gateway enforces the rule, not just the device the rule
# restricts.
#
# The support laptop belongs to the customer. Its agent runs on their hardware
# and they can rebuild it — so a rule enforced only there is a rule enforced
# only by the party it restricts. The reception PC is the one device in the
# path the restricted party does not control, and it has to reach the same
# verdict on its own.
#
# Alpha runs the tampered build for this drill: same protocol, same enrolment,
# rule enforcement compiled out.
scenario_gateway_tamper() {
    step "gateway — a tampered client still cannot reach past the rule"
    fixture gateway

    if ! lab::wait_tunnel alpha "$BETA_IP" 60; then
        record "gwtamper/tunnel" FAIL "no tunnel between the support laptop and the site PC"
        return
    fi

    if ! lab::serve_tcp nvr 554 || ! lab::serve_tcp nvr 8080; then
        record "gwtamper/setup" FAIL "the NVR's test listeners never came up"
        lab::stop_servers
        return
    fi

    local advertised routeId
    advertised="$(php "$LAB_DIR/lab-setup.php" route "$NETWORK" 192.168.77.0/24 "$UID_BETA")" \
        || { record "gwtamper/setup" FAIL "could not advertise the route"; lab::stop_servers; return; }
    routeId="$(awk -F= '/^ROUTE=/ {print $2}' <<<"$advertised")"
    php "$LAB_DIR/lab-setup.php" route-approve "$routeId" >/dev/null \
        || { record "gwtamper/setup" FAIL "could not approve the route"; lab::stop_servers; return; }

    php "$LAB_DIR/lab-setup.php" acl-cidr "$NETWORK" allow "$UID_ALPHA" 192.168.77.50/32 tcp 554 >/dev/null \
        || { record "gwtamper/setup" FAIL "could not author the LAN rule"; lab::stop_servers; return; }

    if ! lab::wait_reachable alpha 192.168.77.50 554 45; then
        record "gwtamper/setup" FAIL "the NVR was unreachable before tampering, so nothing below proves anything"
        lab::stop_servers
        return
    fi

    # Swap alpha for the build that ignores its own rules. Beta keeps the real
    # one: the whole question is whether beta holds.
    lab::down_agents
    lab::up_tampered alpha
    lab::up beta

    if ! lab::wait_tunnel alpha "$BETA_IP" 60; then
        record "gwtamper/tunnel" FAIL "the tampered agent never came up, so the drill did not run"
        lab::stop_servers
        return
    fi
    record "gwtamper/tunnel" PASS "the tampered agent enrolled and handshaked exactly like the real one"

    # It must still reach what the rule allows — otherwise a refusal below
    # would prove nothing but a broken tunnel.
    if ! lab::wait_reachable alpha 192.168.77.50 554 45; then
        record "gwtamper/allowed" FAIL "the tampered agent could not reach the allowed port either; the drill is inconclusive"
        lab::stop_servers
        return
    fi
    record "gwtamper/allowed" PASS "the tampered agent still reaches tcp/554, so the path is working"

    if ! lab::serving nvr 8080; then
        record "gwtamper/blocked" FAIL "the NVR's second listener died, so a refused connection proves nothing"
    elif lab::tcp_connect alpha 192.168.77.50 8080; then
        record "gwtamper/blocked" FAIL "a client with its rules compiled out reached tcp/8080 on the NVR"
    else
        record "gwtamper/blocked" PASS "tcp/8080 refused at the gateway, with the client's own enforcement removed"
    fi

    lab::stop_servers
}
