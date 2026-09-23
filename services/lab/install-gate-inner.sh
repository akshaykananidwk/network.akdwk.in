#!/usr/bin/env bash
#
# One sequence of the install gate, inside its own mount, network and PID
# namespaces. Run by install-gate.sh; not meant to be run directly.
#
# Everything the installer writes lands in overlays that vanish with the
# namespace, and the database it talks to is a private MariaDB on this
# namespace's own loopback — checked, before anything runs, to really be the
# one answering. The machine this runs on has a lab database called akconnect,
# and the installer's --uninstall drops a database by that name.
set -uo pipefail

: "${GATE_SEQ:?}" "${GATE_DIR:?}" "${GATE_WORK:?}" "${GATE_SCRIPT:?}" "${GATE_STUBS:?}"
: "${GATE_FAILING:?}" "${GATE_DOMAIN:?}" "${GATE_WEB_USER:?}" "${GATE_PANEL:?}"
: "${GATE_SRC:?}" "${GATE_ETC:?}"

RESULTS="$GATE_DIR/results"
: > "$RESULTS"

# One line per check: VERDICT|name|detail, with newlines in the detail
# escaped so the outer script can read it back a line at a time.
record() {
    local detail
    detail="$(printf '%s' "${3:-}" | sed ':a;N;$!ba;s/\n/\\n/g')"
    printf '%s|%s|%s\n' "$1" "$2" "$detail" >> "$RESULTS"
}
pass() { record PASS "$1"; }
fail() { record FAIL "$1" "${2:-}"; }
# as_web <command...>: the gate's own commands as the web user, the way the
# panel runs — umask 022, as PHP-FPM has it. Not through sudo: sudo's PAM
# session gives the web user umask 0002, and a `git status` or a migration the
# gate itself ran would rewrite .git/index or a log group-writable and fail
# the check that nothing the INSTALLER left is.
as_web() {
    ( umask 022
      exec setpriv --reuid="$(id -u "$GATE_WEB_USER")" --regid="$(id -g "$GATE_WEB_USER")" --init-groups \
          env HOME="$(getent passwd "$GATE_WEB_USER" | cut -d: -f6)" "$@" )
}

check() {
    # check <name> <detail-if-failed> <command...>
    local name=$1 detail=$2
    shift 2
    if "$@"; then pass "$name"; else fail "$name" "$detail"; fi
}

# ------------------------------------------------------------------ isolate

ip link set lo up

OVL="$GATE_DIR/ovl"
mkdir -p "$OVL"
mount -t tmpfs tmpfs "$OVL"

for d in etc var opt root run usr/local; do
    name="${d//\//_}"
    mkdir -p "$OVL/$name/u" "$OVL/$name/w"
    [ -d "/$d" ] || { fail "isolation" "/$d does not exist on this machine"; exit 0; }
    mount -t overlay overlay \
        -o "lowerdir=/$d,upperdir=$OVL/$name/u,workdir=$OVL/$name/w" "/$d" \
        || { fail "isolation" "could not overlay /$d"; exit 0; }
done

# A socket in an overlay's lower layer still connects to the process that
# owns it. Without a fresh directory here, mysql inside this namespace would
# reach this machine's real server through its socket, network namespace or
# not.
mkdir -p /run/mysqld /run/php
mount -t tmpfs tmpfs /run/mysqld
mount -t tmpfs tmpfs /run/php

DATADIR=/var/lib/install-gate-mysql
mkdir -p "$DATADIR"

if ! mariadb-install-db --no-defaults --user=root --datadir="$DATADIR" \
        --auth-root-authentication-method=socket --skip-test-db \
        > "$GATE_DIR/mariadb-install.log" 2>&1; then
    fail "a private database server" "mariadb-install-db failed:
$(tail -15 "$GATE_DIR/mariadb-install.log")"
    exit 0
fi

mariadbd --no-defaults --user=root --datadir="$DATADIR" \
    --socket=/run/mysqld/mysqld.sock --pid-file=/run/mysqld/gate.pid \
    --port=3306 --bind-address=127.0.0.1 \
    --log-error="$GATE_DIR/mariadb.err" &

for _ in $(seq 1 60); do
    mysqladmin --socket=/run/mysqld/mysqld.sock ping >/dev/null 2>&1 && break
    sleep 0.5
done

# The one check everything else depends on. If mysql here answers from any
# datadir but the private one, nothing below may run.
answering="$(mysql -N -B -e 'SELECT @@datadir' 2>/dev/null)"
if [ "$answering" != "$DATADIR/" ]; then
    fail "the gate talks to its own database, never this machine's" \
        "mysql answered from '${answering:-nothing}', expected $DATADIR/ — stopping before anything can touch it"
    exit 0
fi

# PHP-FPM is not run here, but the installer checks its socket exists before
# writing a site that points at it. A bound socket file is what it looks for.
PHP_SERIES="$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
python3 -c 'import socket, sys; socket.socket(socket.AF_UNIX).bind(sys.argv[1])' \
    "/run/php/php$PHP_SERIES-fpm.sock"

git daemon --export-all --base-path="$GATE_WORK" --listen=127.0.0.1 --port=9418 \
    --reuseaddr --detach --pid-file="$GATE_DIR/git-daemon.pid" \
    || { fail "a git server for the tree under test" "git daemon would not start"; exit 0; }

# ---------------------------------------------------------------- running it

export AKCONNECT_REPO_URL="git://127.0.0.1/repo.git"
export AKCONNECT_BRANCH="gate"
export HOME=/root
export TMPDIR="$GATE_DIR/tmp"
# Offline and reproducible: every module the edge build needs is already in
# this machine's cache, and nothing may be fetched from a namespace with no
# route out.
export GOPROXY=off GOTOOLCHAIN=local GOFLAGS=-mod=readonly
# No proxy: everything this namespace talks to is on its own loopback, and a
# proxy inherited from the machine running the gate would be asked for it.
unset http_proxy https_proxy HTTP_PROXY HTTPS_PROXY
BASE_PATH="$GATE_STUBS:/usr/local/go/bin:$PATH"

RUN=0

# install [extra args...] — one run of the installer, with the real PATH or,
# when FAILING=1, with the panel installer rigged to die. SCRIPT and BRANCH
# choose another installer and another ref, for the sequence that starts from
# the previous release.
install() {
    RUN=$((RUN + 1))
    local log="$GATE_DIR/run$RUN.log" path="$BASE_PATH"
    [ "${FAILING:-0}" -eq 1 ] && path="$GATE_FAILING:$BASE_PATH"
    [ "${FAIL_CADDY:-0}" -eq 1 ] && path="$GATE_FAILING_CADDY:$path"

    PATH="$path" AKCONNECT_BRANCH="${BRANCH:-gate}" bash "${SCRIPT:-$GATE_SCRIPT}" \
        --domain "$GATE_DOMAIN" --email gate@example.test --channel edge \
        --unattended "$@" > "$log" 2>&1 < /dev/null
    LAST_CODE=$?
    LAST_LOG="$log"
    # Colour codes out, so the assertions read the words.
    sed -i 's/\x1b\[[0-9;]*m//g' "$log"
}

tail_of() { tail -"${2:-20}" "$1"; }

# ------------------------------------------------------------- invariants

# Everything that has to be true after EVERY run, whatever the sequence.
after_every_run() {
    local label=$1

    if grep -q "dubious ownership" "$LAST_LOG"; then
        fail "$label: git never refuses a tree for belonging to someone else" \
            "$(grep -B2 -A2 'dubious ownership' "$LAST_LOG" | head -8)"
    else
        pass "$label: git never refuses a tree for belonging to someone else"
    fi

    # The panel tree belongs to the web user, all of it. The panel updates
    # itself in place as that user, and a single root-owned file is an
    # update that fails part-way.
    if [ -d "$GATE_PANEL" ]; then
        local foreign
        foreign="$(find "$GATE_PANEL" ! -user "$GATE_WEB_USER" -printf '%u %p\n' 2>/dev/null | head -8)"
        if [ -z "$foreign" ]; then
            pass "$label: every file in the panel tree belongs to $GATE_WEB_USER"
        else
            fail "$label: every file in the panel tree belongs to $GATE_WEB_USER" "not theirs:
$foreign"
        fi
    fi

    # Never a global, system or per-user safe.directory — the field
    # operator's workaround, and not a fix. Anything the installer wrote to a
    # git config anywhere is in an overlay's upper layer now.
    local configs leaked=""
    configs="$(find "$OVL"/*/u \( -name .gitconfig -o -name gitconfig -o -path '*/git/config' \) -type f 2>/dev/null)"
    for f in $configs; do
        if git config --file "$f" --get-all safe.directory >/dev/null 2>&1; then
            leaked="$leaked$f: $(git config --file "$f" --get-all safe.directory | tr '\n' ' ')
"
        fi
    done
    if [ -z "$leaked" ]; then
        pass "$label: no safe.directory was written to any git configuration"
    else
        fail "$label: no safe.directory was written to any git configuration" "$leaked"
    fi

    # Nothing the installer made in the panel tree is writable by anyone but
    # its owner. sudo's PAM session gives the web user umask 0002 on Ubuntu,
    # and a checkout made through it came out group-writable, .git included.
    if [ -d "$GATE_PANEL" ]; then
        local writable
        writable="$(find "$GATE_PANEL" ! -type l -perm /022 -printf '%m %p\n' 2>/dev/null | head -8)"
        if [ -z "$writable" ]; then
            pass "$label: nothing in the panel tree is writable by anyone but its owner"
        else
            fail "$label: nothing in the panel tree is writable by anyone but its owner" "$writable"
        fi
    fi

    # The edge is built once in a run, if at all. The installer used to build
    # the tip of its branch and upgrade-edge.sh, minutes later, the panel's
    # release: two builds on every new server, of two versions on a re-run
    # over an older panel. Both print this heading.
    local builds
    builds="$(grep -c "── building the coordinator and the relay" "$LAST_LOG" 2>/dev/null)"
    if [ "${builds:-0}" -le 1 ]; then
        pass "$label: the edge was built at most once (${builds:-0})"
    else
        fail "$label: the edge was built at most once" "built $builds times in one run:
$(grep -n -E 'building the coordinator|building the panel|services current|^\s+build\s' "$LAST_LOG" | head -6)"
    fi

    # No secret in anything the operator sees. The coordinator's shared
    # secret was printed by every install up to 1.9.7-dev.21, and pasted into
    # a chat from there.
    local leaked="" name value
    for name in AKCONNECT_COORDINATOR_SECRET AKCONNECT_COORDINATOR_KEY; do
        value="$(sed -n "s/^$name=//p" "$GATE_ETC/coordinator.env" 2>/dev/null | head -1)"
        [ -n "$value" ] && grep -qF -- "$value" "$LAST_LOG" && leaked="$leaked$name "
    done
    while IFS='=' read -r name value; do
        [ -n "$value" ] && grep -qF -- "$value" "$LAST_LOG" && leaked="$leaked$name "
    done < <(grep -h '^AKCONNECT_RELAY_SECRET' "$GATE_ETC/coordinator.env" "$GATE_ETC/relay.env" 2>/dev/null)
    value="$(sed -n 's/^DB_PASS=//p' "$GATE_PANEL/config/.env" 2>/dev/null | head -1 | tr -d "\"'")"
    [ -n "$value" ] && grep -qF -- "$value" "$LAST_LOG" && leaked="${leaked}DB_PASS "
    if [ -z "$leaked" ]; then
        pass "$label: no secret appears in what it printed"
    else
        fail "$label: no secret appears in what it printed" "printed: $leaked"
    fi

    # Every temporary file the script made is gone, however it exited.
    local left
    left="$(find "$TMPDIR" -mindepth 1 -maxdepth 1 2>/dev/null | head -5)"
    if [ -z "$left" ]; then
        pass "$label: it left no temporary files behind"
    else
        fail "$label: it left no temporary files behind" "$left"
    fi
}

# the_install_works <label> — what an install is for, not only that it exited.
#
# Two releases passed an exit-code check here with a panel that could not do
# its job: the step that gives the panel its coordinator's key and secret ran
# a file its user could not open and called that a warning, and the panel
# never learnt which branch it was installed from, so upgrade-edge.sh failed
# on every install and no Windows installer was ever published.
the_install_works() {
    local label=$1 settings expected_commit

    settings="$(as_web php "$GATE_DIR/probe-settings.php" "$GATE_PANEL" 2>/dev/null)"
    expected_commit="$(as_web git -C "$GATE_PANEL" rev-parse HEAD 2>/dev/null)"

    field() { printf '%s' "$settings" | python3 -c "import json,sys; print(json.load(sys.stdin).get(sys.argv[1],''))" "$1" 2>/dev/null; }

    local key secret
    key="$(tr -d '\r\n' < "$GATE_ETC/coordinator.pub" 2>/dev/null)"
    secret="$(tr -d '\r\n' < "$GATE_ETC/coordinator.secret.for-panel" 2>/dev/null)"

    if [ -n "$key" ] && [ "$(field public_key)" = "$key" ] && [ "$(field shared_secret)" = "$secret" ] \
            && [ "$(field host)" = "$GATE_DOMAIN" ]; then
        pass "$label: the panel holds its coordinator's address, key and secret"
    else
        fail "$label: the panel holds its coordinator's address, key and secret" \
            "host '$(field host)' (want $GATE_DOMAIN); key $([ "$(field public_key)" = "$key" ] && echo matches || echo "'$(field public_key)' vs '$key'"); secret $([ "$(field shared_secret)" = "$secret" ] && echo matches || echo differs)"
    fi

    # Not after a simulated self-update: there the panel's record and its
    # files disagree on purpose, and the check that matters is that the
    # re-run left the record alone.
    if [ "${SKIP_SOURCE:-0}" -eq 1 ]; then
        :
    elif [ "$(field branch)" = "${EXPECT_BRANCH:-${BRANCH:-gate}}" ] && [ -n "$expected_commit" ] && [ "$(field commit)" = "$expected_commit" ]; then
        pass "$label: the panel knows the branch and commit it was installed from"
    else
        fail "$label: the panel knows the branch and commit it was installed from" \
            "branch '$(field branch)', commit '$(field commit)'; the tree is on ${EXPECT_BRANCH:-${BRANCH:-gate}} at $expected_commit"
    fi

    if grep -qE "Could not open input file|could not write the settings automatically|would not take its coordinator settings" "$LAST_LOG"; then
        fail "$label: no step failed and was passed off as a warning" \
            "$(grep -E 'Could not open input file|could not write the settings|would not take' "$LAST_LOG" | head -3)"
    else
        pass "$label: no step failed and was passed off as a warning"
    fi

    # upgrade-edge.sh, as the installer runs it, against this panel. The
    # listening and Windows-installer rows cannot pass in a namespace with
    # no services running and no network; the rows below can, and are the
    # ones that were failing.
    [ "${SKIP_SOURCE:-0}" -eq 1 ] && return 0

    local row missing=""
    # "build", or "services current": an edge the installer has just built at
    # the panel's release is found running it, and is not built again.
    for row in "panel reachable" "source fetched" "checkout is on a branch" \
               "source matches the panel" "build|services current" "upgrade timer"; do
        grep -qE "^\s+($row)\s+PASS\b" "$LAST_LOG" || missing="$missing$row, "
    done
    if grep -E "^\s+source fetched\s+PASS" "$LAST_LOG" | grep -vqE "[0-9a-f]{7}"; then
        missing="${missing}source fetched (with no commit), "
    fi
    if [ -z "$missing" ]; then
        pass "$label: upgrade-edge.sh reaches the panel, fetches, checks out and builds its release"
    else
        fail "$label: upgrade-edge.sh reaches the panel, fetches, checks out and builds its release" \
            "not PASS: ${missing%, }
$(grep -E '^\s+(panel reachable|source fetched|checkout is on|source matches|build|upgrade timer|run completed)\s+(PASS|FAIL|INFO)' "$LAST_LOG" | head -8)"
    fi
}

# snapshot <file> — owner and mode of everything the installer manages,
# apart from git's own object store.
snapshot() {
    find "$GATE_PANEL" "$GATE_ETC" \( -path "$GATE_PANEL/.git" -prune \) -o \
        -printf '%m %u:%g %p\n' 2>/dev/null | sort -k3 > "$1"
}

# loosened <before> <after> — every path that gained a permission bit or
# changed hands between two snapshots.
loosened() {
    python3 - "$1" "$2" <<'PY'
import sys
def read(p):
    out = {}
    for line in open(p):
        mode, owner, path = line.rstrip("\n").split(" ", 2)
        out[path] = (int(mode, 8), owner)
    return out
a, b = read(sys.argv[1]), read(sys.argv[2])
for path in sorted(set(a) & set(b)):
    (ma, oa), (mb, ob) = a[path], b[path]
    if mb & ~ma:
        print(f"{oct(ma)[2:]} -> {oct(mb)[2:]}  {path}")
    if oa != ob:
        print(f"{oa} -> {ob}  {path}")
PY
}

db_tables() {
    mysql -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='akconnect'" 2>/dev/null
}

db_exists() {
    [ -n "$(mysql -N -B -e "SHOW DATABASES LIKE 'akconnect'" 2>/dev/null)" ]
}

# check_database <name>: the panel reaches its database, and a failure shows
# what THIS migrate said, not the previous one's log.
check_database() {
    if panel_reaches_its_database; then
        pass "$1"
    else
        fail "$1" "$(tail_of "$GATE_DIR/migrate.log" 8)"
    fi
}

panel_reaches_its_database() {
    # The log is root's to write and PHP runs as the web user: the split
    # intended.
    as_web php "$GATE_PANEL/cli/migrate.php" > "$GATE_DIR/migrate.log" 2>&1
}

# The panel's own view of its settings, as the web user — written before any
# sequence runs, because some ask it before an install has been checked.
cat > "$GATE_DIR/probe-settings.php" <<'PHP'
<?php
define('APP_ROOT', $argv[1]);
require APP_ROOT . '/app/bootstrap.php';
$c = App\Services\CoordinatorSettings::current();
$u = App\Models\UpdateSetting::current();
echo json_encode([
'host' => $c['host'], 'port' => $c['port'], 'public_key' => $c['public_key'],
'shared_secret' => $c['shared_secret'], 'fallback_url' => $c['fallback_url'],
'branch' => $u['branch'], 'commit' => (string) $u['current_commit'], 'channel' => $u['channel'],
]);
PHP
chmod 644 "$GATE_DIR/probe-settings.php"

# ---------------------------------------------------------------- sequences

case "$GATE_SEQ" in

field)
    # First run: dies at the panel installer, after the chown.
    FAILING=1 install
    FAILING=0

    if grep -q "install gate: the panel installer fails here" "$LAST_LOG"; then
        pass "run 1 died where the field run died — at the panel installer"
    else
        fail "run 1 died where the field run died — at the panel installer" \
            "the injected failure never fired, so this sequence proves nothing. Exit $LAST_CODE; end of log:
$(tail_of "$LAST_LOG")"
    fi

    check "run 1 reported the failure rather than finishing" "it exited 0" \
        test "$LAST_CODE" -ne 0

    # And left the machine in the field state: a panel tree that is already
    # the web user's, an empty database, no configuration.
    if [ -d "$GATE_PANEL/.git" ] && [ "$(stat -c %U "$GATE_PANEL")" = "$GATE_WEB_USER" ] \
            && [ ! -f "$GATE_PANEL/config/config.php" ] && db_exists && [ "$(db_tables)" = "0" ]; then
        pass "run 1 left the field state: tree chowned, database empty, no config"
    else
        fail "run 1 left the field state: tree chowned, database empty, no config" \
            "tree owner: $(stat -c %U "$GATE_PANEL" 2>/dev/null || echo none); config: $([ -f "$GATE_PANEL/config/config.php" ] && echo present || echo absent); database: $(db_exists && echo "exists, $(db_tables) tables" || echo absent)"
    fi

    after_every_run "run 1"

    # Second run: must finish.
    install

    check "run 2 finished" "exit $LAST_CODE; end of log:
$(tail_of "$LAST_LOG" 25)" test "$LAST_CODE" -eq 0

    check "run 2 reused the empty database and said so" \
        "no 'reusing the empty' line in the log" grep -q "reusing the empty" "$LAST_LOG"

    check "run 2 installed the panel" "config/config.php is missing" \
        test -f "$GATE_PANEL/config/config.php"

    the_install_works "run 2"

    check_database "the panel reaches its database with the credentials it was given"

    after_every_run "run 2"
    ;;

twice)
    install
    check "run 1 finished" "exit $LAST_CODE; end of log:
$(tail_of "$LAST_LOG" 25)" test "$LAST_CODE" -eq 0
    after_every_run "run 1"
    the_install_works "run 1"
    check "run 1 printed the administrator's setup link" "no reset-password link in the log" \
        grep -q "reset-password?token=" "$LAST_LOG"

    # Between the two runs, the panel updates itself — from a release
    # archive, as it does, which records a new commit and never moves .git.
    # A re-run that read its commit from the tree and wrote it back would
    # rewind the panel, and have it offer again an update it had applied.
    cat > "$GATE_DIR/self-update.php" <<'PHP'
<?php
define('APP_ROOT', $argv[1]);
require APP_ROOT . '/app/bootstrap.php';
App\Models\UpdateSetting::save(['current_commit' => str_repeat('ab', 20), 'branch' => 'release/after-update']);
PHP
    chmod 644 "$GATE_DIR/self-update.php"
    as_web php "$GATE_DIR/self-update.php" "$GATE_PANEL" >/dev/null 2>&1

    # And the administrator has taken the account up: signed in. From here a
    # fresh takeover link would be for an account in use.
    cat > "$GATE_DIR/sign-in.php" <<'PHP'
<?php
define('APP_ROOT', $argv[1]);
require APP_ROOT . '/app/bootstrap.php';
App\Core\DB::execute('UPDATE ' . App\Core\DB::table('users') . ' SET last_login_at = UTC_TIMESTAMP()');
PHP
    chmod 644 "$GATE_DIR/sign-in.php"
    as_web php "$GATE_DIR/sign-in.php" "$GATE_PANEL" >/dev/null 2>&1

    snapshot "$GATE_DIR/after1"
    pub1="$(cat "$GATE_ETC/coordinator.pub" 2>/dev/null)"
    env1="$(sha256sum "$GATE_ETC/coordinator.env" 2>/dev/null | cut -d' ' -f1)"
    conf1="$(sha256sum "$GATE_PANEL/config/config.php" 2>/dev/null | cut -d' ' -f1)"

    install
    check "run 2 finished" "exit $LAST_CODE; end of log:
$(tail_of "$LAST_LOG" 25)" test "$LAST_CODE" -eq 0
    after_every_run "run 2"

    snapshot "$GATE_DIR/after2"
    pub2="$(cat "$GATE_ETC/coordinator.pub" 2>/dev/null)"
    env2="$(sha256sum "$GATE_ETC/coordinator.env" 2>/dev/null | cut -d' ' -f1)"
    conf2="$(sha256sum "$GATE_PANEL/config/config.php" 2>/dev/null | cut -d' ' -f1)"

    SKIP_SOURCE=1 the_install_works "run 2"

    after_update="$(as_web php "$GATE_DIR/probe-settings.php" "$GATE_PANEL" 2>/dev/null)"
    if printf '%s' "$after_update" | grep -q '"branch":"release\\/after-update"' \
            && printf '%s' "$after_update" | grep -q "\"commit\":\"$(printf 'ab%.0s' $(seq 1 20))\""; then
        pass "run 2 left the panel's own record of its commit and branch alone"
    else
        fail "run 2 left the panel's own record of its commit and branch alone" \
            "after the panel updated itself, a re-run set it back: $after_update"
    fi

    # A re-run of a finished install, whose administrator has signed in, must
    # not mint a new takeover link, and must not restart the coordinator for
    # nothing.
    if grep -q "reset-password?token=" "$LAST_LOG"; then
        fail "run 2 issued no new administrator setup link" "$(grep -m1 'reset-password' "$LAST_LOG")"
    else
        pass "run 2 issued no new administrator setup link"
    fi
    if grep -q "The administrator account is in use" "$LAST_LOG"; then
        pass "run 2 said why: the account is in use"
    else
        fail "run 2 said why: the account is in use" \
            "$(grep -iE 'setup link|setup-link' "$LAST_LOG" | head -3)"
    fi

    check "run 2 left the installed panel alone and said so" \
        "no 'left exactly as it is' in the log" grep -q "left exactly as it is" "$LAST_LOG"

    # The promise the script makes in its header: a re-run never regenerates
    # the coordinator's keypair, which would orphan every enrolled device.
    if [ -n "$pub1" ] && [ "$pub1" = "$pub2" ] && [ "$env1" = "$env2" ]; then
        pass "the coordinator's keypair and secrets are unchanged"
    else
        fail "the coordinator's keypair and secrets are unchanged" "public key: '$pub1' -> '$pub2'; coordinator.env changed: $([ "$env1" = "$env2" ] && echo no || echo yes)"
    fi

    check "the panel's configuration is unchanged" "config/config.php was rewritten" \
        test -n "$conf1" -a "$conf1" = "$conf2"

    changed="$(loosened "$GATE_DIR/after1" "$GATE_DIR/after2")"
    if [ -z "$changed" ]; then
        pass "no file became more readable or changed hands"
    else
        fail "no file became more readable or changed hands" "$(printf '%s\n' "$changed" | head -12)"
    fi

    check_database "the panel still reaches its database"
    ;;

cycle)
    install
    check "the install finished" "exit $LAST_CODE; end of log:
$(tail_of "$LAST_LOG" 25)" test "$LAST_CODE" -eq 0
    pub1="$(cat "$GATE_ETC/coordinator.pub" 2>/dev/null)"

    install --uninstall --yes
    check "--uninstall finished" "exit $LAST_CODE; end of log:
$(tail_of "$LAST_LOG" 25)" test "$LAST_CODE" -eq 0

    left=""
    for path in "$GATE_PANEL" "$GATE_SRC" "$GATE_ETC" \
                /usr/local/bin/akconnect-coordinator /usr/local/bin/akconnect-relay \
                /usr/local/bin/akconnect-maintenance "/etc/caddy/sites/$GATE_DOMAIN.caddy" \
                /etc/systemd/system/akconnect-coordinator.service \
                /etc/systemd/system/akconnect-relay.service \
                /etc/systemd/system/akconnect-worker.timer; do
        [ -e "$path" ] && left="$left$path
"
    done
    for unit in /etc/systemd/system/akconnect-*; do
        [ -e "$unit" ] && left="$left$unit
"
    done
    db_exists && left="${left}the akconnect database
"
    [ -n "$(mysql -N -B -e "SELECT 1 FROM mysql.user WHERE user='akconnect'" 2>/dev/null)" ] \
        && left="${left}the akconnect database account
"
    if [ -z "$left" ]; then
        pass "--uninstall removed everything it installed"
    else
        fail "--uninstall removed everything it installed" "still here:
$left"
    fi

    install
    check "installing again after --uninstall finished" "exit $LAST_CODE; end of log:
$(tail_of "$LAST_LOG" 25)" test "$LAST_CODE" -eq 0
    after_every_run "reinstall"

    pub2="$(cat "$GATE_ETC/coordinator.pub" 2>/dev/null)"
    # Expected, and the reason the checklist warns about it: uninstall
    # removes the coordinator's identity, so enrolled devices must re-enrol.
    check "the reinstall has a new coordinator identity, as documented" \
        "public key before: '$pub1', after: '$pub2'" test -n "$pub2" -a "$pub1" != "$pub2"
    ;;

legacy)
    # The previous release's installer, on the previous release's code, dies
    # at the panel installer — the field server's first run, exactly.
    SCRIPT="$GATE_LEGACY_SCRIPT" BRANCH=legacy FAILING=1 install
    FAILING=0

    check "the previous release's run died at the panel installer" \
        "exit $LAST_CODE; $(tail_of "$LAST_LOG" 6)" \
        grep -q "install gate: the panel installer fails here" "$LAST_LOG"

    # What it left: a tree that is the web user's, with whatever modes that
    # release's permissions step gave it.
    stripped="$(cd "$GATE_PANEL" 2>/dev/null && as_web git ls-files -s 2>/dev/null \
        | awk '$1 == "100755" {print $4}' | while read -r f; do [ -x "$f" ] || echo "$f"; done | wc -l)"
    printf 'legacy tree: %s tracked executables without +x\n' "$stripped" >> "$GATE_DIR/notes"

    # This release's installer, on a branch that has moved on — and moved
    # files the old run stripped the execute bit from.
    install

    check "this release's installer finished on the tree the previous one left" \
        "exit $LAST_CODE; end of log:
$(tail_of "$LAST_LOG" 25)" test "$LAST_CODE" -eq 0

    still="$(cd "$GATE_PANEL" 2>/dev/null && as_web git status --porcelain 2>/dev/null | head -5)"
    if [ -z "$still" ]; then
        pass "the checkout is clean afterwards — no mode changes git counts as local edits"
    else
        fail "the checkout is clean afterwards — no mode changes git counts as local edits" "$still"
    fi

    the_install_works "after the previous release"
    after_every_run "after the previous release"
    ;;

repair)
    # The previous release's installer runs to the end — which, before
    # 1.9.7-dev.21, meant a panel whose coordinator settings had silently
    # failed to write, on branch "main" with no commit: the state a field
    # server is in after a completed dev.20 run.
    SCRIPT="$GATE_LEGACY_SCRIPT" BRANCH=legacy install
    check "the previous release's install ran to the end" \
        "exit $LAST_CODE; $(tail_of "$LAST_LOG" 8)" test "$LAST_CODE" -eq 0

    # A previous release that recorded the panel's branch (1.9.7-dev.21 and
    # later) is left as it recorded it: a re-run fills in only what was never
    # set. One that recorded nothing is given this run's.
    as_web php "$GATE_DIR/probe-settings.php" "$GATE_PANEL" > "$GATE_DIR/after-legacy.json" 2>/dev/null
    recorded="$(python3 -c "import json,sys; print(json.load(open(sys.argv[1])).get('branch',''))" "$GATE_DIR/after-legacy.json" 2>/dev/null)"
    [ -n "$recorded" ] && [ "$recorded" != "main" ] && EXPECT_BRANCH="$recorded"

    # This release, run again over it. The panel is installed, so it is left
    # alone — and what the previous run never wrote is filled in.
    install
    check "this release's installer finished over it" \
        "exit $LAST_CODE; end of log:
$(tail_of "$LAST_LOG" 25)" test "$LAST_CODE" -eq 0
    check "and left the installed panel alone" \
        "no 'left exactly as it is' in the log" grep -q "left exactly as it is" "$LAST_LOG"

    # It was installed from the previous release, so that is its commit;
    # the branch it is told is this run's.
    the_install_works "repaired"
    after_every_run "repaired"

    # Nobody ever signed in to the account the previous release made, so the
    # run that finally makes the panel usable says how to get in.
    check "repaired: the administrator nobody has taken up gets a setup link" \
        "no reset-password link in the log" grep -q "reset-password?token=" "$LAST_LOG"
    ;;

late)
    # The first run gets past the panel installer, the web server and the
    # edge build, and dies starting the relay — after install-edge.sh has
    # written coordinator.env, so the edge looks installed from its files.
    export GATE_FAIL_RELAY_START=1
    install
    unset GATE_FAIL_RELAY_START

    check "run 1 died starting the relay, after the panel was installed" \
        "exit $LAST_CODE; $(tail_of "$LAST_LOG" 6)" \
        sh -c "[ $LAST_CODE -ne 0 ] && grep -q 'relay would not start' '$LAST_LOG' && [ -f '$GATE_PANEL/config/config.php' ] && [ -f '$GATE_ETC/coordinator.env' ]"
    check "run 1 printed no setup link — it never reached the end" \
        "$(grep -m1 'reset-password' "$LAST_LOG")" sh -c "! grep -q 'reset-password?token=' '$LAST_LOG'"
    after_every_run "run 1"

    before="$(wc -l < "$GATE_DIR/machine.log" 2>/dev/null || echo 0)"
    install
    check "run 2 finished" "exit $LAST_CODE; end of log:
$(tail_of "$LAST_LOG" 25)" test "$LAST_CODE" -eq 0

    # Both services enabled on this run, not only found on disk: a coordinator
    # the first run never enabled would otherwise stay down after a reboot.
    check "run 2 enabled and started both edge services" \
        "$(tail -n +"$((before + 1))" "$GATE_DIR/machine.log" | grep 'systemctl enable' | head -3)" \
        sh -c "tail -n +$((before + 1)) '$GATE_DIR/machine.log' | grep 'systemctl enable --now' | grep 'akconnect-relay' | grep -q 'akconnect-coordinator'"

    check "run 2 printed the setup link run 1 never reached" \
        "no reset-password link in the log; $(grep -m2 -i 'setup link\|account is in use' "$LAST_LOG")" \
        grep -q "reset-password?token=" "$LAST_LOG"

    the_install_works "run 2"
    after_every_run "run 2"
    ;;

interrupt)
    # SIGTERM, from outside, part-way through the edge build. A background
    # job in a script ignores SIGINT, and a signal ignored when a shell starts
    # cannot be trapped, so TERM stands in for the operator's ^C — the trap
    # is the same one.
    RUN=$((RUN + 1))
    log="$GATE_DIR/run$RUN.log"
    PATH="$BASE_PATH" AKCONNECT_BRANCH=gate bash "$GATE_SCRIPT" \
        --domain "$GATE_DOMAIN" --email gate@example.test --channel edge \
        --unattended > "$log" 2>&1 < /dev/null &
    pid=$!
    reached=0
    for _ in $(seq 1 1200); do
        if grep -q "── building the coordinator and the relay" "$log" 2>/dev/null; then
            reached=1
            break
        fi
        kill -0 "$pid" 2>/dev/null || break
        sleep 0.25
    done
    # Only at the step it is meant to stop in. A poll that ran out used to
    # send the signal wherever the run had got to.
    sent=0
    if [ "$reached" -eq 1 ] && kill -TERM "$pid" 2>/dev/null; then sent=1; fi
    [ "$reached" -eq 1 ] || kill -KILL "$pid" 2>/dev/null
    wait "$pid"
    LAST_CODE=$?
    LAST_LOG="$log"
    sed -i 's/\x1b\[[0-9;]*m//g' "$log"

    check "the run was stopped while it built the edge" \
        "it never reached that step, or had already exited; $(tail_of "$log" 4)" test "$sent" -eq 1
    # Stopped by the signal — exit 143 — and at once: nothing after the step
    # it was in. A trap that cleaned up and returned carried on, and failed a
    # step later for having deleted its own build; exit 1 and no "installed"
    # line would have passed that.
    after_step="$(sed -n '/── building the coordinator and the relay/,$p' "$log" | grep -c '^── ')"
    check "it stopped there, with the signal's exit status, and went no further" \
        "exit $LAST_CODE, $((after_step - 1)) step(s) after it; $(tail_of "$log" 6)" \
        sh -c "[ $LAST_CODE -eq 143 ] && [ $after_step -le 1 ] && ! grep -q 'AK Connect is installed' '$log'"
    after_every_run "stopped run"

    install
    check "the next run finished" "exit $LAST_CODE; end of log:
$(tail_of "$LAST_LOG" 25)" test "$LAST_CODE" -eq 0
    the_install_works "the next run"
    after_every_run "the next run"
    ;;

older)
    # The second field run of this installer, exactly: the previous release
    # installs the panel and dies at Caddy, before the edge. Then this release
    # runs over it. The panel is left on the previous release — and the edge
    # must be built at THAT release, once. It used to be built at this
    # release's, and upgrade-edge.sh then rebuilt it at the panel's minutes
    # later: two builds, the second one older.
    SCRIPT="$GATE_LEGACY_SCRIPT" BRANCH=legacy FAIL_CADDY=1 install

    check "the previous release died at Caddy, after installing the panel" \
        "exit $LAST_CODE; $(tail_of "$LAST_LOG" 6)" \
        sh -c "[ $LAST_CODE -ne 0 ] && grep -q 'install gate: caddy refuses' '$LAST_LOG' && [ -f '$GATE_PANEL/config/config.php' ] && ! grep -q '── installing the edge services' '$LAST_LOG'"
    panel_release="$(tr -d '\r\n' < "$GATE_PANEL/VERSION" 2>/dev/null)"
    after_every_run "the previous release"

    install
    check "this release finished over it" "exit $LAST_CODE; end of log:
$(tail_of "$LAST_LOG" 25)" test "$LAST_CODE" -eq 0

    built="$(/usr/local/bin/akconnect-coordinator version 2>/dev/null | awk '{print $NF}')"
    check "the edge was built at the panel's release ($panel_release), not this one's" \
        "the coordinator reports '$built'; $(grep -E "panel's release|panel is on" "$LAST_LOG" | head -3)" \
        test -n "$panel_release" -a "$built" = "$panel_release"
    check "and upgrade-edge.sh found it running and built nothing" \
        "$(grep -E '^\s+(services current|build)\s' "$LAST_LOG" | head -3)" \
        grep -qE "^\s+services current\s+PASS" "$LAST_LOG"

    the_install_works "older"
    after_every_run "older"
    ;;

rotate)
    install
    check "the install finished" "exit $LAST_CODE; end of log:
$(tail_of "$LAST_LOG" 25)" test "$LAST_CODE" -eq 0

    env_value() { sed -n "s/^$1=//p" "$2" 2>/dev/null | head -1; }
    old_secret="$(env_value AKCONNECT_COORDINATOR_SECRET "$GATE_ETC/coordinator.env")"
    old_key="$(env_value AKCONNECT_COORDINATOR_KEY "$GATE_ETC/coordinator.env")"
    old_relay="$(env_value AKCONNECT_RELAY_SECRET "$GATE_ETC/relay.env")"
    coord_pid="$(cat "$GATE_DIR/svc/akconnect-coordinator.pid" 2>/dev/null)"
    relay_pid="$(cat "$GATE_DIR/svc/akconnect-relay.pid" 2>/dev/null)"
    mode_before="$(stat -c '%a %U:%G' "$GATE_ETC/coordinator.env")"
    before_log="$(wc -l < "$GATE_DIR/machine.log")"

    RUN=$((RUN + 1))
    LAST_LOG="$GATE_DIR/run$RUN.log"
    PATH="$BASE_PATH" /usr/local/bin/akconnect-rotate-secret --yes > "$LAST_LOG" 2>&1 < /dev/null
    LAST_CODE=$?
    sed -i 's/\x1b\[[0-9;]*m//g' "$LAST_LOG"

    new_secret="$(env_value AKCONNECT_COORDINATOR_SECRET "$GATE_ETC/coordinator.env")"
    check "akconnect-rotate-secret finished" "exit $LAST_CODE; $(tail_of "$LAST_LOG" 12)" test "$LAST_CODE" -eq 0
    check "the coordinator has a new secret, in both of its files" \
        "coordinator.env changed: $([ "$new_secret" != "$old_secret" ] && echo yes || echo no); for-panel matches: $([ "$(tr -d '\n' < "$GATE_ETC/coordinator.secret.for-panel")" = "$new_secret" ] && echo yes || echo no)" \
        sh -c "[ -n '$new_secret' ] && [ '$new_secret' != '$old_secret' ] && [ \"\$(tr -d '\n' < '$GATE_ETC/coordinator.secret.for-panel')\" = '$new_secret' ]"
    panel_secret="$(as_web php "$GATE_DIR/probe-settings.php" "$GATE_PANEL" 2>/dev/null \
        | python3 -c "import json,sys; print(json.load(sys.stdin).get('shared_secret',''))" 2>/dev/null)"
    check "and so does the panel" "the panel holds a different value" test "$panel_secret" = "$new_secret"
    check "the panel accepts the new secret and refuses the old" "$(grep -E 'accepts|refuses|confirm' "$LAST_LOG" | head -3)" \
        grep -q "the panel accepts the new secret and refuses the old one" "$LAST_LOG"
    check "the coordinator's key, the relay's secret and the files' owner and mode are unchanged" \
        "key same: $([ "$(env_value AKCONNECT_COORDINATOR_KEY "$GATE_ETC/coordinator.env")" = "$old_key" ] && echo yes || echo no); relay same: $([ "$(env_value AKCONNECT_RELAY_SECRET "$GATE_ETC/relay.env")" = "$old_relay" ] && echo yes || echo no); mode $mode_before -> $(stat -c '%a %U:%G' "$GATE_ETC/coordinator.env")" \
        sh -c "[ \"\$(sed -n 's/^AKCONNECT_COORDINATOR_KEY=//p' '$GATE_ETC/coordinator.env')\" = '$old_key' ] && [ \"\$(sed -n 's/^AKCONNECT_RELAY_SECRET=//p' '$GATE_ETC/relay.env')\" = '$old_relay' ] && [ \"\$(stat -c '%a %U:%G' '$GATE_ETC/coordinator.env')\" = '$mode_before' ]"
    check "the coordinator restarted, and the relay did not" \
        "coordinator pid $coord_pid -> $(cat "$GATE_DIR/svc/akconnect-coordinator.pid" 2>/dev/null); relay pid $relay_pid -> $(cat "$GATE_DIR/svc/akconnect-relay.pid" 2>/dev/null)" \
        sh -c "[ \"\$(cat '$GATE_DIR/svc/akconnect-coordinator.pid')\" != '$coord_pid' ] && [ \"\$(cat '$GATE_DIR/svc/akconnect-relay.pid')\" = '$relay_pid' ] && kill -0 \"\$(cat '$GATE_DIR/svc/akconnect-coordinator.pid')\""
    check "the upgrade timer was held for it and put back" \
        "$(tail -n +"$((before_log + 1))" "$GATE_DIR/machine.log" | grep timer)" \
        sh -c "tail -n +$((before_log + 1)) '$GATE_DIR/machine.log' | grep -q 'stop akconnect-upgrade.timer' && tail -n +$((before_log + 1)) '$GATE_DIR/machine.log' | grep -q 'start akconnect-upgrade.timer'"
    leaked=""
    for value in "$old_secret" "$new_secret"; do
        [ -n "$value" ] && grep -qF -- "$value" "$LAST_LOG" && leaked="yes"
    done
    check "neither the old secret nor the new one was printed" "a secret appears in the output" test -z "$leaked"
    check "and it said what was published with the secret" "$(tail_of "$LAST_LOG" 8)" \
        grep -q "Served now" "$LAST_LOG"

    # And the relay's, on request.
    relay_pid="$(cat "$GATE_DIR/svc/akconnect-relay.pid" 2>/dev/null)"
    RUN=$((RUN + 1))
    LAST_LOG="$GATE_DIR/run$RUN.log"
    PATH="$BASE_PATH" /usr/local/bin/akconnect-rotate-secret --yes --relay > "$LAST_LOG" 2>&1 < /dev/null
    LAST_CODE=$?
    sed -i 's/\x1b\[[0-9;]*m//g' "$LAST_LOG"
    new_relay="$(env_value AKCONNECT_RELAY_SECRET "$GATE_ETC/relay.env")"
    relay_name="$(env_value AKCONNECT_RELAY_NAME "$GATE_ETC/relay.env")"
    relay_line="AKCONNECT_RELAY_SECRET_$(printf '%s' "$relay_name" | tr '[:lower:]-' '[:upper:]_')"
    check "--relay finished" "exit $LAST_CODE; $(tail_of "$LAST_LOG" 12)" test "$LAST_CODE" -eq 0
    check "and gave the relay a new secret, the same on both sides" \
        "relay.env changed: $([ "$new_relay" != "$old_relay" ] && echo yes || echo no); coordinator's $relay_line matches: $([ "$(env_value "$relay_line" "$GATE_ETC/coordinator.env")" = "$new_relay" ] && echo yes || echo no)" \
        sh -c "[ -n '$new_relay' ] && [ '$new_relay' != '$old_relay' ] && [ \"\$(sed -n 's/^$relay_line=//p' '$GATE_ETC/coordinator.env')\" = '$new_relay' ]"
    check "and restarted the relay" "relay pid $relay_pid -> $(cat "$GATE_DIR/svc/akconnect-relay.pid" 2>/dev/null)" \
        sh -c "[ \"\$(cat '$GATE_DIR/svc/akconnect-relay.pid')\" != '$relay_pid' ] && kill -0 \"\$(cat '$GATE_DIR/svc/akconnect-relay.pid')\""
    check "without printing it" "the relay secret appears in the output" \
        sh -c "! grep -qF -- '$new_relay' '$LAST_LOG' && ! grep -qF -- '$old_relay' '$LAST_LOG'"

    after_every_run "after rotating"
    ;;

*)
    fail "sequence" "unknown sequence $GATE_SEQ"
    ;;
esac

# The sequence ran to its end. The outer script counts a sequence without this
# line as one that died part-way, whatever checks it had recorded by then.
printf 'DONE\n' >> "$GATE_DIR/results"

# Evidence out of the overlay before the namespace takes it away.
cp -a "$OVL/etc/u" "$GATE_DIR/upper-etc" 2>/dev/null || true

exit 0
