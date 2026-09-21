#!/usr/bin/env bash
# The systemd-resolved path (§18).
#
# Sourced by scenarios.sh.
#
# Every other DNS check runs inside a network namespace, where systemd-resolved
# cannot see the interfaces and so cannot be used. What the gate exercises
# there is the hosts-file fallback — which is correct on a machine without
# resolved, and is *not* what most of this product's Linux boxes will be.
# Ubuntu 22.04 and 24.04 server both ship it and both have it running.
#
# So this scenario steps outside the namespaces deliberately. It creates a
# throwaway interface on the host, drives netcfg's real Linux DNS code against
# it, and reverts. Nothing else in the lab does that, and the reason it is
# worth the exception is that "the resolvectl path is written and has never
# run" is not a sentence to ship a release on.

# The interface exists only for this scenario and is removed on the way out.
# Kept short because Linux caps an interface name at 15 characters, and the
# peer name has to fit too.
RESOLVED_IFACE="akc-dnstest"

lab::resolved_available() {
    command -v resolvectl >/dev/null 2>&1 || return 1
    resolvectl status >/dev/null 2>&1
}

lab::resolved_cleanup() {
    resolvectl revert "$RESOLVED_IFACE" >/dev/null 2>&1 || true
    ip link del "$RESOLVED_IFACE" 2>/dev/null || true
    ip link del "${RESOLVED_IFACE}-p" 2>/dev/null || true
}

scenario_resolved() {
    step "names — the systemd-resolved path, on a host that actually has it"

    if ! lab::resolved_available; then
        # Not a pass. A machine without systemd-resolved cannot test the
        # systemd-resolved path, and recording a green tick for a check that
        # did not run is the worst thing a drill can do.
        record "resolved/available" FAIL \
            "systemd-resolved is not running here, so this path is still unproven — install it and re-run"
        return
    fi
    record "resolved/available" PASS "systemd-resolved is running; the resolvectl path can be exercised"

    lab::resolved_cleanup

    # An address as well as a link. systemd-resolved gives a link no DNS scope
    # until it has one, which is why this failed the first time it ran — the
    # server and the domain were both set and the link still answered nothing.
    # The real tunnel interface always has an address by the time DNS is
    # configured, so this matches the product rather than working around it.
    if ! ip link add name "$RESOLVED_IFACE" type veth peer name "${RESOLVED_IFACE}-p" \
        || ! ip link set dev "$RESOLVED_IFACE" up \
        || ! ip link set dev "${RESOLVED_IFACE}-p" up \
        || ! ip address add 10.99.250.1/24 dev "$RESOLVED_IFACE"; then
        record "resolved/setup" FAIL "could not create a throwaway interface to configure"
        lab::resolved_cleanup
        return
    fi

    # The fixture applies the configuration, asks systemd-resolved what it
    # made of it while it is still live, and reverts. What it prints between
    # the markers is resolved's own output.
    local out
    out="$("$BIN/akconnect-dnstest" -iface "$RESOLVED_IFACE" -zone lab-resolved.internal \
        -record "nvr.lab-resolved.internal=10.128.0.50" 2>&1)"

    if ! grep -q "^APPLIED=systemd-resolved" <<<"$out"; then
        record "resolved/applied" FAIL "netcfg did not use systemd-resolved: $(tr '\n' ' ' <<<"$out")"
        lab::resolved_cleanup
        return
    fi
    record "resolved/applied" PASS "netcfg configured the zone through systemd-resolved, not the hosts file"

    local resolver status
    resolver="$(sed -n 's/^RESOLVER=//p' <<<"$out")"
    status="$(sed -n '/---STATUS-BEGIN---/,/---STATUS-END---/p' <<<"$out")"

    if grep -q "DNS Servers: *$resolver" <<<"$status"; then
        record "resolved/server" PASS "resolved has $resolver as the server for that link"
    else
        record "resolved/server" FAIL "resolved does not list our resolver: $(tr '\n' ' ' <<<"$status")"
    fi

    # The tilde is the whole point. "~zone" is a routing-only domain: that
    # domain goes to this link's server and nothing else does. Without it the
    # same line would be a search domain and resolved would send us
    # everything.
    if grep -q "DNS Domain: *~lab-resolved.internal" <<<"$status"; then
        record "resolved/domain" PASS "lab-resolved.internal is routed to it, as ~domain rather than a search domain"
    else
        record "resolved/domain" FAIL "the routing domain was not set: $(tr '\n' ' ' <<<"$status")"
    fi

    if grep -q -- "-DefaultRoute" <<<"$status"; then
        record "resolved/not-default" PASS "the link is not a default DNS route; only that one domain reaches us"
    else
        record "resolved/not-default" FAIL "resolved would send every lookup to us, which is a takeover"
    fi

    # resolved has to actually have a DNS scope on the link, or it holds the
    # configuration and answers nothing — which is what happened before the
    # interface was given an address.
    if grep -q "Current Scopes:.*DNS" <<<"$status"; then
        record "resolved/scope" PASS "the link has a DNS scope, so the configuration is in use rather than merely stored"
    else
        record "resolved/scope" FAIL "the link has no DNS scope: $(tr '\n' ' ' <<<"$status")"
    fi

    if grep -q "^QUERY=nvr.lab-resolved.internal nvr.lab-resolved.internal: 10.128.0.50" <<<"$out"; then
        record "resolved/query" PASS "resolvectl query returns our answer: nvr.lab-resolved.internal is 10.128.0.50"
    else
        record "resolved/query" FAIL "resolved answered: $(sed -n 's/^QUERY=//p' <<<"$out")"
    fi

    if grep -q "^REFUSED=0" <<<"$out"; then
        record "resolved/isolation" PASS "our resolver was asked about nothing outside its zone"
    else
        record "resolved/isolation" FAIL "our resolver was sent $(sed -n 's/^REFUSED=//p' <<<"$out") out-of-zone queries"
    fi

    # The hosts file must be untouched on this path. Defender flags writes to
    # it, which is the whole reason Windows has no fallback at all.
    if grep -q "^HOSTS_TOUCHED=no" <<<"$out"; then
        record "resolved/hosts-untouched" PASS "the hosts file was not written to; resolved carried it alone"
    else
        record "resolved/hosts-untouched" FAIL "the hosts file was modified even though resolved was available"
    fi

    if grep -q "^REVERTED=yes" <<<"$out"; then
        record "resolved/revert" PASS "removing the configuration put the link back to its defaults"
    else
        record "resolved/revert" FAIL "the link was left configured after removal"
    fi

    lab::resolved_cleanup
}
