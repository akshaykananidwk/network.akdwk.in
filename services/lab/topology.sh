#!/usr/bin/env bash
# Two hosts on separate network stacks, for testing the agent for real.
#
# This is not a simulation of networking: each namespace has its own routing
# table, its own interfaces and its own view of the world, and the packets
# between them are real packets through the kernel. What it is not is two
# machines on two ISPs — see VERIFICATION_REPORT.md for what that means for
# which claims this lab can and cannot support.
#
#   ./topology.sh up      build the lab
#   ./topology.sh down    tear it down
#   ./topology.sh nat     rebuild with both hosts behind separate NATs
#   ./topology.sh mixed A B   one side cone, the other symmetric
#   ./topology.sh gateway     adds an agentless "nvr" on beta's LAN
#
# Namespaces:
#   alpha  10.0.0.2/24  ─┐
#                        ├─ akbr0 10.0.0.1/24 (host) ── panel on :8099
#   beta   10.0.0.3/24  ─┘
set -euo pipefail

BRIDGE=akbr0
HOST_IP=10.0.0.1

die() { echo "  ✗ $*" >&2; exit 1; }
note() { echo "  $*"; }

teardown() {
    # natgw-alpha and cgnat-alpha are the two layers the CGNAT topology adds.
    # A namespace left behind makes the next "ip netns add" fail, and the
    # scenario then blames the product for a lab that did not clean up.
    for ns in alpha beta gamma natgw-a natgw-b natgw-alpha cgnat-alpha natgw-s nvr office; do
        ip netns del "$ns" 2>/dev/null || true
    done
    ip link del "$BRIDGE" 2>/dev/null || true
    rm -rf /etc/netns/alpha /etc/netns/beta

    # Host-side veths outlive the namespace they were paired into, for a moment
    # or for good if the delete raced. A leftover one makes the next "ip link
    # add" fail with "File exists", that side of the topology never gets its
    # default route, and the scenario then reports the product unreachable when
    # it is the lab that is broken.
    local link
    for link in br-alpha br-beta br-natgw-a br-natgw-b \
                veth-alpha veth-beta wan-natgw-a wan-natgw-b \
                lan-natgw-a lan-natgw-b lan-beta veth-nvr lan-alpha veth-office \
                lan-local lan-local-far lan-office lan-office-far \
                wan-natgw-s br-natgw-s lan-natgw-s lan-shared \
                veth-gamma lan-gamma; do
        ip link del "$link" 2>/dev/null || true
    done

    note "lab torn down"
}

build_flat() {
    teardown

    ip link add "$BRIDGE" type bridge
    ip address add "$HOST_IP/24" dev "$BRIDGE"
    ip link set "$BRIDGE" up

    local idx=2
    for ns in alpha beta; do
        ip netns add "$ns"
        ip link add "veth-$ns" type veth peer name "br-$ns"
        ip link set "br-$ns" master "$BRIDGE"
        ip link set "br-$ns" up
        ip link set "veth-$ns" netns "$ns"

        ip netns exec "$ns" ip link set lo up
        ip netns exec "$ns" ip address add "10.0.0.$idx/24" dev "veth-$ns"
        ip netns exec "$ns" ip link set "veth-$ns" up
        ip netns exec "$ns" ip route add default via "$HOST_IP"

        note "$ns is 10.0.0.$idx/24"
        idx=$((idx + 1))
    done

    sysctl -qw net.ipv4.ip_forward=1
    note "both hosts share a LAN; peers can reach each other directly"
}

# Each host sits behind its own NAT gateway, so neither can reach the other's
# address at all. The only thing they share is the "internet" segment where the
# coordinator lives. A tunnel between them therefore cannot be explained by
# anything except hole punching.
#
#   alpha 192.168.10.2 ── natgw-a 10.0.0.10 ─┐
#                                            ├─ akbr0 10.0.0.1 (coordinator)
#   beta  192.168.20.2 ── natgw-b 10.0.0.11 ─┘
build_nat() {
    teardown

    ip link add "$BRIDGE" type bridge
    ip address add "$HOST_IP/24" dev "$BRIDGE"
    ip link set "$BRIDGE" up

    build_nat_side alpha natgw-a 10 192.168.10
    build_nat_side beta  natgw-b 11 192.168.20

    sysctl -qw net.ipv4.ip_forward=1
    note "each host is behind its own NAT; they share only the coordinator segment"
}

# apply_nat_mode installs the NAT rules for one gateway.
#
# The two modes differ in exactly the way that decides whether hole punching
# can work, so getting the distinction right is the point of the lab.
#
#   cone       the agent's port is preserved and inbound traffic to it is
#              accepted from anyone. This is a home router with UPnP, or a
#              well-behaved full-cone NAT. Punching works.
#
#   symmetric  a fresh external port per destination, so the address the
#              coordinator observes is useless to a peer. This is what a lot
#              of Indian broadband and mobile CGNAT does. Punching cannot work,
#              by construction, and the relay is the only path.
#
# Plain MASQUERADE is *not* a reliable cone: Linux preserves the source port
# only when it is free, and once the agent has flows to the coordinator and the
# relay from the same port, a new flow to a peer gets a different one — which
# looks symmetric. An explicit SNAT/DNAT pair on the agent's port removes that
# ambiguity, so "cone" in this lab means cone.
apply_nat_mode() {
    local gw=$1 publicLast=$2 private=$3 mode=$4
    local wan="10.0.0.$publicLast"
    local host="$private.2"

    if [ "$mode" = "symmetric" ]; then
        ip netns exec "$gw" iptables -t nat -A POSTROUTING -s "$private.0/24" \
            -o "wan-$gw" -p udp -j MASQUERADE --random-fully
        ip netns exec "$gw" iptables -t nat -A POSTROUTING -s "$private.0/24" \
            -o "wan-$gw" -j MASQUERADE

        return
    fi

    # Outbound from the agent's port keeps that port.
    ip netns exec "$gw" iptables -t nat -A POSTROUTING -s "$host" -p udp --sport 51820 \
        -o "wan-$gw" -j SNAT --to-source "$wan:51820"
    # Inbound to it reaches the agent, whoever sent it.
    ip netns exec "$gw" iptables -t nat -A PREROUTING -d "$wan" -p udp --dport 51820 \
        -i "wan-$gw" -j DNAT --to-destination "$host:51820"
    # Everything else is ordinary masquerading.
    ip netns exec "$gw" iptables -t nat -A POSTROUTING -s "$private.0/24" \
        -o "wan-$gw" -j MASQUERADE
}

build_nat_side() {
    local host=$1 gw=$2 publicLast=$3 private=$4 mode=${5:-cone}

    ip netns add "$gw"
    ip netns add "$host"

    # Gateway to the shared segment.
    ip link add "wan-$gw" type veth peer name "br-$gw"
    ip link set "br-$gw" master "$BRIDGE"
    ip link set "br-$gw" up
    ip link set "wan-$gw" netns "$gw"

    ip netns exec "$gw" ip link set lo up
    ip netns exec "$gw" ip address add "10.0.0.$publicLast/24" dev "wan-$gw"
    ip netns exec "$gw" ip link set "wan-$gw" up
    ip netns exec "$gw" ip route add default via "$HOST_IP"

    # Gateway to the private segment.
    ip link add "lan-$gw" type veth peer name "veth-$host"
    ip link set "lan-$gw" netns "$gw"
    ip link set "veth-$host" netns "$host"

    ip netns exec "$gw" ip address add "$private.1/24" dev "lan-$gw"
    ip netns exec "$gw" ip link set "lan-$gw" up
    ip netns exec "$gw" sysctl -qw net.ipv4.ip_forward=1
    apply_nat_mode "$gw" "$publicLast" "$private" "$mode"

    ip netns exec "$host" ip link set lo up
    ip netns exec "$host" ip address add "$private.2/24" dev "veth-$host"
    ip netns exec "$host" ip link set "veth-$host" up
    ip netns exec "$host" ip route add default via "$private.1"

    note "$host is $private.2 behind NAT at 10.0.0.$publicLast"
}

# Symmetric NAT: the case hole punching cannot beat.
#
# A cone NAT gives one external port per internal socket, whoever it is talking
# to — so the address the coordinator observes is the address a peer can use.
# A symmetric NAT allocates a *different* external port per destination, so the
# address the coordinator sees is useless to anyone else, by construction.
#
# --random-fully makes Linux pick a fresh source port per connection rather
# than trying to preserve it, which is what produces that behaviour. This is
# the shape of a carrier-grade NAT, and the reason the relay is a launch
# requirement rather than a fallback.
build_symmetric() {
    teardown

    ip link add "$BRIDGE" type bridge
    ip address add "$HOST_IP/24" dev "$BRIDGE"
    ip link set "$BRIDGE" up

    build_nat_side alpha natgw-a 10 192.168.10 symmetric
    build_nat_side beta  natgw-b 11 192.168.20 symmetric

    sysctl -qw net.ipv4.ip_forward=1
    note "both hosts are behind SYMMETRIC NAT; hole punching cannot succeed"
    note "a tunnel here proves the relay works"
}

# Carrier-grade NAT, two layers deep, which is what a Jio hotspot is.
#
# The lab's "symmetric" is one NAT that remaps ports. A real mobile connection
# is two: the phone NATs the laptop onto a private range, and the carrier NATs
# the phone onto a shared public address out of 100.64.0.0/10. The laptop's
# public port bears no relation to the port it is listening on, there is no
# inbound path to it at all, and the address it appears from is shared with
# thousands of other subscribers.
#
# It matters as a separate case because the field failure was not that punching
# failed — punching is supposed to fail here — it was that the *relay* was
# never contacted, and the lab could not have shown that: the lab configured
# its relays by IP address, and the agent's offer handler only parsed IP
# addresses. Everything passed. So this scenario exists alongside a relay
# configured by hostname, which is how DEPLOY tells an operator to configure
# one and how the production deployment did.
#
#   alpha  192.168.10.2  →  CPE at 100.64.0.10  →  carrier at 10.0.0.10
#   beta   192.168.20.2  →  cone NAT at 10.0.0.11
#
# Beta on a decent line is deliberate: the acceptance test is one PC behind
# CGNAT and one not, because that is the shop-and-laptop case this product is
# sold for.
build_cgnat() {
    local betaMode=${1:-symmetric}
    teardown

    ip link add "$BRIDGE" type bridge
    ip address add "$HOST_IP/24" dev "$BRIDGE"
    ip link set "$BRIDGE" up

    build_cgnat_side alpha 10 192.168.10 100.64.0
    build_nat_side beta natgw-b 11 192.168.20 "$betaMode"

    sysctl -qw net.ipv4.ip_forward=1
    note "alpha is behind TWO layers of NAT (CPE then carrier); no inbound path exists"
    note "beta is behind a $betaMode NAT"

    if [ "$betaMode" = "cone" ]; then
        # Worth having as its own case: a customer behind CGNAT is not
        # automatically condemned to a relay. If the *other* end is reachable
        # inbound — a shop with a port forward, or a well-behaved router —
        # alpha can open the path from its side and the pair goes direct. The
        # first run of this scenario with a cone peer went direct, which was
        # the product being right and the scenario being wrong.
        note "beta is reachable inbound, so a direct path is possible even from behind CGNAT"
    else
        note "neither end is reachable inbound, so a tunnel here can only be relayed"
    fi
}

# build_cgnat_side wires one host behind a CPE and then a carrier.
build_cgnat_side() {
    local host=$1 publicLast=$2 private=$3 carrier=$4
    local cpe="natgw-$host" cg="cgnat-$host"

    ip netns add "$cg"
    ip netns add "$cpe"
    ip netns add "$host"

    # The carrier's own uplink to the shared segment, where the coordinator
    # and the relays live.
    ip link add "wan-$cg" type veth peer name "br-$cg"
    ip link set "br-$cg" master "$BRIDGE"
    ip link set "br-$cg" up
    ip link set "wan-$cg" netns "$cg"

    ip netns exec "$cg" ip link set lo up
    ip netns exec "$cg" ip address add "10.0.0.$publicLast/24" dev "wan-$cg"
    ip netns exec "$cg" ip link set "wan-$cg" up
    ip netns exec "$cg" ip route add default via "$HOST_IP"
    ip netns exec "$cg" sysctl -qw net.ipv4.ip_forward=1

    # Carrier to CPE, on 100.64/10 — the range reserved for exactly this.
    ip link add "lan-$cg" type veth peer name "wan-$cpe"
    ip link set "lan-$cg" netns "$cg"
    ip link set "wan-$cpe" netns "$cpe"

    ip netns exec "$cg" ip address add "$carrier.1/24" dev "lan-$cg"
    ip netns exec "$cg" ip link set "lan-$cg" up

    ip netns exec "$cpe" ip link set lo up
    ip netns exec "$cpe" ip address add "$carrier.10/24" dev "wan-$cpe"
    ip netns exec "$cpe" ip link set "wan-$cpe" up
    ip netns exec "$cpe" ip route add default via "$carrier.1"
    ip netns exec "$cpe" sysctl -qw net.ipv4.ip_forward=1

    # CPE to the laptop.
    ip link add "lan-$cpe" type veth peer name "veth-$host"
    ip link set "lan-$cpe" netns "$cpe"
    ip link set "veth-$host" netns "$host"

    ip netns exec "$cpe" ip address add "$private.1/24" dev "lan-$cpe"
    ip netns exec "$cpe" ip link set "lan-$cpe" up

    ip netns exec "$host" ip link set lo up
    ip netns exec "$host" ip address add "$private.2/24" dev "veth-$host"
    ip netns exec "$host" ip link set "veth-$host" up
    ip netns exec "$host" ip route add default via "$private.1"

    # Both layers remap the port, and neither accepts anything inbound that it
    # did not see going out. --random-fully is what makes the mapping
    # per-destination rather than per-socket, which is the property that
    # defeats punching.
    ip netns exec "$cpe" iptables -t nat -A POSTROUTING -s "$private.0/24" \
        -o "wan-$cpe" -p udp -j MASQUERADE --random-fully
    ip netns exec "$cpe" iptables -t nat -A POSTROUTING -s "$private.0/24" \
        -o "wan-$cpe" -j MASQUERADE

    ip netns exec "$cg" iptables -t nat -A POSTROUTING -s "$carrier.0/24" \
        -o "wan-$cg" -p udp -j MASQUERADE --random-fully
    ip netns exec "$cg" iptables -t nat -A POSTROUTING -s "$carrier.0/24" \
        -o "wan-$cg" -j MASQUERADE

    note "$host is $private.2 behind a CPE at $carrier.10 behind a carrier at 10.0.0.$publicLast"
}

# One host behind a cone NAT, the other behind a symmetric one.
#
# This is the common real case, not a corner: a shop on a decent fibre line
# talking to a laptop on 4G. It is also the case where the answer is not
# obvious — punching can succeed if the symmetric side is the one that starts,
# because its fresh mapping is created towards an address the cone side is
# already reachable on. The drill records which path actually resulted rather
# than asserting one, because either is a correct outcome.
build_mixed() {
    local alphaMode=${1:-cone} betaMode=${2:-symmetric}
    teardown

    ip link add "$BRIDGE" type bridge
    ip address add "$HOST_IP/24" dev "$BRIDGE"
    ip link set "$BRIDGE" up

    build_nat_side alpha natgw-a 10 192.168.10 "$alphaMode"
    build_nat_side beta  natgw-b 11 192.168.20 "$betaMode"

    sysctl -qw net.ipv4.ip_forward=1
    note "alpha is behind a $alphaMode NAT, beta behind a $betaMode NAT"
}

# A site LAN behind beta, with a machine on it that runs no agent.
#
# This is the shape of every customer site: one PC that can run our software,
# and the things that matter — an NVR, a printer, a till — that cannot. If the
# nvr namespace can be reached without our agent on it, subnet-router mode is
# real; if it cannot, the feature does not exist however much code it has.
#
#   alpha 192.168.10.2 ── natgw-a ─┐
#                                  ├─ akbr0 (coordinator, relays)
#   beta  192.168.20.2 ── natgw-b ─┘
#     └── 192.168.77.1  (beta's LAN side)
#           └── nvr 192.168.77.50   no agent, no overlay address
build_gateway() {
    teardown

    ip link add "$BRIDGE" type bridge
    ip address add "$HOST_IP/24" dev "$BRIDGE"
    ip link set "$BRIDGE" up

    build_nat_side alpha natgw-a 10 192.168.10 cone
    build_nat_side beta  natgw-b 11 192.168.20 cone

    # The site LAN, hanging off beta.
    ip netns add nvr
    ip link add lan-beta type veth peer name veth-nvr
    ip link set lan-beta netns beta
    ip link set veth-nvr netns nvr

    ip netns exec beta ip address add 192.168.77.1/24 dev lan-beta
    ip netns exec beta ip link set lan-beta up

    ip netns exec nvr ip link set lo up
    ip netns exec nvr ip address add 192.168.77.50/24 dev veth-nvr
    ip netns exec nvr ip link set veth-nvr up

    # Deliberately no route back to the overlay. A camera recorder does not
    # know about 10.99.0.0/24 and nobody is going to teach it — which is
    # exactly why the gateway has to translate rather than route.
    ip netns exec nvr ip route add default via 192.168.77.1

    sysctl -qw net.ipv4.ip_forward=1
    note "nvr is 192.168.77.50 on beta's LAN, with no agent and no route to the overlay"
}

# The topology the subnet-mapping feature exists for.
#
# The customer's hotel is on 192.168.1.0/24. So is the technician's own office.
# So, in this country, is nearly everybody — which is why "renumber one side"
# is not an answer a business can give its customers.
#
# Both LANs carry a machine at .50, deliberately. If the mapping is wrong in
# either direction the technician reaches their own printer and gets a
# perfectly successful connection to the wrong machine, which is the failure
# worth catching: it looks exactly like success.
build_collision() {
    teardown

    ip link add "$BRIDGE" type bridge
    ip address add "$HOST_IP/24" dev "$BRIDGE"
    ip link set "$BRIDGE" up

    build_nat_side alpha natgw-a 10 192.168.10 cone
    build_nat_side beta  natgw-b 11 192.168.20 cone

    # The customer's site, behind beta.
    ip netns add nvr
    ip link add lan-beta type veth peer name veth-nvr
    ip link set lan-beta netns beta
    ip link set veth-nvr netns nvr

    ip netns exec beta ip address add 192.168.1.1/24 dev lan-beta
    ip netns exec beta ip link set lan-beta up

    ip netns exec nvr ip link set lo up
    ip netns exec nvr ip address add 192.168.1.50/24 dev veth-nvr
    ip netns exec nvr ip link set veth-nvr up
    ip netns exec nvr ip route add default via 192.168.1.1

    # The technician's own office, on the same range, with its own machine at
    # the same address.
    ip netns add office
    ip link add lan-alpha type veth peer name veth-office
    ip link set lan-alpha netns alpha
    ip link set veth-office netns office

    ip netns exec alpha ip address add 192.168.1.1/24 dev lan-alpha
    ip netns exec alpha ip link set lan-alpha up

    ip netns exec office ip link set lo up
    ip netns exec office ip address add 192.168.1.50/24 dev veth-office
    ip netns exec office ip link set veth-office up
    ip netns exec office ip route add default via 192.168.1.1

    # A resolver configuration of the customer's own, so the §18 claim — that
    # everything outside our zone goes where it went before — is something the
    # drill can watch rather than assert. A namespace with no file of its own
    # shares the host's, and then "the agent did not touch it" would be a
    # statement about the wrong file.
    mkdir -p /etc/netns/alpha
    printf 'nameserver 192.168.10.1\noptions timeout:2 attempts:1\n' > /etc/netns/alpha/resolv.conf

    sysctl -qw net.ipv4.ip_forward=1
    note "both LANs are 192.168.1.0/24, and both have a machine at .50"
    note "alpha resolves through 192.168.10.1, which stands in for its ISP"
}

# Two devices on ONE router, which is what a customer site looks like.
#
# Defect 23: every agent binds the same fixed UDP port, so behind a shared
# router exactly one of them can hold the public 51820 mapping. In the field
# the laptop's hellos reached the coordinator from :51820 and the replies came
# back to the OTHER PC — the laptop showed "Coordinator: not reachable" while
# its own log said it was announcing every twenty seconds, and the pair sat at
# "connecting" until one machine was moved to a different ISP.
#
# The lab had no topology where two agents share a router, so it could not have
# seen any of it. Every NAT scenario here put exactly one device behind each
# gateway.
#
#   alpha 192.168.30.2 ─┐
#                       ├─ natgw-s 10.0.0.30 ── shared segment ── coordinator
#   gamma 192.168.30.3 ─┘
#
# The gateway masquerades with port preservation (no --random-fully), which is
# what a home router does: the first device to send from 51820 keeps 51820, and
# the second is given something else. Add a stale forward with
# `shared forward` to reproduce the rest of the field case, where inbound
# traffic to :51820 is delivered to whichever machine the router decided owns
# it rather than to the one whose flow created the mapping.
build_shared() {
    local stale=${1:-none}

    teardown
    ip link add "$BRIDGE" type bridge
    ip link set "$BRIDGE" up
    ip address add "$HOST_IP/24" dev "$BRIDGE"
    ip netns add natgw-s

    ip link add wan-natgw-s type veth peer name br-natgw-s
    ip link set br-natgw-s master "$BRIDGE"
    ip link set br-natgw-s up
    ip link set wan-natgw-s netns natgw-s

    ip netns exec natgw-s ip link set lo up
    ip netns exec natgw-s ip address add 10.0.0.30/24 dev wan-natgw-s
    ip netns exec natgw-s ip link set wan-natgw-s up
    ip netns exec natgw-s ip route add default via "$HOST_IP"
    ip netns exec natgw-s sysctl -qw net.ipv4.ip_forward=1

    # One LAN, two machines on it — the part no other topology here has.
    # Created INSIDE the namespace: a bridge cannot be moved into one
    # afterwards ("The interface netns is immutable").
    ip netns exec natgw-s ip link add lan-natgw-s type bridge
    ip netns exec natgw-s ip address add 192.168.30.1/24 dev lan-natgw-s
    ip netns exec natgw-s ip link set lan-natgw-s up

    local host last
    last=2
    for host in alpha gamma; do
        ip netns add "$host"
        ip link add "veth-$host" type veth peer name "lan-$host"
        ip link set "lan-$host" netns natgw-s
        ip link set "veth-$host" netns "$host"

        ip netns exec natgw-s ip link set "lan-$host" master lan-natgw-s
        ip netns exec natgw-s ip link set "lan-$host" up

        ip netns exec "$host" ip link set lo up
        ip netns exec "$host" ip address add "192.168.30.$last/24" dev "veth-$host"
        ip netns exec "$host" ip link set "veth-$host" up
        ip netns exec "$host" ip route add default via 192.168.30.1
        last=$((last + 1))
    done

    # Port-preserving masquerade: what a home router does, and the reason only
    # one of the two can hold the public 51820.
    ip netns exec natgw-s iptables -t nat -A POSTROUTING -s 192.168.30.0/24 \
        -o wan-natgw-s -j MASQUERADE

    if [ "$stale" = "forward" ]; then
        # One LAN host may use external :51820, and no other.
        #
        # This models a router that will not create a second mapping for a port
        # another machine has already leased — a stale UPnP lease, or a
        # hand-made forward. Traffic from a second host using that source port
        # is dropped rather than mapped.
        #
        # What it models exactly: a device cannot assume it owns a fixed
        # external port, and must notice and move when it does not.
        #
        # What it does NOT model: the field router delivered the second
        # device's REPLIES to the first machine rather than dropping them. That
        # cannot be built with iptables — NAT is conntrack-based, so an
        # established flow is always answered correctly, and taking conntrack
        # away (NOTRACK) disables SNAT with it, which broke the first two
        # versions of this topology and made the drill test nothing. The
        # observable failure for the device is the same either way: its
        # announcements go unanswered for ever while it holds that port.
        ip netns exec natgw-s iptables -t nat -A PREROUTING -d 10.0.0.30 \
            -p udp --dport 51820 -i wan-natgw-s -j DNAT --to-destination 192.168.30.2:51820
        ip netns exec natgw-s iptables -A FORWARD -i lan-natgw-s \
            -p udp --sport 51820 ! -s 192.168.30.2 -j DROP

        note "external :51820 is leased to alpha; another host using it is not mapped"
    fi

    # And a third machine behind a router of its own, because "two PCs at one
    # site talk to each other" and "they both talk to the branch office" are
    # different claims and a fix for the first can break the second.
    build_nat_side beta natgw-b 11 192.168.20 cone

    note "alpha 192.168.30.2 and gamma 192.168.30.3 share one router at 10.0.0.30"
}

case "${1:-up}" in
    up)        build_flat ;;
    nat)       build_nat ;;
    symmetric) build_symmetric ;;
    cgnat)     build_cgnat "${2:-symmetric}" ;;
    mixed)     build_mixed "${2:-cone}" "${3:-symmetric}" ;;
    gateway)   build_gateway ;;
    collision) build_collision ;;
    shared)    build_shared "${2:-none}" ;;
    down)      teardown ;;
    *)         die "unknown command: $1 (use up, nat, symmetric, cgnat, mixed, gateway, collision or down)" ;;
esac
