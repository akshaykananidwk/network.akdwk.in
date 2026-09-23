#!/usr/bin/env bash
# The Linux half of the field kit. Same evidence, same output shape, so two
# ends of one test can be read side by side.
#
# It reads; it does not change anything.
#
#   sudo ./collect-evidence.sh 10.99.0.3
set -uo pipefail

PEER="${1:-}"
AGENT="${AKCONNECT_AGENT:-$(dirname "$0")/akconnect-agent-linux-amd64}"
[ -x "$AGENT" ] || AGENT="akconnect-agent"
OUT="akconnect-evidence-$(hostname)-$(date +%Y%m%d-%H%M%S).txt"

section() { printf '\n==============================================================\n  %s\n==============================================================\n' "$1"; }
try()     { section "$1"; shift; "$@" 2>&1 || echo "FAILED (exit $?)"; }

{
    section "AKConnect field evidence"
    echo "collected  : $(date -Is)"
    echo "machine    : $(hostname)"
    echo "user       : $(id -un) (uid $(id -u))"
    echo "agent path : $AGENT"
    echo "peer under test: ${PEER:-(none given)}"

    try "OS" sh -c 'cat /etc/os-release 2>/dev/null | head -4; uname -a'

    try "agent version" "$AGENT" version
    try "agent status"  "$AGENT" status

    section "agent runtime status file (machine readable)"
    for d in "${AKCONNECT_STATE_DIR:-}" /etc/akconnect "$HOME/.config/akconnect"; do
        [ -n "$d" ] && [ -f "$d/runtime.json" ] && cat "$d/runtime.json" && break
    done || echo "not found"

    try "interfaces" ip -brief address show
    try "routes" ip route
    try "default routes only" ip route show default
    try "path to a public address" ip route get 1.1.1.1
    [ -n "$PEER" ] && try "path to the peer" ip route get "$PEER"

    if [ -n "$PEER" ]; then
        try "ping the peer over the overlay" ping -c 10 -W 2 "$PEER"
        try "traceroute to the peer" traceroute -n -m 8 -w 2 "$PEER"
    fi
    try "traceroute to a public address (must not enter the overlay)" traceroute -n -m 8 -w 2 1.1.1.1

    try "key file permissions" sh -c 'ls -la /etc/akconnect/ 2>/dev/null || ls -la "$HOME/.config/akconnect/" 2>/dev/null'
    try "listening UDP sockets" sh -c 'ss -ulnp 2>/dev/null | grep -E "51820|Local" || true'

    section "end of report"
} | tee "$OUT" > /dev/null

echo
echo "  Report written to $OUT"
echo "  Send that file back. It contains no private keys and no device token."
echo
