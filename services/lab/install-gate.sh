#!/usr/bin/env bash
#
# Is deploy/getting-started.sh really safe to run again?
#
# Its header said so from the first version. Nothing had ever run it twice.
# The first two field runs on a clean server each stopped on something a
# second run would have found:
#
#   1.9.7-dev.17  the answers went between root and the web user in a file only
#                 root could read, and the installer called that "not valid JSON"
#   1.9.7-dev.20  the first run died after chowning the panel tree to the web
#                 user; the re-run did git as root on it and git refused —
#                 "detected dubious ownership in repository"
#
# So this runs it, for real, several times over:
#
#   field    the first run dies at "installing the panel", after the
#            permissions step — exactly where the field run stopped — and
#            the second run must finish. This is the one that was red.
#   twice    a clean install, then the same command again. It must finish,
#            and it must not have changed anything it promised to leave
#            alone: the coordinator's keypair, the panel's configuration,
#            the mode of a single file.
#   late     the first run dies after the panel is installed and the
#            coordinator's files are written — the relay will not start — and
#            the second must finish: enable both services, print the setup
#            link nobody has seen, write the settings the first never reached.
#   interrupt  a run is sent SIGTERM part-way. It must stop — not carry on
#            to "AK Connect is installed" — and the next run must finish.
#   cycle    --uninstall, then install again.
#   repair   the previous release's installer runs to the end — leaving, before
#            1.9.7-dev.21, a panel with no coordinator key on branch "main" —
#            and this release's must fill in what it never wrote.
#   legacy   the previous release's installer dies half-way, as it did on the
#            field server; the branch moves on; this release's installer must
#            pick up the tree the old one left — root-cloned, chowned, and with
#            the execute bits its blanket chmod took off.
#
# And it asserts what an install is for, not only that the script exited 0:
# the panel holds the coordinator's key and secret, knows the branch and
# commit it was installed from, and upgrade-edge.sh — run by the installer
# against that panel — fetches, checks out and builds the release. Two
# releases passed an exit-code check with a panel that had none of those.
#
# "Twice in a row on a clean tree" alone would NOT have caught the dev.20
# defect. A first run that succeeds writes config/config.php, and a second
# run that finds it never touches the panel's git at all. The field defect
# needs a first run that failed after the chown — hence the first sequence.
#
# Everything real that can be real is: git, PHP, the panel installer, MariaDB,
# sudo, file ownership, Go builds, Caddy's own validator. What cannot run in a
# container is stubbed and logged — systemctl, ufw, apt — along with the two
# network facts a server has and a namespace does not: that the domain
# resolves to this machine, and that GitHub answers.
#
# Isolation: each sequence runs in its own mount, network and PID namespace,
# with overlays on /etc /var /opt /root /run and /usr/local and a private
# MariaDB on loopback; the PID namespace means that server, and everything
# else a sequence started, dies with it. Nothing the installer writes survives
# the namespace — which matters, because this machine's own lab database is
# also called akconnect, and --uninstall drops a database by that name.
#
#   sudo services/lab/install-gate.sh                  the working tree
#   sudo services/lab/install-gate.sh --ref 5592ec6    a commit (a red check)
#   sudo services/lab/install-gate.sh --legacy-ref 5592ec6
#                                                      the "previous release" to start from
#   sudo services/lab/install-gate.sh --only field     one sequence
#   sudo services/lab/install-gate.sh --keep           leave the evidence
set -uo pipefail

LAB="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$LAB/../.." && pwd)"

REF=""
LEGACY_REF=""
ONLY=""
KEEP=0

while [ $# -gt 0 ]; do
    case "$1" in
        --ref)  REF="${2:?--ref needs a commit}"; shift 2 ;;
        --legacy-ref) LEGACY_REF="${2:?--legacy-ref needs a commit}"; shift 2 ;;
        --only) ONLY="${2:?--only needs a sequence: field, twice, late, interrupt, cycle, legacy, older, repair or rotate}"; shift 2 ;;
        --keep) KEEP=1; shift ;;
        *) echo "unknown option: $1" >&2; exit 2 ;;
    esac
done

DOMAIN="gate.akconnect.test"
FAKE_IP="203.0.113.10"   # TEST-NET-3: documentation only, never routable
WEB_USER="www-data"
PANEL="/var/www/$DOMAIN"
SRC="/opt/akconnect/src"
ETC="/etc/akconnect"

PASS=0
FAIL=0

ok()    { printf '  \033[32m✓\033[0m %s\n' "$1"; PASS=$((PASS + 1)); }
bad()   { printf '  \033[31m✗\033[0m %s\n' "$1"; [ $# -gt 1 ] && [ -n "$2" ] && printf '%s\n' "$2" | sed 's/^/      /'; FAIL=$((FAIL + 1)); }
group() { printf '\n\033[1m── %s\033[0m\n' "$1"; }
die()   { printf '\n  \033[31m✗ %s\033[0m\n\n' "$*" >&2; exit 2; }

# ---------------------------------------------------------------- preflight

[ "$(id -u)" -eq 0 ] || die "run as root: the namespaces, the overlays and sudo -u $WEB_USER all need it"

for tool in unshare setpriv sudo git php mariadbd mariadb-install-db mysqladmin caddy python3 ip; do
    command -v "$tool" >/dev/null 2>&1 || die "$tool is not installed"
done
id "$WEB_USER" >/dev/null 2>&1 || die "there is no $WEB_USER user to install as"

# A machine-wide safe.directory would make git on another user's tree succeed
# for reasons that have nothing to do with the script — exactly the global
# workaround the field operator had to use. A gate that passed because of it
# would be passing on the defect.
for scope in --system --global; do
    if git config "$scope" --get-all safe.directory >/dev/null 2>&1; then
        die "git has a $scope safe.directory set on this machine ($(git config "$scope" --get-all safe.directory | tr '\n' ' ')).
    It would hide exactly the defect this gate exists to catch. Remove it, or run
    this somewhere clean."
    fi
done

WORK="$(mktemp -d)"
chmod 755 "$WORK"   # the web user runs a stub from here
if [ "$KEEP" -eq 1 ]; then
    trap 'echo; echo "  evidence kept in $WORK"' EXIT
else
    trap 'rm -rf "$WORK"' EXIT
fi

SCRIPT="$WORK/getting-started.sh"

# ------------------------------------------------ the tree under test, as git

# The installer clones what it installs, so what it clones has to be the code
# under test: a snapshot of the working tree — untracked files included,
# ignored ones not — or the commit given with --ref. Served over git:// from
# inside the namespace, because the installer clones as two different users,
# and a local path would put git's ownership rules between the CLIENT and the
# source repository too, which a server cloning from GitHub never has.
group "the tree under test"

git init -q --bare "$WORK/repo.git" || die "could not make a bare repository"

# snap_git: git in the developer's repository, writing nothing into it.
#
# The snapshot's blobs, tree and commit go into this run's own object
# directory, with the repository's objects borrowed read-only. This runs as
# root under sudo, and git lets root write into the invoking user's repository
# (it trusts SUDO_UID) — which left root-owned object directories in their
# .git, and their next `git add` of a file that hashed into one failed with
# "insufficient permission". The same defect this release fixes in
# upgrade-edge.sh, in the tool that tests it.
REPO_OBJECTS="$(git -C "$REPO" rev-parse --path-format=absolute --git-common-dir)/objects"
mkdir -p "$WORK/objects"
snap_git() {
    GIT_OBJECT_DIRECTORY="$WORK/objects" GIT_ALTERNATE_OBJECT_DIRECTORIES="$REPO_OBJECTS" \
        git -C "$REPO" "$@"
}

if [ -n "$REF" ]; then
    COMMIT="$(snap_git rev-parse --verify "$REF^{commit}" 2>/dev/null)" \
        || die "$REF is not a commit in $REPO"
    what="commit $(snap_git rev-parse --short "$COMMIT")"
else
    export GIT_INDEX_FILE="$WORK/index"
    snap_git read-tree HEAD
    snap_git add -A
    tree="$(snap_git write-tree)"
    unset GIT_INDEX_FILE
    COMMIT="$(snap_git commit-tree "$tree" -p HEAD -m 'install gate: the working tree')" \
        || die "could not snapshot the working tree"
    what="the working tree, on top of $(snap_git rev-parse --short HEAD)"
fi

snap_git -c push.negotiate=false push -q "$WORK/repo.git" "$COMMIT:refs/heads/gate" 2>/dev/null \
    || die "could not publish the snapshot"

# The release before the one under test, for the sequences that start from
# what it made: the nearest ancestor whose VERSION differs, or --legacy-ref.
#
# Not simply the parent. Run on a committed, clean tree the snapshot's parent
# IS the tree under test, and legacy, older and repair then tested this
# release against itself and passed for that reason.
if [ -n "$LEGACY_REF" ]; then
    LEGACY="$(snap_git rev-parse --verify "$LEGACY_REF^{commit}" 2>/dev/null)" \
        || die "$LEGACY_REF is not a commit in $REPO"
else
    UNDER_TEST_VERSION="$(snap_git show "$COMMIT:VERSION" 2>/dev/null | tr -d '\r\n')"
    LEGACY=""
    while read -r candidate; do
        if [ "$(snap_git show "$candidate:VERSION" 2>/dev/null | tr -d '\r\n')" != "$UNDER_TEST_VERSION" ]; then
            LEGACY="$candidate"
            break
        fi
    done < <(snap_git rev-list --max-count=200 "$COMMIT^" 2>/dev/null)
fi
if [ -n "$LEGACY" ]; then
    printf '  previous release: %s (%s)\n' "$(snap_git rev-parse --short "$LEGACY")" \
        "$(snap_git show "$LEGACY:VERSION" 2>/dev/null | tr -d '\r\n')"
    snap_git -c push.negotiate=false push -q "$WORK/repo.git" "$LEGACY:refs/heads/legacy" 2>/dev/null
    git -C "$WORK/repo.git" show "legacy:deploy/getting-started.sh" > "$WORK/legacy-getting-started.sh" 2>/dev/null \
        && chmod 755 "$WORK/legacy-getting-started.sh"
fi
git -C "$WORK/repo.git" show "gate:deploy/getting-started.sh" > "$SCRIPT" \
    || die "the snapshot has no deploy/getting-started.sh"
chmod 755 "$SCRIPT"
printf '  testing %s\n' "$what"

# ------------------------------------------------------------------- stubs

# Only what cannot run in a namespace. Each one logs every call, so the
# evidence shows what the installer asked of the machine.
STUBS="$WORK/stubs"
mkdir -p "$STUBS"

# systemctl: a small service manager for the two services this project runs,
# and a log of everything else.
#
# The coordinator and the relay really run — started from their own unit
# files, with their EnvironmentFile, as the akconnect user — so "is it up",
# "which binary is it running" and "is it listening" are facts about real
# processes. upgrade-edge.sh decides from exactly those whether an edge has
# to be rebuilt; a stub that said "active" for everything made every edge look
# like one that needed it, and the double build this release removes could not
# be seen. Everything else (caddy, php-fpm, mariadb, the timers) is logged and
# reported up, as a machine that has them would.
cat > "$STUBS/systemctl" <<'SH'
#!/bin/bash
echo "systemctl $*" >> "$GATE_LOG_DIR/machine.log"
verb=""; units=(); quiet=0; now=0; prop=""; skip=0
for a in "$@"; do
    if [ "$skip" -eq 1 ]; then prop="$a"; skip=0; continue; fi
    case "$a" in
        -q|--quiet) quiet=1 ;;
        --now) now=1 ;;
        -p|--property) skip=1 ;;
        -*) ;;
        *) if [ -z "$verb" ]; then verb="$a"; else units+=("${a%.service}"); fi ;;
    esac
done
SVC="$GATE_LOG_DIR/svc"
mkdir -p "$SVC"

managed() { case "$1" in akconnect-coordinator|akconnect-relay) return 0 ;; esac; return 1; }
pid_of() {
    local p
    p="$(cat "$SVC/$1.pid" 2>/dev/null)"
    [ -n "$p" ] && kill -0 "$p" 2>/dev/null && printf '%s\n' "$p"
}
start_unit() {
    local unit=$1 file="/etc/systemd/system/$1.service" envfile cmd
    [ -f "$file" ] || { echo "Unit $unit.service not found." >&2; return 5; }
    [ -n "$(pid_of "$unit")" ] && return 0
    envfile="$(sed -n 's/^EnvironmentFile=-\{0,1\}//p' "$file" | head -1)"
    cmd="$(awk '/^ExecStart=/ { sub(/^ExecStart=/, ""); line = $0
               while (line ~ /\\$/) { sub(/\\$/, "", line); if ((getline more) <= 0) break; line = line " " more }
               print line; exit }' "$file")"
    (
        set -a
        # shellcheck disable=SC1090
        [ -n "$envfile" ] && . "$envfile"
        set +a
        eval "set -- $cmd"
        exec setsid setpriv --reuid=akconnect --regid=akconnect --clear-groups "$@"
    ) >> "$SVC/$unit.log" 2>&1 < /dev/null &
    echo $! > "$SVC/$unit.pid"
    sleep 0.5
    [ -n "$(pid_of "$unit")" ] || { echo "Job for $unit.service failed (install gate): $(tail -2 "$SVC/$unit.log")" >&2; return 1; }
}
stop_unit() {
    local p
    p="$(pid_of "$1")"
    if [ -n "$p" ]; then
        kill "$p" 2>/dev/null
        for _ in $(seq 1 50); do kill -0 "$p" 2>/dev/null || break; sleep 0.1; done
    fi
    rm -f "$SVC/$1.pid"
}

# The late sequence's injected failure: the relay will not start, the first
# time install-edge.sh asks — after it has written coordinator.env.
if [ "$verb" = "enable" ] && [ "${units[0]:-}" = "akconnect-relay" ] && [ -n "${GATE_FAIL_RELAY_START:-}" ]; then
    echo "Job for akconnect-relay.service failed (install gate)." >&2
    exit 1
fi

code=0
case "$verb" in
    start)   for u in "${units[@]}"; do managed "$u" && { start_unit "$u" || code=1; }; done ;;
    stop)    for u in "${units[@]}"; do managed "$u" && stop_unit "$u"; done ;;
    restart) for u in "${units[@]}"; do managed "$u" && { stop_unit "$u"; start_unit "$u" || code=1; }; done ;;
    enable)  [ "$now" -eq 1 ] && for u in "${units[@]}"; do managed "$u" && { start_unit "$u" || code=1; }; done ;;
    disable) [ "$now" -eq 1 ] && for u in "${units[@]}"; do managed "$u" && stop_unit "$u"; done ;;
    is-active)
        u="${units[0]:-}"
        if managed "$u"; then
            if [ -n "$(pid_of "$u")" ]; then [ "$quiet" -eq 1 ] || echo active; exit 0; fi
            [ "$quiet" -eq 1 ] || echo inactive; exit 3
        fi
        # Nothing else is serving here — the installer refuses a machine that
        # is — and no edge upgrade is running: it is a oneshot, and this is not
        # that moment.
        case "$u" in
            apache2|nginx|httpd|akconnect-upgrade) [ "$quiet" -eq 1 ] || echo inactive; exit 3 ;;
            *) [ "$quiet" -eq 1 ] || echo active; exit 0 ;;
        esac ;;
    show)
        u="${units[0]:-}"
        case "$prop" in
            MainPID) p="$(pid_of "$u")"; echo "${p:-0}" ;;
            *) echo "Wed 2026-09-23 00:00:00 UTC" ;;
        esac ;;
esac
exit "$code"
SH

cat > "$STUBS/ufw" <<'SH'
#!/bin/sh
echo "ufw $*" >> "$GATE_LOG_DIR/machine.log"
[ "$1" = "status" ] && echo "Status: active"
exit 0
SH

cat > "$STUBS/apt-get" <<'SH'
#!/bin/sh
echo "apt-get $*" >> "$GATE_LOG_DIR/machine.log"
exit 0
SH

cat > "$STUBS/apt-cache" <<'SH'
#!/bin/sh
# The PHP the machine really has, named the way Ubuntu's archive names it.
case "$*" in
    *fpm*) printf 'php%s-fpm - server-side, HTML-embedded scripting language (FPM-CGI binary)\n' \
               "$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')" ;;
esac
exit 0
SH

# curl: the installer asks the internet three things. GitHub is reachable, and
# this machine's public address is the documentation address the domain
# "resolves" to. And the panel's own address is the panel: requests for it are
# handed to the installed panel, served by PHP's built-in server as the web
# user, started the first time something asks. That is what lets
# upgrade-edge.sh reach the panel it was installed beside, authenticate with
# the secret the installer wired in, and go on to build the release — the
# path that failed on every install until 1.9.7-dev.21, and that a gate which
# stopped at "panel reachable" could never see.
cat > "$STUBS/curl" <<'SH'
#!/bin/bash
url=""; code=0
for a in "$@"; do
    case "$a" in http://*|https://*) url="$a" ;; *http_code*) code=1 ;; esac
done
echo "curl $url" >> "$GATE_LOG_DIR/machine.log"
case "$url" in
    https://github.com|https://github.com/) exit 0 ;;
    https://api.ipify.org*) echo "$GATE_FAKE_IP"; exit 0 ;;
    "https://$GATE_DOMAIN"*)
        if [ -f "$GATE_PANEL/config/config.php" ]; then
            if ! (exec 3<>/dev/tcp/127.0.0.1/8088) 2>/dev/null; then
                setsid setpriv --reuid="$(id -u "$GATE_WEB_USER")" --regid="$(id -g "$GATE_WEB_USER")" --clear-groups \
                    php -S 127.0.0.1:8088 -t "$GATE_PANEL" "$GATE_PANEL/tests/dev-server.php" \
                    >> "$GATE_LOG_DIR/panel-http.log" 2>&1 < /dev/null &
                for _ in $(seq 1 40); do
                    (exec 3<>/dev/tcp/127.0.0.1/8088) 2>/dev/null && break
                    sleep 0.25
                done
            fi
            args=()
            for a in "$@"; do
                case "$a" in "https://$GATE_DOMAIN"*) a="http://127.0.0.1:8088${a#"https://$GATE_DOMAIN"}" ;; esac
                args+=("$a")
            done
            exec /usr/bin/curl "${args[@]}"
        fi ;;
esac
[ "$code" -eq 1 ] && printf '000'
exit 7
SH

cat > "$STUBS/getent" <<'SH'
#!/bin/sh
if [ "$1" = "ahostsv4" ] && [ "$2" = "$GATE_DOMAIN" ]; then
    printf '%s       STREAM %s\n' "$GATE_FAKE_IP" "$GATE_DOMAIN"
    exit 0
fi
exec /usr/bin/getent "$@"
SH

chmod 755 "$STUBS"/*

# The failure injection for the field sequence: the panel installer dies, and
# only the panel installer. Everything before it in the script has run for
# real by then — the clone, the chown, the database — which is the state the
# field server was left in. Named for every way the script finds PHP, because
# it prefers phpX.Y when that exists, and a stub it did not find would inject
# nothing and let the sequence pass for the wrong reason.
FAILING="$WORK/failing-php"
mkdir -p "$FAILING"
PHP_REAL="$(command -v php)"
PHP_SERIES="$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
cat > "$FAILING/php" <<SH
#!/bin/sh
for a in "\$@"; do
    case "\$a" in
        */cli/install.php)
            echo "install gate: the panel installer fails here, as it did on the field server" >&2
            exit 1 ;;
    esac
done
exec "$PHP_REAL" "\$@"
SH
chmod 755 "$FAILING/php"
ln -s php "$FAILING/php$PHP_SERIES"
chmod 755 "$FAILING"

# And the older sequence's: Caddy refuses its configuration — which is where
# the second field run on a clean server stopped, after the panel had been
# installed and before the edge was.
FAILING_CADDY="$WORK/failing-caddy"
mkdir -p "$FAILING_CADDY"
CADDY_REAL="$(command -v caddy)"
cat > "$FAILING_CADDY/caddy" <<SH
#!/bin/sh
if [ "\$1" = "validate" ]; then
    echo "install gate: caddy refuses this configuration, as it did on the field server" >&2
    exit 1
fi
exec "$CADDY_REAL" "\$@"
SH
chmod 755 "$FAILING_CADDY/caddy" "$FAILING_CADDY"

# --------------------------------------------------------- one sequence

# run_sequence runs one sequence in a fresh namespace. The body is below, in a
# file of its own so it can be read; it writes one line per check to results.
run_sequence() {
    local seq=$1
    local dir="$WORK/$seq"
    mkdir -p "$dir/tmp"
    chmod 755 "$dir"

    GATE_SEQ="$seq" GATE_DIR="$dir" GATE_WORK="$WORK" GATE_SCRIPT="$SCRIPT" \
    GATE_STUBS="$STUBS" GATE_FAILING="$FAILING" GATE_FAILING_CADDY="$FAILING_CADDY" GATE_DOMAIN="$DOMAIN" \
    GATE_FAKE_IP="$FAKE_IP" GATE_WEB_USER="$WEB_USER" GATE_PANEL="$PANEL" \
    GATE_SRC="$SRC" GATE_ETC="$ETC" GATE_LOG_DIR="$dir" \
    GATE_LEGACY_SCRIPT="$WORK/legacy-getting-started.sh" \
        unshare --mount --net --pid --fork --mount-proc --propagation private \
        bash "$LAB/install-gate-inner.sh" > "$dir/sequence.log" 2>&1
    local code=$?

    if [ ! -s "$dir/results" ]; then
        bad "the $seq sequence ran to completion" "it exited $code before recording anything; the end of its log:
$(tail -25 "$dir/sequence.log")"

        return
    fi

    while IFS='|' read -r verdict name detail; do
        [ "$verdict" = "DONE" ] && continue
        if [ "$verdict" = "PASS" ]; then
            ok "$name"
        else
            bad "$name" "$(printf '%b' "$detail")"
        fi
    done < "$dir/results"

    # A sequence that died part-way recorded only the checks it reached, and
    # used to count as a pass of those.
    if ! grep -qx 'DONE' "$dir/results"; then
        bad "the $seq sequence ran to completion" "it exited $code part-way; the end of its log:
$(tail -25 "$dir/sequence.log")"
    fi
}

for seq in field twice late interrupt cycle legacy older repair rotate; do
    [ -n "$ONLY" ] && [ "$ONLY" != "$seq" ] && continue

    case "$seq" in
        field) group "field: the first run dies after the chown, the second must finish" ;;
        twice) group "twice: a clean install, then the same command again" ;;
        late)  group "late: the first run dies after the panel is installed, the second must finish it" ;;
        interrupt) group "interrupt: a run stopped part-way stops, and the next one finishes" ;;
        cycle) group "cycle: uninstall, then install again" ;;
        rotate) group "rotate: akconnect-rotate-secret, and then with --relay" ;;
        legacy|older|repair)
            if [ ! -x "$WORK/legacy-getting-started.sh" ]; then
                group "legacy: skipped — the tree under test has no parent to start from"
                continue
            fi
            if [ "$seq" = legacy ]; then
                group "legacy: a tree the previous release made, run again after the branch moved"
            elif [ "$seq" = older ]; then
                group "older: the previous release dies at Caddy; this one builds the panel's release, once"
            else
                group "repair: a panel the previous release installed, run again with this one"
            fi ;;
    esac

    run_sequence "$seq"
done

printf '\n'
if [ "$FAIL" -eq 0 ]; then
    printf '  \033[32m%d checks, all passed.\033[0m The installer is safe to run again.\n\n' "$PASS"
    exit 0
fi

printf '  \033[31m%d of %d checks FAILED.\033[0m\n' "$FAIL" "$((PASS + FAIL))"
[ "$KEEP" -eq 1 ] || printf '  Re-run with --keep to look at the logs and the overlays.\n'
printf '\n'
exit 1
