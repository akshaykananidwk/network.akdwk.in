#!/usr/bin/env bash
# Access-control scenarios.
#
# Sourced by scenarios.sh. These are about §7.5 and §36: the panel authors
# rules, the agent enforces them at both ends, and a device cannot talk its way
# past them.

# §7.5 and §36: the ACL is enforced on the device, at both ends.
#
# The use case is the one that sells this: AK Support may reach the hotel's
# server, NVR and reception PC and nothing else. The half worth proving is the
# "nothing else", because that is the half a customer is trusting, and a deny
# that only blocks ping is not a deny — so this checks a TCP connect too.
scenario_acl() {
    step "ACL — a deny blocks traffic, and a rule change lands within 10s"
    fixture nat

    if ! lab::wait_tunnel alpha "$BETA_IP" 60; then
        record "acl/tunnel" FAIL "no tunnel to filter"
        return
    fi

    # A real listener, so a refused connection means the filter refused it
    # rather than that nothing was listening.
    if ! lab::serve_tcp beta 8080; then
        record "acl/baseline" FAIL "the test listener never came up"
        lab::stop_servers
        return
    fi

    if ! lab::tcp_connect alpha "$BETA_IP" 8080; then
        record "acl/baseline" FAIL "could not reach the service before any rule was written"
        lab::stop_servers
        return
    fi
    record "acl/baseline" PASS "alpha reaches beta:8080 with no rules in force"

    # Now deny it, the way an administrator would, and time how long the agents
    # take to find out.
    local authored elapsed=0 started
    authored="$(php "$LAB_DIR/lab-setup.php" acl "$NETWORK" deny "$UID_ALPHA" "$UID_BETA" tcp 8080)" \
        || { record "acl/deny" FAIL "could not author the rule"; lab::stop_servers; return; }
    started="$(date +%s)"

    while [ "$elapsed" -lt 30 ]; do
        lab::tcp_connect alpha "$BETA_IP" 8080 || break
        sleep 1
        elapsed=$(( $(date +%s) - started ))
    done

    # Before concluding anything from a failed connection, check the service
    # is still there. A dead listener refuses connections just as convincingly
    # as a filter does, and telling the two apart is the whole point.
    if ! lab::serving beta 8080; then
        record "acl/deny-tcp" FAIL "the listener died, so a refused connection proves nothing"
        lab::stop_servers
        return
    fi

    if lab::tcp_connect alpha "$BETA_IP" 8080; then
        record "acl/deny-tcp" FAIL "still connecting to beta:8080 ${elapsed}s after the deny"
    elif [ "$elapsed" -lt 1 ]; then
        # The agent only learns about a rule when it next polls, so a deny
        # cannot take effect instantly. Zero seconds means something else
        # refused the connection.
        record "acl/deny-tcp" FAIL "connection refused before the agent could have learned the rule"
    elif [ "$elapsed" -le 10 ]; then
        record "acl/deny-tcp" PASS "TCP to beta:8080 stopped ${elapsed}s after the rule was written, service still listening"
    else
        record "acl/deny-tcp" FAIL "TCP stopped, but after ${elapsed}s (limit is 10s)"
    fi

    # A deny on one port must not take the rest of the peer with it, or the
    # rule an operator wrote is not the rule they got.
    if lab::ping alpha "$BETA_IP" 2; then
        record "acl/scope" PASS "the deny named tcp/8080 and left ping alone"
    else
        record "acl/scope" FAIL "a deny on tcp/8080 also blocked ICMP"
    fi

    lab::stop_servers
}

# The source-port bypass.
#
# A rule naming a service port is about the port being connected *to*. If it
# also matches the source port, a device binds that port locally and reaches
# everything on the target — "AK Support may reach the NVR on tcp/554" becomes
# "AK Support may reach the whole NVR". This ran against a filter that had
# exactly that hole, and it must fail against one.
scenario_acl_source_port() {
    step "ACL — binding the allowed port as a source must not open others"
    fixture nat

    if ! lab::wait_tunnel alpha "$BETA_IP" 60; then
        record "srcport/tunnel" FAIL "no tunnel to filter"
        return
    fi

    # Two services on beta: one the rule allows, one it does not.
    if ! lab::serve_tcp beta 554 || ! lab::serve_tcp beta 8080; then
        record "srcport/setup" FAIL "the test listeners never came up"
        lab::stop_servers
        return
    fi

    php "$LAB_DIR/lab-setup.php" acl "$NETWORK" allow "$UID_ALPHA" "$UID_BETA" tcp 554 >/dev/null \
        || { record "srcport/setup" FAIL "could not author the rule"; lab::stop_servers; return; }
    sleep 12

    if ! lab::tcp_connect alpha "$BETA_IP" 554; then
        record "srcport/allowed" FAIL "the allowed port is not reachable, so nothing below means anything"
        lab::stop_servers
        return
    fi
    record "srcport/allowed" PASS "alpha reaches the allowed port tcp/554"

    if ! lab::serving beta 8080; then
        record "srcport/bypass" FAIL "the second listener died, so a refused connection proves nothing"
        lab::stop_servers
        return
    fi

    # The attack: source port 554, destination port 8080.
    if lab::tcp_connect_from alpha "$BETA_IP" 8080 554; then
        record "srcport/bypass" FAIL "binding source port 554 reached tcp/8080 — the rule is bypassable"
    else
        record "srcport/bypass" PASS "source port 554 did not open tcp/8080; the rule is about the destination"
    fi

    lab::stop_servers
}

# The other half of §36: a device must not be able to talk its way past the
# rules by changing what it knows locally.
scenario_acl_tamper() {
    step "ACL — a device cannot grant itself access by editing local state"
    fixture nat

    if ! lab::wait_tunnel alpha "$BETA_IP" 60; then
        record "tamper/tunnel" FAIL "no tunnel to test against"
        return
    fi

    if ! lab::serve_tcp beta 8080; then
        record "tamper/setup" FAIL "the test listener never came up"
        lab::stop_servers
        return
    fi

    php "$LAB_DIR/lab-setup.php" acl "$NETWORK" deny "$UID_ALPHA" "$UID_BETA" tcp 8080 >/dev/null \
        || { record "tamper/setup" FAIL "could not author the rule"; lab::stop_servers; return; }
    sleep 12

    if lab::tcp_connect alpha "$BETA_IP" 8080; then
        record "tamper/setup" FAIL "the deny never took effect, so tampering proves nothing"
        lab::stop_servers
        return
    fi

    # Alpha rewrites its own state and restarts its agent, which is the most a
    # customer with root on their own machine can do without rebuilding the
    # binary. Enforcement at beta's end has to hold regardless.
    local state
    state="$(lab::state_dir alpha)/state.json"
    jq '.revision = 0' "$state" > "$state.tmp" && mv "$state.tmp" "$state"

    lab::down_agents
    lab::up alpha
    lab::up beta
    sleep 15

    if ! lab::serving beta 8080; then
        record "tamper/blocked" FAIL "the listener died, so a refused connection proves nothing"
    elif lab::tcp_connect alpha "$BETA_IP" 8080; then
        record "tamper/blocked" FAIL "a device reached a denied port after editing its own state"
    else
        record "tamper/blocked" PASS "the deny held after alpha rewrote its state and restarted, service still listening"
    fi

    lab::stop_servers
}
