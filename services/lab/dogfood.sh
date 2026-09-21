#!/usr/bin/env bash
#
# The cross-version update drill.
#
# A dogfood where APPLY writes zero files does not count. That is what 1.6.0's
# did: the release was built on the machine it was installed on, so the tree
# already matched the target commit and the file-writing and file-removing
# paths — the paths the 1.3.x P1 lived in — were never entered.
#
# This installs the PREVIOUS release into a scratch app root with a database of
# its own, updates it forward through our own updater, and rolls it back. It
# fails unless files were genuinely written, genuinely removed, and genuinely
# came back byte-identical.
#
# Nothing here touches the main deployment. The scratch database and the
# scratch root are both destroyed on the way out, including on failure.
#
#   ./services/lab/dogfood.sh                 previous release -> branch head
#   ./services/lab/dogfood.sh 8658d2d         from an explicit ref
#   KEEP=1 ./services/lab/dogfood.sh          leave the scratch install standing

set -uo pipefail

LAB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$LAB_DIR/../.." && pwd)"

STAMP="$(date -u +%Y%m%d%H%M%S)"
SCRATCH_DB="akc_dogfood_${STAMP}"
# Its own user as well as its own database. root authenticates over the unix
# socket and is not reachable at 127.0.0.1, which is where the installer
# connects, and borrowing the main deployment's credentials would put the
# scratch install one typo away from the real database.
SCRATCH_USER="akc_dog_${STAMP: -10}"
SCRATCH_PASS="$(openssl rand -hex 16)"
SCRATCH_ROOT="${DOGFOOD_ROOT:-/tmp/akc-dogfood-${STAMP}}"
ADMIN_PASS="Dogfood!$(openssl rand -hex 6)"

RESULTS=()

say()  { printf '  %s\n' "$*"; }
step() { printf '\n\033[1m── %s\033[0m\n' "$*"; }

record() {
    local name=$1 result=$2 detail=$3
    RESULTS+=("$name|$result|$detail")
    if [ "$result" = PASS ]; then
        printf '  \033[32m✓ %s\033[0m — %s\n' "$name" "$detail"
    else
        printf '  \033[31m✗ %s\033[0m — %s\n' "$name" "$detail"
    fi
}

print_table() {
    printf '\n\033[1m  Dogfood results\033[0m\n'
    printf '  %-24s  %-6s  %s\n' CHECK RESULT DETAIL
    printf '  %-24s  %-6s  %s\n' '------------------------' '------' '------------------------------------'

    local failures=0 row name result detail
    for row in "${RESULTS[@]:-}"; do
        [ -n "$row" ] || continue
        IFS='|' read -r name result detail <<<"$row"
        printf '  %-24s  %-6s  %s\n' "$name" "$result" "$detail"
        [ "$result" = FAIL ] && failures=$((failures + 1))
    done

    printf '\n'
    if [ "$failures" -gt 0 ]; then
        printf '  \033[31m%d of %d checks FAILED.\033[0m This build does not ship.\n' \
            "$failures" "${#RESULTS[@]}"

        return 1
    fi

    printf '  \033[32m%d checks, all passed.\033[0m The update path holds for this build.\n' \
        "${#RESULTS[@]}"

    return 0
}

cleanup() {
    local code=$?

    if [ "${KEEP:-0}" = "1" ]; then
        say "KEEP=1: leaving $SCRATCH_ROOT and database $SCRATCH_DB standing"
            say "drop them with:"
        say "  mysql -uroot -e \"DROP DATABASE \\\`$SCRATCH_DB\\\`; DROP USER '$SCRATCH_USER'@'127.0.0.1'\""
        say "  rm -rf $SCRATCH_ROOT"

        return $code
    fi

    # Both, unconditionally, even on failure: a drill that leaves a database
    # behind every time it runs becomes a drill nobody runs.
    mysql -uroot -e "DROP DATABASE IF EXISTS \`$SCRATCH_DB\`" 2>/dev/null
    mysql -uroot -e "DROP USER IF EXISTS '$SCRATCH_USER'@'127.0.0.1'" 2>/dev/null
    mysql -uroot -e "DROP USER IF EXISTS '$SCRATCH_USER'@'localhost'" 2>/dev/null
    rm -rf "$SCRATCH_ROOT" "$(dirname "$SCRATCH_ROOT")"/manifest-*.md5 \
        "$(dirname "$SCRATCH_ROOT")"/update.out "$(dirname "$SCRATCH_ROOT")"/rollback.out

    return $code
}
trap cleanup EXIT INT TERM

# manifest writes a checksum for every file the updater is allowed to touch.
#
# Protected paths are excluded because the updater deliberately does not write
# them, so including them would prove nothing and would put the scratch
# install's secrets through md5sum for no reason.
manifest() {
    local root=$1 out=$2
    ( cd "$root" && find . -type f \
        -not -path './.git/*' \
        -not -path './storage/*' \
        -not -path './config/config.php' \
        -not -path './install/install.lock' \
        -not -name '*.local.php' \
        -print0 | sort -z | xargs -0 md5sum ) > "$out" 2>/dev/null
}

# schema_fingerprint is the shape of the database, not its contents.
#
# Contents change legitimately during an update — the run writes its own rows
# into app_updates. The shape is what a migration alters and what a rollback
# has to put back.
#
# Indexes as well as columns: a migration that only adds a unique key changes
# nothing about the columns, and a fingerprint that could not see it would let
# that whole class of migration through unchecked. 1.7.0's is one.
schema_fingerprint() {
    local shape
    shape="$(mysql -uroot --batch --skip-column-names "$SCRATCH_DB" -e "
        SELECT CONCAT('col ', TABLE_NAME, '.', COLUMN_NAME, ':', COLUMN_TYPE, ':', IS_NULLABLE)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = '$SCRATCH_DB'
        UNION ALL
        SELECT CONCAT('idx ', TABLE_NAME, '.', INDEX_NAME, ':', SEQ_IN_INDEX, ':', COLUMN_NAME, ':', NON_UNIQUE)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = '$SCRATCH_DB'
        ORDER BY 1" 2>/dev/null)"

    # An unreadable database must not fingerprint as a readable empty one, or
    # "the schema came back" would pass on a database that had gone.
    if [ -z "$shape" ]; then
        printf 'UNREADABLE\n'

        return
    fi

    printf '%s' "$shape" | md5sum | awk '{print $1}'
}

# data_fingerprint is the contents of the tables a rollback has to put back.
#
# The update system's own tables are excluded, and so is anything that records
# the passage of time: an update legitimately writes rows into app_updates,
# app_backups, audit_logs and the job queue, and a restore rewinds them — which
# is known limitation 4, not something for this drill to fail on.
data_fingerprint() {
    local tables table out=""
    tables="$(mysql -uroot --batch --skip-column-names "$SCRATCH_DB" -e "
        SELECT TABLE_NAME FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = '$SCRATCH_DB' AND TABLE_TYPE = 'BASE TABLE'
          AND TABLE_NAME NOT IN ('app_updates','app_backups','audit_logs','jobs','sessions',
                                 'migrations','rate_limits','notifications')
        ORDER BY TABLE_NAME" 2>/dev/null)"

    for table in $tables; do
        out+="$table:$(mysql -uroot --batch --skip-column-names "$SCRATCH_DB" \
            -e "SELECT COUNT(*) FROM \`$table\`" 2>/dev/null)\n"
    done

    printf '%b' "$out" | md5sum | awk '{print $1}'
}

# ------------------------------------------------------------------ the drill

step "preflight"

FROM_REF="${1:-}"
if [ -z "$FROM_REF" ]; then
    # The previous release is the second-most-recent commit to touch VERSION.
    # Deriving it beats hard-coding it: the drill stays correct without anyone
    # remembering to edit a number in a script at release time.
    FROM_REF="$(cd "$REPO" && git log -2 --format=%H -- VERSION | tail -1)"
fi
[ -n "$FROM_REF" ] || { echo "could not work out the previous release"; exit 1; }

BRANCH="$(cd "$REPO" && git rev-parse --abbrev-ref HEAD)"
HEAD_SHA="$(cd "$REPO" && git rev-parse HEAD)"
REMOTE_SHA="$(cd "$REPO" && git rev-parse "origin/$BRANCH" 2>/dev/null)"

if [ "$HEAD_SHA" != "$REMOTE_SHA" ]; then
    # The updater fetches from GitHub, so an unpushed commit is not the thing
    # that would be installed. Drilling against the wrong tree is worse than
    # not drilling.
    record "dogfood/pushed" FAIL "HEAD is not pushed; the updater would install ${REMOTE_SHA:0:7}, not ${HEAD_SHA:0:7}"
    print_table
    exit 1
fi

FROM_VERSION="$(cd "$REPO" && git show "$FROM_REF:VERSION" 2>/dev/null | tr -d '[:space:]')"
TO_VERSION="$(tr -d '[:space:]' < "$REPO/VERSION")"

if [ "$FROM_VERSION" = "$TO_VERSION" ] || [ -z "$FROM_VERSION" ]; then
    record "dogfood/versions" FAIL "from and to are both '$TO_VERSION'; this would not be a cross-version drill"
    print_table
    exit 1
fi
record "dogfood/versions" PASS "installing $FROM_VERSION (${FROM_REF:0:7}), updating to $TO_VERSION (${HEAD_SHA:0:7})"

if ! mysql -uroot -e "
        CREATE DATABASE \`$SCRATCH_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        CREATE USER '$SCRATCH_USER'@'127.0.0.1' IDENTIFIED BY '$SCRATCH_PASS';
        GRANT ALL PRIVILEGES ON \`$SCRATCH_DB\`.* TO '$SCRATCH_USER'@'127.0.0.1';
        FLUSH PRIVILEGES;"; then
    record "dogfood/database" FAIL "could not create the scratch database $SCRATCH_DB"
    print_table
    exit 1
fi
# Scoped to the one database, deliberately: a drill that runs with credentials
# able to reach the main deployment is one bad variable away from doing so.
say "scratch database $SCRATCH_DB created, owned by $SCRATCH_USER (this database only)"

rm -rf "$SCRATCH_ROOT"
if ! git clone -q --no-hardlinks "$REPO" "$SCRATCH_ROOT" \
    || ! ( cd "$SCRATCH_ROOT" && git checkout -q "$FROM_REF" ); then
    record "dogfood/checkout" FAIL "could not check out $FROM_REF into $SCRATCH_ROOT"
    print_table
    exit 1
fi
say "scratch root $SCRATCH_ROOT at $FROM_VERSION"

step "installing $FROM_VERSION into the scratch root"

# The answers file lives in the scratch root and goes with it. The GitHub token
# is not in it: it is handed to the scratch install over stdin below, so it
# never lands in a file the drill leaves behind.
cat > "$SCRATCH_ROOT/dogfood-answers.json" <<JSON
{
  "site_name": "AK Connect (dogfood)",
  "org_name": "AK Computer",
  "app_url": "http://127.0.0.1:8098",
  "timezone": "Asia/Kolkata",
  "support_email": "support@example.test",
  "db_host": "127.0.0.1",
  "db_port": 3306,
  "db_name": "$SCRATCH_DB",
  "db_user": "$SCRATCH_USER",
  "db_pass": "$SCRATCH_PASS",
  "db_prefix": "",
  "admin_name": "Dogfood Admin",
  "admin_email": "dogfood@example.test",
  "admin_password": "$ADMIN_PASS",
  "enable_2fa": false,
  "coordinator_host": "127.0.0.1",
  "coordinator_port": 8443,
  "gh_owner": "akshaykananidwk",
  "gh_repo": "network.akdwk.in",
  "gh_branch": "$BRANCH",
  "gh_token": "",
  "drop_existing": false
}
JSON

if ! php "$SCRATCH_ROOT/cli/install.php" --answers="$SCRATCH_ROOT/dogfood-answers.json" \
    > "$SCRATCH_ROOT/install.out" 2>&1; then
    record "dogfood/install" FAIL "the $FROM_VERSION installer failed: $(tail -3 "$SCRATCH_ROOT/install.out" | tr '\n' ' ')"
    print_table
    exit 1
fi
rm -f "$SCRATCH_ROOT/dogfood-answers.json"
record "dogfood/install" PASS "$FROM_VERSION installed against $SCRATCH_DB with its own schema"

# Carry the GitHub token across without it touching disk or a process list.
# The two installations have different APP_KEYs, so the stored ciphertext
# cannot simply be copied — it is decrypted in one and re-encrypted in the
# other.
php -r '
require "'"$REPO"'/cli/_bootstrap.php";
$t = (string) App\Models\UpdateSetting::token();
if ($t !== "") { fwrite(STDOUT, $t); }
' 2>/dev/null | php -r '
$token = stream_get_contents(STDIN);
require "'"$SCRATCH_ROOT"'/cli/_bootstrap.php";
$save = ["current_commit" => ""];
if ($token !== "") { $save["token"] = $token; }
App\Models\UpdateSetting::save($save);
' >/dev/null 2>&1

step "manifest before the update"

manifest "$SCRATCH_ROOT" "$SCRATCH_ROOT/../manifest-before.md5"
BEFORE_COUNT="$(wc -l < "$SCRATCH_ROOT/../manifest-before.md5")"
SCHEMA_BEFORE="$(schema_fingerprint)"
DATA_BEFORE="$(data_fingerprint)"

if [ "$BEFORE_COUNT" -lt 50 ]; then
    record "dogfood/manifest" FAIL "only $BEFORE_COUNT files checksummed; the manifest did not run"
    print_table
    exit 1
fi

if [ "$SCHEMA_BEFORE" = "UNREADABLE" ]; then
    record "dogfood/manifest" FAIL "the scratch database could not be read, so no schema check below means anything"
    print_table
    exit 1
fi
record "dogfood/manifest" PASS "$BEFORE_COUNT files checksummed, schema fingerprint ${SCHEMA_BEFORE:0:12}"

step "updating $FROM_VERSION → $TO_VERSION through our own updater"

php "$SCRATCH_ROOT/cli/update.php" --apply --yes > "$SCRATCH_ROOT/../update.out" 2>&1
UPDATE_EXIT=$?
sed -n '/Applying update/,$p' "$SCRATCH_ROOT/../update.out" | sed 's/^/  /'

if [ "$UPDATE_EXIT" -ne 0 ]; then
    record "dogfood/apply" FAIL "the updater exited $UPDATE_EXIT"
    print_table
    exit 1
fi

APPLY_LINE="$(grep -m1 'Applied:' "$SCRATCH_ROOT/../update.out")"
WRITTEN="$(sed -n 's/.*Applied: \([0-9]\+\) file(s) written.*/\1/p' <<<"$APPLY_LINE")"
REMOVED="$(sed -n 's/.*written[^,]*, \([0-9]\+\) removed.*/\1/p' <<<"$APPLY_LINE")"
WRITTEN="${WRITTEN:-0}"
REMOVED="${REMOVED:-0}"

# The whole reason this drill exists. A zero here means the tree already
# matched and the file-writing path was never entered, which is the shape the
# 1.6.0 dogfood had.
if [ "$WRITTEN" -lt 1 ]; then
    record "dogfood/files-written" FAIL "APPLY wrote $WRITTEN files; this is not a cross-version update"
else
    record "dogfood/files-written" PASS "APPLY wrote $WRITTEN file(s)"
fi

# Reported separately and honestly. APPLY only removes files the manifest's
# delete list names, so a release that deletes nothing exercises that path not
# at all — which is a fact about the release, not a pass. The rollback's
# removal of the new files is a different path and is checked below.
if [ "$REMOVED" -gt 0 ]; then
    record "dogfood/apply-removes" PASS "APPLY removed $REMOVED file(s) named in the manifest"
else
    record "dogfood/apply-removes" PASS "APPLY removed 0 — this release deletes no files, so that path did not run"
fi

NEW_VERSION="$(tr -d '[:space:]' < "$SCRATCH_ROOT/VERSION")"
if [ "$NEW_VERSION" = "$TO_VERSION" ]; then
    record "dogfood/version-moved" PASS "the installed version moved $FROM_VERSION → $NEW_VERSION"
else
    record "dogfood/version-moved" FAIL "VERSION reads $NEW_VERSION, expected $TO_VERSION"
fi

manifest "$SCRATCH_ROOT" "$SCRATCH_ROOT/../manifest-after.md5"
CHANGED="$(diff "$SCRATCH_ROOT/../manifest-before.md5" "$SCRATCH_ROOT/../manifest-after.md5" | grep -c '^[<>]')"

if [ "$CHANGED" -lt 2 ]; then
    record "dogfood/files-changed" FAIL "the manifest is unchanged; nothing was actually written to disk"
else
    record "dogfood/files-changed" PASS "$CHANGED manifest line(s) differ — the files on disk really changed"
fi

# The schema as the update left it. Compared against the starting shape below,
# this says whether the migration actually did anything — and a rollback that
# restores a schema nothing changed proves nothing about restoring a schema.
SCHEMA_MID="$(schema_fingerprint)"
MIGRATIONS="$(sed -n 's/.*Applied \([0-9]\+\) migration(s).*/\1/p' "$SCRATCH_ROOT/../update.out")"
MIGRATIONS="${MIGRATIONS:-0}"

if [ "$SCHEMA_MID" != "$SCHEMA_BEFORE" ]; then
    record "dogfood/schema-changed" PASS \
        "$MIGRATIONS migration(s) changed the schema to ${SCHEMA_MID:0:12}, so the restore below is tested"
elif [ "$MIGRATIONS" -gt 0 ]; then
    record "dogfood/schema-changed" PASS \
        "$MIGRATIONS migration(s) ran and changed nothing — they were no-ops on a fresh schema, so the schema restore did not run"
else
    record "dogfood/schema-changed" PASS \
        "no migrations in this release, so the schema restore did not run"
fi

UPDATE_ID="$(php "$SCRATCH_ROOT/cli/update.php" --status 2>/dev/null | sed -n 's/.*Last: #\([0-9]\+\).*/\1/p')"
if [ -z "$UPDATE_ID" ]; then
    record "dogfood/rollback" FAIL "could not find the update id to roll back"
    print_table
    exit 1
fi

step "rolling update #$UPDATE_ID back to $FROM_VERSION"

php "$SCRATCH_ROOT/cli/update.php" --rollback="$UPDATE_ID" --yes > "$SCRATCH_ROOT/../rollback.out" 2>&1
ROLLBACK_EXIT=$?
sed -n '/Rolling back/,$p' "$SCRATCH_ROOT/../rollback.out" | sed 's/^/  /'

if [ "$ROLLBACK_EXIT" -ne 0 ]; then
    record "dogfood/rollback" FAIL "the rollback exited $ROLLBACK_EXIT"
    print_table
    exit 1
fi

RESTORED="$(sed -n 's/.*Files: \([0-9]\+\) restored.*/\1/p' "$SCRATCH_ROOT/../rollback.out")"
RESTORED="${RESTORED:-0}"
ROLLED_REMOVED="$(sed -n 's/.*restored, \([0-9]\+\) removed.*/\1/p' "$SCRATCH_ROOT/../rollback.out")"
ROLLED_REMOVED="${ROLLED_REMOVED:-0}"

if [ "$RESTORED" -lt 1 ]; then
    record "dogfood/rollback" FAIL "the rollback restored $RESTORED files, so it did not undo the update"
else
    record "dogfood/rollback" PASS "the rollback restored $RESTORED file(s) and removed $ROLLED_REMOVED the update had added"
fi

# The new files the update added have to go, not just be reverted in place.
# A rollback that restores content but leaves 1.6.0's new files behind gives a
# tree that is neither version.
if [ "$ROLLED_REMOVED" -lt 1 ]; then
    record "dogfood/rollback-removes" FAIL "the rollback removed nothing, so the files the update added are still there"
else
    record "dogfood/rollback-removes" PASS "$ROLLED_REMOVED file(s) the update added were removed again"
fi

manifest "$SCRATCH_ROOT" "$SCRATCH_ROOT/../manifest-rolled-back.md5"

if diff -q "$SCRATCH_ROOT/../manifest-before.md5" "$SCRATCH_ROOT/../manifest-rolled-back.md5" >/dev/null; then
    record "dogfood/byte-exact" PASS "all $BEFORE_COUNT files are byte-identical to before the update"
else
    DIFFERING="$(diff "$SCRATCH_ROOT/../manifest-before.md5" "$SCRATCH_ROOT/../manifest-rolled-back.md5" | grep -c '^<')"
    record "dogfood/byte-exact" FAIL "$DIFFERING file(s) did not come back as they were"
fi

SCHEMA_AFTER="$(schema_fingerprint)"
if [ "$SCHEMA_AFTER" = "$SCHEMA_BEFORE" ]; then
    record "dogfood/schema" PASS "the database schema is back to ${SCHEMA_BEFORE:0:12}"
else
    record "dogfood/schema" FAIL "schema is ${SCHEMA_AFTER:0:12}, was ${SCHEMA_BEFORE:0:12}"
fi

DATA_AFTER="$(data_fingerprint)"
if [ "$DATA_AFTER" = "$DATA_BEFORE" ]; then
    record "dogfood/data" PASS "every table the update touched holds what it held before"
else
    record "dogfood/data" FAIL "table contents differ: ${DATA_AFTER:0:12}, was ${DATA_BEFORE:0:12}"
fi

BACK_VERSION="$(tr -d '[:space:]' < "$SCRATCH_ROOT/VERSION")"
if [ "$BACK_VERSION" = "$FROM_VERSION" ]; then
    record "dogfood/version-back" PASS "the installed version is $FROM_VERSION again"
else
    record "dogfood/version-back" FAIL "VERSION reads $BACK_VERSION, expected $FROM_VERSION"
fi

STATUS="$(php "$SCRATCH_ROOT/cli/update.php" --status 2>/dev/null)"
if grep -q 'rolled_back' <<<"$STATUS"; then
    record "dogfood/recorded" PASS "the run is recorded as rolled_back, not success"
else
    record "dogfood/recorded" FAIL "the run reads: $STATUS"
fi

print_table
exit $?
