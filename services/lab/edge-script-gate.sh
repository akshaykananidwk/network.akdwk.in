#!/usr/bin/env bash
#
# Does upgrade-edge.sh tell the truth when it fails?
#
# It did not. Run early on a VPS where Go was installed from the official
# tarball — which unpacks to /usr/local/go/bin and is on nobody's PATH — it
# printed
#
#     ✗ go is not installed …
#     All steps passed.
#
# Two defects in four lines: it could not find a Go that was plainly there, and
# a failed preflight produced a PASS summary. The second is the false-pass
# class this whole project exists to find in other people's code, and it was in
# my own deployment script, on the first thing the operator ran.
#
# So every early exit is now exercised, and the assertion is the same for all
# of them: a run that did not finish must say FAIL and must exit non-zero.
#
#   ./services/lab/edge-script-gate.sh
#
# Needs root, for the mount namespace that hides Go from one of the cases. It
# changes nothing outside a temporary directory: the script under test stops at
# its own preflight in every case here, long before it would touch a binary,
# a service or the panel.
set -uo pipefail

LAB="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$LAB/../.." && pwd)"
SCRIPT="$REPO/deploy/upgrade-edge.sh"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

PASS=0
FAIL=0

ok()   { printf '  \033[32m✓\033[0m %s\n' "$1"; PASS=$((PASS + 1)); }
bad()  { printf '  \033[31m✗\033[0m %s\n' "$1"; [ $# -gt 1 ] && printf '      %s\n' "$2"; FAIL=$((FAIL + 1)); }
group(){ printf '\n\033[1m── %s\033[0m\n' "$1"; }

[ -x "$SCRIPT" ] || { echo "  $SCRIPT is missing or not executable" >&2; exit 2; }

# ---------------------------------------------------------------- the checks

# assert_fails runs the script and insists it failed, loudly.
#
# Three things have to be true together, because the defect was that two of
# them were and the third was not: a non-zero exit, the word FAIL in the
# summary, and no claim of success anywhere in the output.
assert_fails() {
    local name=$1 out=$2 code=$3

    local problems=()

    [ "$code" -ne 0 ] || problems+=("exited 0")

    grep -q 'FAIL' "$out" || problems+=("the summary does not say FAIL")

    # The wording that was printed over a failed preflight. Any of these in the
    # output of a failed run is the defect back again.
    if grep -qE 'All steps passed|PASS —|all clean' "$out"; then
        problems+=("it claimed success")
    fi

    if [ "${#problems[@]}" -eq 0 ]; then
        ok "$name"

        return 0
    fi

    bad "$name" "$(IFS='; '; echo "${problems[*]}")"
    sed 's/^/        /' "$out" | tail -12

    return 1
}

group "a failed preflight is a failure, not a pass"

# 1. Go genuinely absent: nothing on PATH, and the tarball location hidden by a
#    mount namespace so the fallback has nothing to find either.
out="$WORK/nogo.out"
mkdir -p "$WORK/emptygo" "$WORK/bin"

# An ordinary machine that simply has no go on its PATH: everything the real
# PATH holds, minus the Go toolchain. Built by mirroring rather than by listing
# what the script needs, because a list gets out of date and then the test
# fails for the wrong reason — which it did, twice, before this loop replaced
# it.
while IFS= read -r -d '' binary; do
    name="$(basename "$binary")"
    case "$name" in
        go|gofmt) continue ;;
    esac
    [ -e "$WORK/bin/$name" ] || ln -sf "$binary" "$WORK/bin/$name" 2>/dev/null
done < <(find $(echo "$PATH" | tr ':' ' ') -maxdepth 1 -type f -perm -u+x -print0 2>/dev/null
         find $(echo "$PATH" | tr ':' ' ') -maxdepth 1 -type l -print0 2>/dev/null)

command -v go >/dev/null 2>&1 && [ ! -e "$WORK/bin/go" ] \
    && printf '  (the stripped PATH has %d tools and no go)\n' "$(ls "$WORK/bin" | wc -l)"

unshare -m -- sh -c "
    mount --bind '$WORK/emptygo' /usr/local/go 2>/dev/null
    mkdir -p '$WORK/emptylib' && mount --bind '$WORK/emptylib' /usr/lib/go 2>/dev/null
    PATH='$WORK/bin' HOME='$WORK' '$SCRIPT' --check
" >"$out" 2>&1
code=$?

assert_fails "Go absent → FAIL and non-zero (exit $code)" "$out" "$code"

if grep -qi 'go is not installed\|Go is not installed' "$out"; then
    ok "and it says which tool is missing"
else
    bad "it does not say Go is the problem" "$(head -3 "$out")"
fi

if grep -q '/usr/local/go/bin' "$out"; then
    ok "and names where it looked"
else
    bad "it does not say where it looked"
fi

group "Go off PATH but installed is not missing"

# 2. The operator's actual machine: Go at /usr/local/go/bin, not on PATH. The
#    script must find it and get past the Go check — proved by a "go" PASS row
#    and by the run then failing on something else.
out="$WORK/offpath.out"

if [ -x /usr/local/go/bin/go ]; then
    env -i PATH="$WORK/bin" HOME="$WORK" "$SCRIPT" --check >"$out" 2>&1
    code=$?

    if grep -qE '^\s+go\s+PASS|go +PASS' "$out"; then
        ok "Go at /usr/local/go/bin is found without PATH"
    else
        bad "Go off PATH was reported as missing" "$(grep -i 'go' "$out" | head -3)"
    fi

    # It still has to fail — there is no /etc/akconnect here — and that failure
    # must be honest too.
    assert_fails "and the run still fails honestly further on (exit $code)" "$out" "$code"
else
    printf '  \033[33m·\033[0m /usr/local/go/bin/go is not present here; case skipped\n'
fi

group "a Go too old to build with is refused, not attempted"

# A toolchain older than the services declare fails deep inside the build with
# a message about a language feature. The script has to say so up front, and
# saying so is still a failure.
out="$WORK/oldgo.out"
mkdir -p "$WORK/oldgo"
cat > "$WORK/oldgo/go" <<'FAKE'
#!/bin/sh
[ "$1" = "version" ] && echo "go version go1.19.8 linux/amd64" && exit 0
exit 1
FAKE
chmod +x "$WORK/oldgo/go"

env PATH="$WORK/oldgo:$WORK/bin" HOME="$WORK" "$SCRIPT" --check >"$out" 2>&1
code=$?

assert_fails "Go 1.19 → FAIL and non-zero (exit $code)" "$out" "$code"

if grep -q '1.24' "$out"; then
    ok "and says which version is needed"
else
    bad "it does not say what version is needed" "$(grep -i go "$out" | head -3)"
fi

group "every other early exit fails honestly too"

# 3. Not root. The first check in the script, and the one most likely to be hit
#    by somebody trying it without sudo.
out="$WORK/notroot.out"
if command -v setpriv >/dev/null 2>&1; then
    setpriv --reuid=65534 --regid=65534 --clear-groups \
        env PATH="/usr/local/go/bin:$PATH" HOME="$WORK" "$SCRIPT" --check >"$out" 2>&1
    code=$?

    assert_fails "run without root → FAIL and non-zero (exit $code)" "$out" "$code"
else
    printf '  \033[33m·\033[0m setpriv is not available; case skipped\n'
fi

# 4. No /etc/akconnect: the machine has not been set up by install-edge.sh.
out="$WORK/nosetup.out"
env PATH="/usr/local/go/bin:$PATH" HOME="$WORK" \
    AKCONNECT_SRC="$WORK/not-a-checkout" "$SCRIPT" --check >"$out" 2>&1
code=$?

assert_fails "no source checkout → FAIL and non-zero (exit $code)" "$out" "$code"

# 5. An unknown flag, which exits before any of the reporting is set up at all.
out="$WORK/badflag.out"
"$SCRIPT" --wat >"$out" 2>&1
code=$?

if [ "$code" -ne 0 ]; then
    ok "an unknown option exits non-zero (exit $code)"
else
    bad "an unknown option exited 0"
fi

if grep -qE 'All steps passed|PASS —|all clean' "$out"; then
    bad "an unknown option claimed success"
else
    ok "and claims nothing"
fi

# 6. The headers that actually leave the machine.
#
# Everything above is about failing honestly. This one is about succeeding: for
# one release the script signed its requests correctly and sent the timestamp
# as an empty header, because TS was assigned inside a function called in a
# $( ) subshell and the assignment never escaped it. curl drops a header with
# an empty value, so the panel saw no timestamp at all and answered 401.
#
# Nothing above would have caught it — those cases all stop at the preflight,
# long before a request is made. Asserting on a status code would not have
# caught it cleanly either; what catches it is reading the bytes that left.
group "the signed request is actually signed"

CAPTURE="$WORK/capture.jsonl"
CAP_SECRET="0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
CAP_PORT=8791

python3 "$LAB/capture-panel.py" "$CAP_PORT" "$CAPTURE" --secret "$CAP_SECRET" \
    >"$WORK/capture.log" 2>&1 &
CAP_PID=$!
trap 'kill "$CAP_PID" 2>/dev/null; rm -rf "$WORK"' EXIT

# Waited for on the port rather than with a request: a readiness probe is
# recorded like anything else, and the first version of this drill then read
# its own probe instead of the script's request — so the headers it asserted on
# were the ones curl never sent, and the check passed while the defect was
# still there. The reader below filters by path for the same reason.
for _ in $(seq 1 40); do
    (exec 3<>"/dev/tcp/127.0.0.1/$CAP_PORT") 2>/dev/null && break
    sleep 0.25
done

# The environment file install-edge.sh would have written.
mkdir -p "$WORK/etc"
cat > "$WORK/etc/coordinator.env" <<ENVFILE
AKCONNECT_PANEL_URL=http://127.0.0.1:$CAP_PORT
AKCONNECT_COORDINATOR_SECRET=$CAP_SECRET
ENVFILE

# --check stops after asking the panel, which is exactly as far as this needs
# to go: the request has been made and recorded.
env PATH="/usr/local/go/bin:$PATH" HOME="$WORK" \
    AKCONNECT_ETC="$WORK/etc" AKCONNECT_SRC="$REPO" \
    "$SCRIPT" --check >"$WORK/signed.out" 2>&1

# jq would be neater; python3 is already a hard requirement of the script under
# test, and one fewer dependency in a gate is one fewer reason it does not run.
#
# Only /api/ requests count, and a null field prints as empty rather than as
# the string "None" — which is non-empty, and which made the first version of
# this drill report a missing header as present.
read_field() {
    python3 -c "
import json, sys

rows = [json.loads(line) for line in open(sys.argv[1]) if line.strip()]
signed = [row for row in rows if row.get('path', '').startswith('/api/')]

if not signed:
    print('')
else:
    value = signed[0].get(sys.argv[2])
    print('' if value is None else value)
" "$CAPTURE" "$1" 2>/dev/null
}

if [ -n "$(read_field path)" ]; then
    ok "the panel was actually called"
else
    bad "no signed request reached the panel" "see $WORK/signed.out"
fi

TS_SENT="$(read_field timestamp)"
SIG_SENT="$(read_field signature)"
VERIFIES="$(read_field signature_verifies)"

if [ -n "$TS_SENT" ]; then
    ok "X-Coordinator-Timestamp arrived non-empty ($TS_SENT)"
else
    bad "X-Coordinator-Timestamp was empty or missing" \
        "the panel would answer 401 and log 'missing signature headers'"
fi

if [ -n "$SIG_SENT" ]; then
    ok "X-Coordinator-Signature arrived non-empty"
else
    bad "X-Coordinator-Signature was empty or missing"
fi

# Non-empty is necessary and not sufficient: a timestamp the signature was not
# computed over would also be non-empty.
if [ "$VERIFIES" = "true" ] || [ "$VERIFIES" = "True" ]; then
    ok "and the signature verifies against the timestamp that was sent"
else
    bad "the signature does not verify against the timestamp that was sent" \
        "verifies=$VERIFIES"
fi

# A timestamp the panel would accept. CoordinatorMiddleware refuses one outside
# its window, so "some string arrived" is not enough either.
if [ -n "$TS_SENT" ] && [ "$TS_SENT" -eq "$TS_SENT" ] 2>/dev/null \
    && [ $(( $(date +%s) - TS_SENT )) -lt 300 ]; then
    ok "and it is a current unix timestamp, not a leftover"
else
    bad "the timestamp is not a plausible current one" "got '$TS_SENT'"
fi

kill "$CAP_PID" 2>/dev/null
trap 'rm -rf "$WORK"' EXIT

# 7. The checkout is left usable, and the release's own script is what runs.
#
# Two field faults. The script checked out the target commit with --detach, so
# every edge server it touched was left on a detached HEAD and the next `git
# pull` answered "You are not currently on a branch". And because the operator
# runs the copy that is in the checkout, the script that executed was the
# PREVIOUS release's — a fix to this file only took effect the second time
# somebody ran it, which is how a release shipped with a signing defect still
# in it.
#
# Worse than either: the checkout rewrites this file while bash is reading it.
group "the release's own script runs, and the checkout stays usable"

CLONE="$WORK/clone"
ORIGIN="$WORK/origin"

# -b main explicitly: git's default branch name depends on the host's
# configuration, and a seed repository on "master" while the drill pushes to
# "main" leaves the clone tracking a branch that does not exist — which failed
# this drill for its own reasons the first time it ran.
git init --quiet --bare -b main "$ORIGIN"
git clone --quiet "$ORIGIN" "$WORK/seed" 2>/dev/null
git -C "$WORK/seed" checkout --quiet -B main 2>/dev/null

# Two commits. The "old" one is this same script with a marker line added, not
# a stub: the state being modelled is an operator whose checkout holds the
# PREVIOUS release, and a previous release still has to be able to hand over. A
# stub could never hand over, so the drill would be asserting something no
# version of the product does.
#
# The very first upgrade onto a release carrying this is the exception: the
# copy already on the box predates the hand-over and cannot do it, so that one
# time the operator runs the old script and it behaves as it always did.
# DEPLOY.md says so rather than leaving it to be found out.
#
# Its SCRIPT_REVISION is lowered to 1, because that is what makes it older.
# "They differ" was the old rule and it is what defect 27 was: a checkout ahead
# of the panel handed over to the panel's OLDER copy. The drill has to model a
# genuine upgrade or it is asserting a hand-over that should not happen.
mkdir -p "$WORK/seed/deploy"
{
    head -1 "$SCRIPT"
    echo 'echo I-AM-THE-OLD-SCRIPT'
    tail -n +2 "$SCRIPT"
} | sed 's/^SCRIPT_REVISION=.*/SCRIPT_REVISION=1/' > "$WORK/seed/deploy/upgrade-edge.sh"
chmod +x "$WORK/seed/deploy/upgrade-edge.sh"
printf '0.0.1
' > "$WORK/seed/VERSION"
git -C "$WORK/seed" add -A >/dev/null
git -C "$WORK/seed" -c user.email=g@l -c user.name=gate commit --quiet -m old
git -C "$WORK/seed" push --quiet origin HEAD:refs/heads/main 2>/dev/null

cp "$SCRIPT" "$WORK/seed/deploy/upgrade-edge.sh"
printf '9.9.9-capture
' > "$WORK/seed/VERSION"
git -C "$WORK/seed" add -A >/dev/null
git -C "$WORK/seed" -c user.email=g@l -c user.name=gate commit --quiet -m new
git -C "$WORK/seed" push --quiet origin HEAD:refs/heads/main 2>/dev/null

TARGET_SHA="$(git -C "$WORK/seed" rev-parse HEAD)"

# The operator's checkout is one commit behind, and detached — the state the
# previous run left it in.
git clone --quiet "$ORIGIN" "$CLONE" 2>/dev/null
git -C "$CLONE" checkout --quiet --detach HEAD~1 2>/dev/null

# A panel that names that commit.
CAP2="$WORK/capture2.jsonl"
python3 "$LAB/capture-panel.py" 8792 "$CAP2" --secret "$CAP_SECRET" --commit "$TARGET_SHA" --branch main >"$WORK/panel2.log" 2>&1 &
CAP2_PID=$!
trap 'kill "$CAP_PID" "$CAP2_PID" 2>/dev/null; rm -rf "$WORK"' EXIT

for _ in $(seq 1 40); do
    (exec 3<>"/dev/tcp/127.0.0.1/8792") 2>/dev/null && break
    sleep 0.25
done

mkdir -p "$WORK/etc2"
cat > "$WORK/etc2/coordinator.env" <<ENVFILE
AKCONNECT_PANEL_URL=http://127.0.0.1:8792
AKCONNECT_COORDINATOR_SECRET=$CAP_SECRET
ENVFILE

# The OLD copy is what the operator invokes, exactly as on a real box.
#
# Not --check: that reports and stops before touching the working tree, which
# is what it is for. The state being asserted below — the checkout left on a
# branch at the release — belongs to a real run, so this is one. It gets as far
# as the build and dies there, because the fixture repository contains a script
# and a VERSION file and nothing to build; that is expected and the run's own
# verdict is ignored. Everything the drill asserts happens before the build.
env PATH="/usr/local/go/bin:$PATH" HOME="$WORK" \
    AKCONNECT_ETC="$WORK/etc2" AKCONNECT_SRC="$CLONE" \
    "$CLONE/deploy/upgrade-edge.sh" --skip-pack >"$WORK/reexec.out" 2>&1

# The marker proves the OLD copy is what started — otherwise the hand-over
# below would be trivially true because nothing else ever ran.
if ! grep -q "I-AM-THE-OLD-SCRIPT" "$WORK/reexec.out"; then
    bad "the drill never started the old copy" "it is testing nothing; see $WORK/reexec.out"
elif grep -q "handing over" "$WORK/reexec.out"; then
    ok "the old copy started and handed over to the release's own script"
else
    bad "the previous release's script ran to the end" \
        "a fix to this file would not take effect until it had been run twice"
    # A failure that does not show what happened makes the next person
    # reproduce it before they can start reading it.
    sed 's/^/      /' "$WORK/reexec.out" | head -14
fi

ON="$(git -C "$CLONE" rev-parse --abbrev-ref HEAD 2>/dev/null)"
if [ "$ON" = "main" ]; then
    ok "the checkout is left on a branch ($ON), so git pull still works"
else
    bad "the checkout is left on '$ON'" "git pull answers 'You are not currently on a branch'"
fi

AT="$(git -C "$CLONE" rev-parse HEAD 2>/dev/null)"
if [ "$AT" = "$TARGET_SHA" ]; then
    ok "and at the commit the panel named"
else
    bad "the checkout is at $AT, not the panel's $TARGET_SHA"
fi

if git -C "$CLONE" pull --quiet --ff-only >/dev/null 2>&1; then
    ok "git pull runs in the checkout without complaint"
else
    bad "git pull still fails in the checkout" "$(git -C "$CLONE" pull 2>&1 | head -1)"
fi

# 8. Twice in a row.
#
# Defect 26: a successful run left "M services/kit/akconnect-windows-test-pack.zip"
# in its own checkout, because the pack was built inside the source tree and
# tracked in git. The next run hit the clean-tree guard and refused with "has
# local changes" — so the tool that maintains the edge could be run exactly
# once per clone, and the second attempt looked like the operator's fault.
#
# The guard itself is right and stays. What was wrong is a build writing into
# the tree it builds from.
group "running it twice in a row"

env PATH="/usr/local/go/bin:$PATH" HOME="$WORK" \
    AKCONNECT_ETC="$WORK/etc2" AKCONNECT_SRC="$CLONE" \
    AKCONNECT_REEXEC=1 "$SCRIPT" --skip-pack >"$WORK/twice.out" 2>&1

DIRT="$(git -C "$CLONE" status --porcelain)"
if [ -z "$DIRT" ]; then
    ok "the checkout is clean after a run"
else
    bad "the run dirtied its own checkout" "$(printf '%s' "$DIRT" | head -3 | tr '\n' ' ')"
fi

env PATH="/usr/local/go/bin:$PATH" HOME="$WORK" \
    AKCONNECT_ETC="$WORK/etc2" AKCONNECT_SRC="$CLONE" \
    AKCONNECT_REEXEC=1 "$SCRIPT" --skip-pack >"$WORK/twice2.out" 2>&1

if grep -q "has local changes" "$WORK/twice2.out"; then
    bad "the second run refused" "has local changes — the first run dirtied the tree"
else
    ok "the second run was not refused"
fi

# The two runs above use --skip-pack, so they prove the script does not dirty
# the tree by itself; they do not build a Windows pack. The pack is the thing
# that actually dirtied it, and what made that possible was the artefact being
# a tracked file — so that is asserted directly, against THIS repository rather
# than the throwaway fixture, which has no services/kit to track.
if git -C "$REPO" ls-files --error-unmatch services/kit/akconnect-windows-test-pack.zip >/dev/null 2>&1; then
    bad "the Windows pack is tracked in git" \
        "a build output under version control dirties every checkout that builds one"
else
    ok "the Windows pack is not a tracked file"
fi

# And the build must honour a destination outside the tree, which is how
# upgrade-edge.sh keeps its checkout clean while still producing a pack.
if grep -q 'AKCONNECT_BUILD_DIR' "$REPO/services/kit/build-windows-pack.sh"; then
    ok "the pack build can write outside the source tree"
else
    bad "the pack build always writes into services/kit" "it will dirty the checkout again"
fi

# 9. The panel is BEHIND the checkout, and no arguments are given.
#
# Defect 27, three faults in the hand-over added for defect 25, all in one run
# on a real VPS:
#
#   target: 1.9.3 (55e6978be9fb ...)
#   the release carries a newer copy of this script; handing over to it
#   unknown option:
#
# and it stopped there. It handed over to the PANEL's copy although that copy
# was OLDER than the one running — the panel was behind the checkout — so the
# upgrade would have been driven by a script with a known signing defect and no
# hand-over guard. The exec passed one empty string, because "${ARR[@]:-}" on
# an empty array expands to exactly that, so the replacement was given an
# argument of "" and refused it. And refusing it exited with one line to stderr
# and no summary at all, from a script whose whole contract is ending in PASS
# or FAIL.
group "the panel is behind the checkout, and no arguments are given"

BEHIND_CLONE="$WORK/behind"
git clone --quiet "$ORIGIN" "$BEHIND_CLONE" 2>/dev/null

# An older copy of this script: the real thing with its revision line taken
# out, which is what every copy from before the hand-over existed looks like.
# It also has to DIFFER in content, not only in revision. The first version of
# this fixture built the older copy by stripping the SCRIPT_REVISION line —
# which the code being tested against did not have either, so the two files
# came out byte-identical, the old "they differ" rule never fired, and two of
# the three assertions passed against the defect they were written for.
{
    grep -v '^SCRIPT_REVISION=' "$SCRIPT"
    echo '# an older release of this script'
} > "$WORK/seed/deploy/upgrade-edge.sh"
chmod +x "$WORK/seed/deploy/upgrade-edge.sh"
printf '1.9.3
' > "$WORK/seed/VERSION"
git -C "$WORK/seed" add -A >/dev/null
git -C "$WORK/seed" -c user.email=g@l -c user.name=gate commit --quiet -m older
git -C "$WORK/seed" push --quiet origin HEAD:refs/heads/older 2>/dev/null
OLD_SHA="$(git -C "$WORK/seed" rev-parse HEAD)"

# The checkout keeps the current script; the panel names the older commit.
git -C "$BEHIND_CLONE" checkout --quiet main 2>/dev/null
cp "$SCRIPT" "$BEHIND_CLONE/deploy/upgrade-edge.sh"
git -C "$BEHIND_CLONE" -c user.email=g@l -c user.name=gate commit --quiet -am current >/dev/null 2>&1 || true

python3 "$LAB/capture-panel.py" 8794 "$WORK/cap3.jsonl" --secret "$CAP_SECRET" --version 1.9.3 --commit "$OLD_SHA" --branch older >"$WORK/panel3.log" 2>&1 &
CAP3_PID=$!
trap 'kill "$CAP_PID" "$CAP2_PID" "$CAP3_PID" 2>/dev/null; rm -rf "$WORK"' EXIT

for _ in $(seq 1 40); do
    (exec 3<>"/dev/tcp/127.0.0.1/8794") 2>/dev/null && break
    sleep 0.25
done

mkdir -p "$WORK/etc3"
cat > "$WORK/etc3/coordinator.env" <<ENVFILE
AKCONNECT_PANEL_URL=http://127.0.0.1:8794
AKCONNECT_COORDINATOR_SECRET=$CAP_SECRET
ENVFILE

# No arguments at all, exactly as reported.
env PATH="/usr/local/go/bin:$PATH" HOME="$WORK" \
    AKCONNECT_ETC="$WORK/etc3" AKCONNECT_SRC="$BEHIND_CLONE" \
    "$BEHIND_CLONE/deploy/upgrade-edge.sh" >"$WORK/behind.out" 2>&1
BEHIND_CODE=$?

if grep -q "unknown option" "$WORK/behind.out"; then
    bad "a run with no arguments was given one" \
        "\"\${ARR[@]:-}\" on an empty array expands to one empty string"
else
    ok "a run with no arguments passes no arguments on"
fi

if grep -q "handing over" "$WORK/behind.out"; then
    bad "it handed over to the panel's OLDER copy" \
        "that copy has the defects this one fixes, and cannot hand back"
else
    ok "it did not downgrade to the panel's older copy of itself"
fi

if [ -n "${EDGE_GATE_VERBOSE:-}" ]; then
    printf '      ── the run, for reference\n'
    sed 's/^/      /' "$WORK/behind.out" | head -14
fi

if grep -qE 'edge is NOT upgraded|step\(s\), all clean' "$WORK/behind.out"; then
    ok "and it printed a summary rather than exiting silently (exit $BEHIND_CODE)"
else
    bad "it exited without a summary (exit $BEHIND_CODE)" \
        "a run that does not say what it did is the false-pass class; see $WORK/behind.out"
fi

kill "$CAP3_PID" 2>/dev/null

# An unrecognised option must reach the table too, for the same reason.
env PATH="/usr/local/go/bin:$PATH" HOME="$WORK" \
    AKCONNECT_ETC="$WORK/etc3" AKCONNECT_SRC="$BEHIND_CLONE" \
    "$SCRIPT" --nonsense >"$WORK/badopt.out" 2>&1

if grep -q 'edge is NOT upgraded' "$WORK/badopt.out"; then
    ok "an unrecognised option is reported as a FAIL, not a bare line on stderr"
else
    bad "an unrecognised option exits without a summary" "see $WORK/badopt.out"
fi

# 10. The target release's pack builder predates AKCONNECT_BUILD_DIR.
#
# Defect 28. The script and the builder it runs come from two different
# releases whenever the panel is behind: the script is whatever the operator
# has, the builder is the target's. AKCONNECT_BUILD_DIR arrived in 1.9.4, so a
# target older than that ignored it, wrote into services/kit, and the new
# script looked in its temp directory and reported
#
#   windows installer  FAIL  the build reported success but no akconnect-setup.exe was found
#
# having dirtied the checkout on the way, so the next run refused as well.
#
# Everything around the pack step is stubbed here — go, systemctl, ss — because
# what is under test is which directory the script looks in and what it tidies
# up, not whether Go can compile. The real build is exercised by the release
# gate's own Windows pack step.
group "the target's pack builder predates AKCONNECT_BUILD_DIR"

SKEW="$WORK/skew"
mkdir -p "$SKEW/bin" "$SKEW/stub"

# A worktree of this repository, so the script under test runs against a real
# checkout with real git history.
git -C "$REPO" worktree add --quiet --detach "$SKEW/src" HEAD 2>/dev/null     || bad "could not create a worktree to test against"

if [ -d "$SKEW/src" ]; then
    # The target release's builder: the shape every release before 1.9.4 had.
    # It ignores AKCONNECT_BUILD_DIR and writes into the checkout.
    cat > "$SKEW/src/services/kit/build-windows-pack.sh" <<'OLDBUILDER'
#!/usr/bin/env bash
# Stands in for a pre-1.9.4 pack builder: writes into the source tree and has
# never heard of an output directory.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
mkdir -p "$ROOT/services/kit/pack"
printf 'setup
' > "$ROOT/services/kit/pack/akconnect-setup.exe"
printf 'agent
' > "$ROOT/services/kit/pack/akconnect-agent.exe"
printf 'zip
'   > "$ROOT/services/kit/akconnect-windows-test-pack.zip"
echo "  pack: $ROOT/services/kit/akconnect-windows-test-pack.zip"
OLDBUILDER
    chmod +x "$SKEW/src/services/kit/build-windows-pack.sh"

    # Tracked, as it was in those releases, so removing it leaves a deletion
    # the clean-up has to restore rather than a file it can just delete.
    printf 'zip
' > "$SKEW/src/services/kit/akconnect-windows-test-pack.zip"
    git -C "$SKEW/src" add -f services/kit/build-windows-pack.sh         services/kit/akconnect-windows-test-pack.zip >/dev/null 2>&1
    git -C "$SKEW/src" -c user.email=g@l -c user.name=gate commit --quiet -m "old pack builder"
    SKEW_SHA="$(git -C "$SKEW/src" rev-parse HEAD)"

    # go, systemctl and ss, stubbed. The "binaries" go build produces are
    # shell scripts that report the version the panel asked for, which is
    # exactly what the script checks before installing anything.
    cat > "$SKEW/stub/go" <<'GOSTUB'
#!/usr/bin/env bash
case "${1:-}" in
    version) echo "go version go1.24.7 linux/amd64" ;;
    build)
        out=""
        while [ $# -gt 0 ]; do
            [ "$1" = "-o" ] && { out="$2"; shift 2; continue; }
            shift
        done
        [ -n "$out" ] || exit 0
        printf '#!/bin/sh
echo "%s 9.9.9-capture"
' "$(basename "$out")" > "$out"
        chmod +x "$out"
        ;;
esac
GOSTUB
    printf '#!/usr/bin/env bash
[ "${1:-}" = "is-active" ] && { echo active; exit 0; }
exit 0
'         > "$SKEW/stub/systemctl"
    printf '#!/usr/bin/env bash
echo "UNCONN 0 0 0.0.0.0:8443 0.0.0.0:*"
echo "UNCONN 0 0 0.0.0.0:9000 0.0.0.0:*"
'         > "$SKEW/stub/ss"
    chmod +x "$SKEW/stub"/*

    python3 "$LAB/capture-panel.py" 8796 "$WORK/cap4.jsonl" --secret "$CAP_SECRET"         --version 9.9.9-capture --commit "$SKEW_SHA" --branch HEAD >"$WORK/panel4.log" 2>&1 &
    CAP4_PID=$!
    trap 'kill "$CAP_PID" "$CAP2_PID" "$CAP3_PID" "$CAP4_PID" 2>/dev/null; git -C "$REPO" worktree remove --force "$SKEW/src" 2>/dev/null; rm -rf "$WORK"' EXIT

    for _ in $(seq 1 40); do
        (exec 3<>"/dev/tcp/127.0.0.1/8796") 2>/dev/null && break
        sleep 0.25
    done

    mkdir -p "$WORK/etc4"
    cat > "$WORK/etc4/coordinator.env" <<ENVFILE
AKCONNECT_PANEL_URL=http://127.0.0.1:8796
AKCONNECT_COORDINATOR_SECRET=$CAP_SECRET
ENVFILE

    env PATH="$SKEW/stub:$PATH" HOME="$WORK"         AKCONNECT_ETC="$WORK/etc4" AKCONNECT_SRC="$SKEW/src"         AKCONNECT_BIN_DIR="$SKEW/bin" AKCONNECT_REEXEC=1         WINTUN_ZIP="$WORK/wintun.zip"         "$SCRIPT" >"$WORK/skew.out" 2>&1
    SKEW_CODE=$?

    if grep -q "no akconnect-setup.exe was found" "$WORK/skew.out"; then
        bad "the installer was not found" "the script looked in its own directory, not the builder's"
    elif grep -q "windows installer .*PASS" "$WORK/skew.out"; then
        ok "the installer the old builder produced was found"
    else
        ok "the pack step did not fail on the output path"
    fi

    DIRT="$(git -C "$SKEW/src" status --porcelain)"
    if [ -z "$DIRT" ]; then
        ok "and the old builder's output was cleaned out of the checkout"
    else
        bad "the checkout is dirty after a legacy pack build"             "$(printf '%s' "$DIRT" | head -3 | tr '
' ' ')"
    fi

    # The summary must not claim the edge is unchanged when the services were
    # installed and restarted and only a later step failed.
    if grep -q "the edge is NOT upgraded" "$WORK/skew.out"         && grep -q "installed .*PASS" "$WORK/skew.out"; then
        bad "the summary says the edge is NOT upgraded" "the coordinator and relay were installed"
    else
        ok "the summary does not claim the edge is unchanged when it is not"
    fi

    if [ -n "${EDGE_GATE_VERBOSE:-}" ]; then
        sed 's/^/      /' "$WORK/skew.out" | tail -20
    fi

    kill "$CAP4_PID" 2>/dev/null
fi

# --check is the other half of the contract: it reports and changes nothing.
# A "tell me whether I am behind" that moved the working tree would be a trap.
git -C "$CLONE" checkout --quiet --detach HEAD 2>/dev/null
BEFORE_CHECK="$(git -C "$CLONE" rev-parse HEAD)"

env PATH="/usr/local/go/bin:$PATH" HOME="$WORK" \
    AKCONNECT_ETC="$WORK/etc2" AKCONNECT_SRC="$CLONE" \
    "$CLONE/deploy/upgrade-edge.sh" --check >"$WORK/checkonly.out" 2>&1

if [ "$(git -C "$CLONE" rev-parse HEAD)" = "$BEFORE_CHECK" ]; then
    ok "--check reports without moving the checkout"
else
    bad "--check moved the checkout" "a read-only option must not touch the working tree"
fi

kill "$CAP2_PID" 2>/dev/null

# ---------------------------------------------------------------- the report

printf '\n  ────────────────────────────────────────────────────────────\n'
printf '  %d passed, %d failed\n\n' "$PASS" "$FAIL"

[ "$FAIL" -eq 0 ]
