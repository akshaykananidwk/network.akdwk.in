#!/usr/bin/env bash
# Driving the agents, and watching what they do.
#
# Sourced by lib.sh, which owns the paths and the control plane. The split is
# by subject: everything here concerns a device — enrolling it, running it and
# reading what it reports — rather than the infrastructure it talks to.

# ------------------------------------------------------------------- agents

lab::state_dir() { printf '%s/state/%s' "$RUN" "$1"; }

lab::agent() {
    local ns=$1; shift
    ip netns exec "$ns" env \
        AKCONNECT_STATE_DIR="$(lab::state_dir "$ns")" \
        "$BIN/akconnect-agent" "$@"
}

# Enrolment is deliberately run the way a customer runs it: "enroll --wait"
# blocks in the background until an administrator approves, and only then does
# the device receive a token and an address. Approving before the agent has
# even registered would skip R4 rather than test it.
lab::enrol_start() {
    local ns=$1 code=$2
    mkdir -p "$(lab::state_dir "$ns")"
    lab::agent "$ns" enroll --panel "$PANEL_URL" --join-code "$code" --name "$ns" --wait \
        >"$LOGS/$ns-enroll.log" 2>&1 &
    printf -v "ENROL_PID_$ns" '%s' "$!"
}

# enrol_uid blocks until the device has registered and has a uid to approve.
lab::enrol_uid() {
    local ns=$1 file deadline
    file="$(lab::state_dir "$ns")/state.json"
    deadline=$(( $(date +%s) + 25 ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        if [ -f "$file" ] && jq -e -r '.device_uid // empty' "$file" >/dev/null 2>&1; then
            jq -r '.device_uid' "$file"
            return 0
        fi
        sleep 1
    done
    return 1
}

# enrol_finish blocks until the waiting agent has claimed its token.
#
# It watches the state file rather than waiting on the enrolling process. The
# pid is the shell's idea of a background job, and "wait" on it returns
# immediately in cases the harness does not control — the job already reaped,
# job control off, the pid belonging to a subshell that has gone. When that
# happened the check ran against a state file the agent had not written yet,
# and the drill reported a product failure that was its own.
#
# The token landing in the state file is the thing being waited for, so it is
# the thing to watch.
lab::enrol_finish() {
    local ns=$1 file deadline
    file="$(lab::state_dir "$ns")/state.json"

    deadline=$(( $(date +%s) + 60 ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        if [ -f "$file" ] && jq -e -r '.virtual_ip // empty' "$file" >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done

    return 1
}

lab::uid() { jq -r '.device_uid' "$(lab::state_dir "$1")/state.json"; }

# Agents run in their own process group.
#
# "ip netns exec" forks rather than execing, so the pid the shell records is a
# wrapper and killing it leaves the agent behind, reparented to init. Every
# scenario then left two more agents running against a topology that no longer
# existed — they kept polling the panel, kept holding state, and eventually
# made a later scenario fail for reasons that had nothing to do with it.
# setsid puts the whole chain in one group so the group can be killed.
lab::up() {
    local ns=$1
    setsid ip netns exec "$ns" env \
        AKCONNECT_STATE_DIR="$(lab::state_dir "$ns")" \
        "$BIN/akconnect-agent" up --verbose \
        >"$LOGS/$ns-up.log" 2>&1 &
    AGENT_PIDS+=("$!")
}

lab::down_agents() {
    local pid
    for pid in "${AGENT_PIDS[@]:-}"; do
        [ -n "$pid" ] || continue
        # The group first, the process second: the group is what actually has
        # the agent in it.
        kill -TERM -- "-$pid" 2>/dev/null || kill -TERM "$pid" 2>/dev/null || true
        wait "$pid" 2>/dev/null || true
    done
    AGENT_PIDS=()

    lab::reap_agents
}

# reap_agents kills any agent left over from an earlier run.
#
# Matched by the binary each process is actually executing, read from /proc,
# rather than by a pattern over command lines: pkill -f has matched this
# harness's own shell and killed the run doing it.
lab::reap_agents() {
    local entry pid exe killed=0
    for entry in /proc/[0-9]*; do
        pid="${entry#/proc/}"
        exe="$(readlink "$entry/exe" 2>/dev/null)" || continue
        [ "$exe" = "$BIN/akconnect-agent" ] || continue
        kill -TERM "$pid" 2>/dev/null && killed=$((killed + 1))
    done

    [ "$killed" -gt 0 ] && say "reaped $killed stray agent(s) from an earlier run"

    return 0
}

# ---------------------------------------------------------------- observing

lab::runtime() {
    local file
    file="$(lab::state_dir "$1")/runtime.json"
    [ -f "$file" ] || { echo '{}'; return; }
    cat "$file"
}

# path reports how this agent currently reaches its first peer:
# direct, relay, or "-" when nothing is known yet.
lab::path() {
    lab::runtime "$1" | jq -r '(.peers // []) | if length == 0 then "-" else .[0].path end'
}

# settled_path waits for the agent to report a definite path and prints it.
#
# A ping can succeed a second or two before the agent next writes its runtime
# file, so reading the path the instant traffic flows gets "-" — which is not
# "direct" or "relay", it is "the harness asked too early". Recording that as
# the result of a scenario whose entire question is *which path* would be
# reporting nothing and calling it a pass.
lab::settled_path() {
    local ns=$1 timeout=${2:-30} now deadline
    deadline=$(( $(date +%s) + timeout ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        now="$(lab::path "$ns")"
        case "$now" in
            direct|relay) printf '%s\n' "$now"; return 0 ;;
        esac
        sleep 1
    done

    printf 'unknown\n'

    return 1
}

lab::handshake_age() {
    lab::runtime "$1" | jq -r '(.peers // []) | if length == 0 then "-" else (.[0].last_handshake_ago // "-") end'
}

lab::ping() {
    local ns=$1 target=$2 count=${3:-3}
    ip netns exec "$ns" ping -c "$count" -W 2 -i 0.3 "$target" >/dev/null 2>&1
}

# wait_tunnel blocks until ns can ping target, or the timeout expires.
lab::wait_tunnel() {
    local ns=$1 target=$2 timeout=${3:-45} deadline
    deadline=$(( $(date +%s) + timeout ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        lab::ping "$ns" "$target" 1 && return 0
        sleep 1
    done
    return 1
}

# wait_path blocks until ns reports the wanted path for its peer.
lab::wait_path() {
    local ns=$1 want=$2 timeout=${3:-45} deadline
    deadline=$(( $(date +%s) + timeout ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        [ "$(lab::path "$ns")" = "$want" ] && return 0
        sleep 1
    done
    return 1
}

# loss_during runs a ping flood while an action happens, and reports the loss
# percentage in LOSS_PCT. This is how a failover is measured rather than
# asserted: "it came back" is worth much less than "it came back and cost 12%
# of a 40-second window".
#
# The result comes back in a variable rather than on stdout because the action
# is usually "kill a service and start it again", and a service that announces
# itself on startup would otherwise have its announcement captured as part of
# the number. Running the action here, in the caller's shell, also means the
# new pid it records is the one cleanup will actually stop.
lab::loss_during() {
    local ns=$1 target=$2 seconds=$3 action=$4
    local out="$LOGS/$ns-loss.log"

    ip netns exec "$ns" ping -i 0.2 -W 1 -c "$((seconds * 5))" "$target" >"$out" 2>&1 &
    local pinger=$!
    sleep 2
    eval "$action"
    wait "$pinger" 2>/dev/null || true

    # "0% packet loss" — the percentage is the only thing before the % sign,
    # and the word after it carries a comma, which is what broke an earlier
    # field-splitting version of this.
    LOSS_PCT="$(grep -oE '[0-9]+(\.[0-9]+)?% packet loss' "$out" | grep -oE '^[0-9.]+' | head -1)"
    LOSS_PCT="${LOSS_PCT:-100}"
}

# relay_name reports which relay an agent's peer traffic is going through.
#
# The name, not the port: the relay allocates a fresh data port per session, so
# two ports tell you something changed without telling you what it changed to.
lab::relay_name() {
    lab::runtime "$1" | jq -r '
        (.peers // []) | if length == 0 then "-"
        else (.[0].relay // "-") end'
}

# wait_relay blocks until an agent reports a relay other than the named one.
lab::wait_relay_change() {
    local ns=$1 was=$2 timeout=${3:-60} now deadline
    deadline=$(( $(date +%s) + timeout ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        now="$(lab::relay_name "$ns")"
        if [ -n "$now" ] && [ "$now" != "-" ] && [ "$now" != "$was" ]; then
            return 0
        fi
        sleep 1
    done
    return 1
}

# relay_reported_bytes is the relay's own count for one tenant, taken from its
# log.
#
# This is the independent number. The panel's figure is built from what the
# agents say they sent and received; the relay's is built from what it actually
# forwarded. A customer can influence the first and cannot influence the
# second, so the two agreeing is what makes the invoice defensible.
lab::relay_reported_bytes() {
    local tenant=$1 total=0 file
    for file in "$LOGS/relay-a.log" "$LOGS/relay-b.log"; do
        [ -f "$file" ] || continue
        # Cumulative per session, re-logged every 30 seconds, so the largest
        # value each relay printed is that relay's total.
        local most
        most="$(grep -oE "usage tenant=$tenant sessions=[0-9]+ bytes=[0-9]+" "$file" \
            | grep -oE 'bytes=[0-9]+' | cut -d= -f2 | sort -n | tail -1)"
        total=$((total + ${most:-0}))
    done

    printf '%s\n' "$total"
}

# panel_relay_bytes is the metered figure — the one that becomes an invoice.
lab::panel_relay_bytes() {
    php "$LAB_DIR/lab-setup.php" usage "$1" 2>/dev/null \
        | awk -F= '/^RELAY_BYTES=/ {print $2}'
}

# push_bytes sends a known, countable load through the tunnel.
#
# ping rather than a file transfer: the payload size and packet count are both
# exact and it needs nothing installed on either side, so the expected figure
# is arithmetic rather than an estimate.
lab::push_bytes() {
    local ns=$1 target=$2 size=$3 count=$4
    ip netns exec "$ns" ping -s "$size" -c "$count" -i 0.02 -W 2 "$target" \
        >"$LOGS/$ns-push.log" 2>&1

    grep -oE '[0-9]+ received' "$LOGS/$ns-push.log" | grep -oE '^[0-9]+' | head -1
}

# tcp_connect reports whether a TCP connection to a peer's port completes.
#
# A failed ping is not proof that a deny works: ICMP and TCP are different
# protocols and a rule can block one without the other. §36 asks for both, so
# the drill does both.
lab::tcp_connect() {
    local ns=$1 target=$2 port=$3 timeout=${4:-3}
    ip netns exec "$ns" timeout "$timeout" bash -c "cat < /dev/null > /dev/tcp/$target/$port" 2>/dev/null
}

# serve_tcp starts a listener a connect attempt can actually reach.
#
# Without one, a refused connection proves nothing: it would be refused whether
# the ACL blocked it or not. That is not hypothetical — an earlier version of
# this used a one-shot listener, the baseline check consumed it, and the drill
# then reported the deny taking effect in *zero seconds*, which is impossible
# because the agent only learns about a rule when it next polls. It passed for
# the wrong reason. "-k" keeps the socket open across connections so the
# service is still there when the drill asks again.
lab::serve_tcp() {
    local ns=$1 port=$2
    setsid ip netns exec "$ns" nc -k -l "$port" >/dev/null 2>&1 &
    SERVER_PIDS+=("$!")

    # Confirm it is actually listening before anything is concluded from a
    # connection to it.
    local waited=0
    while [ "$waited" -lt 10 ]; do
        if lab::tcp_connect "$ns" 127.0.0.1 "$port" 2; then
            return 0
        fi
        sleep 1
        waited=$((waited + 1))
    done

    return 1
}

# serving reports whether the listener is still up, from inside its own
# namespace, where no overlay filter can be in the way.
#
# This is what separates "the ACL blocked it" from "the service died". A drill
# that cannot tell those apart is not testing the ACL.
lab::serving() {
    local ns=$1 port=$2
    lab::tcp_connect "$ns" 127.0.0.1 "$port" 2
}

lab::stop_servers() {
    local pid
    for pid in "${SERVER_PIDS[@]:-}"; do
        [ -n "$pid" ] || continue
        kill -TERM -- "-$pid" 2>/dev/null || kill -TERM "$pid" 2>/dev/null || true
    done
    SERVER_PIDS=()
}
