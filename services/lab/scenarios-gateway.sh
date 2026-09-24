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
    local advertised mapped nvr_overlay
    advertised="$(php "$LAB_DIR/lab-setup.php" route "$NETWORK" 192.168.77.0/24 "$UID_BETA")" \
        || { record "gw/advertise" FAIL "could not advertise the route"; lab::stop_servers; return; }
    mapped="$(awk -F= '/^MAPPED=/ {print $2}' <<<"$advertised")"
    nvr_overlay="$(lab::mapped_host "$mapped" 50)"

    if [ -z "$nvr_overlay" ]; then
        record "gw/advertise" FAIL "the panel advertised the route without a virtual prefix"
        lab::stop_servers
        return
    fi

    sleep 12
    if lab::tcp_connect alpha "$nvr_overlay" 554; then
        record "gw/approval" FAIL "an unapproved route was already carrying traffic"
        lab::stop_servers
        return
    fi
    record "gw/approval" PASS "an unapproved route carries nothing"

    local routeId
    routeId="$(awk -F= '/^ROUTE=/ {print $2}' <<<"$advertised")"
    php "$LAB_DIR/lab-setup.php" route-approve "$routeId" >/dev/null \
        || { record "gw/advertise" FAIL "could not approve the route"; lab::stop_servers; return; }

    # An ACL that names the NVR by its LAN address, not by any device and not
    # by the overlay address the panel invented for it.
    php "$LAB_DIR/lab-setup.php" acl-cidr "$NETWORK" allow "$UID_ALPHA" 192.168.77.50/32 tcp 554 >/dev/null \
        || { record "gw/advertise" FAIL "could not author the LAN rule"; lab::stop_servers; return; }

    if ! lab::wait_reachable alpha "$nvr_overlay" 554 45; then
        record "gw/reach" FAIL "the NVR is unreachable at $nvr_overlay through the gateway"
        lab::stop_servers
        return
    fi
    record "gw/reach" PASS "alpha reached the agentless NVR (192.168.77.50) at $nvr_overlay:554 through beta"

    if ! lab::serving nvr 8080; then
        record "gw/denied" FAIL "the NVR's second listener died, so a refused connection proves nothing"
        lab::stop_servers
        return
    fi

    if lab::tcp_connect alpha "$nvr_overlay" 8080; then
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

# §16–17: the backstop, for when even the mapped prefix collides.
#
# Subnet mapping removed the common collision — the technician's own
# 192.168.1.0/24 and the customer's are two different prefixes on the overlay
# now, and `subnet-mapping` proves it. What is left is the rarer case the
# mapping cannot solve: a machine already numbered out of the mapping pool
# itself. Somebody running 10.128.0.0/24 in their own office will be handed an
# overlay prefix that lands on top of it.
#
# There the agent still has to lose the contest deliberately. Cutting a
# technician off from the printer beside them is worse than not reaching the
# customer, and the log has to say which prefix clashed so somebody can change
# the pool.
scenario_gateway_clash() {
    step "gateway — a mapped prefix that lands on the technician's own network"
    fixture gateway

    if ! lab::wait_tunnel alpha "$BETA_IP" 60; then
        record "clash/tunnel" FAIL "no tunnel between the support laptop and the site PC"
        return
    fi

    # The first prefix the panel hands out in a fresh network is the bottom of
    # the pool. Put the technician's own office on exactly that.
    local pool_start
    pool_start="$(php "$LAB_DIR/lab-setup.php" pool)" || pool_start=""
    if [ -z "$pool_start" ]; then
        record "clash/setup" FAIL "could not read the mapping pool from the panel"
        return
    fi

    if ! ip netns exec alpha ip link add name lan-local type veth peer name lan-local-far \
        || ! ip netns exec alpha ip address add "$pool_start" dev lan-local \
        || ! ip netns exec alpha ip link set dev lan-local up \
        || ! ip netns exec alpha ip link set dev lan-local-far up; then
        record "clash/setup" FAIL "could not put alpha's own network on $pool_start"
        return
    fi

    local prefix before
    prefix="$(awk -F/ '{split($1,o,"."); printf "%s.%s.%s.0/%s", o[1], o[2], o[3], $2}' <<<"$pool_start")"
    before="$(ip netns exec alpha ip -o route show exact "$prefix")"
    if [[ "$before" != *"dev lan-local"* ]]; then
        record "clash/setup" FAIL "alpha's own route for $prefix was not there to begin with: ${before:-none}"
        return
    fi
    record "clash/setup" PASS "alpha's own network is $prefix, the first prefix the panel will hand out"

    local advertised routeId mapped
    advertised="$(php "$LAB_DIR/lab-setup.php" route "$NETWORK" 192.168.77.0/24 "$UID_BETA")" \
        || { record "clash/advertise" FAIL "could not advertise the route"; return; }
    routeId="$(awk -F= '/^ROUTE=/ {print $2}' <<<"$advertised")"
    mapped="$(awk -F= '/^MAPPED=/ {print $2}' <<<"$advertised")"
    php "$LAB_DIR/lab-setup.php" route-approve "$routeId" >/dev/null \
        || { record "clash/advertise" FAIL "could not approve the route"; return; }

    if [ "$mapped" != "$prefix" ]; then
        record "clash/collided" FAIL "the panel mapped the customer to $mapped, which does not collide, so nothing below is tested"
        return
    fi
    record "clash/collided" PASS "the panel mapped the customer to $mapped, on top of alpha's own network"

    local deadline
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

    local after
    after="$(ip netns exec alpha ip -o route show exact "$prefix")"
    if [[ "$after" == *"dev lan-local"* ]]; then
        record "clash/local-lan" PASS "the technician's own network survived the advertisement"
    else
        record "clash/local-lan" FAIL "alpha's own route was taken over: ${after:-none}"
    fi

    # The overlay itself must still work. Refusing one route is not a reason to
    # lose the network.
    #
    # Given a window rather than three pings, because the claim being tested is
    # that the overlay survives a refused route, not that the first packet
    # after it gets through.
    #
    # The window was not what fixed this. The scenario adds an interface to
    # alpha to create the clash, the agent's address watcher saw a network
    # change and reopened its socket, and reopening it threw away the NAT
    # mapping every peer was sending to. Fifteen seconds was not enough for
    # the re-punch; the fix was for the agent to stop rebinding when the only
    # news is a new adapter. See cmd/akconnect-agent/wake.go.
    if lab::wait_tunnel alpha "$BETA_IP" 15; then
        record "clash/overlay" PASS "the overlay still carries traffic after the refusal"
    else
        record "clash/overlay" FAIL "refusing one route took the whole tunnel down"
    fi

    # And the panel has to hear about it. A refusal that only reaches a log
    # file on the customer's machine is one nobody acts on, because the person
    # who can change the mapping pool never sees it.
    local deadline reported
    deadline=$(( $(date +%s) + 45 ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        reported="$(php "$LAB_DIR/lab-setup.php" problems "$UID_ALPHA" 2>/dev/null)"
        [[ "$reported" == *"route.clash"* ]] && break
        sleep 3
    done

    if [[ "$reported" == *"route.clash"* ]]; then
        record "clash/reported" PASS "the panel shows the clash on the device: ${reported%%|*}"
    else
        record "clash/reported" FAIL "the agent refused the route and the panel was never told"
    fi
}

# §16 and the mapping pool: the pool itself can clash.
#
# 10.128.0.0/10 is the half of 10/8 almost nobody numbers a LAN out of, and
# "almost nobody" is not "nobody" — plenty of offices, and most ISP-managed
# connections in this market, use 10.x internally. A machine already on the
# range the *overlay* uses is the worst version of it: taking that over would
# cut the machine off from its own file server to join a network.
#
# The agent has to refuse, name what clashed, and tell the panel.
scenario_overlay_clash() {
    step "overlay — a network CIDR that lands on the machine's own network"
    fixture nat

    if ! lab::wait_tunnel alpha "$BETA_IP" 60; then
        record "oclash/tunnel" FAIL "no tunnel to start from"
        return
    fi

    # Polled, not read once: a ping succeeds as soon as the tunnel is up, and
    # the agent writes its runtime file on its own loop a moment later.
    # Reading too early gets nothing and reports it as a product failure.
    local overlay deadline
    overlay=""
    deadline=$(( $(date +%s) + 45 ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        overlay="$(lab::runtime alpha | jq -r '.overlay_cidr // empty')"
        [ -n "$overlay" ] && break
        sleep 2
    done

    if [ -z "$overlay" ]; then
        record "oclash/setup" FAIL "the agent never reported which overlay CIDR it installed"
        return
    fi

    # Give alpha a second network card on exactly the overlay's range, the way
    # an office already numbering out of 10.x would have.
    local office
    office="$(awk -F/ '{split($1,o,"."); printf "%s.%s.%s.9/%s", o[1], o[2], o[3], $2}' <<<"$overlay")"

    if ! ip netns exec alpha ip link add name lan-office type veth peer name lan-office-far \
        || ! ip netns exec alpha ip address add "$office" dev lan-office \
        || ! ip netns exec alpha ip link set dev lan-office up \
        || ! ip netns exec alpha ip link set dev lan-office-far up; then
        record "oclash/setup" FAIL "could not put alpha's own network on $overlay"
        return
    fi
    record "oclash/setup" PASS "alpha's own network is now $overlay, the same range as the overlay"

    # Restart the agent so it applies its configuration against the new state.
    lab::down_agents
    lab::up alpha
    lab::up beta
    sleep 20

    local route
    route="$(ip netns exec alpha ip -o route show exact "$overlay")"
    if [[ "$route" == *"dev lan-office"* ]]; then
        record "oclash/local-kept" PASS "the machine's own $overlay is still on its own adapter"
    else
        record "oclash/local-kept" FAIL "the overlay took over the machine's own network: ${route:-none}"
    fi

    if grep -q "is not installed: this machine is already on that network" "$LOGS/alpha-up.log" 2>/dev/null; then
        record "oclash/refused" PASS "the agent refused the overlay route and named the clash"
    else
        record "oclash/refused" FAIL "the agent did not report refusing the overlay"
    fi

    local deadline reported
    deadline=$(( $(date +%s) + 60 ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        reported="$(php "$LAB_DIR/lab-setup.php" problems "$UID_ALPHA" 2>/dev/null)"
        [[ "$reported" == *"overlay.clash"* ]] && break
        sleep 3
    done

    if [[ "$reported" == *"overlay.clash"* ]]; then
        record "oclash/reported" PASS "the panel shows it on the device, with the range named"
    else
        record "oclash/reported" FAIL "the agent refused the overlay and the panel was never told"
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

    local advertised routeId mapped nvr_overlay
    advertised="$(php "$LAB_DIR/lab-setup.php" route "$NETWORK" 192.168.77.0/24 "$UID_BETA")" \
        || { record "gwtamper/setup" FAIL "could not advertise the route"; lab::stop_servers; return; }
    routeId="$(awk -F= '/^ROUTE=/ {print $2}' <<<"$advertised")"
    mapped="$(awk -F= '/^MAPPED=/ {print $2}' <<<"$advertised")"
    nvr_overlay="$(lab::mapped_host "$mapped" 50)"
    php "$LAB_DIR/lab-setup.php" route-approve "$routeId" >/dev/null \
        || { record "gwtamper/setup" FAIL "could not approve the route"; lab::stop_servers; return; }

    php "$LAB_DIR/lab-setup.php" acl-cidr "$NETWORK" allow "$UID_ALPHA" 192.168.77.50/32 tcp 554 >/dev/null \
        || { record "gwtamper/setup" FAIL "could not author the LAN rule"; lab::stop_servers; return; }

    if ! lab::wait_reachable alpha "$nvr_overlay" 554 45; then
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
    if ! lab::wait_reachable alpha "$nvr_overlay" 554 45; then
        record "gwtamper/allowed" FAIL "the tampered agent could not reach the allowed port either; the drill is inconclusive"
        lab::stop_servers
        return
    fi
    record "gwtamper/allowed" PASS "the tampered agent still reaches tcp/554, so the path is working"

    if ! lab::serving nvr 8080; then
        record "gwtamper/blocked" FAIL "the NVR's second listener died, so a refused connection proves nothing"
    elif lab::tcp_connect alpha "$nvr_overlay" 8080; then
        record "gwtamper/blocked" FAIL "a client with its rules compiled out reached tcp/8080 on the NVR"
    else
        record "gwtamper/blocked" PASS "tcp/8080 refused at the gateway, with the client's own enforcement removed"
    fi

    lab::stop_servers
}

# §16–17: the collision case, which is the normal case in this country.
#
# The customer's hotel is on 192.168.1.0/24. So is the technician's own office.
# Both have a machine at .50. Before subnet mapping the agent refused the
# customer's route and said so in its log, which was honest and useless: the
# NVR stayed unreachable until somebody renumbered a building.
#
# The proof is not "the connection succeeded" — a connection to 192.168.1.50
# succeeds either way, because the technician's own printer is at that address.
# The proof is *which machine answered*, so both ends announce themselves.
scenario_subnet_mapping() {
    step "gateway — both LANs are 192.168.1.0/24, and the customer is still reachable"
    fixture collision

    if ! lab::wait_tunnel alpha "$BETA_IP" 60; then
        record "map/tunnel" FAIL "no tunnel between the support laptop and the site PC"
        return
    fi

    # Same address, same port, two different machines.
    if ! lab::serve_tcp nvr 554 "CUSTOMER-NVR" || ! lab::serve_tcp nvr 8080 "CUSTOMER-NVR-WEB" \
        || ! lab::serve_tcp office 554 "TECHNICIAN-OFFICE"; then
        record "map/setup" FAIL "the test listeners never came up"
        lab::stop_servers
        return
    fi

    local local_banner
    local_banner="$(lab::banner_from alpha 192.168.1.50 554 5)"
    if [ "$local_banner" != "TECHNICIAN-OFFICE" ]; then
        record "map/setup" FAIL "alpha's own LAN is not answering at 192.168.1.50 (got '${local_banner:-nothing}')"
        lab::stop_servers
        return
    fi
    record "map/own-lan" PASS "alpha's own 192.168.1.50 answers from its office before anything is advertised"

    # Advertise the customer's identical range and approve it.
    local advertised routeId mapped
    advertised="$(php "$LAB_DIR/lab-setup.php" route "$NETWORK" 192.168.1.0/24 "$UID_BETA")" \
        || { record "map/advertise" FAIL "could not advertise the route"; lab::stop_servers; return; }
    routeId="$(awk -F= '/^ROUTE=/ {print $2}' <<<"$advertised")"
    mapped="$(awk -F= '/^MAPPED=/ {print $2}' <<<"$advertised")"

    if [ -z "$mapped" ]; then
        record "map/allocated" FAIL "the panel advertised the route without allocating a virtual prefix"
        lab::stop_servers
        return
    fi
    record "map/allocated" PASS "the panel gave the customer's 192.168.1.0/24 the overlay prefix $mapped"

    php "$LAB_DIR/lab-setup.php" route-approve "$routeId" >/dev/null \
        || { record "map/advertise" FAIL "could not approve the route"; lab::stop_servers; return; }

    # The rule names the machine by the address on its own label, not by the
    # overlay address the panel invented for it.
    php "$LAB_DIR/lab-setup.php" acl-cidr "$NETWORK" allow "$UID_ALPHA" 192.168.1.50/32 tcp 554 >/dev/null \
        || { record "map/advertise" FAIL "could not author the LAN rule"; lab::stop_servers; return; }

    # The overlay address of the customer's NVR: host .50 inside the prefix the
    # panel allocated.
    local nvr_overlay
    nvr_overlay="$(lab::mapped_host "$mapped" 50)"

    if ! lab::wait_reachable alpha "$nvr_overlay" 554 60; then
        record "map/reach" FAIL "the customer's NVR is unreachable at $nvr_overlay through the gateway"
        lab::stop_servers
        return
    fi

    local remote_banner
    remote_banner="$(lab::banner_from alpha "$nvr_overlay" 554 8)"
    if [ "$remote_banner" = "CUSTOMER-NVR" ]; then
        record "map/reach" PASS "alpha reached the customer's NVR at $nvr_overlay — it answered CUSTOMER-NVR"
    else
        record "map/reach" FAIL "$nvr_overlay answered '${remote_banner:-nothing}', not the customer's NVR"
        lab::stop_servers
        return
    fi

    # The failure that looks like success: reaching your own printer and
    # believing you reached the customer.
    local_banner="$(lab::banner_from alpha 192.168.1.50 554 5)"
    if [ "$local_banner" = "TECHNICIAN-OFFICE" ]; then
        record "map/own-lan-intact" PASS "192.168.1.50 still reaches the technician's own office, not the customer's"
    else
        record "map/own-lan-intact" FAIL "alpha's own LAN now answers '${local_banner:-nothing}'; the overlay took it over"
    fi

    # The ACL is authored against 192.168.1.50 and has to be enforced against
    # the overlay address it became — at the gateway, not only at the client.
    if ! lab::serving nvr 8080; then
        record "map/acl" FAIL "the NVR's second listener died, so a refused connection proves nothing"
        lab::stop_servers
        return
    elif lab::tcp_connect alpha "$nvr_overlay" 8080; then
        record "map/acl" FAIL "tcp/8080 on the mapped NVR was reachable; the rule named 554 only"
        lab::stop_servers
        return
    else
        record "map/acl" PASS "tcp/8080 on the mapped NVR is refused; the rule named the real IP and still bound the mapped one"
    fi

    # That refusal was alpha's own. The rule has to hold when alpha's
    # enforcement is not there — a mapped address must not be a way around the
    # gateway's half of the check.
    lab::down_agents
    lab::up_tampered alpha
    lab::up beta

    if ! lab::wait_tunnel alpha "$BETA_IP" 60 || ! lab::wait_reachable alpha "$nvr_overlay" 554 60; then
        record "map/gateway-acl" FAIL "the tampered agent never reached the allowed port, so the drill is inconclusive"
        lab::stop_servers
        return
    fi

    if ! lab::serving nvr 8080; then
        record "map/gateway-acl" FAIL "the NVR's second listener died, so a refused connection proves nothing"
    elif lab::tcp_connect alpha "$nvr_overlay" 8080; then
        record "map/gateway-acl" FAIL "a client with its rules compiled out reached tcp/8080 on the mapped NVR"
    else
        record "map/gateway-acl" PASS "the gateway refused tcp/8080 on the mapped address with the client's own enforcement removed"
    fi

    lab::stop_servers
}

# 1.9.7-dev.25: the field defect, as a gate. "Windows gateway + relayed pair +
# ping/HTTP to the gateway's LAN router" had never been tested end to end.
#
# The site router has no route back to the overlay (build_gateway_router), so
# the only way a reply returns is the gateway putting its own LAN address in
# the source. Beta runs the agent's own NAT (gwnat) — the code every Windows
# gateway runs, since WinNAT is absent without Hyper-V — forced on here with
# AKCONNECT_GATEWAY_USERSPACE=1. Run relayed (symmetric NAT both sides) and
# direct (cone): the gateway must work "via server" and "direct" alike.
gateway_lan_router() {
    local mode=$1 want_path=$2 tag=$3
    step "gateway — ping and HTTP to the site's router through a $want_path pair, NAT done by the agent"

    LAB_ENV_beta="AKCONNECT_GATEWAY_USERSPACE=1" fixture gateway-router "$mode"

    if ip netns exec lanrtr ip route get "$ALPHA_IP" >/dev/null 2>&1; then
        record "$tag/no-route-back" FAIL "the site router has a route to the overlay, so this drill cannot see missing NAT"
        return
    fi
    record "$tag/no-route-back" PASS "the site router 192.168.77.1 has no route to the overlay, like a real one"

    if ! lab::wait_tunnel alpha "$BETA_IP" 90; then
        record "$tag/tunnel" FAIL "no tunnel between the laptop and the gateway"
        return
    fi
    if ! lab::wait_path alpha "$want_path" 60; then
        record "$tag/path" FAIL "the pair is on '$(lab::path alpha)', not $want_path — this drill needs $want_path"
        return
    fi
    record "$tag/path" PASS "the pair is $(lab::path alpha)"

    # The router's web page, served on the router itself.
    local www="$RUN/lanrtr-www" weblog="$LOGS/lanrtr-http.log"
    mkdir -p "$www"
    printf 'ZTE-ROUTER-LOGIN\n' >"$www/index.html"
    setsid ip netns exec lanrtr php -S 192.168.77.1:80 -t "$www" >"$weblog" 2>&1 &
    SERVER_PIDS+=("$!")
    if ! lab::wait_reachable lanrtr 192.168.77.1 80 10; then
        record "$tag/setup" FAIL "the router's web server never came up"
        lab::stop_servers
        return
    fi

    local advertised routeId mapped router
    advertised="$(php "$LAB_DIR/lab-setup.php" route "$NETWORK" 192.168.77.0/24 "$UID_BETA")" \
        || { record "$tag/advertise" FAIL "could not advertise the route"; lab::stop_servers; return; }
    routeId="$(awk -F= '/^ROUTE=/ {print $2}' <<<"$advertised")"
    mapped="$(awk -F= '/^MAPPED=/ {print $2}' <<<"$advertised")"
    php "$LAB_DIR/lab-setup.php" route-approve "$routeId" >/dev/null \
        || { record "$tag/advertise" FAIL "could not approve the route"; lab::stop_servers; return; }
    router="$(lab::mapped_host "$mapped" 1)"

    # ICMP first: the field symptom was "ping 10.128.0.1 — Request timed out".
    local deadline=$(( $(date +%s) + 60 ))
    while [ "$(date +%s)" -lt "$deadline" ] && ! lab::ping alpha "$router" 1; do sleep 1; done
    local out received
    out="$(ip netns exec alpha ping -c 20 -i 0.2 -W 2 "$router" 2>&1)"
    received="$(grep -oE '[0-9]+ received' <<<"$out" | grep -oE '^[0-9]+')"
    if [ "${received:-0}" -ge 19 ]; then
        record "$tag/icmp" PASS "ping $router (the router 192.168.77.1): ${received}/20 answered"
    else
        record "$tag/icmp" FAIL "ping $router (the router 192.168.77.1): ${received:-0}/20 answered"
    fi

    local body
    body="$(ip netns exec alpha curl -s -m 8 "http://$router/" 2>/dev/null)"
    if [ "$body" = "ZTE-ROUTER-LOGIN" ]; then
        record "$tag/http" PASS "HTTP GET http://$router/ returned the router's page"
    else
        record "$tag/http" FAIL "HTTP GET http://$router/ returned '${body:-nothing}'"
    fi

    # Proof it was translated, from the router's side: the request came from
    # the gateway's own LAN address, never an overlay one.
    if grep -q '192\.168\.77\.23:' "$weblog" && ! grep -qE '10\.99\.0\.[0-9]+:' "$weblog"; then
        record "$tag/source" PASS "the router saw the request from the gateway's LAN address 192.168.77.23"
    else
        record "$tag/source" FAIL "the router's log does not show 192.168.77.23 as the source (see $weblog)"
    fi

    # And the gateway says so itself — the counters status prints.
    # The status file is rewritten on the agent's next poll, so wait for it.
    local gw ok=0
    deadline=$(( $(date +%s) + 45 ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        gw="$(lab::runtime beta | jq -c '.gateway // {}')"
        if [ "$(jq -r '.mode // ""' <<<"$gw")" = "agent" ] && [ "$(jq -r '.pings_ok // 0' <<<"$gw")" -gt 0 ] \
            && [ "$(jq -r '.tcp_opened // 0' <<<"$gw")" -gt 0 ] && [ -z "$(jq -r '.problem // ""' <<<"$gw")" ]; then
            ok=1
            break
        fi
        sleep 2
    done
    if [ "$ok" = 1 ]; then
        record "$tag/counters" PASS "the gateway reports agent NAT with pings and TCP answered: $gw"
    else
        record "$tag/counters" FAIL "the gateway's status does not show the agent's NAT working: $gw"
    fi

    lab::stop_servers
}

scenario_gateway_lan_router_relayed() { gateway_lan_router symmetric relay gwr-relay; }
scenario_gateway_lan_router_direct()  { gateway_lan_router cone direct gwr-direct; }

# The negative control: the same site with the gateway's NAT taken away must
# fail. Linux's own NAT (iptables MASQUERADE, kernel mode) is used and then
# removed; a drill that still passes after that could not have caught the
# field defect, whatever it prints.
scenario_gateway_lan_router_needs_nat() {
    step "gateway — without translation the site router cannot answer (the drill can see the defect)"

    LAB_ENV_beta="" fixture gateway-router symmetric

    if ! lab::wait_tunnel alpha "$BETA_IP" 90; then
        record "gwr-nonat/tunnel" FAIL "no tunnel between the laptop and the gateway"
        return
    fi

    local www="$RUN/lanrtr-www"
    mkdir -p "$www"
    printf 'ZTE-ROUTER-LOGIN\n' >"$www/index.html"
    setsid ip netns exec lanrtr php -S 192.168.77.1:80 -t "$www" >"$LOGS/lanrtr-http.log" 2>&1 &
    SERVER_PIDS+=("$!")

    local advertised routeId mapped router
    advertised="$(php "$LAB_DIR/lab-setup.php" route "$NETWORK" 192.168.77.0/24 "$UID_BETA")" \
        || { record "gwr-nonat/advertise" FAIL "could not advertise the route"; lab::stop_servers; return; }
    routeId="$(awk -F= '/^ROUTE=/ {print $2}' <<<"$advertised")"
    mapped="$(awk -F= '/^MAPPED=/ {print $2}' <<<"$advertised")"
    php "$LAB_DIR/lab-setup.php" route-approve "$routeId" >/dev/null \
        || { record "gwr-nonat/advertise" FAIL "could not approve the route"; lab::stop_servers; return; }
    router="$(lab::mapped_host "$mapped" 1)"

    local deadline=$(( $(date +%s) + 60 )) body=""
    while [ "$(date +%s)" -lt "$deadline" ]; do
        body="$(ip netns exec alpha curl -s -m 3 "http://$router/" 2>/dev/null)"
        [ "$body" = "ZTE-ROUTER-LOGIN" ] && break
        sleep 2
    done
    if [ "$body" = "ZTE-ROUTER-LOGIN" ]; then
        record "gwr-nonat/kernel" PASS "with Linux's own NAT (kernel mode) the router answers HTTP through the relayed pair"
    else
        record "gwr-nonat/kernel" FAIL "even with Linux's own NAT the router did not answer HTTP"
        lab::stop_servers
        return
    fi

    # Take the translation away, and the connection tracking that would keep
    # an existing flow translated.
    ip netns exec beta iptables -t nat -F POSTROUTING
    ip netns exec beta conntrack -F >/dev/null 2>&1 || true

    body="$(ip netns exec alpha curl -s -m 5 "http://$router/" 2>/dev/null)"
    if [ -z "$body" ]; then
        record "gwr-nonat/detects" PASS "with the gateway's NAT removed the router cannot answer — the drill sees the field defect"
    else
        record "gwr-nonat/detects" FAIL "the router answered with no NAT on the gateway; this topology cannot catch the defect"
    fi

    lab::stop_servers
}
