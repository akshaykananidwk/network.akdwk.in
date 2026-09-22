#!/usr/bin/env bash
# Shared plumbing for the networking drills.
#
# Sourced by run-all.sh. Everything here is about getting a real panel, a real
# coordinator, a real relay and two real agents running in network namespaces,
# so that the assertions in run-all.sh are made against packets rather than
# against mocks.

LAB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$LAB_DIR/../.." && pwd)"
RUN="$LAB_DIR/.run"
BIN="$RUN/bin"
LOGS="$RUN/logs"

HOST_IP=10.0.0.1
PANEL_PORT=8099
COORD_PORT=8443
RELAY_PORT=9000
RELAY_B_PORT=9001
# A name rather than an address, deliberately. See lab::relay_hostname.
RELAY_HOST=relay.lab.internal
PANEL_URL="http://$HOST_IP:$PANEL_PORT"

# This box reaches the internet through a proxy. The lab talks to addresses
# that only exist inside it, so every lab process — curl here, and the Go
# agent, which honours the same variables — has to be told not to proxy them.
export NO_PROXY="10.0.0.0/8,192.168.0.0/16,127.0.0.1,localhost"
export no_proxy="$NO_PROXY"

say()  { printf '  %s\n' "$*"; }
step() { printf '\n\033[1m── %s\033[0m\n' "$*"; }
die()  { printf '  \033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------- preflight

# LOCK records which process owns the shared state: the topology, the panel's
# config.local.php and the lab tenant. Only the owner may tear any of it down.
#
# Without it an aborted run's cleanup arrives late and destroys a later run's
# environment from underneath it. That is not hypothetical — it deleted the
# coordinator's public key and the namespaces in the middle of a scenario, and
# the resulting failures pointed at the product rather than at the harness.
LOCK=""

lab::preflight() {
    [ "$(id -u)" -eq 0 ] || die "run as root: the drills create network namespaces"

    local missing=()
    for tool in ip iptables go php mysql jq ping; do
        command -v "$tool" >/dev/null 2>&1 || missing+=("$tool")
    done
    [ ${#missing[@]} -eq 0 ] || die "missing tools: ${missing[*]}"

    [ -c /dev/net/tun ] || die "/dev/net/tun is missing; the agent cannot create an interface"
    mysqladmin ping >/dev/null 2>&1 || die "the database is not running"
    [ -f "$REPO/config/config.php" ] || die "the panel is not installed (no config/config.php)"

    lab::claim
    lab::reap
    lab::reap_agents
}

# claim takes ownership of the shared state, stopping any older harness first.
lab::claim() {
    mkdir -p "$RUN"
    LOCK="$RUN/harness.pid"

    if [ -f "$LOCK" ]; then
        local previous waited=0
        previous="$(cat "$LOCK" 2>/dev/null)"
        if [ -n "$previous" ] && [ "$previous" != "$$" ] && kill -0 "$previous" 2>/dev/null; then
            say "stopping an older harness (pid $previous) before taking over"
            kill -TERM "$previous" 2>/dev/null || true
            while kill -0 "$previous" 2>/dev/null && [ "$waited" -lt 30 ]; do
                sleep 1
                waited=$((waited + 1))
            done
        fi
    fi

    printf '%s\n' "$$" > "$LOCK"
}

# owns reports whether this process still holds the lock. A cleanup that has
# lost it must touch nothing: the state now belongs to somebody else.
lab::owns() {
    [ -n "$LOCK" ] && [ -f "$LOCK" ] && [ "$(cat "$LOCK" 2>/dev/null)" = "$$" ]
}

lab::release() {
    lab::owns && rm -f "$LOCK"
    return 0
}

# Kill anything still holding the lab's ports.
#
# A run cut short by a timeout or a Ctrl-C leaves a coordinator or relay
# behind, and the next run then dies on "address already in use" — which reads
# like a product fault and is not one. Processes are found by which port they
# hold, never by matching a command line: pkill -f has matched this harness's
# own shell and killed the run doing it.
lab::reap() {
    local port pids
    for port in "$PANEL_PORT" "$COORD_PORT" "$RELAY_PORT" "$RELAY_B_PORT"; do
        pids="$(ss -lunpH "sport = :$port" 2>/dev/null; ss -ltnpH "sport = :$port" 2>/dev/null)"
        pids="$(printf '%s\n' "$pids" | grep -oE 'pid=[0-9]+' | cut -d= -f2 | sort -u)"
        [ -n "$pids" ] || continue
        say "reaping stale process on port $port: $(printf '%s' "$pids" | tr '\n' ' ')"
        printf '%s\n' "$pids" | xargs -r kill 2>/dev/null || true
    done
    [ -n "$pids" ] && sleep 1
    return 0
}

lab::build() {
    mkdir -p "$BIN" "$LOGS"
    local svc
    for svc in coordinator relay agent; do
        ( cd "$REPO/services/$svc" && go build -o "$BIN/akconnect-$svc" "./cmd/akconnect-$svc" ) \
            || die "build failed: $svc"
    done
    # A second agent with its own rule enforcement compiled out, for the drills
    # that have to prove the *far* end holds. Build-tag gated, so the binary
    # the product ships cannot contain it.
    ( cd "$REPO/services/agent" && go build -tags labtamper -o "$BIN/akconnect-agent-tampered" ./cmd/akconnect-agent ) \
        || die "build failed: tampered agent"

    # And the fixture that drives netcfg's DNS code against a real
    # systemd-resolved, which cannot be done from inside a namespace.
    ( cd "$REPO/services/agent" && go build -o "$BIN/akconnect-dnstest" ./cmd/akconnect-dnstest ) \
        || die "build failed: dnstest"

    say "built coordinator, relay and agent (plus the tampered agent the ACL drills need)"
}

# ------------------------------------------------------------------ secrets
#
# Fresh per run and never written anywhere but config.local.php, which is
# gitignored. The coordinator's private key is passed through the environment
# rather than a flag, because a flag is visible in ps to every user on the box.

lab::secrets() {
    local keypair
    keypair="$("$BIN/akconnect-coordinator" keygen)" || die "keygen failed"

    # keygen prints each key on the line after its label.
    COORD_PRIVATE="$(printf '%s\n' "$keypair" | awk '/Private key/ {getline; print $1}')"
    COORD_PUBLIC="$(printf '%s\n' "$keypair" | awk '/Public key/  {getline; print $1}')"
    [ -n "$COORD_PRIVATE" ] && [ -n "$COORD_PUBLIC" ] || die "could not parse the coordinator keypair"

    COORD_SECRET="$(openssl rand -hex 32)"
    RELAY_SECRET="$(openssl rand -hex 32)"
    RELAY_B_SECRET="$(openssl rand -hex 32)"

    # config.local.php is merged over config.php and is a protected path, so
    # this steers the panel for the drill without touching the real config.
    cat > "$REPO/config/config.local.php" <<PHP
<?php

declare(strict_types=1);

// Written by services/lab/run-all.sh. Removed when the drill finishes.

return [
    'app' => ['url' => '$PANEL_URL'],
    'coordinator' => [
        'host'          => '$HOST_IP',
        'public_host'   => '$HOST_IP',
        'port'          => $COORD_PORT,
        'public_key'    => '$COORD_PUBLIC',
        'shared_secret' => '$COORD_SECRET',
    ],
];
PHP
    # Registered by NAME, not by address.
    #
    # This one word is the whole of defect 16. DEPLOY tells an operator to
    # configure the relay by the VPS's hostname, the production deployment did,
    # and the agent's offer handler parsed the endpoint with a function that
    # only accepts literal IP addresses — so every relay offer was silently
    # discarded and no agent ever contacted the relay. Every relay drill in
    # this lab passed, because this line used to pass "$HOST_IP".
    php "$LAB_DIR/lab-setup.php" relays "$RELAY_HOST" "$RELAY_PORT" "$RELAY_B_PORT" >/dev/null \
        || die "could not register the lab relays with the panel"

    say "coordinator key generated; panel pointed at $HOST_IP:$COORD_PORT"
    say "relays registered as $RELAY_HOST (a name, not an address — see defect 16)"
}

# lab::relay_hostname makes $RELAY_HOST resolve inside the agent namespaces.
#
# /etc/netns/<ns>/hosts, which ip-netns bind-mounts over /etc/hosts for that
# namespace only — so nothing global is touched and teardown removes it with
# the rest. The coordinator and the relays run on the host and never resolve
# this name; they only pass the string through, which is exactly the path that
# was broken.
lab::relay_hostname() {
    local ns
    for ns in alpha beta; do
        mkdir -p "/etc/netns/$ns"
        {
            printf '127.0.0.1 localhost\n'
            printf '%s %s\n' "$HOST_IP" "$RELAY_HOST"
        } > "/etc/netns/$ns/hosts"
    done
}

lab::secrets_clear() {
    # Only the owner. A late cleanup from an aborted run must not take the
    # coordinator's public key away from whoever is running now.
    lab::owns && rm -f "$REPO/config/config.local.php"
    return 0
}

# ------------------------------------------------------------ control plane

lab::panel_start() {
    # Through tests/dev-server.php, the router the README already documents.
    # The built-in server reads neither .htaccess nor nginx rules, so without
    # it the panel serves config/config.php — credentials and all — to anyone
    # who asks for it by name, and the public-surface tests fail against the
    # lab for reasons that are entirely real.
    ( cd "$REPO" && php -S "0.0.0.0:$PANEL_PORT" -t . tests/dev-server.php ) >"$LOGS/panel.log" 2>&1 &
    PANEL_PID=$!
    lab::wait_http "http://127.0.0.1:$PANEL_PORT/login" 15 || die "the panel did not come up (see $LOGS/panel.log)"
    say "panel on $PANEL_URL (pid $PANEL_PID)"
}

# Bound to the wildcard address on purpose: each scenario deletes and
# rebuilds the bridge, and a socket bound to 10.0.0.1 would go with it.
lab::coord_start() {
    AKCONNECT_COORDINATOR_KEY="$COORD_PRIVATE" \
    AKCONNECT_COORDINATOR_SECRET="$COORD_SECRET" \
    AKCONNECT_RELAY_SECRET_LAB_A="$RELAY_SECRET" \
    AKCONNECT_RELAY_SECRET_LAB_B="$RELAY_B_SECRET" \
        "$BIN/akconnect-coordinator" serve \
            --listen ":$COORD_PORT" \
            --panel "$PANEL_URL" \
            --relays "lab-a::$RELAY_HOST:$RELAY_PORT,lab-b::$RELAY_HOST:$RELAY_B_PORT" \
        >"$LOGS/coordinator.log" 2>&1 &
    COORD_PID=$!
    sleep 1
    kill -0 "$COORD_PID" 2>/dev/null || die "the coordinator exited (see $LOGS/coordinator.log)"
    say "coordinator on :$COORD_PORT, reachable as $HOST_IP (pid $COORD_PID)"
}

# Two relays, so failover has somewhere to fail over to. A fleet of one only
# ever proves that a relay coming back is used again, which is a different and
# much weaker claim.
lab::relay_start() {
    AKCONNECT_RELAY_SECRET="$RELAY_SECRET" \
        "$BIN/akconnect-relay" serve \
            --control ":$RELAY_PORT" \
            --coordinator "$HOST_IP:$COORD_PORT" \
            --name lab-a \
        >"$LOGS/relay-a.log" 2>&1 &
    RELAY_PID=$!
    sleep 1
    kill -0 "$RELAY_PID" 2>/dev/null || die "relay lab-a exited (see $LOGS/relay-a.log)"
    say "relay lab-a on :$RELAY_PORT, reachable as $HOST_IP (pid $RELAY_PID)"
}

lab::relay_b_start() {
    AKCONNECT_RELAY_SECRET="$RELAY_B_SECRET" \
        "$BIN/akconnect-relay" serve \
            --control ":$RELAY_B_PORT" \
            --coordinator "$HOST_IP:$COORD_PORT" \
            --name lab-b \
        >"$LOGS/relay-b.log" 2>&1 &
    RELAY_B_PID=$!
    sleep 1
    kill -0 "$RELAY_B_PID" 2>/dev/null || die "relay lab-b exited (see $LOGS/relay-b.log)"
    say "relay lab-b on :$RELAY_B_PORT, reachable as $HOST_IP (pid $RELAY_B_PID)"
}

# Stopping by recorded pid, never by pattern: pkill -f matches this script's
# own command line and has killed the harness mid-run before.
lab::stop_pid() {
    local pid=${1:-}
    [ -n "$pid" ] || return 0
    kill "$pid" 2>/dev/null || true
    wait "$pid" 2>/dev/null || true
}

lab::wait_http() {
    local url=$1 timeout=${2:-15} waited=0
    while [ "$waited" -lt "$timeout" ]; do
        curl -fsS --noproxy '*' -o /dev/null --max-time 2 "$url" 2>/dev/null && return 0
        sleep 1
        waited=$((waited + 1))
    done
    return 1
}

# The agent, observation and measurement helpers live next door, so that
# neither half outgrows a file somebody has to read in one sitting.
source "$LAB_DIR/lib-agents.sh"
