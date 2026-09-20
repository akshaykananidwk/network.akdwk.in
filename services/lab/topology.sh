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

build_nat_side() {
    local host=$1 gw=$2 publicLast=$3 private=$4

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
    ip netns exec "$gw" iptables -t nat -A POSTROUTING -s "$private.0/24" -o "wan-$gw" -j MASQUERADE

    ip netns exec "$host" ip link set lo up
    ip netns exec "$host" ip address add "$private.2/24" dev "veth-$host"
    ip netns exec "$host" ip link set "veth-$host" up
    ip netns exec "$host" ip route add default via "$private.1"

    note "$host is $private.2 behind NAT at 10.0.0.$publicLast"
}

case "${1:-up}" in
    up)   build_flat ;;
    nat)  build_nat ;;
    down) teardown ;;
    *)    die "unknown command: $1 (use up, nat or down)" ;;
esac
