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
#
# That sentence was here from the first version and nothing had ever run the
# script twice; the first two field re-runs each failed on something a second
# run would have found. It is now exercised by services/lab/install-gate.sh:
# a first run that dies half-way and a second that must finish, a clean run
# followed by the same command again with nothing loosened or regenerated,
# and --uninstall followed by a reinstall. If this script changes, that gate is
# what says whether this paragraph is still true.
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
# Whether --channel was given, as opposed to the question's default being
# taken: only the first may change a panel that is already installed.
CHANNEL_GIVEN=0
# Whether this run installed the panel, as opposed to finding it installed.
INSTALLED_NOW=0
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

# make_tmp <variable> [mktemp options] — a temporary path, registered for the
# trap, in the variable named.
#
# It sets the variable itself rather than printing the path, and that is the
# whole of it. The first version printed it and was always called as
# path="$(keep_tmp "$(mktemp)")" — inside a command substitution, which is a
# subshell, so the path was added to the subshell's copy of the list and the
# trap in this shell never saw one of them. Every temporary file the trap was
# said to guarantee removing, it removed none of. The install gate found it,
# once a step stopped deleting its own.
make_tmp() {
    local __make_tmp_var=$1 __make_tmp_path
    shift
    __make_tmp_path="$(mktemp "$@")" || die "could not create a temporary file"
    AKCONNECT_TMP="$AKCONNECT_TMP $__make_tmp_path"
    printf -v "$__make_tmp_var" '%s' "$__make_tmp_path"
}

akconnect_cleanup() {
    # Its own IFS: a signal that arrives while as_user is running runs this
    # trap in as_user's scope, and anything but whitespace there made the
    # whole list one word that matched nothing.
    local IFS=$' \t\n' path
    for path in $AKCONNECT_TMP; do
        [ -n "$path" ] && rm -rf "$path"
    done
    AKCONNECT_TMP=""
}

# The signals end the run; the EXIT trap then cleans up. A trap on INT that
# only cleaned up would return to the script, which carried on: ^C during the
# edge upgrade killed the upgrade, and the installer went on to write its state
# file and print "AK Connect is installed" — having just deleted the build
# directory it was still using.
trap akconnect_cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

# run_quiet keeps a wall of apt output off the screen but hands all of it over
# when something fails — the one moment it is worth reading.
run_quiet() {
    local log
    make_tmp log
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
# ask_into <variable> <prompt> <default>: ask, set the variable, and set
# ANSWERED=1 if something was typed rather than the default accepted.
ANSWERED=0
ask_into() {
    local __ask_var=$1 prompt=$2 default=$3 reply=''
    ANSWERED=0

    # The terminal, not standard input: under curl | bash, standard input is
    # this script. /dev/tty can exist and still not open (no controlling
    # terminal), which is the same as having none.
    if [ "$UNATTENDED" -ne 1 ] && { exec 3<>/dev/tty; } 2>/dev/null; then
        printf '  %s [%s]: ' "$prompt" "$default" >&3
        IFS= read -r reply <&3 || reply=''
        exec 3>&-
    fi

    [ -n "$reply" ] && ANSWERED=1
    printf -v "$__ask_var" '%s' "${reply:-$default}"
}

ask() {
    local prompt=$1 default=${2:-} reply=''

    if [ "$UNATTENDED" -eq 1 ]; then
        printf '%s\n' "$default"

        return 0
    fi

    # Opened rather than tested for: /dev/tty can exist with no controlling
    # terminal behind it.
    if ! { exec 3<>/dev/tty; } 2>/dev/null; then
        [ -n "$default" ] || die "no terminal to ask '$prompt' on, and no default. Pass it as an option."
        printf '%s\n' "$default"

        return 0
    fi

    if [ -n "$default" ]; then
        printf '  %s [%s]: ' "$prompt" "$default" >&3
    else
        printf '  %s: ' "$prompt" >&3
    fi
    IFS= read -r reply <&3 || reply=''
    exec 3>&-

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
        --channel)    CHANNEL="${2:-}"; CHANNEL_GIVEN=1; shift 2 || die "--channel needs a value" ;;
        --branch)     BRANCH="${2:-}"; shift 2 || die "--branch needs a value" ;;
        --unattended) UNATTENDED=1; shift ;;
        --uninstall)  UNINSTALL=1; shift ;;
        --yes|-y)     ASSUME_YES=1; shift ;;
        -h|--help)    usage; exit 0 ;;
        *)            die "unknown option: $1" ;;
    esac
done

[ "$(id -u)" -eq 0 ] || die "run this as root:  curl -fsSL … | sudo bash"

# Stated rather than inherited. The panel tree has to be readable by the web
# server's own user, and an operator whose shell runs with umask 027 or 077
# would otherwise get a checkout Caddy cannot serve. Nothing secret is created
# under it: mktemp makes 0600 files whatever the umask, and install-edge.sh
# sets 077 around the keys it writes.
umask 022

# ------------------------------------------------------------------ uninstall

# read_state recovers what an earlier run recorded, so --uninstall knows which
# domain and directories it is undoing without being told again.
# One spelling for a domain: no scheme, no path, no spaces, no trailing dot,
# lower case. Typed with capitals or a trailing dot it resolves the same, and
# would otherwise name a second directory under /var/www and a second panel.
normalise_domain() {
    # Lower case first: stripping "https://" before lowering it turned
    # HTTPS://nb.akdwk.in into "https:".
    printf '%s' "$1" | tr -d ' ' | tr '[:upper:]' '[:lower:]' | sed 's#^https\?://##; s#/.*##; s#\.$##'
}

# site_name_for <domain>: the name this domain's panel directory and Caddy site
# already have on disk, or the domain itself.
#
# One spelling for everything a machine reads — the Caddy address, the panel's
# URL, the coordinator's host — and the existing spelling for what is already
# on disk. Releases before 1.9.7-dev.21 kept the domain as typed, capitals and
# trailing dot included, so an install typed as NB.akdwk.in lives in
# /var/www/NB.akdwk.in; a lowercased name would make a second panel beside it,
# and "nb.akdwk.in." handed to the panel as a host is refused.
site_name_for() {
    local want=$1 path name
    for path in /var/www/* /etc/caddy/sites/*.caddy; do
        [ -e "$path" ] || continue
        name="$(basename "$path" .caddy)"
        if [ "$name" != "$want" ] && [ "$(normalise_domain "$name")" = "$want" ]; then
            printf '%s\n' "$name"

            return 0
        fi
    done
    printf '%s\n' "$want"
}

read_state() {
    [ -f "$STATE_FILE" ] || return 1
    # shellcheck disable=SC1090
    . "$STATE_FILE"

    return 0
}

do_uninstall() {
    printf '\n%sAK Connect — uninstall%s\n' "$BOLD" "$RESET"

    local panel_dir="" site_name=""
    if read_state; then
        # The installation's own spelling and directory, as it recorded them.
        DOMAIN="${AKCONNECT_DOMAIN:-$DOMAIN}"
        site_name="${AKCONNECT_SITE_NAME:-$DOMAIN}"
        panel_dir="${AKCONNECT_PANEL_DIR:-}"
        DOMAIN="$(normalise_domain "$DOMAIN")"
    else
        [ -n "$DOMAIN" ] || DOMAIN="$(ask 'Domain to remove' '')"
        DOMAIN="$(normalise_domain "$DOMAIN")"
        [ -n "$DOMAIN" ] && site_name="$(site_name_for "$DOMAIN")"
    fi
    [ -n "$DOMAIN" ] || die "nothing to remove: no domain given and no $STATE_FILE"

    [ -n "$site_name" ] || site_name="$DOMAIN"
    [ -n "$panel_dir" ] || panel_dir="/var/www/$site_name"

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
        [ "$(normalise_domain "$confirm")" = "$DOMAIN" ] || die "that did not match; nothing has been removed"
    fi

    step "stopping services"
    local unit
    # By pattern, not by a list. The list named akconnect-edge-upgrade.timer,
    # and upgrade-edge.sh installs akconnect-upgrade.timer — so an uninstall
    # left an hourly root job enabled, pointing at a script it had just
    # deleted, ready to fire in the middle of the next install.
    local path
    for path in /etc/systemd/system/akconnect-*.timer /etc/systemd/system/akconnect-*.service; do
        [ -e "$path" ] || continue
        unit="$(basename "$path")"
        systemctl disable --now "$unit" >/dev/null 2>&1
        rm -f "$path"
    done
    systemctl daemon-reload >/dev/null 2>&1
    ok "services stopped and unit files removed"

    step "removing the web site"
    rm -f "/etc/caddy/sites/$site_name.caddy" "/etc/caddy/sites/$DOMAIN.caddy"
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
          /usr/local/bin/akconnect-maintenance /usr/local/bin/akconnect-rotate-secret
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
# One spelling. A domain typed with capitals or a trailing dot resolves the
# same, and without this it would name a second directory under /var/www and
# a second panel beside the first.
DOMAIN="$(normalise_domain "$DOMAIN")"

# A re-run is a re-run of the installation that is here, not a second one.
#
# Compared as one spelling. The installation's own spelling is kept for what
# is on disk (SITE_NAME: the panel directory, the Caddy site file) and the one
# spelling is used for everything read as a name — see site_name_for.
SITE_NAME="$(site_name_for "$DOMAIN")"
PREVIOUS_CHANNEL=""
SETTINGS_WRITTEN_BEFORE=0
if [ -f "$STATE_FILE" ]; then
    INSTALLED_DOMAIN="$(sed -n 's/^AKCONNECT_DOMAIN=//p' "$STATE_FILE" | head -1)"
    PREVIOUS_CHANNEL="$(sed -n 's/^AKCONNECT_CHANNEL=//p' "$STATE_FILE" | head -1)"
    grep -qx 'AKCONNECT_SETTINGS_WRITTEN=1' "$STATE_FILE" && SETTINGS_WRITTEN_BEFORE=1
    if [ -n "$INSTALLED_DOMAIN" ] && [ "$(normalise_domain "$INSTALLED_DOMAIN")" != "$DOMAIN" ]; then
        die "this server already has AK Connect installed for $INSTALLED_DOMAIN, not $DOMAIN.

    Running it again is for the installation that is here: pass --domain $INSTALLED_DOMAIN.
    To move to a new name, uninstall first (it removes the database and every device
    enrolled on it) and install again."
    fi
    INSTALLED_SITE="$(sed -n 's/^AKCONNECT_SITE_NAME=//p' "$STATE_FILE" | head -1)"
    if [ -n "$INSTALLED_SITE" ]; then
        SITE_NAME="$INSTALLED_SITE"
    elif [ -n "$INSTALLED_DOMAIN" ]; then
        SITE_NAME="$INSTALLED_DOMAIN"
    fi
fi

[ -n "$ADMIN_EMAIL" ] || ADMIN_EMAIL="$(ask 'Administrator email' '')"
[ -n "$ADMIN_EMAIL" ] || die "an administrator email is required"
case "$ADMIN_EMAIL" in
    ?*@?*.?*) ;;
    *) die "'$ADMIN_EMAIL' does not look like an email address" ;;
esac

# Asked into the variable, not through $( ), so that an answer can be told
# from a default accepted: on a re-run, a channel typed here is applied just as
# --channel is, and Enter leaves the panel's own setting alone.
if [ -z "$CHANNEL" ]; then
    ask_into CHANNEL 'Release channel — stable, beta or edge' "${PREVIOUS_CHANNEL:-edge}"
    [ "$ANSWERED" -eq 1 ] && CHANNEL_GIVEN=1
fi
case "$CHANNEL" in
    stable|beta|edge) ;;
    *) die "channel must be stable, beta or edge — not '$CHANNEL'" ;;
esac

PANEL_URL="https://$DOMAIN"
PANEL_DIR="/var/www/$SITE_NAME"

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
#
# The list is read once and then searched. `php -m | grep -q x` under
# pipefail fails at random: grep -q stops at the first match, php is killed by
# SIGPIPE if it is still writing, the pipeline's status is 141 — and an
# extension that is there was reported missing. The install gate hit it.
PHP_MODULES="$("$PHP_BIN" -m 2>/dev/null)"
MISSING=""
for extension in pdo_mysql openssl curl zip mbstring json fileinfo; do
    grep -qix "$extension" <<<"$PHP_MODULES" || MISSING="$MISSING $extension"
done
[ -z "$MISSING" ] || die "PHP $PHP_SERIES is missing required extension(s):$MISSING"

# Optional, but it is what verifies a signed update manifest — so it is
# installed if the distribution has it, and its absence is said out loud
# rather than discovered when an update will not apply.
if ! "$PHP_BIN" -m 2>/dev/null | grep -ix sodium >/dev/null; then
    run_quiet apt-get install -y "php$PHP_SERIES-sodium" >/dev/null 2>&1
fi
"$PHP_BIN" -m 2>/dev/null | grep -ix sodium >/dev/null \
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

# as_owner <path> <command...> runs a command as whoever owns <path>.
#
# Every git command in this script goes through it, because that is git's own
# rule: it refuses a repository that belongs to someone else, and says
# "detected dubious ownership". The panel tree is the web user's — it has to
# be, the panel updates itself in place — and this script runs as root. The
# first version ran git as root on it anyway, and the first field re-run
# stopped there. The fix is to be the owner, not to tell git to look away:
# no safe.directory, global or otherwise, is ever written.
as_owner() {
    local path=$1 owner
    shift
    owner="$(stat -c %U "$path" 2>/dev/null)"

    if [ -z "$owner" ] || [ "$owner" = "root" ]; then
        "$@"

        return
    fi

    as_user "$owner" "$@"
}

# as_user <user> <command...>: every command this script runs as somebody else
# goes through here.
#
#   umask 022, set inside the command. sudo's PAM session applies pam_umask,
#   and on Ubuntu (USERGROUPS_ENAB yes) that gives the web user 0002 whatever
#   this script's umask is — the panel's checkout, .git included, came out
#   group-writable.
#
#   -H, so git and PHP read that user's configuration and not root's.
#
#   Proxy settings preserved by NAME. sudo drops the environment, and a server
#   behind a proxy would fetch as root and fail as the web user. They used to
#   be passed as env VAR=value arguments, which put a proxy's password on
#   sudo's command line — logged to auth.log, and in ps for every local user
#   to read for as long as git ran.
as_user() {
    local user=$1 keep="" var
    shift
    for var in http_proxy https_proxy HTTP_PROXY HTTPS_PROXY no_proxy NO_PROXY all_proxy ALL_PROXY; do
        [ -n "${!var:-}" ] && keep="$keep${keep:+,}$var"
    done

    sudo -H ${keep:+"--preserve-env=$keep"} -u "$user" -- \
        /bin/sh -c 'umask 022 && exec "$@"' as_user "$@"
}

# git_in <repository> <what it is doing> <git arguments...>
#
# git, as the repository's owner, and when it fails the message is git's own.
# The previous version sent git's output to /dev/null and said "could not
# fetch", which is true and does not say why; the field operator only saw the
# reason because an earlier command in the same function happened not to be
# redirected.
git_in() {
    local repo=$1 doing=$2 out
    shift 2

    if ! out="$(as_owner "$repo" git -C "$repo" "$@" 2>&1)"; then
        die "could not $doing in $repo. git said:
$(printf '%s\n' "$out" | sed 's/^/        /')"
    fi
}

# restore_git_modes <repository> gives back the executable bit git recorded.
#
# Versions up to 1.9.7-dev.20 ran chmod 644 over every file in the panel
# tree, which took +x off the tracked scripts. git then counts each of them
# as locally modified, and the next checkout that moves one of them refuses
# with "Your local changes would be overwritten" — changes nobody made. A
# tree any of those versions touched is repaired here, before git is asked to
# move it. Only the bit git itself recorded; nothing is loosened.
restore_git_modes() {
    local repo=$1 entry mode path

    while IFS= read -r -d '' entry; do
        mode="${entry%% *}"
        path="${entry#*$'\t'}"
        # As the tree's owner, never as root: the index and the files are the
        # owner's to write, and root following a symlink the owner planted —
        # an index entry for "x" that is a link to /etc/akconnect/coordinator.env
        # — made that file 0755 and readable to the web user.
        if [ "$mode" = "100755" ] && [ ! -L "$repo/$path" ] && [ -f "$repo/$path" ] && [ ! -x "$repo/$path" ]; then
            as_owner "$repo" chmod 755 -- "$repo/$path" 2>/dev/null
        fi
    done < <(as_owner "$repo" git -C "$repo" ls-files -s -z 2>/dev/null)
}

# clone_or_update <directory> <owner> [commit]
#
# With a commit, the checkout ends at that commit rather than wherever the
# branch had got to by the time this fetch ran. The panel is pinned to the
# edge source's commit that way: the two are fetched a few seconds apart, and
# a push in between made the panel one release and the edge built for it
# another.
clone_or_update() {
    local target=$1 owner=$2 pin=${3:-}

    if [ -d "$target/.git" ]; then
        # The tree is this script's, and it is <owner>'s before git touches
        # it. Whatever an earlier run left in it — including files an older
        # version of this script wrote as root — git refuses a repository
        # that is not the caller's, and a checkout as <owner> cannot replace
        # a file <owner> does not own.
        [ "$owner" = "root" ] || chown -R "$owner:$owner" "$target"

        restore_git_modes "$target"

        git_in "$target" "point it at $REPO_URL" remote set-url origin "$REPO_URL"
        git_in "$target" "fetch $BRANCH" fetch -q --depth 50 origin "$BRANCH"

        # FETCH_HEAD, not origin/$BRANCH. The clone is single-branch, so a
        # fetch of any other ref — a tag, or the next release branch — updates
        # FETCH_HEAD and nothing else, and origin/<that> never exists. A tag is
        # checked out as what it is, a fixed commit, rather than as a branch
        # that happens to share its name.
        local at=FETCH_HEAD
        if [ -n "$pin" ] && as_owner "$target" git -C "$target" cat-file -e "$pin^{commit}" 2>/dev/null; then
            at="$pin"
        fi
        if grep -q "tag '" "$target/.git/FETCH_HEAD" 2>/dev/null; then
            git_in "$target" "check out $BRANCH" checkout -q --detach "$at"
        else
            git_in "$target" "check out $BRANCH" checkout -q -B "$BRANCH" "$at"
        fi

        return 0
    fi

    if [ -e "$target" ] && [ -n "$(ls -A "$target" 2>/dev/null)" ]; then
        die "$target exists and is not empty, but is not a git checkout. Move it aside first."
    fi

    # Created first and handed over, so the clone is <owner>'s from its first
    # byte. Cloning as root and chowning afterwards is what left a tree git
    # would not touch again, and a chown that did not happen — because the run
    # died in between — would have left one the panel could not update.
    mkdir -p "$target"
    chown "$owner:$owner" "$target"
    git_in "$target" "clone $REPO_URL at $BRANCH" clone -q --depth 50 --branch "$BRANCH" "$REPO_URL" "$target"

    if [ -n "$pin" ] && [ "$(as_owner "$target" git -C "$target" rev-parse HEAD 2>/dev/null)" != "$pin" ] \
            && as_owner "$target" git -C "$target" cat-file -e "$pin^{commit}" 2>/dev/null; then
        if as_owner "$target" git -C "$target" symbolic-ref -q HEAD >/dev/null 2>&1; then
            git_in "$target" "check out $BRANCH" checkout -q -B "$BRANCH" "$pin"
        else
            git_in "$target" "check out $BRANCH" checkout -q --detach "$pin"
        fi
    fi
}

clone_or_update "$SRC_DIR" root
SRC_COMMIT="$(as_owner "$SRC_DIR" git -C "$SRC_DIR" rev-parse HEAD 2>/dev/null)"
ok "edge source in $SRC_DIR (${SRC_COMMIT:0:7})"

# The panel gets its own checkout.
#
# Two copies on purpose: the panel updates itself in place from the release it
# is offered, and upgrade-edge.sh checks $SRC_DIR out to whatever commit the
# panel reports it is on. One shared directory would have the panel's updater
# and the edge build fighting over the same working tree.
if [ ! -d "$PANEL_DIR" ]; then
    clone_or_update "$PANEL_DIR" "$WEB_USER" "$SRC_COMMIT"
    ok "panel in $PANEL_DIR, cloned as $WEB_USER"
elif [ -f "$PANEL_DIR/config/config.php" ]; then
    ok "panel already installed in $PANEL_DIR — left exactly as it is"
else
    clone_or_update "$PANEL_DIR" "$WEB_USER" "$SRC_COMMIT"
    ok "panel refreshed in $PANEL_DIR, as $WEB_USER"
fi

step "permissions"

# Ownership as root; every mode change as the web user.
#
# The tree is the web user's to write, so anything in it can be a symbolic
# link the web user planted — config/.env pointing at /etc/shadow, an index
# entry that is a link to /etc/akconnect/coordinator.env — and root running
# chmod follows it. As the owner, the kernel refuses: a user cannot chmod a
# file that is not theirs. chown -R stays root's; recursively it changes a
# link itself, never what the link points at.
chown -R "$WEB_USER:$WEB_USER" "$PANEL_DIR"

# The directories the panel writes into at run time, which git does not carry.
for directory in storage storage/logs storage/cache storage/backups storage/updates storage/tmp uploads config install; do
    as_user "$WEB_USER" mkdir -p "$PANEL_DIR/$directory" \
        || die "could not create $PANEL_DIR/$directory as $WEB_USER"
done

# No blanket chmod that ADDS a permission. The modes are the ones git gave the
# files when the web user cloned them, under the umask 022 as_user sets.
#
# One that only takes away is safe, and needed: nothing in the panel should be
# writable by anyone but its owner. Up to 1.9.7-dev.20 the web user's commands
# ran through sudo's PAM session, whose pam_umask gives www-data 0002 on
# Ubuntu, so the checkout, .git, the logs and the locks came out group-
# writable. This repairs a tree that left, and never adds a bit anywhere.
as_user "$WEB_USER" chmod -R go-w "$PANEL_DIR" 2>/dev/null
#
# There used to be one — find -exec chmod 644 over the whole tree, on every
# run. On a first run it ran before the installer, so it only touched what git
# had put there. On every run after that it also reached what the installer
# writes at 0640: config/.env, which carries the database password, and
# install/install.lock. Both became world-readable, every time the script was
# re-run, which is exactly when somebody is trying to fix something. The
# install gate's second run is what caught it: 640 -> 644, on both.
#
# The secrets are put back where the installer leaves them instead, on every
# run — which also repairs a tree an earlier version of this script loosened.
as_user "$WEB_USER" chmod 700 "$PANEL_DIR/config" || die "could not close $PANEL_DIR/config to other users"
if [ -f "$PANEL_DIR/config/config.php" ]; then
    as_user "$WEB_USER" chmod 600 "$PANEL_DIR/config/config.php" \
        || die "could not make $PANEL_DIR/config/config.php readable to $WEB_USER alone"
fi
for secret in config/.env install/install.lock; do
    if [ -f "$PANEL_DIR/$secret" ]; then
        as_user "$WEB_USER" chmod 640 "$PANEL_DIR/$secret" \
            || die "could not make $PANEL_DIR/$secret unreadable to other users"
    fi
done
# Each backup, and each update's rollback journal, is a directory the panel
# makes at 0750: a full database dump, and a copy of config.php with every key
# the panel holds. The old blanket chmod made them 0755 on every re-run.
for holder in storage/backups storage/updates; do
    [ -d "$PANEL_DIR/$holder" ] \
        && as_user "$WEB_USER" find "$PANEL_DIR/$holder" -mindepth 1 -maxdepth 1 -type d -exec chmod 750 {} +
done
ok "$PANEL_DIR belongs to $WEB_USER, and its secrets are not readable by anyone else"

# --------------------------------------------------------------- the database

step "the database"

DB_PASS=""
if [ -f "$PANEL_DIR/config/config.php" ]; then
    ok "already installed — the existing database and credentials are untouched"
else
    REUSING=0
    if mysql -N -B -e "SHOW DATABASES LIKE '$DB_NAME'" 2>/dev/null | grep "$DB_NAME" >/dev/null; then
        REUSING=1
        TABLES="$(mysql -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'" 2>/dev/null)"
        [ "${TABLES:-0}" -gt 0 ] && die "the $DB_NAME database already has ${TABLES} table(s), but there is no
    config/config.php to go with it. That is a half-finished install, and
    guessing which half is not something a script should do.

    If a panel is running on this machine under another name, this is its
    database — look in /var/www before doing anything. Otherwise restore
    the panel's config/config.php, or, only if nothing uses it, drop it:
        mysql -e 'DROP DATABASE $DB_NAME'"
    fi

    # A new password every time this step runs, and ALTER USER below is what
    # makes that safe: the account is re-pointed at the password the answers
    # are about to carry, so a re-run after a failure can never leave the
    # panel holding one the database does not have.
    DB_PASS="$(openssl rand -base64 30 | tr -d '/+=' | cut -c1-28)"

    # On standard input, not with -e: the statement carries the password, and
    # mysql's arguments are in ps for every user on the machine to read.
    mysql 2>/dev/null <<SQL || die "could not create the database. Is MariaDB running, and does root have socket access?"
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
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
        | as_user "$WEB_USER" "$PHP_BIN" "$PANEL_DIR/cli/install.php" --answers=-; then
        ANSWERS_JSON=""
        die "the panel installer failed — the output above says why"
    fi
    ANSWERS_JSON=""
    INSTALLED_NOW=1
    ok "panel installed"
fi

chown -R "$WEB_USER:$WEB_USER" "$PANEL_DIR/config" "$PANEL_DIR/storage" "$PANEL_DIR/install"
# As the owner, like every mode change in this tree — see "permissions".
as_user "$WEB_USER" chmod 600 "$PANEL_DIR/config/config.php" 2>/dev/null

# As the web user, never as root: a migration run by root leaves storage/logs
# and the cache owned by root, and the panel then cannot write its own logs.
if MIGRATE_SAYS="$(as_user "$WEB_USER" "$PHP_BIN" "$PANEL_DIR/cli/migrate.php" 2>&1)"; then
    ok "migrations are up to date"
else
    warn "cli/migrate.php failed. It said:"
    printf '%s\n' "$MIGRATE_SAYS" | tail -15 | sed 's/^/        /'
    say "Run it again, as the web user, once the cause is fixed:"
    say "    sudo -u $WEB_USER $PHP_BIN $PANEL_DIR/cli/migrate.php"
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
cat > "/etc/caddy/sites/$SITE_NAME.caddy" <<CADDY
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

caddy fmt --overwrite "/etc/caddy/sites/$SITE_NAME.caddy" >/dev/null 2>&1
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

# ------------------------------------------------- where the panel came from

step "telling the panel where it came from"

# Where the panel came from, so that the edge is built from the same place.
#
# The panel reports a branch and a commit to upgrade-edge.sh, which builds the
# coordinator, the relay and the Windows installer from exactly that. Nothing
# used to tell it: the seed says branch "main" and no commit, the edge source
# is a single-branch clone of $BRANCH, and upgrade-edge.sh failed on every run
# of every install this script made — so its timer was never installed and
# /download/setup.exe was never published.
#
# Told before the edge is built, not after: the edge is built at the release
# the panel says it is on, so the panel has to know first.
PANEL_COMMIT="$(as_owner "$PANEL_DIR" git -C "$PANEL_DIR" rev-parse HEAD 2>/dev/null)"
REPO_OWNER=""
REPO_NAME=""
case "$REPO_URL" in
    https://github.com/*/*)
        REPO_OWNER="$(printf '%s' "$REPO_URL" | cut -d/ -f4)"
        REPO_NAME="$(printf '%s' "$REPO_URL" | cut -d/ -f5 | sed 's/\.git$//')"
        ;;
esac

# The channel is written when this run installed the panel, when one was asked
# for (--channel, or typed at the prompt), or when no run of this release has
# ever finished writing the panel's settings — a first run that died after the
# panel installer, or an install by a release whose settings step never
# worked. Those panels are on the seeded channel, not on one anybody chose.
# Otherwise a re-run that merely accepted the default must not move a panel
# somebody switched to stable back onto edge.
WRITE_CHANNEL=""
if [ "$INSTALLED_NOW" -eq 1 ] || [ "$CHANNEL_GIVEN" -eq 1 ] || [ "$SETTINGS_WRITTEN_BEFORE" -ne 1 ]; then
    WRITE_CHANNEL="$CHANNEL"
fi

# The source as a fact only on the run that installed the panel. On a re-run
# the tree's .git is still at the commit it was installed from — the panel
# updates itself from release archives and never moves it — so writing it back
# would rewind the panel's own record and have it offer again an update it had
# already applied. A re-run fills in only what was never set, which is what
# repairs a panel an earlier version of this script installed without it.
SOURCE_KEY="source_if_unset"
[ "$INSTALLED_NOW" -eq 1 ] && SOURCE_KEY="source"

# This release's copies of the panel helpers, from the edge source fetched
# above, aimed at the panel with --root. Not the panel's own: a re-run leaves
# an installed panel at its own version, and a panel an earlier release
# installed has none of them — which is exactly the panel whose settings a
# re-run is there to repair. The install gate's repair sequence found it.
#
# The web user has to be able to read them. The source is root's and made
# under umask 022, but a directory somebody tightened by hand would give PHP's
# bare "Could not open input file" — so check, and say which path.
for helper in _root.php edge-settings.php edge-release.php setup-link.php; do
    as_user "$WEB_USER" test -r "$SRC_DIR/cli/$helper" || die "$WEB_USER cannot read $SRC_DIR/cli/$helper.

    The panel's settings are written by scripts in that directory, run as $WEB_USER.
    They need read access to $SRC_DIR (the edge source is public code — no secrets
    live there):
        sudo chmod o+rX $(dirname "$SRC_DIR") $SRC_DIR $SRC_DIR/cli
    then run this installer again."
done

# write_state [finished]: what --uninstall and the next run need to know about
# this installation. Root's, 0600.
write_state() {
    local stamp=UPDATED
    [ "${1:-}" = "finished" ] && stamp=INSTALLED
    mkdir -p "$ETC_DIR"
    ( umask 077
      cat > "$STATE_FILE.new" <<STATE
# Written by getting-started.sh. Read by --uninstall and by the next run.
AKCONNECT_DOMAIN=$DOMAIN
AKCONNECT_SITE_NAME=$SITE_NAME
AKCONNECT_PANEL_DIR=$PANEL_DIR
AKCONNECT_SRC_DIR=$SRC_DIR
AKCONNECT_WEB_USER=$WEB_USER
AKCONNECT_PHP=$PHP_BIN
AKCONNECT_CHANNEL=${WRITE_CHANNEL:-$PREVIOUS_CHANNEL}
AKCONNECT_SETTINGS_WRITTEN=1
AKCONNECT_${stamp}_AT=$(date -u +%Y-%m-%dT%H:%M:%SZ)
STATE
    ) && mv -f "$STATE_FILE.new" "$STATE_FILE" && chmod 600 "$STATE_FILE"
}

# edge_settings: JSON on standard input to this release's cli/edge-settings.php,
# as the web user. Standard input, so no temporary file for another user to
# fail to open, and no secret on a command line for every user to read in ps.
edge_settings() {
    as_user "$WEB_USER" "$PHP_BIN" "$SRC_DIR/cli/edge-settings.php" --root="$PANEL_DIR"
}

SOURCE_SETTINGS="$(jq -n \
    --arg owner "$REPO_OWNER" --arg repo "$REPO_NAME" --arg branch "$BRANCH" \
    --arg commit "$PANEL_COMMIT" --arg channel "$WRITE_CHANNEL" --arg sourcekey "$SOURCE_KEY" \
    '{($sourcekey): {repo_owner: $owner, repo_name: $repo, branch: $branch, commit: $commit}}
     + (if $channel == "" then {} else {channel: $channel} end)')"
printf '%s\n' "$SOURCE_SETTINGS" | edge_settings \
    || die "the panel would not record where it came from — the lines above name the field."

# Recorded now, not only at the finish: the panel's channel has just been
# written, and a run stopped after this — ^C during the Windows build, a
# dropped SSH session — used to leave no state, so the next run took the panel
# for one nobody had configured and moved it back to the default channel.
write_state

# The release the panel is on now, as upgrade-edge.sh will be told it.
PANEL_RELEASE="$(as_user "$WEB_USER" "$PHP_BIN" "$SRC_DIR/cli/edge-release.php" --root="$PANEL_DIR" 2>/dev/null | tail -1)"
TARGET_VERSION="$(printf '%s' "$PANEL_RELEASE" | jq -r '.version // empty' 2>/dev/null)"
TARGET_COMMIT="$(printf '%s' "$PANEL_RELEASE" | jq -r '.commit // empty' 2>/dev/null)"
TARGET_BRANCH="$(printf '%s' "$PANEL_RELEASE" | jq -r '.branch // empty' 2>/dev/null)"
[ -n "$TARGET_VERSION" ] || die "the panel would not say which release it is on:
$(as_user "$WEB_USER" "$PHP_BIN" "$SRC_DIR/cli/edge-release.php" --root="$PANEL_DIR" 2>&1 | sed 's/^/        /')"
ok "the panel is on $TARGET_VERSION${TARGET_COMMIT:+ (${TARGET_COMMIT:0:12} on $TARGET_BRANCH)}"

# release_tree_at <commit> <branch> <version> <dir>: the services/ tree of one
# commit, taken out of the edge source into <dir> — only if <commit> is a full
# commit id, on <branch> at origin, and carries <version>. A panel's database
# is written by a web application; what it names is checked before anything is
# built from it and run as root.
release_tree_at() {
    local commit=$1 branch=$2 version=$3 dir=$4

    printf '%s' "$commit" | grep -Eq '^[0-9a-f]{40}$' || return 1
    printf '%s' "$branch" | grep -Eq '^[A-Za-z0-9._/-]+$' || return 1

    as_owner "$SRC_DIR" git -C "$SRC_DIR" fetch -q --no-tags --depth 200 origin \
        "+refs/heads/$branch:refs/akconnect/panel-branch" >/dev/null 2>&1 || return 1
    as_owner "$SRC_DIR" git -C "$SRC_DIR" cat-file -e "$commit^{commit}" 2>/dev/null || return 1
    as_owner "$SRC_DIR" git -C "$SRC_DIR" merge-base --is-ancestor "$commit" refs/akconnect/panel-branch 2>/dev/null || return 1
    [ "$(as_owner "$SRC_DIR" git -C "$SRC_DIR" show "$commit:VERSION" 2>/dev/null | tr -d '\r\n')" = "$version" ] || return 1

    mkdir -p "$dir"
    as_owner "$SRC_DIR" git -C "$SRC_DIR" archive --format=tar "$commit" services/coordinator services/relay services/shared \
        | tar -x -C "$dir" 2>/dev/null || return 1
    [ -f "$dir/services/coordinator/go.mod" ]
}

# ------------------------------------------------------------------- the edge

# The panel's own version, for the summary: the edge source may be ahead of
# it, and the line that says what is installed should say what is installed.
PANEL_VERSION="$(tr -d '\r\n' < "$PANEL_DIR/VERSION" 2>/dev/null)"

EDGE_BUILT_NOW=0

if [ -f "$ETC_DIR/coordinator.env" ] && [ -x /usr/local/bin/akconnect-coordinator ] \
        && [ -x /usr/local/bin/akconnect-relay ]; then
    step "the edge"

    # Installed already, so left alone. A re-run used to rebuild both services
    # from the tip of $BRANCH, whatever release the panel was on, restart the
    # coordinator — a few seconds' outage for every relayed pair — and then
    # let upgrade-edge.sh overwrite its rollback copies with that build.
    # Keeping the edge on the panel's release is upgrade-edge.sh's job, and it
    # runs below.
    ok "the coordinator and relay are installed ($(/usr/local/bin/akconnect-coordinator version 2>/dev/null | awk '{print $NF}')); upgrade-edge.sh keeps them on the panel's release"

    # Enabled and running, though — not only built. install-edge.sh writes
    # coordinator.env before it enables the services, so a run stopped between
    # the two leaves an edge that looks installed with services that were never
    # enabled, and a re-run that looked only at the files would call it done
    # and let the next reboot take it down. enable --now does nothing to a
    # service that is already enabled and running: no restart.
    systemctl enable --now akconnect-relay akconnect-coordinator >/dev/null 2>&1 \
        || die "the coordinator and relay are installed but would not start:
        systemctl status akconnect-relay akconnect-coordinator
        journalctl -u akconnect-relay -u akconnect-coordinator -n 50"
    ok "both enabled and running"
else
    step "building the coordinator and the relay"

    make_tmp BUILD_DIR -d

    # At the panel's release, once.
    #
    # This used to build the tip of $BRANCH, and upgrade-edge.sh — run a few
    # minutes later, below — asked the panel which release it was on and built
    # that: two builds of the edge on every new server, and on a re-run over a
    # panel an earlier release installed, two different versions, the second
    # older than the first. Now the one build is the panel's release, and
    # upgrade-edge.sh finds it already running and builds only the Windows
    # installers.
    #
    # On a fresh install the edge source already is the panel's release: the
    # panel is checked out at its commit. Otherwise that commit is taken out of
    # the edge source with git archive — never built from the panel's own
    # tree, which the web user can write — after checking it is a real commit
    # on the panel's branch at origin, and not just a value in a database.
    BUILD_ROOT="$SRC_DIR"
    EDGE_VERSION="$(tr -d '\r\n' < "$SRC_DIR/VERSION" 2>/dev/null)"
    SRC_HEAD="$(as_owner "$SRC_DIR" git -C "$SRC_DIR" rev-parse HEAD 2>/dev/null)"
    if [ -n "$TARGET_COMMIT" ] && [ "$TARGET_COMMIT" != "$SRC_HEAD" ]; then
        if release_tree_at "$TARGET_COMMIT" "${TARGET_BRANCH:-$BRANCH}" "$TARGET_VERSION" "$BUILD_DIR/release"; then
            BUILD_ROOT="$BUILD_DIR/release"
            EDGE_VERSION="$TARGET_VERSION"
            ok "building the panel's release, $TARGET_VERSION (${TARGET_COMMIT:0:12})"
        else
            warn "could not take the panel's release ($TARGET_VERSION) out of $SRC_DIR — building $EDGE_VERSION;"
            say "upgrade-edge.sh, below, moves the edge to the panel's release."
        fi
    elif [ "$EDGE_VERSION" != "$TARGET_VERSION" ]; then
        warn "the panel says $TARGET_VERSION and names no commit — building $EDGE_VERSION;"
        say "upgrade-edge.sh, below, moves the edge to the panel's release."
    fi

    # -buildvcs=false: the version is stamped explicitly, and go build would
    # otherwise run git on the source tree itself — which fails, as a
    # "compile" error, on a checkout that is not the caller's.
    build_one() {
        local svc=$1
        ( cd "$BUILD_ROOT/services/$svc" \
            && CGO_ENABLED=0 go build -trimpath -buildvcs=false \
                -ldflags "-s -w -X main.version=$EDGE_VERSION" \
                -o "$BUILD_DIR/akconnect-$svc" "./cmd/akconnect-$svc" )
    }

    build_one coordinator || die "the coordinator did not compile"
    build_one relay       || die "the relay did not compile"

    install -m 755 "$BUILD_DIR/akconnect-coordinator" /usr/local/bin/akconnect-coordinator
    install -m 755 "$BUILD_DIR/akconnect-relay"       /usr/local/bin/akconnect-relay
    ok "coordinator and relay $EDGE_VERSION in /usr/local/bin"

    step "installing the edge services"

    # install-edge.sh does the service account, the keypair, the shared
    # secrets, the env files and the systemd units, and it is the script the
    # release gate exercises. --no-configure-apache because there is no Apache
    # here: Caddy already carries /fallback, configured above.
    "$SRC_DIR/deploy/install-edge.sh" \
        --panel "$PANEL_URL" \
        --relay-name "$(printf '%s' "$DOMAIN" | cut -d. -f1)-1" \
        --public-host "$DOMAIN" \
        --no-configure-apache \
        --panel-configured-by-caller \
        || die "install-edge.sh failed — its output above says where"

    EDGE_BUILT_NOW=1
fi

# ------------------------------------------------------- telling the panel

step "pointing the panel at its coordinator"

COORD_PUBLIC="$(tr -d '\r\n' < "$ETC_DIR/coordinator.pub" 2>/dev/null)"
COORD_SECRET="$(tr -d '\r\n' < "$ETC_DIR/coordinator.secret.for-panel" 2>/dev/null)"
[ -n "$COORD_PUBLIC" ] || die "$ETC_DIR/coordinator.pub is missing — install-edge.sh did not finish"
[ -n "$COORD_SECRET" ] || die "$ETC_DIR/coordinator.secret.for-panel is missing — install-edge.sh did not finish"

# The secret reaches jq in its environment, not its arguments: ps shows every
# process's arguments to every user on the machine, for as long as it runs.
EDGE_SETTINGS="$(AKCONNECT_COORD_SECRET="$COORD_SECRET" jq -n \
    --arg host "$DOMAIN" --arg key "$COORD_PUBLIC" \
    --arg fallback "wss://$DOMAIN/fallback" \
    '{coordinator: {host: $host, port: 8443, public_key: $key, shared_secret: env.AKCONNECT_COORD_SECRET,
                    fallback_url: $fallback}}')"
COORD_SECRET=""

# This release's helper, on standard input, as the web user — see
# edge_settings above.
if ! printf '%s\n' "$EDGE_SETTINGS" | edge_settings; then
    EDGE_SETTINGS=""
    die "the panel would not take its coordinator settings — the lines above name the field.

    Without them no agent can connect and upgrade-edge.sh cannot build this panel's
    release. This used to be reported as a warning and the install called a success."
fi
EDGE_SETTINGS=""

# Only when something here changed what it is talking to. A re-run that found
# everything in place has no reason to cost every relayed pair an outage.
if [ "$INSTALLED_NOW" -eq 1 ] || [ "$EDGE_BUILT_NOW" -eq 1 ]; then
    systemctl restart akconnect-coordinator >/dev/null 2>&1
fi

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

    if ! ufw status 2>/dev/null | grep "^Status: active" >/dev/null; then
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
#
# umask 022 inside: sudo's PAM session would otherwise give the web user 0002.
exec sudo -u $WEB_USER -- /bin/sh -c 'umask 022 && exec "\$@"' akconnect-maintenance $PHP_BIN $PANEL_DIR/cli/maintenance.php "\$@"
WRAPPER
chmod 755 /usr/local/bin/akconnect-maintenance
ok "akconnect-maintenance on|off|status"

# A copy, not a link into the edge source: upgrade-edge.sh moves that checkout
# to whatever release the panel is on, which may be one that predates this.
install -m 755 "$SRC_DIR/deploy/rotate-secret.sh" /usr/local/bin/akconnect-rotate-secret \
    || die "could not install akconnect-rotate-secret"
ok "akconnect-rotate-secret — replaces the coordinator's shared secret everywhere, in one step"

# ------------------------------------------------------- the setup link

# When this run created the administrator, or nobody has taken the account up
# yet — never signed in to it, never set its password. An account that is in
# use, perhaps for months, should not get a fresh two-hour takeover link
# printed into a terminal, or into whatever captured an unattended run's
# output; that is what the throwaway password exists to avoid. But a first run
# that died after creating the account (at the edge build, at Caddy) left it
# with a random password nobody has seen, and the re-run that finished the job
# used to print no link for it, because it had not created the account itself.
#
# This release's copy of the script, aimed at the panel, as with its settings:
# an older panel's copy does not know --if-unclaimed, and would ignore it.
#
# Issued here, printed at the end. Here, because upgrade-edge.sh, below, puts
# the edge source on the panel's own release — which on a re-run over an older
# panel is older than this script, and has no --root. The first version asked
# for the link after it, and got "not installed" from a copy that looked for
# the panel in /opt/akconnect/src.
SETUP_LINK=""
SETUP_LINK_ARGS=(--root="$PANEL_DIR" --quiet --minutes=120)
[ "$INSTALLED_NOW" -eq 1 ] || SETUP_LINK_ARGS+=(--if-unclaimed)
SETUP_LINK="$(as_user "$WEB_USER" "$PHP_BIN" "$SRC_DIR/cli/setup-link.php" "${SETUP_LINK_ARGS[@]}" 2>/dev/null)"
SETUP_LINK_CODE=$?

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

write_state finished

step "ready"


printf '\n'
printf '  %sAK Connect is installed.%s\n\n' "$GREEN$BOLD" "$RESET"
printf '  Panel        %s\n' "$PANEL_URL"
printf '  Installer    %s/download/setup.exe\n' "$PANEL_URL"
if [ -n "$WRITE_CHANNEL" ]; then
    printf '  Channel      %s\n' "$WRITE_CHANNEL"
else
    printf '  Channel      as the panel has it (Platform → Updates); --channel changes it\n'
fi
printf '  Version      %s\n' "${PANEL_VERSION:-unknown}"
printf '\n'

if [ -n "$SETUP_LINK" ]; then
    printf '  %sSet the administrator password here — the link works once, and expires in two hours:%s\n\n' "$BOLD" "$RESET"
    printf '    %s\n\n' "$SETUP_LINK"
    printf '    account: %s\n\n' "$ADMIN_EMAIL"
    # It is the administrator account, to whoever opens it first.
    printf '  %s! Do not share this link or paste it anywhere.%s Whoever opens it first sets the\n' "$YELLOW$BOLD" "$RESET"
    printf '    administrator password and has the panel. Open it yourself, now. If it has been\n'
    printf '    seen by anyone else, run this and use the new one — it cancels the old:\n'
    printf '        sudo -u %s %s %s/cli/setup-link.php\n' "$WEB_USER" "$PHP_BIN" "$PANEL_DIR"
elif [ "$SETUP_LINK_CODE" -eq 3 ]; then
    say "The administrator account is in use, so no new setup link was issued. If one is needed:"
    say "    sudo -u $WEB_USER $PHP_BIN $PANEL_DIR/cli/setup-link.php"
else
    warn "could not issue a setup link — run it yourself:"
    say "    sudo -u $WEB_USER $PHP_BIN $PANEL_DIR/cli/setup-link.php"
fi

printf '\n  %sUseful from here%s\n' "$BOLD" "$RESET"
printf '    akconnect-maintenance on|off|status        take this panel down, and only this panel\n'
printf '    akconnect-rotate-secret                    replace the coordinator'"'"'s shared secret everywhere\n'
printf '    systemctl status akconnect-coordinator     the rendezvous service\n'
printf '    systemctl status akconnect-relay           the fallback data path\n'
printf '    journalctl -u caddy -f                     certificates and web requests\n'
printf '    %s/deploy/upgrade-edge.sh                  bring the edge to the panel'"'"'s release\n' "$SRC_DIR"
printf '    curl … | bash -s -- --uninstall            remove everything this installed\n'
printf '\n'
