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
    for ns in alpha beta natgw-a natgw-b; do
        ip netns del "$ns" 2>/dev/null || true
    done
    ip link del "$BRIDGE" 2>/dev/null || true
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

case "${1:-up}" in
    up)        build_flat ;;
    nat)       build_nat ;;
    symmetric) build_symmetric ;;
    down)      teardown ;;
    *)         die "unknown command: $1 (use up, nat, symmetric or down)" ;;
esac
