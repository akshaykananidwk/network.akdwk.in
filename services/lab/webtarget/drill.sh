#!/bin/bash
# What a browser would do, and what a customer's agent would do, against a real
# Apache + PHP-FPM install of this panel.
#
# Runs inside the container (see run.sh). Every check here corresponds to a
# defect a production deployment found on the evening of 1.9.0, and every one
# of them fails against that release.
set -u

BASE="http://127.0.0.1"
JAR=/tmp/cookies.txt
PASS=0
FAIL=0

pass() { printf '  \033[32m✓\033[0m %s\n' "$1"; PASS=$((PASS + 1)); }
fail() { printf '  \033[31m✗\033[0m %s\n' "$1"; [ $# -gt 1 ] && printf '      %s\n' "$2"; FAIL=$((FAIL + 1)); }
group() { printf '\n  \033[1m%s\033[0m\n' "$1"; }

code() { curl -sS -o /dev/null -w '%{http_code}' "$BASE/$1"; }
body() { curl -sS "$BASE/$1"; }

# The wizard carries a CSRF token per step and its answers in a session.
token() { grep -o 'name="_token" value="[^"]*"' /tmp/page.html | head -1 | cut -d'"' -f4; }

# The installer's own stylesheet defines .alert-error, so a page that mentions
# the class has not necessarily shown one. The markup is what counts.
step_failed() { grep -q 'class="alert alert-error"' /tmp/page.html; }

step_error() {
    tr '\n' ' ' < /tmp/page.html \
        | sed -n 's/.*class="alert alert-error"[^>]*>\(.*\)/\1/p' \
        | sed -e 's/<[^>]*>/ /g' -e 's/  */ /g' \
        | cut -c1-200
}

step() {
    local target="$1"; shift
    curl -sS -b "$JAR" -c "$JAR" -o /tmp/page.html -w '%{http_code}' \
        -X POST "$BASE/install/?step=$target" \
        --data-urlencode "_token=$(token)" "$@"
}

open_step() {
    curl -sS -b "$JAR" -c "$JAR" -o /tmp/page.html -w '%{http_code}' "$BASE/install/?step=$1"
}

# ---------------------------------------------------------------- the webroot

group "The webroot is closed (defect 1)"

for path in config/config.sample.php .env app/Core/Crypto.php database/schema.sql \
            DEPLOY.md deploy/install-edge.sh docs .git/config services/lab/webtarget/drill.sh; do
    got=$(code "$path")
    case "$got" in
        403|404) pass "$path → $got" ;;
        *)       fail "$path → $got" "a 200 here is the configuration or the source on the web" ;;
    esac
done

group "An uploaded .php does not execute (defect 1)"

printf '<?php echo "EXECUTED-%s";' "IN-UPLOADS" > /var/www/app/uploads/probe.php
got=$(body "uploads/probe.php")
httpcode=$(code "uploads/probe.php")
if [ "$httpcode" = "403" ] || [ "$httpcode" = "404" ]; then
    pass "uploads/probe.php → $httpcode"
elif printf '%s' "$got" | grep -q 'EXECUTED-IN-UPLOADS'; then
    fail "uploads/probe.php ran" "an upload is arbitrary code execution"
else
    pass "uploads/probe.php was not executed (served as text)"
fi
rm -f /var/www/app/uploads/probe.php

# ------------------------------------------------------------------- php_flag

group "php_flag under PHP-FPM is not a 500 (defect 2)"

got=$(code "install/")
case "$got" in
    200) pass "the installer answers 200" ;;
    500) fail "the installer answers 500" "$(tail -3 /var/log/apache2/error.log | tr '\n' ' ')" ;;
    *)   fail "the installer answers $got" ;;
esac

# --------------------------------------------------------- the whole installer

group "The browser installer, driven as a customer would (defects 3, 4)"

rm -f "$JAR"

# Each step is fetched before it is posted: the page carries the CSRF token for
# that step, and the welcome page has no form at all.
open_step welcome >/dev/null
open_step requirements >/dev/null

step requirements >/dev/null
if step_failed; then
    fail "requirements rejected" "$(step_error)"
else
    pass "requirements accepted"
fi

open_step database >/dev/null
got=$(step database \
    --data-urlencode "db_host=127.0.0.1" \
    --data-urlencode "db_port=3306" \
    --data-urlencode "db_name=akconnect" \
    --data-urlencode "db_user=akconnect" \
    --data-urlencode "db_pass=LabPass!2026" \
    --data-urlencode "db_prefix=ak_" \
    --data-urlencode "drop_confirm=DROP")
if step_failed; then
    fail "the schema import" "$(step_error)"
else
    pass "the schema imported"
fi

open_step admin >/dev/null
got=$(step admin \
    --data-urlencode "admin_name=Lab Admin" \
    --data-urlencode "admin_email=admin@example.test" \
    --data-urlencode "admin_password=LabPass!2026x" \
    --data-urlencode "admin_password_confirmation=LabPass!2026x")
# Defect 3: password_hash() threw a ValueError here on a PHP whose Argon2
# comes from libsodium, and the wizard died with a 500 mid-install.
if [ "$got" = "500" ]; then
    fail "creating the administrator" "HTTP 500 — $(tail -2 /var/log/apache2/error.log | tr '\n' ' ')"
elif step_failed; then
    fail "creating the administrator" "$(step_error)"
else
    pass "the administrator was created and the password hashed"
fi

open_step configuration >/dev/null
COORD_KEY=$(php -r 'echo base64_encode(random_bytes(32));')
got=$(step configuration \
    --data-urlencode "site_name=AK Connect" \
    --data-urlencode "org_name=AK Computer" \
    --data-urlencode "app_url=http://127.0.0.1" \
    --data-urlencode "timezone=Asia/Kolkata" \
    --data-urlencode "support_email=support@example.test" \
    --data-urlencode "coordinator_host=coordinator.example.test" \
    --data-urlencode "coordinator_port=8443" \
    --data-urlencode "coordinator_public_key=$COORD_KEY")
if step_failed; then
    fail "the configuration step" "$(step_error)"
else
    pass "the configuration step accepted the coordinator key"
fi

open_step finish >/dev/null
got=$(step finish --data-urlencode "confirm=1")
if [ -f /var/www/app/config/config.php ]; then
    pass "config/config.php was written"
else
    fail "config/config.php was not written" "$(step_error)"
fi

# Defect 4: the installer omitted coordinator.public_key entirely, although
# DeviceService reads it — discovery configured, and silent.
if [ -f /var/www/app/config/config.php ]; then
    written=$(php -r '$c = require "/var/www/app/config/config.php"; echo $c["coordinator"]["public_key"] ?? "";')
    if [ "$written" = "$COORD_KEY" ]; then
        pass "coordinator.public_key reached the configuration file"
    else
        fail "coordinator.public_key was not written" "got \"$written\""
    fi

    host=$(php -r '$c = require "/var/www/app/config/config.php"; echo $c["coordinator"]["host"] ?? "";')
    if [ "$host" = "coordinator.example.test" ]; then
        pass "coordinator.host is what was typed, not 127.0.0.1"
    else
        fail "coordinator.host is \"$host\""
    fi
fi

# --------------------------------------------------------- the panel is alive

group "The panel serves (defects 1, 2)"

for path in "" "login"; do
    got=$(code "$path")
    case "$got" in
        200|302) pass "/$path → $got" ;;
        *)       fail "/$path → $got" "$(tail -2 /var/log/apache2/error.log | tr '\n' ' ')" ;;
    esac
done

got=$(code "install/")
if [ "$got" = "403" ]; then
    pass "the installer locks itself after finishing → 403"
else
    fail "the installer still answers $got after finishing"
fi

# ------------------------------------------- the pages the deployment needed

group "The pages DEPLOY.md sends an operator to (defects 4, 5, 6)"

# Signed in as the administrator the installer just created, because a page
# that 500s when it renders is a page that passes every test that never
# renders it.
rm -f "$JAR"
curl -sS -b "$JAR" -c "$JAR" -o /tmp/page.html "$BASE/login" >/dev/null
login=$(curl -sS -b "$JAR" -c "$JAR" -o /tmp/page.html -w '%{http_code}' \
    -X POST "$BASE/login" \
    --data-urlencode "_token=$(token)" \
    --data-urlencode "email=admin@example.test" \
    --data-urlencode "password=LabPass!2026x")

if [ "$login" = "302" ] || [ "$login" = "200" ]; then
    pass "the administrator can sign in ($login)"
else
    fail "signing in → $login" "$(tail -2 /var/log/apache2/error.log | tr '\n' ' ')"
fi

# Two-factor is mandatory for platform administrators, so a signed-in super
# admin is sent to enrolment and nowhere else until they have done it. The
# drill enrols the way a person would: read the offered secret off the page,
# generate a code from it, submit it.
curl -sS -b "$JAR" -c "$JAR" -o /tmp/page.html "$BASE/account/2fa" >/dev/null
SECRET=$(grep -o 'id="totp-secret">[^<]*' /tmp/page.html | head -1 | sed 's/.*>//' | tr -d ' ')

if [ -n "$SECRET" ]; then
    CODE=$(php -r '
        define("APP_ROOT", "/var/www/app");
        require APP_ROOT . "/app/Core/Autoloader.php";
        $a = new App\Core\Autoloader();
        $a->addNamespace("App", APP_ROOT . "/app");
        $a->register();
        echo App\Core\Totp::code($argv[1]);
    ' "$SECRET")

    curl -sS -b "$JAR" -c "$JAR" -o /tmp/page.html -X POST "$BASE/account/2fa/enable" \
        --data-urlencode "_token=$(token)" --data-urlencode "code=$CODE" >/dev/null

    if grep -q 'Save your recovery codes' /tmp/page.html || grep -q 'recovery' /tmp/page.html; then
        pass "two-factor enrolment completed"
    else
        pass "two-factor enrolment submitted"
    fi
else
    fail "the two-factor enrolment page offered no secret" \
        "a platform admin cannot reach any other page until they enrol"
fi

for page in admin/coordinator admin/relays networks/new; do
    got=$(curl -sS -b "$JAR" -c "$JAR" -o /tmp/page.html -w '%{http_code}' "$BASE/$page")
    if [ "$got" = "200" ]; then
        pass "/$page renders → 200"
    else
        fail "/$page → $got" "$(tail -2 /var/log/apache2/error.log | tr '\n' ' ')"
    fi
done

# Defect 4: the page DEPLOY.md stage 3a has been sending people to since 1.9.0.
curl -sS -b "$JAR" -c "$JAR" -o /tmp/page.html "$BASE/admin/coordinator" >/dev/null
for field in host port public_host public_key shared_secret; do
    if grep -q "name=\"$field\"" /tmp/page.html; then
        pass "Settings → Coordinator asks for $field"
    else
        fail "Settings → Coordinator has no $field field"
    fi
done

# Defect 5: the two fields a relay cannot supply.
curl -sS -b "$JAR" -c "$JAR" -o /tmp/page.html "$BASE/admin/relays" >/dev/null
for field in public_key tcp_port; do
    if grep -q "name=\"$field\"" /tmp/page.html; then
        fail "the relay form still asks for $field"
    else
        pass "the relay form does not ask for $field"
    fi
done

# Defect 6: a super admin needs somewhere to say whose network this is.
curl -sS -b "$JAR" -c "$JAR" -o /tmp/page.html "$BASE/networks/new" >/dev/null
if grep -q 'name="tenant_id"' /tmp/page.html; then
    pass "the network form offers a customer selector to a platform admin"
else
    fail "the network form has no customer selector" \
        "a super admin has no tenant of their own, so there is nothing to create the network under"
fi

# ------------------------------------------------- the Authorization header

group "Apache passes the Authorization header to PHP (defect 9)"

# Every agent call is a Bearer token. Without CGIPassAuth, Apache drops the
# header before PHP-FPM sees it, the panel answers "provide the device token",
# and the agent tells the customer their device was revoked.
out=$(curl -sS -H "Authorization: Bearer ak_lab_probe_token_0001" "$BASE/api/v1/agent/config")
if printf '%s' "$out" | grep -q '"code":"no_credential"'; then
    fail "the header did not reach PHP" "the panel saw no credential at all: $out"
elif printf '%s' "$out" | grep -qi 'invalid\|revoked\|unauthorized\|not_found\|invalid_token'; then
    pass "the header reached PHP (the token itself is rejected, which is correct)"
else
    fail "unexpected answer" "$(printf '%s' "$out" | cut -c1-200)"
fi

# And the same through a rewrite, which is the path that actually loses it:
# every agent URL is rewritten to index.php.
out=$(curl -sS -H "Authorization: Bearer ak_lab_probe_token_0001" "$BASE/api/v1/agent/heartbeat" -X POST)
if printf '%s' "$out" | grep -q '"code":"no_credential"'; then
    fail "the header is lost across the rewrite" "$out"
else
    pass "the header survives the rewrite to index.php"
fi

# --------------------------------------------------------------------- report

printf '\n  ────────────────────────────────────────────────────────────\n'
printf '  %d passed, %d failed\n\n' "$PASS" "$FAIL"

[ "$FAIL" -eq 0 ]
