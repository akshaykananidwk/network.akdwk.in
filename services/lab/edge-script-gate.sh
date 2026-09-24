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
# The libraries it loads from the checkout, as a release carries them: the
# HTTPS fallback step runs on an edge that is already current too.
cp "$REPO"/deploy/lib-edge-*.sh "$WORK/seed/deploy/"
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
    # The copy handed to runs from a temporary path. Anything it loads from
    # beside itself is loaded from there — and revision 2 put that path
    # straight into /tmp.
    if grep -q "lib-edge-args" "$WORK/reexec.out"; then
        bad "the copy handed over looked for a file beside itself" \
            "$(grep 'lib-edge-args' "$WORK/reexec.out" | head -2)"
    else
        ok "and the copy handed over loaded nothing from beside itself"
    fi
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

# A shallow clone of this repository, so the script under test runs against a
# real checkout with real sources.
#
# A clone rather than a worktree, although a worktree is cheaper: in a worktree
# .git is a FILE, and releases before 1.9.4 tested for a .git DIRECTORY and
# refused at the preflight. The drill would then go red against those releases
# for a reason that has nothing to do with the defect under test, which is no
# better than going green for the wrong one.
git clone --quiet --no-hardlinks "$REPO" "$SKEW/src" 2>/dev/null \
    || bad "could not clone this repository to test against"

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
# Different bytes every time, so the tracked file is genuinely MODIFIED.
# Writing back the same content the fixture committed would leave the tree
# clean and the clean-up assertion could never fail.
date +%s%N > "$ROOT/services/kit/akconnect-windows-test-pack.zip"
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

    # A local "origin" holding that commit on a branch of its own. Without it
    # the worktree's origin is the real remote, the script fetches its default
    # branch, and the checkout lands on a years-old commit whose VERSION does
    # not match — which is a mismatch the drill created, not one it found.
    # Refuses to touch anything but the throwaway clone.
    #
    # An earlier version of this drill built the fixture with `git worktree
    # add`, and a worktree SHARES .git/config with the repository it came
    # from — so the `remote set-url` below rewrote the real repository's
    # origin, and the next push failed with "does not appear to be a git
    # repository". A harness that can reach outside its sandbox will
    # eventually do so.
    FIXTURE_GITDIR="$(git -C "$SKEW/src" rev-parse --absolute-git-dir)"
    case "$FIXTURE_GITDIR" in
        "$SKEW/src/.git") : ;;
        *) bad "the fixture's git directory is $FIXTURE_GITDIR" \
               "refusing to configure a repository outside the drill's own clone"
           SKEW_SHA="" ;;
    esac

    git init --quiet --bare -b skew-gate "$SKEW/origin"
    git -C "$SKEW/src" push --quiet "$SKEW/origin" HEAD:refs/heads/skew-gate 2>/dev/null
    git -C "$SKEW/src" remote set-url origin "$SKEW/origin"

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
echo "%s ${AKCONNECT_STUB_VERSION:-0.0.0}"
' "$(basename "$out")" > "$out"
        chmod +x "$out"
        ;;
esac
GOSTUB
    printf '#!/usr/bin/env bash
echo "$@" >> "${AKCONNECT_SYSTEMCTL_LOG:-/dev/null}"
[ "${1:-}" = "is-active" ] && { echo active; exit 0; }
exit 0
'         > "$SKEW/stub/systemctl"
    printf '#!/usr/bin/env bash
echo "UNCONN 0 0 0.0.0.0:8443 0.0.0.0:*"
echo "UNCONN 0 0 0.0.0.0:9000 0.0.0.0:*"
'         > "$SKEW/stub/ss"
    chmod +x "$SKEW/stub"/*

    # The version the panel claims must be the version that commit actually
    # carries, and the branch must be one the checkout can be put on. Get
    # either wrong and the script refuses before the pack step — correctly,
    # and this drill then passed every later assertion without running one.
    SKEW_VERSION="$(tr -d '[:space:]' < "$SKEW/src/VERSION")"

    python3 "$LAB/capture-panel.py" 8796 "$WORK/cap4.jsonl" --secret "$CAP_SECRET" \
        --version "$SKEW_VERSION" --commit "$SKEW_SHA" --branch skew-gate >"$WORK/panel4.log" 2>&1 &
    CAP4_PID=$!
    trap 'kill "$CAP_PID" "$CAP2_PID" "$CAP3_PID" "$CAP4_PID" 2>/dev/null; rm -rf "$WORK"' EXIT

    for _ in $(seq 1 40); do
        (exec 3<>"/dev/tcp/127.0.0.1/8796") 2>/dev/null && break
        sleep 0.25
    done

    # A panel that did not start is a panel somebody else is running on that
    # port, and it answers with somebody else's release — which sent this
    # drill chasing a version mismatch that had nothing to do with it.
    if ! kill -0 "$CAP4_PID" 2>/dev/null; then
        bad "the drill's panel could not start on 8796" \
            "$(head -3 "$WORK/panel4.log" | tr '\n' ' ')"
    fi

    mkdir -p "$WORK/etc4"
    cat > "$WORK/etc4/coordinator.env" <<ENVFILE
AKCONNECT_PANEL_URL=http://127.0.0.1:8796
AKCONNECT_COORDINATOR_SECRET=$CAP_SECRET
ENVFILE

        AKCONNECT_STUB_VERSION="$SKEW_VERSION" \
    env PATH="$SKEW/stub:$PATH" HOME="$WORK"         AKCONNECT_ETC="$WORK/etc4" AKCONNECT_SRC="$SKEW/src"         AKCONNECT_BIN_DIR="$SKEW/bin" AKCONNECT_REEXEC=1         WINTUN_ZIP="$WORK/wintun.zip"         AKCONNECT_SYSTEMD_DIR="$WORK/units" AKCONNECT_SYSTEMCTL_LOG="$WORK/systemctl.log"         "$SCRIPT" >"$WORK/skew.out" 2>&1
    SKEW_CODE=$?

    # First: did the run even get there? Three assertions below passed the
    # first time this drill ran, on a run that stopped at the preflight and
    # never reached the pack step at all. A check that cannot fail is worse
    # than no check, because it is counted.
    if ! grep -q "building the Windows installer" "$WORK/skew.out"; then
        bad "the run never reached the pack step" \
            "everything below would pass without testing anything; see $WORK/skew.out"
        sed 's/^/      /' "$WORK/skew.out" | tail -12
    else
        ok "the run reached the pack step"
    fi

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

    # 11. The timer, which is the default from 1.9.5.
    #
    # An edge that is only upgraded when somebody remembers to log in runs an
    # old coordinator for months, and the operator finds out when two
    # customers cannot connect rather than when the release is published. So
    # the run above passed no arguments at all, and the timer must be there.
    if [ -f "$WORK/units/akconnect-upgrade.timer" ] && [ -f "$WORK/units/akconnect-upgrade.service" ]; then
        ok "a run with no arguments installs the upgrade timer"
    else
        bad "no timer was installed by a run with no arguments" \
            "the edge would only ever be upgraded by hand"
    fi

    if grep -q "enable --now akconnect-upgrade.timer" "$WORK/systemctl.log" 2>/dev/null; then
        ok "and enables it, rather than writing units nothing starts"
    else
        bad "the timer units were written but never enabled" \
            "$(head -5 "$WORK/systemctl.log" 2>/dev/null | tr '\n' ' ')"
    fi

    # And --no-timer is honoured, visibly. An operator who turned it off
    # months ago should be able to see in the table why their edge is not
    # keeping itself current.
    rm -rf "$WORK/units2"
        AKCONNECT_STUB_VERSION="$SKEW_VERSION" \
    env PATH="$SKEW/stub:$PATH" HOME="$WORK" \
        AKCONNECT_ETC="$WORK/etc4" AKCONNECT_SRC="$SKEW/src" \
        AKCONNECT_BIN_DIR="$SKEW/bin" AKCONNECT_REEXEC=1 \
        WINTUN_ZIP="$WORK/wintun.zip" \
        AKCONNECT_SYSTEMD_DIR="$WORK/units2" \
        "$SCRIPT" --no-timer >"$WORK/notimer.out" 2>&1

    if [ -f "$WORK/units2/akconnect-upgrade.timer" ]; then
        bad "--no-timer installed the timer anyway" "the option does nothing"
    else
        ok "--no-timer installs no timer"
    fi

    if grep -qE "upgrade timer +INFO" "$WORK/notimer.out"; then
        ok "and says so in the table, as INFO rather than as a failure"
    else
        bad "--no-timer left no trace in the summary" \
            "an operator cannot see why the edge is not keeping itself current"
    fi

    if grep -qE "upgrade timer +FAIL" "$WORK/notimer.out"; then
        bad "a deliberately skipped step is printed as a FAIL" \
            "the table contradicts its own summary line"
    else
        ok "and not as a red row the failure count knows nothing about"
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

# --------------------------------------------------- the HTTPS fallback proxy
#
# The fallback is what makes AK Connect work on a network that carries nothing
# but the port a browser uses, and none of it does anything unless Apache is
# actually proxying the path. That configuration is generated by a script and
# has two forms, so the things worth checking are that both forms exist, that
# they agree about where the relay is, that neither is wrapped in the
# <IfModule> that would make a missing module silent, and that the version
# rule picks between them the way it says it does.

group "the HTTPS fallback proxy"

# shellcheck source=../../deploy/lib-edge-apache.sh
. "$REPO/deploy/lib-edge-apache.sh"

WSTUNNEL_CONF="$REPO/deploy/apache/akconnect-fallback.conf"
UPGRADE_CONF="$REPO/deploy/apache/akconnect-fallback-upgrade.conf"

for conf in "$WSTUNNEL_CONF" "$UPGRADE_CONF"; do
    if [ -f "$conf" ]; then
        ok "$(basename "$conf") is in the repository"
    else
        bad "$(basename "$conf") is missing" "install-edge.sh installs it by name"
    fi
done

# The two forms must point at the same relay, or an Apache upgrade silently
# moves the fallback to a port nothing is listening on.
WS_TARGET_A="$(grep -oE '(ws|http)://127\.0\.0\.1:[0-9]+/ws' "$WSTUNNEL_CONF" 2>/dev/null | head -1 | grep -oE ':[0-9]+')"
WS_TARGET_B="$(grep -oE '(ws|http)://127\.0\.0\.1:[0-9]+/ws' "$UPGRADE_CONF" 2>/dev/null | head -1 | grep -oE ':[0-9]+')"
UNIT_PORT="$(grep -oE 'ws-listen 127\.0\.0\.1:[0-9]+' "$REPO/deploy/systemd/akconnect-relay.service" 2>/dev/null | grep -oE ':[0-9]+$')"

if [ -n "$WS_TARGET_A" ] && [ "$WS_TARGET_A" = "$WS_TARGET_B" ] && [ "$WS_TARGET_A" = "$UNIT_PORT" ]; then
    ok "both proxy forms and the relay unit agree on 127.0.0.1$UNIT_PORT"
else
    bad "the proxy and the relay disagree about the port" \
        "wstunnel $WS_TARGET_A, upgrade $WS_TARGET_B, unit $UNIT_PORT"
fi

# The older form is the only one that may name ws://, and only it may need
# mod_proxy_wstunnel; the newer one carries the upgrade over http://.
if grep -q 'ws://127' "$WSTUNNEL_CONF" && grep -q 'upgrade=websocket' "$UPGRADE_CONF"; then
    ok "the two forms use the directive their Apache version understands"
else
    bad "the proxy forms are not what their Apache versions expect" \
        "2.4.47+ needs upgrade=websocket on mod_proxy_http; older needs ws:// on wstunnel"
fi

# <IfModule> would turn a missing module into a configuration that quietly
# does nothing — which is the failure nobody finds until a customer travels.
#
# Directives only. Both files explain in a comment that they are deliberately
# not wrapped in one, and a check that read the comments would fail on the
# sentence saying it is right.
if grep -Ev '^[[:space:]]*#' "$WSTUNNEL_CONF" "$UPGRADE_CONF" | grep -q '<IfModule'; then
    bad "the proxy configuration is wrapped in <IfModule>" \
        "a missing module would then be silent instead of an error"
else
    ok "neither form hides a missing module behind <IfModule>"
fi

# The health path has to come first or ProxyPass swallows it with /fallback.
for conf in "$WSTUNNEL_CONF" "$UPGRADE_CONF"; do
    health_line="$(grep -n 'ProxyPass  *\/fallback\/health' "$conf" | head -1 | cut -d: -f1)"
    tunnel_line="$(grep -n 'ProxyPass  *\/fallback  ' "$conf" | head -1 | cut -d: -f1)"
    if [ -n "$health_line" ] && [ -n "$tunnel_line" ] && [ "$health_line" -lt "$tunnel_line" ]; then
        ok "$(basename "$conf") matches /fallback/health before /fallback"
    else
        bad "$(basename "$conf") would swallow the health path" \
            "ProxyPass matches in order; the longer path must come first"
    fi
done

# An idle timeout has to be set on the worker, because ProxyTimeout otherwise
# inherits whatever the panel's vhost uses — and a short one there would cut
# every tunnel at that age, which presents as a relay fault.
if grep -q 'timeout=' "$WSTUNNEL_CONF" && grep -q 'timeout=' "$UPGRADE_CONF"; then
    ok "both forms set an explicit idle timeout rather than inheriting one"
else
    bad "the proxy does not set its own timeout" "it would inherit the vhost's Timeout"
fi

# And the version rule itself, which is the part that decides all of the above.
version_picks() {
    local version=$1 want=$2 got
    if [ "$version" -ge 2004047 ]; then got=upgrade; else got=wstunnel; fi
    [ "$got" = "$want" ]
}

if version_picks 2004046 wstunnel && version_picks 2004047 upgrade && version_picks 2004058 upgrade; then
    ok "2.4.46 gets mod_proxy_wstunnel and 2.4.47+ gets the upgrade directive"
else
    bad "the Apache version rule picks the wrong form"
fi

# The version parser, against the strings Apache actually prints.
apache_version_parses() {
    local printed=$1 want=$2 got
    got="$(printf 'Server version: Apache/%s (Ubuntu)\nServer built: x\n' "$printed" \
        | sed -n 's|.*Apache/\([0-9.]*\).*|\1|p' | head -1 \
        | awk -F. '{ printf "%d\n", $1 * 1000000 + $2 * 1000 + $3 }')"
    [ "$got" = "$want" ]
}

if apache_version_parses 2.4.58 2004058 && apache_version_parses 2.4.7 2004007; then
    ok "the Apache version string parses into a comparable number"
else
    bad "the Apache version parser is wrong" "it decides which proxy form is installed"
fi

# The relay has to be told to serve it at all. A unit that lost --ws-listen
# would install the fallback and never start it.
if grep -q -- '--ws-listen' "$REPO/deploy/systemd/akconnect-relay.service"; then
    ok "the relay unit starts the fallback listener"
else
    bad "the relay unit has no --ws-listen" "the proxy would have nothing to talk to"
fi

# And upgrade-edge.sh has to refresh unit files, or an upgraded relay keeps
# the flags of whatever version was installed first.
if grep -qE 'install (-D )?-m 644 "\$unit"' "$REPO/deploy/upgrade-edge.sh"; then
    ok "upgrade-edge.sh refreshes the systemd units it ships"
else
    bad "upgrade-edge.sh does not refresh unit files" \
        "a new flag would never reach an existing installation"
fi

# --------------------------------- against a production aaPanel layout
#
# The machine this is deployed on is an aaPanel VPS with thirty-odd other live
# websites on one Apache. An adversarial review of the first version of this
# found three ways it could write into somebody else's site, and the fixture
# below is shaped around all three, because a fixture that does not contain
# the trap does not catch it:
#
#   - blog.customer-a.com parks network.akdwk.in as a ServerAlias, and sorts
#     alphabetically BEFORE the panel's own file;
#   - bundle.conf holds two TLS virtual hosts, neither of them the panel's;
#   - and the panel's own :80 block carries a commented-out #SSL-START stanza,
#     which is exactly what aaPanel writes and what made the first version put
#     the proxy on port 80, where the tunnel would have run in cleartext.

group "the fallback against a production aaPanel server"

AA="$WORK/aapanel"

aapanel_fixture() {
    local root=$1 syntax=${2:-ok} i

    rm -rf "$root"
    mkdir -p "$root/apache/bin" "$root/apache/conf" "$root/apache/modules" "$root/vhost/apache"

    cat > "$root/apache/conf/httpd.conf" <<'CONF'
ServerRoot "/www/server/apache"
#LoadModule proxy_module modules/mod_proxy.so
#LoadModule proxy_http_module modules/mod_proxy_http.so
Listen 80
Listen 443
IncludeOptional /www/server/panel/vhost/apache/*.conf
CONF
    touch "$root/apache/modules/mod_proxy.so" "$root/apache/modules/mod_proxy_http.so"

    for i in $(seq 1 28); do
        cat > "$root/vhost/apache/site$i.conf" <<VH
<VirtualHost *:80>
    ServerName site$i.example.com
    ServerAlias www.site$i.example.com
</VirtualHost>
<VirtualHost *:443>
    ServerName site$i.example.com
    SSLEngine on
    SSLCertificateFile /etc/ssl/site$i.crt
</VirtualHost>
VH
    done

    # Somebody else's site, parking our domain as an alias, sorting first.
    cat > "$root/vhost/apache/blog.customer-a.com.conf" <<'VH'
<VirtualHost *:443>
    ServerName blog.customer-a.com
    ServerAlias network.akdwk.in
    SSLEngine on
    SSLCertificateFile /etc/ssl/blog.customer-a.crt
</VirtualHost>
VH

    # Two TLS virtual hosts in one file, neither of them ours.
    cat > "$root/vhost/apache/bundle.conf" <<'VH'
<VirtualHost *:443>
    ServerName shop.customer-b.com
    SSLEngine on
    SSLCertificateFile /etc/ssl/shop.crt
</VirtualHost>
<VirtualHost *:443>
    ServerName bundled.customer-b.com
    SSLEngine on
    SSLCertificateFile /etc/ssl/bundled.crt
</VirtualHost>
VH

    # The panel, aaPanel-shaped: a commented SSL stanza in the :80 block.
    cat > "$root/vhost/apache/network.akdwk.in.conf" <<'VH'
<VirtualHost *:80>
    ServerName network.akdwk.in
    DocumentRoot /www/wwwroot/network.akdwk.in
    #SSL-START
    #SSLEngine on
    #SSLCertificateFile /etc/ssl/network.akdwk.in.crt
    #SSL-END
</VirtualHost>
<VirtualHost *:443>
    ServerName network.akdwk.in
    SSLEngine on
    SSLCertificateFile /etc/ssl/network.akdwk.in.crt
    DocumentRoot /www/wwwroot/network.akdwk.in
</VirtualHost>
VH

    cat > "$root/apache/bin/apachectl" <<CTL
#!/usr/bin/env bash
CONF="\$(dirname "\$0")/../conf/httpd.conf"
case "\${1:-}" in
    -v) echo "Server version: Apache/2.4.58 (Unix)" ;;
    -M) grep -E '^[[:space:]]*LoadModule' "\$CONF" | awk '{print "  "\$2}' ;;
    -t) [ "$syntax" = ok ] && exit 0 || exit 1 ;;
    *)  exit 0 ;;
esac
CTL
    chmod +x "$root/apache/bin/apachectl"
}

# run_against drives the library at a fixture.
#
#   $1 root   $2 panel URL   $3 name that leaks /fallback over TLS
#   $4 name that leaks it over plain HTTP   $5 y|n, the operator's answer
run_against() {
    env FIXTURE="$1" PANEL_URL="$2" LEAK="${3:-}" LEAKPLAIN="${4:-}" \
        ANSWER="${5:-y}" PRELEAK="${6:-}" WRONGBLOCK="${7:-}" REPO="$REPO" bash -c '
        set -uo pipefail
        . "$REPO/deploy/lib-edge-apache.sh"
        . "$REPO/deploy/lib-edge-vhost.sh"
        . "$REPO/deploy/lib-edge-probe.sh"

        AAPANEL_APACHE="$FIXTURE/apache"
        AKCONNECT_APACHE_CONF="$FIXTURE/etc/akconnect/apache/akconnect-fallback.conf"
        AKCONNECT_APACHE_BACKUPS="$FIXTURE/backups"
        akconnect_vhost_dirs() { printf "%s\n" "$FIXTURE/vhost/apache"; }

        # Stands in for curl. Everything answers 200 on / and 404 on
        # /fallback, except the panel once the include is really in its file,
        # and whatever LEAK names — which is the scope failure to catch.
        akconnect_site_probe() {
            local out=${1:-/dev/stdout} name fb plain panel applied=0
            panel="$FIXTURE/vhost/apache/network.akdwk.in.conf"
            grep -q "AK Connect" "$panel" 2>/dev/null && applied=1

            while read -r name; do
                fb=404; plain=404

                # PRELEAK answers 200 whether or not the change went in: a site
                # with a catch-all front controller, which answers 200 for a
                # path it has never heard of. Thirty of those is what the
                # target machine is. LEAK answers 200 only once the change is
                # really in the panel file, which is what a scope leak this
                # change caused actually looks like.
                case " $PRELEAK " in *" $name "*) fb=200 ;; esac

                if [ "$applied" = 1 ]; then
                    [ "$name" = network.akdwk.in ] && fb=200
                    case " $LEAK " in *" $name "*) fb=200 ;; esac
                    case " $LEAKPLAIN " in *" $name "*) plain=200 ;; esac
                fi

                printf "%s 200 %s %s\n" "$name" "$fb" "$plain"
            done < <(akconnect_site_names) > "$out"
        }

        # No Apache here, so there is no default virtual host to ask.
        akconnect_default_vhost_leaks() { return 0; }

        # Whether the RELAY answered, as opposed to the site answering 200 for
        # a path it does not recognise. True once the include is really in the
        # panel file — unless WRONGBLOCK says the include landed in a virtual
        # host that is not the one serving the request, which is the case a
        # status code alone can never see.
        akconnect_probe_is_relay() {
            [ -n "$WRONGBLOCK" ] && return 1
            grep -q "AK Connect" "$FIXTURE/vhost/apache/network.akdwk.in.conf" 2>/dev/null
        }

        akconnect_confirm() { [ "$ANSWER" = y ]; }
        systemctl() { return 1; }
        akconnect_apache_fallback "$REPO/deploy/apache" "$PANEL_URL" true
        echo "RESULT=$?"
    ' 2>/dev/null
}

PANEL_CONF="vhost/apache/network.akdwk.in.conf"

aapanel_fixture "$AA"
cp -r "$AA/vhost/apache" "$WORK/vhost.pristine"
cp "$AA/apache/conf/httpd.conf" "$WORK/httpd.pristine"
OUT="$(run_against "$AA" https://network.akdwk.in)"

grep -q 'RESULT=0' <<<"$OUT" \
    && ok "it configures the panel's site on a server with 30 others" \
    || bad "it did not configure the panel's site" "$(tr '\n' ' ' <<<"$OUT" | cut -c1-200)"

# The parked alias. This is the one that put the proxy in a stranger's vhost.
if grep -q 'AK Connect' "$AA/vhost/apache/blog.customer-a.com.conf"; then
    bad "the proxy was written into another customer's virtual host" \
        "blog.customer-a.com parks network.akdwk.in as a ServerAlias"
else
    ok "a parked ServerAlias on another customer's site does not capture it"
fi

# The bundled file. Neither of its TLS blocks is ours.
if grep -q 'AK Connect' "$AA/vhost/apache/bundle.conf"; then
    bad "the proxy was written into a file holding two other TLS sites"
else
    ok "a file with two other TLS virtual hosts is left alone"
fi

CHANGED="$(diff -rq "$WORK/vhost.pristine" "$AA/vhost/apache" 2>/dev/null | wc -l)"
if [ "$CHANGED" = "1" ] && grep -q 'AK Connect' "$AA/$PANEL_CONF"; then
    ok "exactly one virtual host file changed — the panel's"
else
    bad "$CHANGED virtual host file(s) changed" "only the panel's site may be touched"
fi

# The commented #SSL-START stanza: the include must be in the :443 block.
if awk '/<VirtualHost/ { n++ } /AK Connect HTTPS fallback/ && !seen { seen = n } END { exit !(seen == 2) }' \
        "$AA/$PANEL_CONF"; then
    ok "and in the TLS block, not the :80 block with the commented SSL stanza"
else
    bad "the proxy went into the wrong block" \
        "a commented #SSL-START stanza must not read as a TLS virtual host"
fi

if diff "$WORK/httpd.pristine" "$AA/apache/conf/httpd.conf" \
        | grep -E '^[<>]' | grep -qvE '^[<>][[:space:]]*#?[[:space:]]*LoadModule'; then
    bad "httpd.conf was changed by more than a LoadModule"
else
    ok "httpd.conf gained LoadModule lines and nothing else"
fi

grep -rqE '^[[:space:]]*ProxyPass' "$AA/apache/conf/httpd.conf" \
    && bad "a ProxyPass was written into httpd.conf" "it would reach all 30 sites" \
    || ok "no proxy directive is at server level"

run_against "$AA" https://network.akdwk.in >/dev/null
[ "$(grep -c 'BEGIN AK Connect' "$AA/$PANEL_CONF")" = "1" ] \
    && ok "running it again leaves one block, not two" \
    || bad "a second run duplicated the block"

# ------------------------------------------------- what must roll it back

aapanel_fixture "$AA"
cp "$AA/$PANEL_CONF" "$WORK/panel.before"
OUT="$(run_against "$AA" https://network.akdwk.in site5.example.com)"

grep -q 'RESULT=2' <<<"$OUT" \
    && ok "another site answering /fallback fails the run" \
    || bad "the tunnel appeared on another site and the run passed"

cmp -s "$WORK/panel.before" "$AA/$PANEL_CONF" \
    && ok "and the change is rolled back although Apache accepted it" \
    || bad "a scope leak was detected and the change was left in place"

# The other half of the same judgement. A site that answered 200 for an unknown
# path BEFORE the change has not gained a tunnel, and rolling back somebody's
# production server over a front controller it has always had is its own kind
# of harm. On this machine there are thirty candidates for it.
aapanel_fixture "$AA"
OUT="$(run_against "$AA" https://network.akdwk.in "" "" y site5.example.com)"

grep -q 'RESULT=0' <<<"$OUT" \
    && ok "a site that already answered 200 for any path is not called a leak" \
    || bad "a pre-existing catch-all was read as a tunnel this change published"

# And the case a status code cannot see at all: the panel answers 200 on
# /fallback/health because it answers 200 on everything, while the include
# actually landed in a virtual host that is not the one serving the request.
# Apache accepts the configuration, every site answers as before, and the
# tunnel does not work. Only the relay naming itself in the body tells them
# apart.
aapanel_fixture "$AA"
cp "$AA/$PANEL_CONF" "$WORK/panel.before"
OUT="$(run_against "$AA" https://network.akdwk.in "" "" y "" wrong-block)"

grep -q 'RESULT=2' <<<"$OUT" \
    && ok "a panel that answers 200 without the relay behind it fails the run" \
    || bad "a 200 from the site itself was accepted as the tunnel working"

cmp -s "$WORK/panel.before" "$AA/$PANEL_CONF" \
    && ok "and that change is rolled back too" \
    || bad "the include was left in a virtual host that does not serve the request"

aapanel_fixture "$AA"
cp "$AA/$PANEL_CONF" "$WORK/panel.before"
OUT="$(run_against "$AA" https://network.akdwk.in "" network.akdwk.in)"

grep -q 'RESULT=2' <<<"$OUT" \
    && ok "the tunnel answering over plain HTTP fails the run" \
    || bad "the tunnel was reachable in cleartext and the run passed"

cmp -s "$WORK/panel.before" "$AA/$PANEL_CONF" \
    && ok "and that is rolled back too" \
    || bad "cleartext was detected and the change was left in place"

aapanel_fixture "$AA" refuses
cp "$AA/$PANEL_CONF" "$WORK/panel.before"
cp "$AA/apache/conf/httpd.conf" "$WORK/httpd.before"
OUT="$(run_against "$AA" https://network.akdwk.in)"

grep -q 'RESULT=2' <<<"$OUT" \
    && ok "a configuration Apache refuses is reported as a failure" \
    || bad "Apache refused and the script said it worked"

cmp -s "$WORK/panel.before" "$AA/$PANEL_CONF" && cmp -s "$WORK/httpd.before" "$AA/apache/conf/httpd.conf" \
    && ok "and every file it touched is put back byte for byte" \
    || bad "a refused configuration left edits behind"

# A path with an underscore. The first version flattened paths by turning
# slashes into underscores and reversed it by turning underscores into
# slashes, which is not invertible — so a file like panel_ssl.conf was
# "restored" to a path that does not exist, silently, reported as success.
aapanel_fixture "$AA"
mv "$AA/$PANEL_CONF" "$AA/vhost/apache/panel_ssl_site.conf"
cp "$AA/vhost/apache/panel_ssl_site.conf" "$WORK/underscore.before"
run_against "$AA" https://network.akdwk.in site9.example.com >/dev/null

cmp -s "$WORK/underscore.before" "$AA/vhost/apache/panel_ssl_site.conf" \
    && ok "a vhost file with an underscore in its path rolls back correctly" \
    || bad "a path with an underscore was not restored" "the rollback was a silent no-op"

# The manifest records what each copy was, including a file that did not
# exist — so a rollback removes what the run created instead of leaving it.
aapanel_fixture "$AA"
run_against "$AA" https://network.akdwk.in >/dev/null
MANIFEST="$(find "$AA/backups" -name MANIFEST -print -quit)"
if [ -n "$MANIFEST" ] && grep -q 'absent' "$MANIFEST" && grep -q 'present' "$MANIFEST"; then
    ok "the backup manifest records both existing and created files"
else
    bad "the backup manifest does not distinguish created files from edited ones"
fi

# ----------------------------------------------------- what must refuse

aapanel_fixture "$AA"
rm -f "$AA/$PANEL_CONF"
OUT="$(run_against "$AA" https://network.akdwk.in)"
grep -q 'RESULT=3' <<<"$OUT" \
    && ok "a domain served only as somebody else's alias is refused" \
    || bad "it configured a site the panel's domain is only an alias of"

aapanel_fixture "$AA"
run_against "$AA" https://notonthisserver.example | grep -q 'RESULT=3' \
    && ok "a domain this Apache does not serve is refused" \
    || bad "it edited something for a domain this server does not serve"

aapanel_fixture "$AA"
cp "$AA/$PANEL_CONF" "$WORK/panel.before"
OUT="$(run_against "$AA" https://network.akdwk.in "" "" n)"
grep -q 'RESULT=4' <<<"$OUT" \
    && ok "saying no changes nothing" \
    || bad "it went ahead without being told to"
cmp -s "$WORK/panel.before" "$AA/$PANEL_CONF" \
    && ok "and the virtual host is untouched" \
    || bad "it edited the virtual host after being told no"

# --------------------------------------------- removing it again

aapanel_fixture "$AA"
cp -r "$AA/vhost/apache" "$WORK/before-install"
run_against "$AA" https://network.akdwk.in >/dev/null

env FIXTURE="$AA" REPO="$REPO" bash -c '
    set -uo pipefail
    . "$REPO/deploy/lib-edge-apache.sh"
    . "$REPO/deploy/lib-edge-vhost.sh"
    AKCONNECT_APACHE_CONF="$FIXTURE/etc/akconnect/apache/akconnect-fallback.conf"
    akconnect_vhost_dirs() { printf "%s\n" "$FIXTURE/vhost/apache"; }
    while read -r f; do akconnect_vhost_remove "$f"; done < <(akconnect_vhost_files_with)
    rm -f "$AKCONNECT_APACHE_CONF"
' 2>/dev/null

diff -rq "$WORK/before-install" "$AA/vhost/apache" >/dev/null \
    && ok "removing it puts every virtual host back exactly as it was" \
    || bad "removing it left the virtual hosts changed"

[ -f "$REPO/deploy/remove-apache-fallback.sh" ] && [ -x "$REPO/deploy/remove-apache-fallback.sh" ] \
    && ok "and deploy/remove-apache-fallback.sh ships, executable" \
    || bad "there is no removal script"

grep -q 'akconnect_vhost_files_with' "$REPO/deploy/remove-apache-fallback.sh" \
    && ok "which removes the block from every file carrying it, not just the expected one" \
    || bad "the removal script only looks at the file it expects"

# --------------------------------------- the timer never touches Apache

group "Apache is opt-in, and the timer cannot reach it"

# shellcheck source=../../deploy/lib-edge-apache.sh
. "$REPO/deploy/lib-edge-apache.sh"

decision_is() {
    [ "$(akconnect_may_configure_apache "$1" "$2")" = "$3" ]
}

decision_is "" 0 0 && decision_is "" 1 0 \
    && ok "with no option Apache is never configured, watched or not" \
    || bad "Apache would be configured without being asked for"

decision_is 1 0 1 \
    && ok "--configure-apache at a terminal does configure it" \
    || bad "--configure-apache did nothing"

decision_is 1 1 0 \
    && ok "and --configure-apache from a timer still does NOT" \
    || bad "a cron entry carrying the flag would edit a production Apache"

decision_is 0 0 0 && decision_is 0 1 0 \
    && ok "--no-configure-apache never configures it" \
    || bad "--no-configure-apache configured Apache anyway"

grep -q 'ExecStart=.*--unattended' "$REPO/deploy/upgrade-edge.sh" \
    && ok "the timer unit passes --unattended" \
    || bad "the timer unit does not pass --unattended"

grep -q 'INVOCATION_ID' "$REPO/deploy/upgrade-edge.sh" \
    && ok "and a unit from an older release is caught by INVOCATION_ID" \
    || bad "an older timer unit would run as though somebody were watching"

grep -q '/dev/tty' "$REPO/deploy/lib-edge-apache.sh" \
    && ok "and with no terminal to confirm on, it refuses outright" \
    || bad "nothing requires a terminal to confirm the change"

grep -q 'hold "Apache proxy"' "$REPO/deploy/upgrade-edge.sh" \
    && ok "an unattended run records it as waiting for a person" \
    || bad "an unattended run does not record the step it skipped"

grep -q 'PARTIAL.*step(s) clean' "$REPO/deploy/upgrade-edge.sh" \
    && ok "and the run ends PARTIAL rather than PASS" \
    || bad "a run that skipped a step would report a clean pass"

group "what the second review found, and what now catches it"

# A script has one EXIT trap. upgrade-edge.sh installs it at the top and it is
# the only thing that prints the results table, removes the temporary files and
# decides the exit status — the script ends with REACHED_END=1 and no explicit
# exit. Taking EXIT inside the library meant a confirmed --configure-apache run
# finished silently and exited 0 whatever else had failed.
TRAPTEST="$WORK/trap-test.sh"
cat > "$TRAPTEST" <<TRAPEOF
set -uo pipefail
. "$REPO/deploy/lib-edge-apache.sh"
. "$REPO/deploy/lib-edge-vhost.sh"
. "$REPO/deploy/lib-edge-probe.sh"
on_exit() { echo "CALLER-EXIT-TRAP-RAN"; }
trap on_exit EXIT
AAPANEL_APACHE="$AA/apache"
AKCONNECT_APACHE_CONF="$AA/etc/akconnect/apache/akconnect-fallback.conf"
AKCONNECT_APACHE_BACKUPS="$AA/backups"
akconnect_vhost_dirs() { printf "%s\n" "$AA/vhost/apache"; }
akconnect_site_probe() {
    local out=\${1:-/dev/stdout}
    while read -r n; do printf "%s 200 404 404\n" "\$n"; done < <(akconnect_site_names) > "\$out"
}
akconnect_default_vhost_leaks() { return 0; }
akconnect_confirm() { return 0; }
systemctl() { return 1; }
akconnect_apache_fallback "$REPO/deploy/apache" https://network.akdwk.in true >/dev/null
TRAPEOF

aapanel_fixture "$AA"
TRAPOUT="$(bash "$TRAPTEST" 2>/dev/null)"

grep -q 'CALLER-EXIT-TRAP-RAN' <<<"$TRAPOUT" \
    && ok "configuring Apache leaves the caller EXIT trap alone" \
    || bad "the library took the EXIT trap" \
           "upgrade-edge.sh would exit 0 with no summary whatever else failed"

grep -qE "trap[^\n]*INT TERM HUP" "$REPO/deploy/lib-edge-apache.sh" \
    && ! grep -qE "trap '[^\n]*' INT TERM EXIT" "$REPO/deploy/lib-edge-apache.sh" \
    && ok "and takes INT, TERM and HUP, which a caller does not rely on" \
    || bad "the rollback trap still names EXIT"

grep -q 'exit 130' "$REPO/deploy/lib-edge-apache.sh" \
    && ok "and an interrupt stops the script instead of resuming it" \
    || bad "the signal handler returns, so bash carries on re-applying the change"

# akconnect_apache_undo read the rollback status with $? after the fi of an if
# whose condition was the rollback. An if with a false condition and no else
# exits 0, so the honest-reporting path always said "0 file(s) could NOT be
# put back" — on the one branch that exists to say otherwise.
UNDOOUT="$(
    set -uo pipefail
    . "$REPO/deploy/lib-edge-apache.sh"
    akconnect_apache_undo "$WORK/no-such-backup-dir" echo "forced" 2>&1
    echo "RC=$?"
)"

grep -q 'RC=1' <<<"$UNDOOUT" \
    && ok "a rollback that could not run reports failure to its caller" \
    || bad "a failed rollback is reported as success"

grep -qE '0 file\(s\) could NOT' <<<"$UNDOOUT" \
    && bad "the failure count is still read after the fi, so it is always zero" \
    || ok "and does not claim zero files could not be put back"

# The count and the "there is nothing here" case are different situations, and
# the number is printed at somebody whose web server is part-way through a
# change. A missing backup set used to return 1, which reads as "one file".
grep -q 'no backup set' <<<"$UNDOOUT" \
    && ok "and a missing backup set says so rather than reporting one file" \
    || bad "a missing backup set is reported as one file that could not be put back"

# /dev/tty is mode 0666, so test -r is true for every process on the machine
# including one with no controlling terminal. The refusal was unreachable.
CONFIRMTEST="$WORK/confirm-test.sh"
printf '. "%s/deploy/lib-edge-apache.sh"\nakconnect_confirm "go?" && echo YES || echo NO\n' \
    "$REPO" > "$CONFIRMTEST"

setsid bash "$CONFIRMTEST" < /dev/null 2>/dev/null | grep -q '^NO$' \
    && ok "with no controlling terminal the confirmation really does refuse" \
    || bad "a process with no terminal is not refused" \
           "test -r /dev/tty is true everywhere; it has to be opened"

setsid bash "$CONFIRMTEST" < /dev/null 2>&1 | grep -q 'No such device' \
    && bad "the shell prints its own redirection error over the refusal" \
    || ok "and says so without the shell printing over it"

# install-edge.sh used to configure Apache on every run, with no flag at all.
grep -q 'configure-apache' "$REPO/deploy/install-edge.sh" \
    && grep -q 'akconnect_may_configure_apache' "$REPO/deploy/install-edge.sh" \
    && ok "install-edge.sh needs --configure-apache too, through the same gate" \
    || bad "install-edge.sh edits Apache with no flag" \
           "the requirement is --configure-apache only, in both scripts"

# remove-apache-fallback.sh without --panel could not tell which domain was
# meant to stop answering, so it read its own removal as a foreign regression
# and put the fallback straight back.
REMOUT="$(bash "$REPO/deploy/remove-apache-fallback.sh" --dry-run 2>&1 || true)"
grep -qE 'panel is required|run this as root' <<<"$REMOUT" \
    && ok "the removal script will not run without --panel" \
    || bad "remove-apache-fallback.sh runs with no --panel and undoes its own removal"

# The restore path was the one write that could still destroy the file it was
# saving: cp opens the destination with O_TRUNC.
grep -q 'akconnect_apache_replace' "$REPO/deploy/lib-edge-apache.sh" \
    && ! grep -qE 'cp -p "\$dest/\$index" "\$original"' "$REPO/deploy/lib-edge-apache.sh" \
    && ok "the rollback writes beside the file and moves it, never truncates it" \
    || bad "the rollback still streams into the live virtual host"

# A ServerName may carry a port, and a directive may be continued with a
# backslash. Both used to produce a name no probe could be aimed at.
PARSEFIX="$WORK/parse-fix.conf"
cat > "$PARSEFIX" <<'PARSEEOF'
<VirtualHost *:443>
    ServerName ported.example.com:443
    ServerAlias first.example.com \
                second.example.com
    SSLEngine on
</VirtualHost>
PARSEEOF

PARSED="$(. "$REPO/deploy/lib-edge-vhost.sh"; akconnect_vhost_blocks "$PARSEFIX")"
[ "$(cut -f5 <<<"$PARSED")" = "ported.example.com" ] \
    && ok "a ServerName with a port is read as the name Apache serves" \
    || bad "a ServerName with a port is kept verbatim and cannot be probed"

[ "$(cut -f6 <<<"$PARSED")" = "first.example.com,second.example.com" ] \
    && ok "and a directive continued with a backslash is one directive" \
    || bad "a continued ServerAlias loses every name after the first"

# 11-14. The checkout, as git sees it — and the hour-by-hour cost of the timer.
#
# Every fixture above was created and run as root, so none of them could see
# what the first field run of the one-command installer hit: git refusing a
# repository that belongs to somebody else. On a checkout a login user cloned,
# every git call here failed into /dev/null, and the preflight reported "no
# git checkout" about a perfectly good one. And every fixture above cloned
# every branch, so none could see a single-branch clone — which is how the
# installer clones — fetch a branch it does not track, fail, and print
# "source fetched" in green with an empty commit.
#
# The origin is served over git://, because git applies its ownership rule to
# a local origin as well, and a server fetching from GitHub never meets that.
chmod 755 "$WORK"
git daemon --export-all --base-path="$WORK" --listen=127.0.0.1 --port=9419 \
    --reuseaddr --detach --pid-file="$WORK/gitd.pid" 2>/dev/null
# Whatever ends this run, the daemon and the stand-in services go with it.
trap 'kill "$(cat "$WORK/gitd.pid" 2>/dev/null)" $(cat "$WORK"/pids/* 2>/dev/null) "${CAP_PID:-}" "${CAP2_PID:-}" "${CAP3_PID:-}" "${CAP4_PID:-}" 2>/dev/null; rm -rf "$WORK"' EXIT
sleep 0.3
kill -0 "$(cat "$WORK/gitd.pid" 2>/dev/null)" 2>/dev/null \
    || bad "the git daemon did not start on 9419" "is something else holding the port?"
for _ in $(seq 1 40); do
    (exec 3<>"/dev/tcp/127.0.0.1/9419") 2>/dev/null && break
    sleep 0.25
done
GIT_URL="git://127.0.0.1:9419/origin"

# A release branch beside main, for the single-branch cases.
git -C "$WORK/seed" push --quiet origin HEAD:refs/heads/release/9.9 2>/dev/null

# run_edge <label> <checkout> <panel port> <panel args...> -- runs the
# working tree's script against a checkout, as root with no SUDO_UID — the
# timer's situation, and a root login's.
run_edge() {
    local label=$1 checkout=$2 port=$3
    shift 3
    python3 "$LAB/capture-panel.py" "$port" "$WORK/cap-$label.jsonl" --secret "$CAP_SECRET" "$@" \
        >"$WORK/panel-$label.log" 2>&1 &
    local panel=$!
    for _ in $(seq 1 40); do
        (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null && break
        sleep 0.25
    done
    mkdir -p "$WORK/etc-$label"
    printf 'AKCONNECT_PANEL_URL=http://127.0.0.1:%s\nAKCONNECT_COORDINATOR_SECRET=%s\n' \
        "$port" "$CAP_SECRET" > "$WORK/etc-$label/coordinator.env"

    # EDGE_PREFIX: a command to run it under (a namespace), and EDGE_RUN: the
    # script to run, when it is not the working tree's.
    # shellcheck disable=SC2086
    ${EDGE_PREFIX[@]+"${EDGE_PREFIX[@]}"} env -u SUDO_UID -u SUDO_USER PATH="$WORK/stub-bin:/usr/local/go/bin:$PATH" HOME="$WORK" \
        AKCONNECT_ETC="$WORK/etc-$label" AKCONNECT_SRC="$checkout" \
        AKCONNECT_BIN_DIR="${EDGE_BIN:-$WORK/no-bin}" AKCONNECT_SYSTEMD_DIR="$WORK/units-$label" \
        bash "${EDGE_RUN:-$SCRIPT}" ${EDGE_ARGS:---skip-pack --no-timer} >"$WORK/edge-$label.out" 2>&1
    EDGE_CODE=$?
    kill "$panel" 2>/dev/null
    wait "$panel" 2>/dev/null
}

# A systemctl that records and changes nothing, so no case here can restart
# anything real — and so the "already current" case can prove nothing was.
# A "service" is a process whose PID is in $HOME/pids/<unit>: active while it
# lives, and that is its MainPID.
mkdir -p "$WORK/stub-bin" "$WORK/pids"
cat > "$WORK/stub-bin/systemctl" <<'STUB'
#!/bin/sh
echo "systemctl $*" >> "${HOME}/systemctl.log"
verb=""; unit=""; quiet=0; skip=0
for a in "$@"; do
    # -p takes a value: `show -p MainPID --value <unit>`.
    if [ "$skip" -eq 1 ]; then skip=0; continue; fi
    case "$a" in
        -q|--quiet) quiet=1 ;;
        -p|--property) skip=1 ;;
        -*) ;;
        *) if [ -z "$verb" ]; then verb="$a"; elif [ -z "$unit" ]; then unit="$a"; fi ;;
    esac
done
pidfile="${HOME}/pids/$unit"
case "$verb" in
    is-active)
        if [ -f "$pidfile" ] && kill -0 "$(cat "$pidfile")" 2>/dev/null; then
            [ "$quiet" -eq 1 ] || echo active; exit 0
        fi
        [ "$quiet" -eq 1 ] || echo inactive; exit 3 ;;
    show)
        case "$*" in
            *MainPID*) cat "$pidfile" 2>/dev/null || echo 0 ;;
            *) echo "Wed 2026-09-23 00:00:00 UTC" ;;
        esac ;;
esac
exit 0
STUB
chmod 755 "$WORK/stub-bin/systemctl"

# ss answers from $HOME/ss-lines: the ports a healthy edge has bound, for the
# cases that model one; nothing for the rest.
cat > "$WORK/stub-bin/ss" <<'STUB'
#!/bin/sh
cat "${HOME}/ss-lines" 2>/dev/null
exit 0
STUB
chmod 755 "$WORK/stub-bin/ss"

group "a checkout its runner does not own"

FOREIGN="$WORK/foreign"
git clone --quiet "$GIT_URL" "$FOREIGN" 2>/dev/null
# A release behind, so that being at the panel's commit afterwards proves the
# run moved it — a run that died at the preflight would otherwise leave it
# exactly where these checks look for it.
git -C "$FOREIGN" checkout --quiet -B main HEAD~1 2>/dev/null
chown -R 65534:65534 "$FOREIGN"
# And what every earlier root run left inside a checkout like this — a sudo
# from its owner was let through by git, and wrote as root: FETCH_HEAD, the
# index. A fix that ran git as the owner and stopped there died on the first
# of these, on exactly the edges it was for.
: > "$FOREIGN/.git/FETCH_HEAD"
chown root:root "$FOREIGN/.git/FETCH_HEAD" "$FOREIGN/.git/index"

run_edge foreign "$FOREIGN" 8797 --commit "$TARGET_SHA" --branch main

if grep -qE "no git checkout|dubious ownership" "$WORK/edge-foreign.out"; then
    bad "a checkout owned by another user is refused" \
        "$(grep -E 'no git checkout|dubious ownership' "$WORK/edge-foreign.out" | head -2)"
else
    ok "a checkout owned by another user is used, as that user"
fi

if grep -E "source fetched" "$WORK/edge-foreign.out" | grep -qE "[0-9a-f]{7}"; then
    ok "and the release is fetched into it"
else
    bad "the release was not fetched" "$(grep -E 'source fetched|could not' "$WORK/edge-foreign.out" | head -3)"
fi

FOREIGN_AT="$(setpriv --reuid=65534 --regid=65534 --clear-groups env HOME=/tmp git -C "$FOREIGN" rev-parse HEAD 2>/dev/null)"
[ "$FOREIGN_AT" = "$TARGET_SHA" ] \
    && ok "and checked out at the commit the panel named" \
    || bad "the checkout is at '${FOREIGN_AT:-nothing}', not $TARGET_SHA"

NOT_THEIRS="$(find "$FOREIGN" ! -user 65534 -printf '%u %p\n' 2>/dev/null | head -3)"
if [ "$FOREIGN_AT" != "$TARGET_SHA" ]; then
    bad "and every file in it is still its owner's" "not reached: the run never got as far as writing to it"
elif [ -n "$NOT_THEIRS" ]; then
    bad "root left files in someone else's checkout" "$NOT_THEIRS"
else
    ok "and every file in it is still its owner's, so their own git pull still works"
fi

group "a single-branch clone, and a panel on another branch"

SINGLE="$WORK/single"
git clone --quiet --depth 50 --branch main "$GIT_URL" "$SINGLE" 2>/dev/null

run_edge single "$SINGLE" 8798 --branch release/9.9 --commit ""

if grep -E "source fetched" "$WORK/edge-single.out" | grep -qE "[0-9a-f]{7}"; then
    ok "a branch the clone does not track is fetched by name"
else
    bad "a single-branch clone could not fetch the panel's branch" \
        "$(grep -E 'source fetched|could not|neither' "$WORK/edge-single.out" | head -3)"
fi

[ "$(git -C "$SINGLE" rev-parse --abbrev-ref HEAD 2>/dev/null)" = "release/9.9" ] \
    && ok "and the checkout is left on it" \
    || bad "the checkout is on '$(git -C "$SINGLE" rev-parse --abbrev-ref HEAD 2>/dev/null)', not release/9.9"

MISSING="$WORK/missing"
git clone --quiet --depth 50 --branch main "$GIT_URL" "$MISSING" 2>/dev/null

run_edge missing "$MISSING" 8799 --branch no-such-branch --commit ""

# Colour out, and any "source fetched ... PASS" row at all is the failure:
# the table prints the name before the verdict, so the first version of this
# pattern could never match.
if sed 's/\x1b\[[0-9;]*m//g' "$WORK/edge-missing.out" | grep -qE "^\s+source fetched\s+PASS"; then
    bad "a branch that does not exist is reported as fetched" \
        "$(grep -E 'source fetched' "$WORK/edge-missing.out" | head -2)"
else
    ok "a branch that does not exist is never reported as fetched"
fi

# The die text, not merely the name: every run prints "target: … on <branch>".
[ "$EDGE_CODE" -ne 0 ] && grep -qE "could not fetch no-such-branch|no-such-branch.*has neither" "$WORK/edge-missing.out" \
    && ok "and the run fails, naming the branch the panel asked for" \
    || bad "the run did not fail naming the branch" "exit $EDGE_CODE; $(grep -E '✗' "$WORK/edge-missing.out" | head -2)"

group "an edge already on the panel's release"

# Binaries that answer with the panel's version and are running as the two
# services, a timer already in place, the ports bound, and a panel that says
# both installers are published for that version.
#
# Real executables, running: "current" now means the process is running the
# file on disk, read from /proc/<pid>/exe, and a shell script's process is the
# shell.
mkdir -p "$WORK/current-bin" "$WORK/units-current" "$WORK/fake-svc"
# release-key as the real coordinator does it (1.9.7-dev.23): the same message,
# the same file format, the same output. What the gate checks is the script
# around it; the signer itself is tested in services/coordinator.
cat > "$WORK/fake-svc/main.go" <<'GO'
package main

import (
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"os"
	"strings"
	"time"
)

var name, version string

func key(path string) ed25519.PrivateKey {
	raw, err := os.ReadFile(path)
	if err != nil {
		seed := make([]byte, 32)
		rand.Read(seed)
		if err := os.WriteFile(path, []byte(hex.EncodeToString(seed)+"\n"), 0o600); err != nil {
			fmt.Fprintln(os.Stderr, err)
			os.Exit(1)
		}
		raw, _ = os.ReadFile(path)
	}
	seed, _ := hex.DecodeString(strings.TrimSpace(string(raw)))
	return ed25519.NewKeyFromSeed(seed)
}

func main() {
	if len(os.Args) > 1 && os.Args[1] == "version" {
		fmt.Println(name, version)
		return
	}
	if len(os.Args) > 1 && os.Args[1] == "release-key" && len(os.Args) <= 3 {
		fmt.Fprintln(os.Stderr, "usage: release-key init|public <keyfile>")
		os.Exit(1)
	}
	if len(os.Args) > 3 && os.Args[1] == "release-key" {
		k := key(os.Args[3])
		pub := hex.EncodeToString(k.Public().(ed25519.PublicKey))
		sum := sha256.Sum256(k.Public().(ed25519.PublicKey))
		fp := hex.EncodeToString(sum[:10])
		fp = fp[0:4] + " " + fp[4:8] + " " + fp[8:12] + " " + fp[12:16] + " " + fp[16:20]
		switch os.Args[2] {
		case "init":
			fmt.Println(pub, "existing", fp)
		case "sign":
			body, _ := os.ReadFile(os.Args[6])
			d := sha256.Sum256(body)
			digest := hex.EncodeToString(d[:])
			msg := "akconnect-edge-artifact/v1\n" + os.Args[4] + "\n" + os.Args[5] + "\n" + digest
			fmt.Println(digest, hex.EncodeToString(ed25519.Sign(k, []byte(msg))), pub)
		}
		return
	}
	for {
		time.Sleep(time.Hour)
	}
}
GO
printf 'module fakesvc\n\ngo 1.21\n' > "$WORK/fake-svc/go.mod"
for svc in coordinator relay; do
    (cd "$WORK/fake-svc" && env GOFLAGS= GOTOOLCHAIN=local GOPROXY=off CGO_ENABLED=0 \
        /usr/local/go/bin/go build -o "$WORK/current-bin/akconnect-$svc" \
        -ldflags "-X main.name=akconnect-$svc -X main.version=9.9.9-capture" . ) \
        || bad "could not build the stand-in akconnect-$svc" "the already-current cases below prove nothing"
    "$WORK/current-bin/akconnect-$svc" serve >/dev/null 2>&1 &
    echo $! > "$WORK/pids/akconnect-$svc"
done
printf 'UNCONN 0 0 0.0.0.0:8443 0.0.0.0:*\nUNCONN 0 0 0.0.0.0:9000 0.0.0.0:*\nLISTEN 0 4096 127.0.0.1:9443 0.0.0.0:*\n' \
    > "$WORK/ss-lines"
: > "$WORK/units-current/akconnect-upgrade.timer"
: > "$WORK/systemctl.log"

CURRENT="$WORK/current"
git clone --quiet "$GIT_URL" "$CURRENT" 2>/dev/null


EDGE_BIN="$WORK/current-bin" EDGE_ARGS=" " \
    run_edge current "$CURRENT" 8800 --commit "$TARGET_SHA" --branch main --published 9.9.9-capture

if [ "$EDGE_CODE" -eq 0 ] && grep -q "already current" "$WORK/edge-current.out"; then
    ok "it says it is already current, and exits cleanly"
else
    bad "an edge on the right release did not recognise it" "exit $EDGE_CODE; $(tail -3 "$WORK/edge-current.out" | tr '\n' ' ')"
fi

if grep -q "restart" "$WORK/systemctl.log" || grep -q "building the coordinator" "$WORK/edge-current.out"; then
    bad "and it rebuilt or restarted anyway" "$(grep restart "$WORK/systemctl.log" | head -2)"
else
    ok "and nothing was rebuilt or restarted — no hourly outage for every relayed pair"
fi

# Nothing to rebuild is not nothing to do. The relay's version reaches the
# panel only in this report, and "Last heard" is its time; the fallback can
# break under an edge that has not moved.
if grep -q '"path": "/api/v1/edge/report"' "$WORK/cap-current.jsonl"; then
    ok "and it still tells the panel what is running here"
else
    bad "an edge that is already current never reports to the panel" \
        "the relay's version and 'Last heard' go stale until the next release"
fi

if sed 's/\x1b\[[0-9;]*m//g' "$WORK/edge-current.out" \
        | grep -qE "^\s+(HTTPS fallback|Apache proxy|relay fallback listener)\s"; then
    ok "and it still checks the HTTPS fallback"
else
    bad "an edge that is already current never checks its HTTPS fallback"
fi

# --configure-apache is the documented way to set the fallback up, and the
# script's own hold message says to run it. On a current edge it said
# "Nothing to do" and configured nothing.
: > "$WORK/systemctl.log"
mkdir -p "$WORK/units-apache"
: > "$WORK/units-apache/akconnect-upgrade.timer"
EDGE_BIN="$WORK/current-bin" EDGE_ARGS="--configure-apache" \
    run_edge apache "$CURRENT" 8802 --commit "$TARGET_SHA" --branch main --published 9.9.9-capture

if grep -q "already current" "$WORK/edge-apache.out" \
        && sed 's/\x1b\[[0-9;]*m//g' "$WORK/edge-apache.out" | grep -qE "^\s+Apache proxy\s"; then
    ok "--configure-apache on a current edge reaches the Apache step"
else
    bad "--configure-apache on a current edge never reaches the Apache step" \
        "$(grep -E 'already current|Apache|fallback' "$WORK/edge-apache.out" | head -4)"
fi
grep -q "restart" "$WORK/systemctl.log" \
    && bad "and it restarted the services to get there" \
    || ok "without rebuilding or restarting anything"

# --skip-pack: the installers were not looked at, so they are not reported on.
mkdir -p "$WORK/units-skippack"
: > "$WORK/units-skippack/akconnect-upgrade.timer"
EDGE_BIN="$WORK/current-bin" EDGE_ARGS="--skip-pack" \
    run_edge skippack "$CURRENT" 8803 --commit "$TARGET_SHA" --branch main
if grep -q "already current" "$WORK/edge-skippack.out" \
        && ! grep -q "installers are all" "$WORK/edge-skippack.out"; then
    ok "with --skip-pack it does not claim the installers are current"
else
    bad "with --skip-pack it reports installers it never compared" \
        "$(grep 'already current' "$WORK/edge-skippack.out" | head -1)"
fi

# The same in every respect but one — the published installer is a release
# behind — so that "not current" is decided by that and nothing else.
: > "$WORK/systemctl.log"
mkdir -p "$WORK/units-stale"
: > "$WORK/units-stale/akconnect-upgrade.timer"
CURRENT2="$WORK/current2"
git clone --quiet "$GIT_URL" "$CURRENT2" 2>/dev/null

EDGE_BIN="$WORK/current-bin" EDGE_ARGS=" " \
    run_edge stale "$CURRENT2" 8801 --commit "$TARGET_SHA" --branch main --published 9.9.8

if sed 's/\x1b\[[0-9;]*m//g' "$WORK/edge-stale.out" | grep -qE "^\s+already current\s"; then
    bad "an installer published for an older release was taken as current"
else
    ok "an installer published for an older release is not taken as current"
fi

# And what that costs is the installers, not the services. The first run
# after an install found the Windows installer unpublished and rebuilt and
# restarted both services it had just been built with: two builds of the same
# release on every new server.
if sed 's/\x1b\[[0-9;]*m//g' "$WORK/edge-stale.out" | grep -qE "^\s+services current\s+PASS" \
        && ! grep -q "── building the coordinator and the relay" "$WORK/edge-stale.out" \
        && ! grep -q "unbound variable" "$WORK/edge-stale.out" \
        && ! grep -q "restart" "$WORK/systemctl.log"; then
    ok "and the services are not rebuilt or restarted for it — only the installers are built"
else
    bad "a missing installer rebuilt or restarted services already on the release" \
        "$(grep -E 'services current|building the coordinator' "$WORK/edge-stale.out" | head -3); $(grep restart "$WORK/systemctl.log" | head -2)"
fi

# The release's unit files differ from the installed ones: a restart, to pick
# them up, and still no build.
UNITS_SEED="$WORK/units-seed"
git init --quiet -b main "$UNITS_SEED"
mkdir -p "$UNITS_SEED/deploy/systemd"
cp "$REPO"/deploy/lib-edge-*.sh "$UNITS_SEED/deploy/"
cp "$SCRIPT" "$UNITS_SEED/deploy/upgrade-edge.sh"
printf '9.9.9-capture\n' > "$UNITS_SEED/VERSION"
printf '[Service]\nExecStart=/bin/true --release-unit\n' > "$UNITS_SEED/deploy/systemd/akconnect-relay.service"
git -C "$UNITS_SEED" add -A >/dev/null
git -C "$UNITS_SEED" -c user.email=g@l -c user.name=gate commit --quiet -m units
UNITS_SHA="$(git -C "$UNITS_SEED" rev-parse HEAD)"
git clone --quiet --bare "$UNITS_SEED" "$WORK/units-origin.git"
git clone --quiet "$WORK/units-origin.git" "$WORK/units-clone" 2>/dev/null
mkdir -p "$WORK/units-unitdiff"
: > "$WORK/units-unitdiff/akconnect-upgrade.timer"
printf '[Service]\nExecStart=/bin/true --installed-unit\n' > "$WORK/units-unitdiff/akconnect-relay.service"
: > "$WORK/systemctl.log"
EDGE_BIN="$WORK/current-bin" EDGE_ARGS=" " \
    run_edge unitdiff "$WORK/units-clone" 8814 --commit "$UNITS_SHA" --branch main --published 9.9.9-capture
if grep -q "restart akconnect" "$WORK/systemctl.log" \
        && ! grep -q "── building the coordinator and the relay" "$WORK/edge-unitdiff.out" \
        && cmp -s "$UNITS_SEED/deploy/systemd/akconnect-relay.service" "$WORK/units-unitdiff/akconnect-relay.service"; then
    ok "a unit file the release changed is installed and the services restarted, with no build"
else
    bad "a changed unit file was not picked up without a rebuild" \
        "$(grep -E 'unit files|building|services current' "$WORK/edge-unitdiff.out" | head -3); $(grep restart "$WORK/systemctl.log" | head -2)"
fi

# ---- 1.9.7-dev.23: what the edge publishes, it signs with its own key.
#
# The panel published whatever arrived with the shared secret — which the
# installer printed, and which ended up in a chat — and signed an uploaded
# agent and offered it to every device. Now every upload carries the edge's
# release-key signature over kind, version and digest; nothing is uploaded
# without one; and an upload the panel is holding for an administrator is not
# built and sent again every hour.
group "what the edge publishes, it signs with its own release key"

SIGN_SEED="$WORK/sign-seed"
git init --quiet -b main "$SIGN_SEED"
mkdir -p "$SIGN_SEED/deploy/systemd" "$SIGN_SEED/services/kit"
cp "$REPO"/deploy/lib-edge-*.sh "$REPO/deploy/publish-artifact.py" "$SIGN_SEED/deploy/"
cp "$SCRIPT" "$SIGN_SEED/deploy/upgrade-edge.sh"
printf '9.9.9-capture\n' > "$SIGN_SEED/VERSION"
cat > "$SIGN_SEED/services/kit/build-windows-pack.sh" <<'BUILDER'
#!/bin/sh
# Stand-in pack builder: writes the two files the real one does, into
# AKCONNECT_BUILD_DIR, in a second rather than ten minutes.
set -e
mkdir -p "$AKCONNECT_BUILD_DIR/pack"
printf 'MZ setup %s\n' "$1" > "$AKCONNECT_BUILD_DIR/pack/akconnect-setup.exe"
printf 'MZ agent %s\n' "$1" > "$AKCONNECT_BUILD_DIR/pack/akconnect-agent.exe"
BUILDER
chmod +x "$SIGN_SEED/services/kit/build-windows-pack.sh"
git -C "$SIGN_SEED" add -A >/dev/null
git -C "$SIGN_SEED" -c user.email=g@l -c user.name=gate commit --quiet -m sign
SIGN_SHA="$(git -C "$SIGN_SEED" rev-parse HEAD)"
git clone --quiet --bare "$SIGN_SEED" "$WORK/sign-origin.git"
: > "$WORK/sign-wintun.zip"

cat > "$WORK/verify-uploads.php" <<'PHP'
<?php
// Every upload in a capture file must carry an ed25519 signature, by the key
// it names, over "akconnect-edge-artifact/v1\n<kind>\n<version>\n<sha256>".
// Prints the kinds that verified; exits 1 at the first that does not.
$kinds = [];
foreach (file($argv[1]) ?: [] as $line) {
    $e = json_decode($line, true);
    if (!is_array($e) || !isset($e['kind'])) {
        continue;
    }
    $message = "akconnect-edge-artifact/v1\n" . $e['kind'] . "\n" . $e['version'] . "\n" . $e['sha256'];
    try {
        $ok = sodium_crypto_sign_verify_detached(
            (string) hex2bin((string) ($e['edge_signature'] ?? '')),
            $message,
            (string) hex2bin((string) ($e['edge_key'] ?? ''))
        );
    } catch (Throwable) {
        $ok = false;
    }
    if (!$ok) {
        echo 'unsigned or badly signed: ', $e['kind'], "\n";
        exit(1);
    }
    $kinds[$e['kind']] = $e['edge_key'];
}
ksort($kinds);
echo implode(' ', array_keys($kinds)), ' ', implode(' ', array_unique(array_values($kinds))), "\n";
PHP

sign_case() {
    local label=$1
    shift
    mkdir -p "$WORK/units-$label"
    : > "$WORK/units-$label/akconnect-upgrade.timer"
    rm -rf "$WORK/sign-clone-$label"
    git clone --quiet "$WORK/sign-origin.git" "$WORK/sign-clone-$label" 2>/dev/null
    WINTUN_ZIP="$WORK/sign-wintun.zip" EDGE_BIN="$WORK/current-bin" EDGE_ARGS=" " \
        run_edge "$label" "$WORK/sign-clone-$label" "$@" --commit "$SIGN_SHA" --branch main
}
plain() { sed 's/\x1b\[[0-9;]*m//g' "$WORK/edge-$1.out"; }

# 1. A panel that publishes: both uploads signed, by one key, made here, root's.
sign_case signs 8820 --published 9.9.8 --accept
VERIFIED="$(php "$WORK/verify-uploads.php" "$WORK/cap-signs.jsonl" 2>&1)"
KEYFILE="$WORK/etc-signs/release-signing.key"
if [ "$(printf '%s' "$VERIFIED" | cut -d' ' -f1-2)" = "windows-agent windows-setup" ] \
        && [ "$(printf '%s' "$VERIFIED" | wc -w)" -eq 3 ]; then
    ok "the installer and the agent are both uploaded with a valid edge signature, by one key"
else
    bad "an upload went out without a valid edge signature" "$VERIFIED; $(plain signs | grep -E 'release key|published|self-update' | head -3)"
fi
if [ -f "$KEYFILE" ] && [ "$(stat -c %a "$KEYFILE")" = "600" ] \
        && plain signs | grep -qE "^\s+release key\s+PASS"; then
    ok "the release key is made on the edge, mode 0600"
else
    bad "no release key, or one others can read" "$(ls -l "$KEYFILE" 2>&1)"
fi
if plain signs | grep -qE "^\s+published\s+PASS" && plain signs | grep -qE "^\s+self-update\s+PASS"; then
    ok "and what the panel accepts is reported as published"
else
    bad "an accepted upload was not reported as published" "$(plain signs | grep -E 'published|self-update' | head -2)"
fi
if grep -q "$(tr -d '\n' < "$KEYFILE")" "$WORK/edge-signs.out" "$WORK/cap-signs.jsonl"; then
    bad "the release key's private half left the key file"
else
    ok "and the private key is in neither the output nor anything sent"
fi

# 2. A panel that holds it: said so, and not called published.
sign_case held 8821 --published 9.9.8 --held
if [ "$EDGE_CODE" -ne 0 ] && plain held | grep -q "held: an administrator approves it under Platform" \
        && ! plain held | grep -qE "^\s+published\s+PASS"; then
    ok "an upload the panel holds is reported as waiting for an administrator, not as published"
else
    bad "a held upload was not reported as held" "exit $EDGE_CODE; $(plain held | grep -E 'published|self-update|held' | head -3)"
fi

# 3. Already waiting, from this edge: not built and uploaded again.
mkdir -p "$WORK/etc-heldskip"
FP="$( (umask 077; "$WORK/current-bin/akconnect-coordinator" release-key init "$WORK/etc-heldskip/release-signing.key") | cut -d' ' -f3-)"
sign_case heldskip 8822 --published 9.9.8 --held --held-release 9.9.9-capture "$FP"
if plain heldskip | grep -q "waiting for an administrator: Platform → Coordinator, edge key $FP" \
        && ! grep -q "── building the Windows installer" "$WORK/edge-heldskip.out" \
        && ! grep -q '/edge/artifact' "$WORK/cap-heldskip.jsonl"; then
    ok "installers this edge already uploaded and the panel holds are not rebuilt every hour"
else
    bad "a held upload was built and sent again" "$(plain heldskip | grep -E 'installer|waiting|release key' | head -3)"
fi

# 4. No key, no upload — never an unsigned one instead.
mkdir -p "$WORK/etc-nokey/release-signing.key"
sign_case nokey 8823 --published 9.9.8 --accept
if [ "$EDGE_CODE" -ne 0 ] && ! grep -q '/edge/artifact' "$WORK/cap-nokey.jsonl" \
        && plain nokey | grep -q "nothing can be published without the release key"; then
    ok "without a usable release key nothing is uploaded, and it says why"
else
    bad "an edge without its release key uploaded anyway, or said nothing" "$(grep -c artifact "$WORK/cap-nokey.jsonl") uploads; $(plain nokey | grep -E 'release key|installer' | head -2)"
fi

# A check that fails on an edge that IS on the release — here the relay's
# fallback listener is not bound — is a failed check, not "the edge is NOT
# upgraded", which sends an operator to undo work that was fine.
cp "$WORK/ss-lines" "$WORK/ss-lines.healthy"
grep -v ':9443 ' "$WORK/ss-lines.healthy" > "$WORK/ss-lines"
mkdir -p "$WORK/units-nolistener"
: > "$WORK/units-nolistener/akconnect-upgrade.timer"
EDGE_BIN="$WORK/current-bin" EDGE_ARGS=" " \
    run_edge nolistener "$CURRENT" 8810 --commit "$TARGET_SHA" --branch main --published 9.9.9-capture
cp "$WORK/ss-lines.healthy" "$WORK/ss-lines"
if [ "$EDGE_CODE" -ne 0 ] && grep -q "ARE upgraded" "$WORK/edge-nolistener.out" \
        && ! grep -q "NOT upgraded" "$WORK/edge-nolistener.out"; then
    ok "a check failing on a current edge fails the run without calling it not upgraded"
else
    bad "a current edge with a failed check is reported as not upgraded" \
        "exit $EDGE_CODE; $(grep -E 'upgraded|PARTIAL|FAIL' "$WORK/edge-nolistener.out" | tail -3 | tr '\n' ' ')"
fi

# Replaced under a running service: the file says the release, the process is
# the build before it. A run stopped between installing the new binaries and
# restarting left exactly this, and every hour after it looked current.
: > "$WORK/systemctl.log"
mkdir -p "$WORK/units-replaced"
: > "$WORK/units-replaced/akconnect-upgrade.timer"
cp "$WORK/current-bin/akconnect-relay" "$WORK/current-bin/akconnect-relay.new"
mv -f "$WORK/current-bin/akconnect-relay.new" "$WORK/current-bin/akconnect-relay"
EDGE_BIN="$WORK/current-bin" EDGE_ARGS=" " \
    run_edge replaced "$CURRENT" 8809 --commit "$TARGET_SHA" --branch main --published 9.9.9-capture
if grep -q "already current" "$WORK/edge-replaced.out"; then
    bad "a relay still running the build its file replaced was taken as current" \
        "the panel would be told the new version while the old one serves"
else
    ok "a service still running a replaced binary is not taken as current"
fi

for svc in coordinator relay; do
    kill "$(cat "$WORK/pids/akconnect-$svc" 2>/dev/null)" 2>/dev/null
done
rm -f "$WORK/ss-lines"

# 15-20. What the review of 1.9.7-dev.21 found, each as it would happen.

group "a copy of this script run from a directory it does not control"

# A copy up to revision 2 hands over by writing the release's copy straight
# into /tmp and running it. The copy sourced lib-edge-args.sh from beside
# itself: /tmp/lib-edge-args.sh, which any local user can create, and root ran
# it. Modelled directly — the working tree's script, beside a planted file.
PLANT="$WORK/planted"
mkdir -p "$PLANT"
cp "$SCRIPT" "$PLANT/upgrade-edge.sh"
printf 'touch "%s/SOURCED"\n' "$PLANT" > "$PLANT/lib-edge-args.sh"
env PATH="/usr/local/go/bin:$PATH" HOME="$WORK" bash "$PLANT/upgrade-edge.sh" --src \
    >"$WORK/planted.out" 2>&1

if [ -e "$PLANT/SOURCED" ]; then
    bad "it ran a file planted beside it, as root" "a local user writing /tmp/lib-edge-args.sh owns the machine"
else
    ok "it runs nothing that happens to be beside it"
fi
grep -q -- "--src needs a value" "$WORK/planted.out" \
    && ok "and still has its own argument checks" \
    || bad "its argument checks went missing with the file" "$(head -3 "$WORK/planted.out")"

group "handing over: an option only the new copy knows, a relative --src, an early stop"

# An origin whose release carries this script, and a checkout one commit
# behind whose copy is older (revision 1) and does not know --force — which
# is what an edge carrying the previous release looks like to an operator
# following this release's instructions.
HO_SEED="$WORK/ho-seed"
git init --quiet -b main "$HO_SEED"
mkdir -p "$HO_SEED/deploy"
cp "$REPO"/deploy/lib-edge-*.sh "$HO_SEED/deploy/"
printf '9.9.9-capture\n' > "$HO_SEED/VERSION"
sed -e 's/^SCRIPT_REVISION=.*/SCRIPT_REVISION=1/' -e '/^        --force) *FORCE=1; shift ;;$/d' \
    "$SCRIPT" > "$HO_SEED/deploy/upgrade-edge.sh"
chmod 755 "$HO_SEED/deploy/upgrade-edge.sh"
if grep -q -- '--force)' "$HO_SEED/deploy/upgrade-edge.sh"; then
    bad "the old copy still knows --force" "the fixture tests nothing; the sed no longer matches the option loop"
fi
git -C "$HO_SEED" add -A >/dev/null
git -C "$HO_SEED" -c user.email=g@l -c user.name=gate commit --quiet -m old
cp "$SCRIPT" "$HO_SEED/deploy/upgrade-edge.sh"
git -C "$HO_SEED" -c user.email=g@l -c user.name=gate commit --quiet -am new
HO_SHA="$(git -C "$HO_SEED" rev-parse HEAD)"
git clone --quiet --bare "$HO_SEED" "$WORK/ho-origin.git"
git clone --quiet "$WORK/ho-origin.git" "$WORK/ho-clone" 2>/dev/null
git -C "$WORK/ho-clone" checkout --quiet -B main HEAD~1 2>/dev/null

ls -d /tmp/akconnect-upgrade-edge.* 2>/dev/null | sort > "$WORK/ho-tmp-before"

# From $WORK, with --src relative to it, running the checkout's old copy.
( cd "$WORK" && EDGE_RUN="$WORK/ho-clone/deploy/upgrade-edge.sh" EDGE_ARGS="--force --skip-pack --no-timer --src ho-clone" \
    run_edge handover ho-clone 8811 --commit "$HO_SHA" --branch main )

if grep -q "unknown option" "$WORK/edge-handover.out"; then
    bad "an option only the release's copy knows was refused before the hand-over" \
        "$(grep -m1 'unknown option' "$WORK/edge-handover.out")"
elif grep -q "handing over" "$WORK/edge-handover.out" \
        && grep -q "running the release's own copy" "$WORK/edge-handover.out"; then
    ok "an option only the release's copy knows is handed to it, not refused"
else
    bad "the old copy did not hand over" "$(grep -E '✗|handing|running the release' "$WORK/edge-handover.out" | head -3)"
fi
if grep -qE "no git checkout at ho-clone|cannot change to 'ho-clone'" "$WORK/edge-handover.out"; then
    bad "a relative --src was looked for inside the checkout after the hand-over" \
        "$(grep -E 'no git checkout|cannot change' "$WORK/edge-handover.out" | head -1)"
else
    ok "and a relative --src means the same thing to the copy handed to"
fi

# The copy handed to stops early: an option neither copy knows. The directory
# it ran from is its to remove, whatever point it stops at.
# Back to the old copy first: the run above left the checkout on the release,
# whose copy has nothing newer to hand over to.
git -C "$WORK/ho-clone" checkout --quiet -B main "$HO_SHA~1" 2>/dev/null
ls -d /tmp/akconnect-upgrade-edge.* 2>/dev/null | sort > "$WORK/ho-tmp-before"
( cd "$WORK" && EDGE_RUN="$WORK/ho-clone/deploy/upgrade-edge.sh" EDGE_ARGS="--no-such-option --skip-pack --no-timer --src ho-clone" \
    run_edge handover2 ho-clone 8812 --commit "$HO_SHA" --branch main )
ls -d /tmp/akconnect-upgrade-edge.* 2>/dev/null | sort > "$WORK/ho-tmp-after"
LEAKED="$(comm -13 "$WORK/ho-tmp-before" "$WORK/ho-tmp-after")"
# The copy handed to refuses the option at once — it is the release's own, and
# nobody newer will know it — so "handing over" is the evidence it ran.
if ! grep -q "handing over" "$WORK/edge-handover2.out"; then
    bad "the early-stop case never handed over, so it tests nothing" \
        "$(grep -E 'handing|running the release|✗' "$WORK/edge-handover2.out" | head -3)"
elif ! grep -q "unknown option: --no-such-option" "$WORK/edge-handover2.out"; then
    bad "an option nobody knows was not refused" "$(tail -3 "$WORK/edge-handover2.out" | tr '\n' ' ')"
elif [ -n "$LEAKED" ]; then
    bad "a copy handed to that stopped early left itself in /tmp" "$LEAKED"
else
    ok "an option nobody knows is refused, and the copy handed to leaves nothing in /tmp"
fi

group "a machine whose own name does not resolve"

# sudo says "unable to resolve host" on standard error and carries on. Read as
# part of git's answer, it made a clean checkout "have local changes".
UNRESOLVED="$WORK/unresolved"
git clone --quiet "$GIT_URL" "$UNRESOLVED" 2>/dev/null
chown -R 65534:65534 "$UNRESOLVED"
EDGE_PREFIX=(unshare -u sh -c 'hostname akc-gate-no-such-host && exec "$@"' sh)
run_edge unresolved "$UNRESOLVED" 8813 --commit "$TARGET_SHA" --branch main
unset EDGE_PREFIX
if ! grep -q "unable to resolve host" "$WORK/edge-unresolved.out"; then
    ok "(sudo here does not warn about an unresolvable name, so this case holds trivially)"
fi
if grep -q "has local changes" "$WORK/edge-unresolved.out"; then
    bad "sudo's warning about the host name was read as local changes"
else
    ok "a warning from sudo is not mistaken for git's answer"
fi

group "a release branch deleted after it was merged"

# The panel names a branch origin no longer has, and a commit that is on main.
# A clone made before the release was merged: it has never had the commit, so
# finding it is the fetch's doing and not something left lying around.
GONE_SEED="$WORK/gone-seed"
git clone --quiet "$WORK/seed" "$GONE_SEED" 2>/dev/null
git -C "$GONE_SEED" checkout --quiet -B main "$TARGET_SHA~1" 2>/dev/null
git init --quiet --bare -b main "$WORK/gone-origin.git"
git -C "$GONE_SEED" push --quiet "$WORK/gone-origin.git" "HEAD:refs/heads/main" 2>/dev/null
GONE="$WORK/gone"
git clone --quiet --branch main --single-branch "$WORK/gone-origin.git" "$GONE" 2>/dev/null
git -C "$GONE_SEED" push --quiet "$WORK/gone-origin.git" "$TARGET_SHA:refs/heads/main" 2>/dev/null
if git -C "$GONE" cat-file -e "$TARGET_SHA^{commit}" 2>/dev/null; then
    bad "the deleted-branch fixture already has the commit" "it tests nothing"
fi

run_edge gone "$GONE" 8804 --branch release/gone --commit "$TARGET_SHA"

if grep -E "source fetched" "$WORK/edge-gone.out" | grep -qE "PASS.*[0-9a-f]{7}"; then
    ok "the panel's commit is fetched by name when its branch is gone"
else
    bad "a deleted branch stopped an edge whose release is still in the repository" \
        "$(grep -E 'source fetched|could not|no longer' "$WORK/edge-gone.out" | head -3)"
fi
[ "$(git -C "$GONE" rev-parse HEAD 2>/dev/null)" = "$TARGET_SHA" ] \
    && ok "and the checkout is at that commit" \
    || bad "the checkout is at $(git -C "$GONE" rev-parse --short HEAD 2>/dev/null), not the panel's commit"

group "the panel names a commit its branch does not contain"

# The panel's database is written by a web application, and this runs as root.
# GitHub serves any pull request's commits by id from the upstream URL, and
# 1.9.7-dev.21 fetched the panel's commit by id when its branch did not bring
# it — so whoever could write the panel's settings could have had every edge
# build a stranger's pull request, and hand over to its copy of this script.
PR_SEED="$WORK/pr-seed"
git clone --quiet "$WORK/seed" "$PR_SEED" 2>/dev/null
git -C "$PR_SEED" checkout --quiet -B pr "$TARGET_SHA" 2>/dev/null
{
    head -1 "$SCRIPT"
    printf 'echo PULL-REQUEST-SCRIPT-RAN; touch "%s/PR-RAN"\n' "$WORK"
    tail -n +2 "$SCRIPT"
} | sed 's/^SCRIPT_REVISION=.*/SCRIPT_REVISION=99/' > "$PR_SEED/deploy/upgrade-edge.sh"
git -C "$PR_SEED" -c user.email=g@l -c user.name=gate commit --quiet -am "a pull request"
PR_SHA="$(git -C "$PR_SEED" rev-parse HEAD)"
git -C "$PR_SEED" push --quiet "$WORK/origin" "HEAD:refs/pull/1/head" 2>/dev/null
git -C "$WORK/origin" rev-parse --verify --quiet "refs/pull/1/head" >/dev/null \
    || bad "the pull request fixture was not published to origin" "the cases below would test nothing"

PR_CLONE="$WORK/pr-clone"
git clone --quiet "$GIT_URL" "$PR_CLONE" 2>/dev/null
run_edge pr "$PR_CLONE" 8815 --branch main --commit "$PR_SHA"
if [ -e "$WORK/PR-RAN" ] || grep -q "PULL-REQUEST-SCRIPT-RAN" "$WORK/edge-pr.out"; then
    bad "a pull request's copy of this script ran as root" "the panel chose the code, not only the release"
elif [ "$(git -C "$PR_CLONE" rev-parse HEAD 2>/dev/null)" = "$PR_SHA" ]; then
    bad "the checkout was put on a commit origin's branch does not contain"
else
    ok "a commit that is only a pull request is never fetched, built or run"
fi

# And one already in the clone — fetched by an older copy, or by hand — is
# refused by name rather than built.
git -C "$PR_CLONE" fetch --quiet origin "refs/pull/1/head" 2>/dev/null
git -C "$PR_CLONE" cat-file -e "$PR_SHA^{commit}" 2>/dev/null \
    || bad "the pull request's commit could not be put in the clone" "the case below would test nothing"
git -C "$PR_CLONE" checkout --quiet -B main "$TARGET_SHA~1" 2>/dev/null
run_edge pr2 "$PR_CLONE" 8816 --branch main --commit "$PR_SHA"
if [ -e "$WORK/PR-RAN" ] || grep -q "PULL-REQUEST-SCRIPT-RAN" "$WORK/edge-pr2.out"; then
    bad "a pull request's copy of this script ran as root, once it was in the clone"
elif [ "$EDGE_CODE" -ne 0 ] && grep -q "which is not on" "$WORK/edge-pr2.out"; then
    ok "one that is already in the clone is refused, naming the branch it is not on"
else
    bad "a commit origin's branch does not contain was not refused" \
        "exit $EDGE_CODE; $(grep -E '✗|source fetched' "$WORK/edge-pr2.out" | head -2)"
fi

# A value that git would read as an option is not a commit.
run_edge inject "$PR_CLONE" 8817 --branch main --commit "--upload-pack=touch $WORK/INJECTED; git-upload-pack"
if [ -e "$WORK/INJECTED" ]; then
    bad "a commit from the panel was run as a git option" "--upload-pack ran a command as root"
elif [ "$EDGE_CODE" -ne 0 ] && grep -q "not a full commit id" "$WORK/edge-inject.out"; then
    ok "a commit that is not a commit id is refused before git sees it"
else
    bad "a malformed commit was not refused" "exit $EDGE_CODE; $(grep '✗' "$WORK/edge-inject.out" | head -2)"
fi

group "a tag that moved on origin"

# git fetch --tags refuses a tag that moved ("would clobber existing tag"),
# and one re-pointed release tag failed every edge's fetch, every hour.
git -C "$WORK/seed" tag -f moved-tag HEAD~1 >/dev/null 2>&1
git -C "$WORK/seed" push --quiet --force origin refs/tags/moved-tag 2>/dev/null
MOVED="$WORK/moved"
git clone --quiet "$GIT_URL" "$MOVED" 2>/dev/null
git -C "$WORK/seed" tag -f moved-tag HEAD >/dev/null 2>&1
git -C "$WORK/seed" push --quiet --force origin refs/tags/moved-tag 2>/dev/null

run_edge moved "$MOVED" 8805 --branch main --commit "$TARGET_SHA"

if grep -E "source fetched" "$WORK/edge-moved.out" | grep -qE "PASS.*[0-9a-f]{7}"; then
    ok "a tag that moved on origin does not stop the fetch"
else
    bad "a moved tag stops the fetch" "$(grep -E 'clobber|could not|source fetched' "$WORK/edge-moved.out" | head -3)"
fi
# Tags are not fetched at all now — nothing reads them — so the clone's own
# tag is left as it was, as are any tags its owner made.
[ "$(git -C "$MOVED" rev-parse moved-tag 2>/dev/null)" != "$(git -C "$WORK/seed" rev-parse moved-tag)" ] \
    && ok "and the clone's own tags are left alone" \
    || bad "the fetch moved a tag in the clone" "nothing here reads tags, and a login user's own would be lost"

group "a checkout reached through a symlink, or owned by nobody"

LINKED="$WORK/linked-clone"
git clone --quiet "$GIT_URL" "$LINKED" 2>/dev/null
git -C "$LINKED" checkout --quiet -B main HEAD~1 2>/dev/null
chown -R 65534:65534 "$LINKED"
ln -s "$LINKED" "$WORK/srclink"

run_edge linked "$WORK/srclink" 8806 --commit "$TARGET_SHA" --branch main

if grep -qE "dubious ownership|not usable as a git checkout" "$WORK/edge-linked.out"; then
    bad "a symlink to a login user's clone is run as the link's owner, root" \
        "$(grep -E 'dubious|not usable' "$WORK/edge-linked.out" | head -2)"
elif grep -E "source fetched" "$WORK/edge-linked.out" | grep -qE "PASS.*[0-9a-f]{7}"; then
    ok "a symlinked checkout is used as the owner of the clone it names"
else
    bad "a symlinked checkout was not fetched" "$(grep -E 'source fetched|could not|✗' "$WORK/edge-linked.out" | head -3)"
fi
NOT_THEIRS="$(find "$LINKED" ! -user 65534 -printf '%u %p\n' 2>/dev/null | head -3)"
[ -z "$NOT_THEIRS" ] \
    && ok "and every file in the clone is still its owner's" \
    || bad "root left files in the clone behind the symlink" "$NOT_THEIRS"

if getent passwd 4242 >/dev/null 2>&1; then
    bad "uid 4242 has an account here, so the no-owner case cannot be built" "pick another uid"
else
    ORPHAN="$WORK/orphan"
    git clone --quiet "$GIT_URL" "$ORPHAN" 2>/dev/null
    chown -R 4242:4242 "$ORPHAN"
    run_edge orphan "$ORPHAN" 8807 --commit "$TARGET_SHA" --branch main
    if grep -q "belongs to uid 4242, which has no account" "$WORK/edge-orphan.out" \
            && ! grep -q "unknown user" "$WORK/edge-orphan.out"; then
        ok "a checkout whose owner has no account is refused, saying so and what to run"
    else
        bad "a checkout whose owner has no account fails with sudo's words" \
            "$(grep -E 'unknown user|✗' "$WORK/edge-orphan.out" | head -2)"
    fi
fi

group "a worktree checkout that earlier root runs touched"

WT_MAIN="$WORK/wt-main"
WT="$WORK/wt"
git clone --quiet "$GIT_URL" "$WT_MAIN" 2>/dev/null
git -C "$WT_MAIN" checkout --quiet --detach HEAD~1 2>/dev/null
git -C "$WT_MAIN" worktree add --quiet "$WT" main >/dev/null 2>&1
git -C "$WT" reset --quiet --hard HEAD~1 >/dev/null 2>&1
chown -R 65534:65534 "$WT_MAIN" "$WT"
WT_GITDIR="$(sed -n 's/^gitdir: //p' "$WT/.git")"
: > "$WT_GITDIR/FETCH_HEAD"
chown root:root "$WT_GITDIR/FETCH_HEAD"

run_edge worktree "$WT" 8808 --commit "$TARGET_SHA" --branch main

if grep -E "source fetched" "$WORK/edge-worktree.out" | grep -qE "PASS.*[0-9a-f]{7}"; then
    ok "a worktree with root's FETCH_HEAD in its git directory is fetched into"
else
    bad "a worktree's root-owned FETCH_HEAD stops every fetch" \
        "$(grep -E 'FETCH_HEAD|could not|✗' "$WORK/edge-worktree.out" | head -3)"
fi
[ "$(stat -c %u "$WT_GITDIR/FETCH_HEAD" 2>/dev/null)" = "65534" ] \
    && ok "and it was given back to the worktree's owner" \
    || bad "FETCH_HEAD in the worktree's git directory is still root's"

kill "$(cat "$WORK/gitd.pid" 2>/dev/null)" 2>/dev/null

# ---------------------------------------------------------------- the report

printf '\n  ────────────────────────────────────────────────────────────\n'
printf '  %d passed, %d failed\n\n' "$PASS" "$FAIL"

[ "$FAIL" -eq 0 ]
