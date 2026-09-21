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
