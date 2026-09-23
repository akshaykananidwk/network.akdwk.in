#!/usr/bin/env bash
#
# AK Connect — one command on a fresh server.
#
#   curl -fsSL https://raw.githubusercontent.com/akshaykananidwk/network.akdwk.in/release/1.9.7/deploy/getting-started.sh | bash
#
# It asks three questions — domain, administrator email, release channel — and
# then installs and configures everything the product needs: Caddy with
# automatic Let's Encrypt certificates, PHP-FPM, MariaDB, the panel, the
# coordinator, a relay, the HTTPS fallback route, the firewall, the systemd
# units, the five-minute worker and the edge upgrade timer. It finishes with
# the panel's address and a one-time link for setting the administrator
# password.
#
#   --domain <name>        skip the question
#   --email <address>      skip the question
#   --channel stable|beta|edge
#   --unattended           take every default and ask nothing (needs the three above)
#   --branch <ref>         which ref to install (default: release/1.9.7)
#   --uninstall            remove everything this script installed
#   --yes                  do not pause before the uninstall
#
# WHAT THIS SCRIPT IS FOR, AND WHAT IT IS NOT FOR
# -----------------------------------------------
# A server of its own, with nothing else on it. It installs a web server,
# opens firewall ports, creates a database and takes port 80 and 443 for one
# domain. On a machine that is already serving somebody — a shared VPS, a box
# with a control panel, anything with other people's sites or mail on it —
# none of that is safe, and this script refuses to run when it finds evidence
# of one. Use DEPLOY.md's staged instructions there instead.
#
# Safe to run again: every step checks for what it would create and leaves an
# existing one alone. Running it twice does not reinstall the panel, does not
# regenerate the coordinator's keypair — which would orphan every enrolled
# device — and does not touch the database.
set -uo pipefail

REPO_URL="${AKCONNECT_REPO_URL:-https://github.com/akshaykananidwk/network.akdwk.in.git}"
BRANCH="${AKCONNECT_BRANCH:-release/1.9.7}"
SRC_DIR="/opt/akconnect/src"
ETC_DIR="/etc/akconnect"
STATE_FILE="$ETC_DIR/getting-started.state"
DB_NAME="akconnect"
DB_USER="akconnect"
WEB_USER="www-data"
GO_VERSION="1.24.7"

DOMAIN=""
ADMIN_EMAIL=""
CHANNEL=""
UNATTENDED=0
UNINSTALL=0
ASSUME_YES=0

# --------------------------------------------------------------------- output

RED=''; GREEN=''; YELLOW=''; BOLD=''; GREY=''; RESET=''
if [ -t 1 ]; then
    RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'
    BOLD=$'\033[1m'; GREY=$'\033[90m'; RESET=$'\033[0m'
fi

say()  { printf '  %s\n' "$*"; }
note() { printf '  %s%s%s\n' "$GREY" "$*" "$RESET"; }
ok()   { printf '  %s✓%s %s\n' "$GREEN" "$RESET" "$*"; }
warn() { printf '  %s!%s %s\n' "$YELLOW" "$RESET" "$*"; }
step() { printf '\n%s── %s%s\n' "$BOLD" "$*" "$RESET"; }
die()  { printf '\n  %s✗ %s%s\n\n' "$RED" "$*" "$RESET" >&2; exit 1; }

# Every temporary path this script makes, removed however it leaves — a
# successful finish, a die, a failed command, or the operator pressing ^C.
#
# Nothing here should ever hold a secret: the answers the panel installer
# needs go to it on standard input and are never written down. This exists so
# that is true of the build directories and scratch files too, and so an
# interrupted run does not leave them behind.
AKCONNECT_TMP=""

keep_tmp() {
    AKCONNECT_TMP="$AKCONNECT_TMP $1"
    printf '%s\n' "$1"
}

akconnect_cleanup() {
    local path
    for path in $AKCONNECT_TMP; do
        [ -n "$path" ] && rm -rf "$path"
    done
    AKCONNECT_TMP=""
}

trap akconnect_cleanup EXIT INT TERM HUP

# run_quiet keeps a wall of apt output off the screen but hands all of it over
# when something fails — the one moment it is worth reading.
run_quiet() {
    local log
    log="$(keep_tmp "$(mktemp)")"
    if "$@" >"$log" 2>&1; then
        rm -f "$log"

        return 0
    fi

    printf '\n  %scommand failed: %s%s\n' "$RED" "$*" "$RESET" >&2
    sed 's/^/    /' "$log" | tail -40 >&2
    rm -f "$log"

    return 1
}

# ask reads from the terminal, not from stdin.
#
# This script is run as `curl … | bash`, so stdin is the script itself. A read
# without this takes the next line of the script as the answer and installs a
# panel on a domain called something like `set -uo pipefail`.
ask() {
    local prompt=$1 default=${2:-} reply=''

    if [ "$UNATTENDED" -eq 1 ]; then
        printf '%s\n' "$default"

        return 0
    fi

    if [ ! -e /dev/tty ]; then
        [ -n "$default" ] || die "no terminal to ask '$prompt' on, and no default. Pass it as an option."
        printf '%s\n' "$default"

        return 0
    fi

    if [ -n "$default" ]; then
        printf '  %s [%s]: ' "$prompt" "$default" > /dev/tty
    else
        printf '  %s: ' "$prompt" > /dev/tty
    fi
    IFS= read -r reply < /dev/tty || reply=''

    printf '%s\n' "${reply:-$default}"
}

# ------------------------------------------------------------------ arguments

# Printed rather than read out of this file: under `curl … | bash` there is no
# $0 to read, and a --help that prints nothing is worse than none.
usage() {
    cat <<'USAGE'

  AK Connect — one command on a fresh server.

    curl -fsSL https://raw.githubusercontent.com/akshaykananidwk/network.akdwk.in/release/1.9.7/deploy/getting-started.sh | sudo bash

    --domain <name>       the panel's domain, e.g. nb.akdwk.in
    --email <address>     the administrator's email address
    --channel <name>      stable, beta or edge (default: asked, edge)
    --branch <ref>        which ref to install (default: release/1.9.7)
    --unattended          ask nothing; needs --domain, --email and --channel
    --uninstall           remove everything this script installed
    --yes                 do not pause before the uninstall

  A server of its own. It installs a web server, opens firewall ports and
  claims 80 and 443 — on a machine already serving other people's sites,
  every one of those is their outage, and it refuses to run there.

USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --domain)     DOMAIN="${2:-}"; shift 2 || die "--domain needs a value" ;;
        --email)      ADMIN_EMAIL="${2:-}"; shift 2 || die "--email needs a value" ;;
        --channel)    CHANNEL="${2:-}"; shift 2 || die "--channel needs a value" ;;
        --branch)     BRANCH="${2:-}"; shift 2 || die "--branch needs a value" ;;
        --unattended) UNATTENDED=1; shift ;;
        --uninstall)  UNINSTALL=1; shift ;;
        --yes|-y)     ASSUME_YES=1; shift ;;
        -h|--help)    usage; exit 0 ;;
        *)            die "unknown option: $1" ;;
    esac
done

[ "$(id -u)" -eq 0 ] || die "run this as root:  curl -fsSL … | sudo bash"

# ------------------------------------------------------------------ uninstall

# read_state recovers what an earlier run recorded, so --uninstall knows which
# domain and directories it is undoing without being told again.
read_state() {
    [ -f "$STATE_FILE" ] || return 1
    # shellcheck disable=SC1090
    . "$STATE_FILE"

    return 0
}

do_uninstall() {
    printf '\n%sAK Connect — uninstall%s\n' "$BOLD" "$RESET"

    if read_state; then
        DOMAIN="${AKCONNECT_DOMAIN:-$DOMAIN}"
    fi
    [ -n "$DOMAIN" ] || DOMAIN="$(ask 'Domain to remove' '')"
    [ -n "$DOMAIN" ] || die "nothing to remove: no domain given and no $STATE_FILE"

    local panel_dir="/var/www/$DOMAIN"

    printf '\n'
    say "This removes, permanently:"
    say "  - the panel at $panel_dir, and every file in it"
    say "  - the $DB_NAME database, and every device, network and tenant in it"
    say "  - the coordinator's keypair in $ETC_DIR — enrolled devices will not come back"
    say "  - the akconnect services, timers, Caddy site and firewall rules"
    printf '\n'

    if [ "$ASSUME_YES" -ne 1 ]; then
        local confirm
        confirm="$(ask "Type the domain to confirm" '')"
        [ "$confirm" = "$DOMAIN" ] || die "that did not match; nothing has been removed"
    fi

    step "stopping services"
    local unit
    for unit in akconnect-coordinator akconnect-relay akconnect-edge-upgrade.timer \
                akconnect-edge-upgrade.service akconnect-worker.timer akconnect-worker.service; do
        systemctl disable --now "$unit" >/dev/null 2>&1
        rm -f "/etc/systemd/system/$unit"
    done
    # The unit files without a suffix, which the loop above named bare.
    rm -f /etc/systemd/system/akconnect-coordinator.service \
          /etc/systemd/system/akconnect-relay.service
    systemctl daemon-reload >/dev/null 2>&1
    ok "services stopped and unit files removed"

    step "removing the web site"
    rm -f "/etc/caddy/sites/$DOMAIN.caddy"
    if [ -f /etc/caddy/Caddyfile ] && command -v caddy >/dev/null 2>&1; then
        systemctl reload caddy >/dev/null 2>&1 || systemctl restart caddy >/dev/null 2>&1
    fi
    ok "Caddy no longer serves $DOMAIN"

    step "removing the database"
    if command -v mysql >/dev/null 2>&1; then
        if mysql -N -B -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; DROP USER IF EXISTS '$DB_USER'@'localhost'; FLUSH PRIVILEGES;" \
            >/dev/null 2>&1; then
            ok "dropped $DB_NAME and the $DB_USER account"
        else
            warn "could not drop the database — do it by hand if it matters"
        fi
    fi

    step "removing files"
    rm -rf "$panel_dir" "$SRC_DIR" "$ETC_DIR" /var/cache/akconnect
    rm -f /usr/local/bin/akconnect-coordinator /usr/local/bin/akconnect-relay \
          /usr/local/bin/akconnect-maintenance
    ok "panel, source, keys and binaries removed"

    step "firewall"
    if command -v ufw >/dev/null 2>&1; then
        local rule
        for rule in "8443/udp" "9000/udp" "51820/udp" "51900:52400/udp"; do
            ufw --force delete allow "$rule" >/dev/null 2>&1
        done
        ok "the AK Connect UDP rules are gone (80 and 443 were left alone)"
    fi

    printf '\n  %sRemoved.%s Caddy, PHP, MariaDB and Go are still installed — this script\n' "$GREEN" "$RESET"
    printf '  does not uninstall packages another service on this machine may be using.\n\n'
    exit 0
}

[ "$UNINSTALL" -eq 1 ] && do_uninstall

# ------------------------------------------------------------------ preflight

printf '\n%sAK Connect — getting started%s\n' "$BOLD" "$RESET"
note "$BRANCH from $REPO_URL"

step "checking this machine"

if [ -r /etc/os-release ]; then
    # shellcheck disable=SC1091
    . /etc/os-release
    say "${PRETTY_NAME:-unknown system}"
    case "${ID:-}" in
        ubuntu|debian) ;;
        *) warn "this script is written for Ubuntu and Debian; carrying on, but package names may differ" ;;
    esac
fi

case "$(uname -m)" in
    x86_64|amd64) ARCH=amd64 ;;
    aarch64|arm64) ARCH=arm64 ;;
    *) die "unsupported architecture: $(uname -m)" ;;
esac
ok "architecture $ARCH"

# A machine somebody else is already being served by.
#
# This script opens firewall ports, claims 80 and 443 and installs a web
# server. On a box with a control panel or another web server, every one of
# those is somebody else's outage. The check is evidence-based rather than a
# question, because the operator who most needs it is the one who would answer
# "no, go ahead" without looking.
OCCUPIED=""
[ -d /www/server/panel ] && OCCUPIED="aaPanel (/www/server/panel)"
[ -d /usr/local/cpanel ] && OCCUPIED="cPanel"
[ -d /usr/local/psa ]    && OCCUPIED="Plesk"
if [ -z "$OCCUPIED" ] && systemctl is-active --quiet apache2 2>/dev/null; then
    OCCUPIED="Apache, already running and serving something"
fi
if [ -z "$OCCUPIED" ] && systemctl is-active --quiet nginx 2>/dev/null; then
    OCCUPIED="nginx, already running and serving something"
fi

if [ -n "$OCCUPIED" ]; then
    die "this machine is already serving something: $OCCUPIED.

    This script installs a web server, takes ports 80 and 443 and opens
    firewall rules. On a server that is already serving other people's sites
    that is their outage, not a configuration choice.

    Use a server of its own, or follow DEPLOY.md's staged instructions here
    and put nothing on this machine that it does not already have."
fi
ok "nothing else is serving on this machine"

for tool in systemctl curl; do
    command -v "$tool" >/dev/null 2>&1 || die "$tool is missing, and this cannot proceed without it"
done

if ! curl -fsS --max-time 10 -o /dev/null https://github.com 2>/dev/null; then
    die "this server cannot reach https://github.com. Everything below needs it."
fi
ok "outbound HTTPS works"

# ------------------------------------------------------------------ questions

step "three questions"

PUBLIC_IP="$(curl -fsS --max-time 10 https://api.ipify.org 2>/dev/null || true)"
[ -n "$PUBLIC_IP" ] || PUBLIC_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"

[ -n "$DOMAIN" ]      || DOMAIN="$(ask 'Domain for the panel (e.g. nb.akdwk.in)' '')"
[ -n "$DOMAIN" ]      || die "a domain is required: Let's Encrypt issues certificates for names, not addresses"
DOMAIN="$(printf '%s' "$DOMAIN" | tr -d ' ' | sed 's#^https\?://##; s#/.*##')"

[ -n "$ADMIN_EMAIL" ] || ADMIN_EMAIL="$(ask 'Administrator email' '')"
[ -n "$ADMIN_EMAIL" ] || die "an administrator email is required"
case "$ADMIN_EMAIL" in
    ?*@?*.?*) ;;
    *) die "'$ADMIN_EMAIL' does not look like an email address" ;;
esac

[ -n "$CHANNEL" ] || CHANNEL="$(ask 'Release channel — stable, beta or edge' 'edge')"
case "$CHANNEL" in
    stable|beta|edge) ;;
    *) die "channel must be stable, beta or edge — not '$CHANNEL'" ;;
esac

PANEL_URL="https://$DOMAIN"
PANEL_DIR="/var/www/$DOMAIN"

printf '\n'
say "domain   : $DOMAIN"
say "email    : $ADMIN_EMAIL"
say "channel  : $CHANNEL"
say "this host: ${PUBLIC_IP:-unknown}"

step "checking DNS"

# Checked before anything is installed, because Let's Encrypt resolves the
# name from the outside and a certificate that cannot be issued leaves a panel
# nobody can reach over HTTPS — with the whole install already done.
RESOLVED="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk 'NR==1 {print $1}')"

if [ -z "$RESOLVED" ]; then
    warn "$DOMAIN does not resolve yet"
    say "Point an A record at ${PUBLIC_IP:-this server} and wait for it to propagate."
    [ "$UNATTENDED" -eq 1 ] && die "refusing to continue unattended with no DNS"
    [ "$(ask 'Continue anyway? Certificates will fail until DNS is right (yes/no)' 'no')" = "yes" ] \
        || die "stopped. Nothing has been installed."
elif [ -n "$PUBLIC_IP" ] && [ "$RESOLVED" != "$PUBLIC_IP" ]; then
    warn "$DOMAIN resolves to $RESOLVED, but this server is $PUBLIC_IP"
    [ "$UNATTENDED" -eq 1 ] && die "refusing to continue unattended with DNS pointing elsewhere"
    [ "$(ask 'Continue anyway? (yes/no)' 'no')" = "yes" ] || die "stopped. Nothing has been installed."
else
    ok "$DOMAIN resolves to this server"
fi

# ------------------------------------------------------------------- packages

step "installing packages"

export DEBIAN_FRONTEND=noninteractive

run_quiet apt-get update || die "apt-get update failed"

BASE_PACKAGES="ca-certificates curl gnupg git unzip rsync openssl sudo ufw jq zip python3 debian-keyring debian-archive-keyring apt-transport-https"
# shellcheck disable=SC2086  # the list is meant to be split into arguments
run_quiet apt-get install -y $BASE_PACKAGES || die "could not install the base packages"
ok "base tools"

# MariaDB rather than MySQL: it is what the panel is developed and gated
# against, and what every deployment note in DEPLOY.md assumes.
if ! command -v mariadbd >/dev/null 2>&1 && ! command -v mysqld >/dev/null 2>&1; then
    run_quiet apt-get install -y mariadb-server || die "could not install MariaDB"
fi
systemctl enable --now mariadb >/dev/null 2>&1 || systemctl enable --now mysql >/dev/null 2>&1
ok "MariaDB"

# PHP. The version is read from the distribution rather than pinned, so this
# keeps working as Ubuntu moves — but it is checked against the panel's own
# minimum below, where a too-old PHP is a clear message instead of a stack
# trace halfway through the installer.
PHP_SERIES="$(apt-cache search --names-only '^php[0-9]+\.[0-9]+-fpm$' 2>/dev/null \
    | sed 's/^php\([0-9.]*\)-fpm.*/\1/' | sort -V | tail -1)"
[ -n "$PHP_SERIES" ] || die "no phpX.Y-fpm package is available from this system's repositories"

PHP_PACKAGES="php$PHP_SERIES-fpm php$PHP_SERIES-mysql php$PHP_SERIES-curl php$PHP_SERIES-zip
    php$PHP_SERIES-mbstring php$PHP_SERIES-intl php$PHP_SERIES-xml php$PHP_SERIES-gd
    php$PHP_SERIES-bcmath php$PHP_SERIES-opcache"
# shellcheck disable=SC2086  # the list is meant to be split into arguments
run_quiet apt-get install -y $PHP_PACKAGES || die "could not install PHP $PHP_SERIES"

PHP_BIN="$(command -v "php$PHP_SERIES" || command -v php)"
[ -n "$PHP_BIN" ] || die "PHP installed but no php binary is on PATH"

# Checked rather than assumed, and named one by one. A missing extension
# surfaces as an unexplained 500 three steps later otherwise.
MISSING=""
for extension in pdo_mysql openssl curl zip mbstring json fileinfo; do
    "$PHP_BIN" -m 2>/dev/null | grep -qix "$extension" || MISSING="$MISSING $extension"
done
[ -z "$MISSING" ] || die "PHP $PHP_SERIES is missing required extension(s):$MISSING"

# Optional, but it is what verifies a signed update manifest — so it is
# installed if the distribution has it, and its absence is said out loud
# rather than discovered when an update will not apply.
if ! "$PHP_BIN" -m 2>/dev/null | grep -qix sodium; then
    run_quiet apt-get install -y "php$PHP_SERIES-sodium" >/dev/null 2>&1
fi
"$PHP_BIN" -m 2>/dev/null | grep -qix sodium \
    || warn "ext-sodium is not available — signed update manifests cannot be verified"
ok "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;')"

# Caddy, from its own repository. The distribution package lags, and automatic
# certificates are the entire reason it is here.
if ! command -v caddy >/dev/null 2>&1; then
    curl -fsSL 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
        | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg 2>/dev/null \
        || die "could not fetch Caddy's signing key"
    printf 'deb [signed-by=/usr/share/keyrings/caddy-stable-archive-keyring.gpg] https://dl.cloudsmith.io/public/caddy/stable/deb/debian any-version main\n' \
        > /etc/apt/sources.list.d/caddy-stable.list
    run_quiet apt-get update || die "apt-get update failed after adding Caddy's repository"
    run_quiet apt-get install -y caddy || die "could not install Caddy"
fi
ok "Caddy $(caddy version 2>/dev/null | awk '{print $1}')"

# Go, for building the coordinator and the relay. The services declare go
# 1.24 and Ubuntu 24.04 ships 1.22, so the distribution package is not enough
# — the official tarball unpacks to /usr/local/go, which upgrade-edge.sh finds
# without PATH being set.
find_go_bin() {
    local candidate
    for candidate in /usr/local/go/bin/go "$(command -v go 2>/dev/null)"; do
        [ -n "$candidate" ] && [ -x "$candidate" ] && { printf '%s\n' "$candidate"; return 0; }
    done

    return 1
}

GO_OK=0
if GO_BIN="$(find_go_bin)"; then
    GO_HAVE="$("$GO_BIN" version 2>/dev/null | awk '{print $3}')"
    GO_MINOR="$(printf '%s' "${GO_HAVE#go}" | cut -d. -f2)"
    case "$GO_MINOR" in
        ''|*[!0-9]*) ;;
        *) [ "$GO_MINOR" -ge 24 ] && GO_OK=1 ;;
    esac
fi

if [ "$GO_OK" -eq 1 ]; then
    ok "Go $GO_HAVE is already here"
else
    say "installing Go $GO_VERSION (the services need 1.24 or newer)"
    GO_TAR="/tmp/go$GO_VERSION.linux-$ARCH.tar.gz"
    curl -fsSL -o "$GO_TAR" "https://go.dev/dl/go$GO_VERSION.linux-$ARCH.tar.gz" \
        || die "could not download Go $GO_VERSION"
    rm -rf /usr/local/go
    tar -C /usr/local -xzf "$GO_TAR" || die "could not unpack Go"
    rm -f "$GO_TAR"
    ok "Go $(/usr/local/go/bin/go version | awk '{print $3}') in /usr/local/go"
fi
export PATH="/usr/local/go/bin:$PATH"

# ------------------------------------------------------------------- the code

step "fetching the code"

mkdir -p "$(dirname "$SRC_DIR")"

clone_or_update() {
    local target=$1

    if [ -d "$target/.git" ]; then
        git -C "$target" remote set-url origin "$REPO_URL"
        git -C "$target" fetch --depth 50 origin "$BRANCH" >/dev/null 2>&1 \
            || die "could not fetch $BRANCH into $target"
        git -C "$target" checkout -q -B "$BRANCH" "origin/$BRANCH" \
            || die "could not check out $BRANCH in $target"

        return 0
    fi

    [ -e "$target" ] && die "$target exists but is not a git checkout. Move it aside first."
    git clone --depth 50 --branch "$BRANCH" "$REPO_URL" "$target" >/dev/null 2>&1 \
        || die "could not clone $REPO_URL at $BRANCH"
}

clone_or_update "$SRC_DIR"
ok "edge source in $SRC_DIR ($(git -C "$SRC_DIR" rev-parse --short HEAD))"

# The panel gets its own checkout.
#
# Two copies on purpose: the panel updates itself in place from the release it
# is offered, and upgrade-edge.sh checks $SRC_DIR out to whatever commit the
# panel reports it is on. One shared directory would have the panel's updater
# and the edge build fighting over the same working tree.
if [ ! -d "$PANEL_DIR" ]; then
    clone_or_update "$PANEL_DIR"
    ok "panel in $PANEL_DIR"
elif [ -f "$PANEL_DIR/config/config.php" ]; then
    ok "panel already installed in $PANEL_DIR — left exactly as it is"
else
    clone_or_update "$PANEL_DIR"
    ok "panel refreshed in $PANEL_DIR"
fi

step "permissions"

for directory in storage storage/logs storage/cache storage/backups storage/updates storage/tmp uploads config install; do
    mkdir -p "$PANEL_DIR/$directory"
done
chown -R "$WEB_USER:$WEB_USER" "$PANEL_DIR"
# The checkout is readable by the web user and writable only where it has to
# be: the updater replaces application files, so the tree is its own.
find "$PANEL_DIR" -type d -exec chmod 755 {} +
find "$PANEL_DIR" -type f -exec chmod 644 {} +
chmod 700 "$PANEL_DIR/config"
ok "$PANEL_DIR belongs to $WEB_USER"

# --------------------------------------------------------------- the database

step "the database"

DB_PASS=""
if [ -f "$PANEL_DIR/config/config.php" ]; then
    ok "already installed — the existing database and credentials are untouched"
else
    REUSING=0
    if mysql -N -B -e "SHOW DATABASES LIKE '$DB_NAME'" 2>/dev/null | grep -q "$DB_NAME"; then
        REUSING=1
        TABLES="$(mysql -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'" 2>/dev/null)"
        [ "${TABLES:-0}" -gt 0 ] && die "the $DB_NAME database already has ${TABLES} table(s), but there is no
    config/config.php to go with it. That is a half-finished install, and
    guessing which half is not something a script should do.

    Either restore the panel's config/config.php, or drop the database:
        mysql -e 'DROP DATABASE $DB_NAME'"
    fi

    # A new password every time this step runs, and ALTER USER below is what
    # makes that safe: the account is re-pointed at the password the answers
    # are about to carry, so a re-run after a failure can never leave the
    # panel holding one the database does not have.
    DB_PASS="$(openssl rand -base64 30 | tr -d '/+=' | cut -c1-28)"

    mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
              CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
              ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
              GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
              FLUSH PRIVILEGES;" 2>/dev/null \
        || die "could not create the database. Is MariaDB running, and does root have socket access?"
    if [ "$REUSING" -eq 1 ]; then
        ok "reusing the empty $DB_NAME database; the $DB_USER password has been reset to match"
    else
        ok "database $DB_NAME and user $DB_USER created"
    fi
fi

# ---------------------------------------------------------------- the panel

step "installing the panel"

if [ -f "$PANEL_DIR/config/config.php" ]; then
    ok "config/config.php is already here — skipping the installer"
else


    # A password nobody ever sees, because nobody ever uses it: the
    # administrator sets their own through the one-time link printed at the
    # end. A password that is printed is a password that lives in scrollback.
    # Stripped of the base64 characters JSON or a shell would have to escape.
    # It is never typed and never shown, so the only thing it has to be is
    # long and unguessable.
    THROWAWAY="$(openssl rand -base64 48 | tr -d '/+=\n' | cut -c1-40)"

    # Held in this shell and handed to the installer on standard input.
    #
    # It used to be a file: root created it with mktemp at 0600 and the
    # installer, running as the web user, could not open it. What the
    # installer then said was "The answers file is not valid JSON", because an
    # unreadable file reads as the empty string — so a permission fault
    # reported itself as a syntax one and sent the operator to look at the
    # JSON. Both halves of that are fixed; this half is the better fix,
    # because the database password and the administrator's throwaway
    # password now never touch the disk at all and there is nothing to chown,
    # nothing to leak and nothing to clean up.
    ANSWERS_JSON="$(cat <<JSON
{
  "site_name": "AK Connect",
  "org_name": "AK Computer",
  "app_url": "$PANEL_URL",
  "timezone": "Asia/Kolkata",
  "support_email": "$ADMIN_EMAIL",
  "db_host": "127.0.0.1",
  "db_port": 3306,
  "db_name": "$DB_NAME",
  "db_user": "$DB_USER",
  "db_pass": "$DB_PASS",
  "db_prefix": "",
  "admin_name": "Administrator",
  "admin_email": "$ADMIN_EMAIL",
  "admin_password": "$THROWAWAY",
  "enable_2fa": false,
  "coordinator_host": "127.0.0.1",
  "coordinator_port": 8443,
  "drop_existing": false
}
JSON
)"

    if ! printf '%s\n' "$ANSWERS_JSON" \
        | sudo -u "$WEB_USER" "$PHP_BIN" "$PANEL_DIR/cli/install.php" --answers=-; then
        ANSWERS_JSON=""
        die "the panel installer failed — the output above says why"
    fi
    ANSWERS_JSON=""
    ok "panel installed"
fi

chown -R "$WEB_USER:$WEB_USER" "$PANEL_DIR/config" "$PANEL_DIR/storage" "$PANEL_DIR/install"
chmod 600 "$PANEL_DIR/config/config.php" 2>/dev/null

# As the web user, never as root: a migration run by root leaves storage/logs
# and the cache owned by root, and the panel then cannot write its own logs.
if sudo -u "$WEB_USER" "$PHP_BIN" "$PANEL_DIR/cli/migrate.php" >/dev/null 2>&1; then
    ok "migrations are up to date"
else
    warn "cli/migrate.php reported a problem — run it by hand and read the output"
fi

# ---------------------------------------------------------------- the website

step "Caddy"

PHP_SOCKET="/run/php/php$PHP_SERIES-fpm.sock"
[ -S "$PHP_SOCKET" ] || PHP_SOCKET="$(find /run/php -name '*-fpm.sock' -type s 2>/dev/null | head -1)"
[ -n "$PHP_SOCKET" ] || die "no PHP-FPM socket in /run/php — is php$PHP_SERIES-fpm running?"

mkdir -p /etc/caddy/sites

# The distribution's Caddyfile serves a placeholder site on :80, which would
# compete with this one for the same requests. It is kept, once, so nothing is
# silently destroyed.
if [ ! -f /etc/caddy/Caddyfile.before-akconnect ] && [ -f /etc/caddy/Caddyfile ]; then
    cp -a /etc/caddy/Caddyfile /etc/caddy/Caddyfile.before-akconnect
    note "the previous Caddyfile is kept at /etc/caddy/Caddyfile.before-akconnect"
fi

cat > /etc/caddy/Caddyfile <<'CADDY'
# AK Connect. Written by deploy/getting-started.sh.
#
# One line, on purpose: every site this server carries has a file of its own
# in /etc/caddy/sites, so adding or removing one never means editing this.
import /etc/caddy/sites/*.caddy
CADDY

# Written with the values interpolated rather than through a template, because
# the one thing this file must not get wrong is which directories it refuses
# to serve.
cat > "/etc/caddy/sites/$DOMAIN.caddy" <<CADDY
# AK Connect — $DOMAIN
#
# Written by deploy/getting-started.sh. Re-running it rewrites this file.
#
# Caddy does not read .htaccess. Everything the repository's .htaccess denies
# has to be denied again here, and this is that list: the repository root IS
# the webroot, so config/config.php — database credentials, the app key — is a
# plain file underneath it. A web server that serves this directory without
# the block below hands those out to anyone who asks for them by name.
$DOMAIN {
	encode zstd gzip

	route {
		# The HTTPS fallback, first: agents on networks that pass nothing
		# but 443 carry both their control messages and their relayed
		# tunnel over this path. The relay listens on loopback and speaks
		# plain websocket; TLS is terminated here.
		#
		# Health first — a longer path has to be matched before the path
		# it starts with, or it is swallowed by it.
		handle /fallback/health {
			rewrite * /ws/health
			reverse_proxy 127.0.0.1:9443
		}

		handle /fallback {
			rewrite * /ws
			# Caddy forwards the client's address itself. The relay
			# reads it and reports it back to the agent, which is how a
			# technician finds the address a site is seen as without
			# asking anyone to visit a what-is-my-ip page.
			reverse_proxy 127.0.0.1:9443
		}

		# What .htaccess denies. Application code, configuration, the
		# database directory, the repository's own files.
		@denied path /app /app/* /config /config/* /database /database/* \\
			/storage /storage/* /cli /cli/* /services /services/* \\
			/tests /tests/* /vendor /vendor/* /deploy /deploy/* \\
			/docs /docs/* /.git /.git/* /.github /.github/* \\
			/.env /.env.* /.gitignore /.gitattributes \\
			/update.json /VERSION /composer.json /composer.lock \\
			*.md *.sh *.sql *.log *.yml *.yaml
		handle @denied {
			respond "Not Found" 404
		}

		handle {
			root * $PANEL_DIR
			# php_fastcgi already does try_files {path} {path}/index.php
			# index.php, which is the front controller the .htaccess
			# rewrite rules describe. Caddy passes the Authorization
			# header to FastCGI without being told to — Apache does not,
			# and every agent call arriving with no credentials is how
			# a working install once looked like a revoked one.
			php_fastcgi unix/$PHP_SOCKET
			file_server
		}
	}

	header {
		X-Content-Type-Options nosniff
		X-Frame-Options DENY
		Referrer-Policy strict-origin-when-cross-origin
		-X-Powered-By
	}

	log {
		output file /var/log/caddy/$DOMAIN.log {
			roll_size 20MiB
			roll_keep 5
		}
	}
}
CADDY

mkdir -p /var/log/caddy
chown -R caddy:caddy /var/log/caddy 2>/dev/null

caddy fmt --overwrite "/etc/caddy/sites/$DOMAIN.caddy" >/dev/null 2>&1
if ! caddy validate --config /etc/caddy/Caddyfile >/dev/null 2>&1; then
    caddy validate --config /etc/caddy/Caddyfile 2>&1 | sed 's/^/    /' >&2
    die "the Caddy configuration is not valid — nothing has been reloaded"
fi
ok "configuration is valid"

systemctl enable caddy >/dev/null 2>&1
systemctl restart caddy || die "Caddy would not start — journalctl -u caddy -n 40"
sleep 2
systemctl is-active --quiet caddy || die "Caddy is not running — journalctl -u caddy -n 40"
ok "Caddy is serving $DOMAIN"

# Proof, not assumption: this is where a certificate problem shows up, and it
# is worth a clear line rather than a surprise at the end.
sleep 3
if curl -fsS --max-time 20 -o /dev/null "https://$DOMAIN/health.php" 2>/dev/null; then
    ok "https://$DOMAIN answers, with a valid certificate"
elif curl -fsS --max-time 20 -o /dev/null -k "https://$DOMAIN/health.php" 2>/dev/null; then
    warn "HTTPS answers but the certificate is not trusted yet — Let's Encrypt may still be issuing it"
    say "Watch it with:  journalctl -u caddy -f"
else
    warn "https://$DOMAIN did not answer yet. Certificates can take a minute on first start."
    say "Watch it with:  journalctl -u caddy -f"
fi

# ------------------------------------------------------------------- the edge

step "building the coordinator and the relay"

PANEL_VERSION="$(cat "$SRC_DIR/VERSION" 2>/dev/null | tr -d '\r\n')"
BUILD_DIR="$(keep_tmp "$(mktemp -d)")"

build_one() {
    local svc=$1
    ( cd "$SRC_DIR/services/$svc" \
        && CGO_ENABLED=0 go build -trimpath \
            -ldflags "-s -w -X main.version=$PANEL_VERSION" \
            -o "$BUILD_DIR/akconnect-$svc" "./cmd/akconnect-$svc" )
}

build_one coordinator || { rm -rf "$BUILD_DIR"; die "the coordinator did not compile"; }
build_one relay       || { rm -rf "$BUILD_DIR"; die "the relay did not compile"; }

install -m 755 "$BUILD_DIR/akconnect-coordinator" /usr/local/bin/akconnect-coordinator
install -m 755 "$BUILD_DIR/akconnect-relay"       /usr/local/bin/akconnect-relay
rm -rf "$BUILD_DIR"
ok "coordinator and relay $PANEL_VERSION in /usr/local/bin"

step "installing the edge services"

# install-edge.sh does the service account, the keypair, the shared secrets,
# the env files and the systemd units, and it is the script the release gate
# exercises. --no-configure-apache because there is no Apache here: Caddy
# already carries /fallback, configured above.
"$SRC_DIR/deploy/install-edge.sh" \
    --panel "$PANEL_URL" \
    --relay-name "$(printf '%s' "$DOMAIN" | cut -d. -f1)-1" \
    --public-host "$DOMAIN" \
    --no-configure-apache \
    || die "install-edge.sh failed — its output above says where"

# ------------------------------------------------------- telling the panel

step "pointing the panel at its coordinator"

COORD_PUBLIC="$(cat "$ETC_DIR/coordinator.pub" 2>/dev/null | tr -d '\r\n')"
COORD_SECRET="$(cat "$ETC_DIR/coordinator.secret.for-panel" 2>/dev/null | tr -d '\r\n')"
[ -n "$COORD_PUBLIC" ] || die "$ETC_DIR/coordinator.pub is missing — install-edge.sh did not finish"

# Written straight into the panel's settings rather than left for somebody to
# type. A key that has to be copied by hand between two terminals is a key
# that gets copied wrong, and the symptom — every agent enrolling and then
# never connecting — looks like a network fault for as long as it takes to
# check.
WIRE_PHP="$(keep_tmp "$(mktemp)")"
cat > "$WIRE_PHP" <<'PHP'
<?php
declare(strict_types=1);

define('APP_ROOT', $argv[1]);
require APP_ROOT . '/app/Core/Autoloader.php';
$autoloader = new App\Core\Autoloader();
$autoloader->addNamespace('App', APP_ROOT . '/app');
$autoloader->register();
require APP_ROOT . '/app/Core/helpers.php';
require APP_ROOT . '/app/bootstrap.php';

[, , $host, $publicKey, $sharedSecret, $channel] = $argv;

try {
    App\Services\CoordinatorSettings::save([
        'host'          => $host,
        'public_host'   => $host,
        'port'          => 8443,
        'public_key'    => $publicKey,
        'shared_secret' => $sharedSecret,
        'fallback_url'  => 'wss://' . $host . '/fallback',
    ]);
} catch (Throwable $e) {
    // A validation failure names the field, and that is the thing worth
    // seeing: "the public key is not 44 characters of base64" is actionable,
    // "could not save settings" is not.
    $detail = method_exists($e, 'errors') ? implode('; ', (array) $e->errors()) : $e->getMessage();
    fwrite(STDERR, 'coordinator settings: ' . $detail . "\n");
    exit(1);
}

App\Models\UpdateSetting::save(['channel' => $channel]);

echo 'saved', "\n";
PHP

if sudo -u "$WEB_USER" "$PHP_BIN" "$WIRE_PHP" \
        "$PANEL_DIR" "$DOMAIN" "$COORD_PUBLIC" "$COORD_SECRET" "$CHANNEL" >/dev/null; then
    ok "coordinator key, secret, fallback URL and the $CHANNEL channel are in the panel"
else
    warn "could not write the settings automatically"
    say "Set them by hand under Settings → Coordinator:"
    say "    host        $DOMAIN"
    say "    port        8443"
    say "    public key  $COORD_PUBLIC"
    say "    fallback    wss://$DOMAIN/fallback"
fi
rm -f "$WIRE_PHP"

systemctl restart akconnect-coordinator >/dev/null 2>&1

# ------------------------------------------------------------------ firewall

step "firewall"

if command -v ufw >/dev/null 2>&1; then
    # Named one at a time, with a reason each, because a rule nobody can
    # justify is a rule nobody dares remove.
    ufw allow 80/tcp            >/dev/null 2>&1   # Let's Encrypt, and the redirect to 443
    ufw allow 443/tcp           >/dev/null 2>&1   # the panel, and the HTTPS fallback
    ufw allow 8443/udp          >/dev/null 2>&1   # the coordinator: every agent announces here
    ufw allow 9000/udp          >/dev/null 2>&1   # the relay's control port
    ufw allow 51900:52400/udp   >/dev/null 2>&1   # the relay's data ports, pinned in its unit
    # Opened now, before the hub exists, so that a server installed today does
    # not need its firewall touched again to gain it. An open UDP port with
    # nothing listening answers nothing and is not a way in. See docs/HUB.md.
    ufw allow 51820/udp         >/dev/null 2>&1   # the hub: phones have one peer, and it is here

    # SSH last and deliberately: enabling ufw without it locks the operator
    # out of the machine they are installing on.
    SSH_PORT="$(sed -n 's/^[[:space:]]*Port[[:space:]]\+\([0-9]\+\).*/\1/p' /etc/ssh/sshd_config 2>/dev/null | head -1)"
    ufw allow "${SSH_PORT:-22}/tcp" >/dev/null 2>&1

    if ! ufw status 2>/dev/null | grep -q "^Status: active"; then
        ufw --force enable >/dev/null 2>&1
    fi
    ok "80,443/tcp · 8443,9000,51820,51900-52400/udp · ssh on ${SSH_PORT:-22}"
else
    warn "ufw is not installed — open 80,443/tcp and 8443,9000,51820,51900-52400/udp yourself"
fi

# ------------------------------------------------------------- scheduled work

step "the five-minute worker"

# A systemd timer rather than a crontab line. The crontab has to be the web
# user's — a root cron leaves storage/logs and the backup files owned by root,
# and the panel then cannot write its own logs — and "run crontab -u www -e"
# is a step that gets done as root by somebody in a hurry.
cat > /etc/systemd/system/akconnect-worker.service <<UNIT
[Unit]
Description=AK Connect scheduled maintenance
After=network-online.target mariadb.service

[Service]
Type=oneshot
User=$WEB_USER
Group=$WEB_USER
ExecStart=$PHP_BIN $PANEL_DIR/cli/worker.php
# It sweeps offline devices, prunes sessions and takes backups. Nothing it
# does should need more than the files it owns.
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ProtectHome=true
UNIT

cat > /etc/systemd/system/akconnect-worker.timer <<'UNIT'
[Unit]
Description=Run AK Connect maintenance every five minutes

[Timer]
OnBootSec=2min
OnUnitActiveSec=5min
AccuracySec=30s
Unit=akconnect-worker.service

[Install]
WantedBy=timers.target
UNIT

systemctl daemon-reload
if systemctl enable --now akconnect-worker.timer >/dev/null 2>&1; then
    ok "akconnect-worker.timer runs as $WEB_USER every five minutes"
else
    warn "the worker timer would not start — systemctl status akconnect-worker.timer"
fi

# ------------------------------------------------------ the maintenance command

step "the maintenance command"

cat > /usr/local/bin/akconnect-maintenance <<WRAPPER
#!/bin/sh
#
# Put THIS PANEL into maintenance, and take it out again.
#
#   akconnect-maintenance on "Testing R6" --allow=1.2.3.4
#   akconnect-maintenance status
#   akconnect-maintenance off
#
# It writes a flag the panel's own middleware reads: this site answers 503
# with a Retry-After, and nothing else on the machine is touched. Never stop
# the web server to take a panel down — on a server carrying other people's
# sites that is their outage, and a runbook that says so is a broken runbook.
exec sudo -u $WEB_USER $PHP_BIN $PANEL_DIR/cli/maintenance.php "\$@"
WRAPPER
chmod 755 /usr/local/bin/akconnect-maintenance
ok "akconnect-maintenance on|off|status"

# ---------------------------------------------------------- the upgrade timer

step "the edge upgrade timer"

# upgrade-edge.sh installs and enables its own timer, and it is the thing that
# keeps the coordinator, the relay and the published agent in step with
# whatever release the panel moves to. Running it once here is also the first
# end-to-end proof that the panel, the shared secret and the fallback all work
# together — it asks the panel which release it is on, and cannot get an
# answer unless they do.
#
# Not --skip-pack. It takes a couple of minutes of cross-compiling, and
# without it the panel has no installer to serve: /download/setup.exe answers
# 503 and there is no way to enrol the first device. An install that finishes
# by telling somebody to go and run another command before they can add a PC
# is not a one-command install.
say "this builds the Windows installer as well, which takes a few minutes"
if "$SRC_DIR/deploy/upgrade-edge.sh"; then
    ok "edge upgrade ran, and its timer is installed"
else
    warn "the first edge upgrade reported a problem — the table above says which check"
    say "Nothing is broken by this: the services are installed and running. Run it again with:"
    say "    sudo $SRC_DIR/deploy/upgrade-edge.sh"
fi

# Asked of the panel rather than inferred from the build, because what matters
# is whether a customer can download it — and the builder already refused to
# ship an executable that was not stamped with this panel's address.
SETUP_STATUS="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$PANEL_URL/download/setup.exe" 2>/dev/null)"
if [ "$SETUP_STATUS" = "200" ]; then
    ok "$PANEL_URL/download/setup.exe is being served, stamped for $DOMAIN"
else
    warn "$PANEL_URL/download/setup.exe answered $SETUP_STATUS — no installer is published yet"
    say "Run this again once the edge upgrade succeeds:"
    say "    sudo $SRC_DIR/deploy/upgrade-edge.sh"
fi

# ----------------------------------------------------------------- the finish

mkdir -p "$ETC_DIR"
cat > "$STATE_FILE" <<STATE
# Written by getting-started.sh. Read by --uninstall.
AKCONNECT_DOMAIN=$DOMAIN
AKCONNECT_PANEL_DIR=$PANEL_DIR
AKCONNECT_SRC_DIR=$SRC_DIR
AKCONNECT_CHANNEL=$CHANNEL
AKCONNECT_INSTALLED_AT=$(date -u +%Y-%m-%dT%H:%M:%SZ)
STATE
chmod 600 "$STATE_FILE"

step "ready"

SETUP_LINK="$(sudo -u "$WEB_USER" "$PHP_BIN" "$PANEL_DIR/cli/setup-link.php" --quiet --minutes=120 2>/dev/null)"

printf '\n'
printf '  %sAK Connect is installed.%s\n\n' "$GREEN$BOLD" "$RESET"
printf '  Panel        %s\n' "$PANEL_URL"
printf '  Installer    %s/download/setup.exe\n' "$PANEL_URL"
printf '  Channel      %s\n' "$CHANNEL"
printf '  Version      %s\n' "${PANEL_VERSION:-unknown}"
printf '\n'

if [ -n "$SETUP_LINK" ]; then
    printf '  %sSet the administrator password here — the link works once, and expires in two hours:%s\n\n' "$BOLD" "$RESET"
    printf '    %s\n\n' "$SETUP_LINK"
    printf '    account: %s\n' "$ADMIN_EMAIL"
else
    warn "could not issue a setup link — run it yourself:"
    say "    sudo -u $WEB_USER $PHP_BIN $PANEL_DIR/cli/setup-link.php"
fi

printf '\n  %sUseful from here%s\n' "$BOLD" "$RESET"
printf '    akconnect-maintenance on|off|status        take this panel down, and only this panel\n'
printf '    systemctl status akconnect-coordinator     the rendezvous service\n'
printf '    systemctl status akconnect-relay           the fallback data path\n'
printf '    journalctl -u caddy -f                     certificates and web requests\n'
printf '    %s/deploy/upgrade-edge.sh                  bring the edge to the panel'"'"'s release\n' "$SRC_DIR"
printf '    curl … | bash -s -- --uninstall            remove everything this installed\n'
printf '\n'
