#!/usr/bin/env bash
#
# Make systemd-resolved available to the gate.
#
# The `resolved` scenario proves the split-DNS path most of this product's
# Linux machines will actually use — Ubuntu 22.04 and 24.04 server both ship
# systemd-resolved running. A lab without it cannot test that path, and the
# scenario FAILS rather than skipping, because a green tick for a check that
# did not run is the worst thing a drill can produce.
#
# This installs it and starts it, including in a container where pid 1 is not
# systemd and `systemctl start` therefore does nothing.
#
#   sudo ./services/lab/setup-resolved.sh
set -uo pipefail

say() { printf '  %s\n' "$*"; }

if [ "$(id -u)" -ne 0 ]; then
    echo "setup-resolved: run this as root." >&2
    exit 1
fi

if ! command -v resolvectl >/dev/null 2>&1; then
    say "installing systemd-resolved"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq >/dev/null 2>&1
    apt-get install -y -qq --no-install-recommends systemd-resolved >/dev/null 2>&1 \
        || { echo "setup-resolved: could not install systemd-resolved." >&2; exit 1; }
fi

if resolvectl status >/dev/null 2>&1; then
    say "systemd-resolved is already running"
    exit 0
fi

# Under systemd, this is all it takes.
if [ "$(cat /proc/1/comm 2>/dev/null)" = "systemd" ]; then
    systemctl enable --now systemd-resolved >/dev/null 2>&1
    resolvectl status >/dev/null 2>&1 && { say "systemd-resolved started"; exit 0; }
fi

# Without systemd as pid 1 — a container, which is where this gate usually
# runs — resolved still works, but it needs the system bus and its own runtime
# directory, and it refuses to start if that directory exists owned by someone
# else. Both are things systemd would normally have done.
mkdir -p /run/dbus
if [ ! -S /run/dbus/system_bus_socket ]; then
    say "starting the system bus"
    dbus-daemon --system --fork >/dev/null 2>&1
    sleep 1
fi

if [ -d /run/systemd/resolve ]; then
    chown -R systemd-resolve:systemd-resolve /run/systemd/resolve 2>/dev/null \
        || rm -rf /run/systemd/resolve
fi

say "starting systemd-resolved directly"
setsid /usr/lib/systemd/systemd-resolved >/dev/null 2>&1 &
sleep 3

if resolvectl status >/dev/null 2>&1; then
    say "systemd-resolved is running; ./run-all.sh resolved can now prove that path"
    exit 0
fi

echo "setup-resolved: systemd-resolved would not start. The resolved scenario will fail," >&2
echo "which is correct — that path is unproven on this machine." >&2
exit 1
