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

# ---------------------------------------------------------------- the report

printf '\n  ────────────────────────────────────────────────────────────\n'
printf '  %d passed, %d failed\n\n' "$PASS" "$FAIL"

[ "$FAIL" -eq 0 ]
