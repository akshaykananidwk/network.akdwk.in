#!/usr/bin/env bash
#
# Put the HTTPS fallback in front of the relay, automatically.
#
# The customer-facing rule this exists to keep is simple: AK Connect must work
# on any network a web browser works on, with nothing for anybody to configure.
# That needs a path on TCP 443, and on this server 443 is already Apache
# serving the panel — so Apache proxies one path to the relay.
#
# Sourced by install-edge.sh and upgrade-edge.sh so the two cannot drift.
# Everything here is idempotent: running it again is how you repair it.

# aaPanel keeps everything of its own under /www/server, including a whole
# Apache that no distribution package manager knows about.
#
# It has to be handled, because it is the deployment this product actually has:
# the panel is an aaPanel site. On a machine like that /etc/apache2 does not
# exist, apache2ctl is not on the path, and a script that looked only for those
# would report "no Apache here" on the one server where the fallback has to
# work — and print instructions for somebody to follow by hand, which is the
# thing this release exists to stop doing.
AAPANEL_APACHE=/www/server/apache

# Where our own configuration file lives.
#
# Outside every directory any Apache reads automatically, and deliberately.
# /etc/httpd/conf.d is included by the stock Red Hat httpd.conf, and
# /etc/apache2/conf-available becomes server-wide the moment a2enconf is run —
# either would put a ProxyPass at server level, where it is inherited by every
# virtual host on the machine that does not override it. On a server with
# thirty other websites that publishes /fallback on all of them.
#
# So the file sits somewhere nothing includes, and exactly one virtual host
# names it. See lib-edge-vhost.sh.
AKCONNECT_APACHE_CONF=/etc/akconnect/apache/akconnect-fallback.conf

# Where a copy of every file this touches is kept, for ever.
#
# Not beside the original: a backup called site.conf.20260922 inside a virtual
# host directory does not match *.conf and is therefore not read, but the next
# person to name one site.20260922.conf would find out the hard way. Outside
# the configuration tree there is nothing to get wrong.
AKCONNECT_APACHE_BACKUPS=/var/backups/akconnect/apache

# akconnect_apache_layout prints where this machine's Apache keeps things, as
# four words: the service name, the command that enables a module (or "-"),
# the control program, and the main configuration file, which is edited for
# one purpose only — loading a module, which proxies nothing by itself.
akconnect_apache_layout() {
    if command -v apache2ctl >/dev/null 2>&1 && [ -d /etc/apache2 ]; then
        printf 'apache2 a2enmod apache2ctl -\n'

        return 0
    fi

    if command -v httpd >/dev/null 2>&1 && [ -d /etc/httpd ]; then
        # Red Hat builds ship the proxy modules loaded already.
        printf 'httpd - httpd -\n'

        return 0
    fi

    if [ -x "$AAPANEL_APACHE/bin/apachectl" ]; then
        # Nothing here is automatic, and the modules are present as .so files
        # with their LoadModule lines commented out.
        printf 'httpd - %s/bin/apachectl %s/conf/httpd.conf\n' \
            "$AAPANEL_APACHE" "$AAPANEL_APACHE"

        return 0
    fi

    return 1
}

# akconnect_apache_backup keeps a timestamped copy of one file and prints
# where it went.
#
# Kept, not cleaned up. The whole point of a copy of somebody's production
# Apache configuration is that it is still there in a month when they want to
# see what changed.
akconnect_apache_backup() {
    local file=$1 stamp=$2 dest

    [ -f "$file" ] || return 0

    dest="$AKCONNECT_APACHE_BACKUPS/$stamp"
    mkdir -p "$dest" || return 1

    # The full path, flattened, so two files of the same name from different
    # directories cannot overwrite each other.
    cp -p "$file" "$dest/$(printf '%s' "${file#/}" | tr '/' '_')" || return 1

    printf '%s\n' "$dest"
}

# akconnect_apache_rollback puts every file in one backup directory back where
# it came from.
akconnect_apache_rollback() {
    local dest=$1 copy original

    [ -d "$dest" ] || return 1

    for copy in "$dest"/*; do
        [ -f "$copy" ] || continue

        original="/$(basename "$copy" | tr '_' '/')"
        [ -f "$original" ] || continue

        cp -p "$copy" "$original" || return 1
    done
}

# akconnect_apache_service names the unit to reload for a given layout.
#
# aaPanel's Apache is usually a systemd unit called httpd, and is sometimes
# only its own init script. Reloading is attempted both ways by the caller, so
# this only has to get the common case right.
akconnect_apache_load_module() {
    local conf=$1 module=$2

    [ "$conf" = "-" ] && return 1

    # Already loaded by a line that is not commented out.
    grep -Eq "^[[:space:]]*LoadModule[[:space:]]+${module}_module" "$conf" && return 0

    if grep -Eq "^[[:space:]]*#[[:space:]]*LoadModule[[:space:]]+${module}_module" "$conf"; then
        # Uncommented in place, so the line keeps whatever path this build
        # uses for its modules.
        sed -i -E "s|^[[:space:]]*#[[:space:]]*(LoadModule[[:space:]]+${module}_module.*)|\1|" "$conf"

        return 0
    fi

    # No line at all. Added only if the module is actually there, because a
    # LoadModule for a file that does not exist stops Apache starting.
    local so
    so="$(dirname "$(dirname "$conf")")/modules/mod_${module}.so"
    if [ -f "$so" ]; then
        printf '\n# Added by AK Connect install-edge.sh — the HTTPS fallback needs it.\nLoadModule %s_module modules/mod_%s.so\n' \
            "$module" "$module" >> "$conf"

        return 0
    fi

    return 1
}

# akconnect_apache_version prints this Apache's version as a comparable
# number: 2.4.58 becomes 2004058.
akconnect_apache_version() {
    local text
    text="$("$1" -v 2>/dev/null | sed -n 's|.*Apache/\([0-9.]*\).*|\1|p' | head -1)"
    [ -n "$text" ] || return 1

    awk -F. '{ printf "%d\n", $1 * 1000000 + $2 * 1000 + $3 }' <<<"$text.0.0"
}

# akconnect_apache_has_module reports whether a module is actually loaded.
#
# Asked rather than assumed. A configuration wrapped in <IfModule> does nothing
# at all when the module is missing, and does it silently — which would leave
# everybody believing the fallback was in place until a customer took a laptop
# somewhere with a strict firewall, months later and in another city.
akconnect_apache_has_module() {
    local ctl=$1 want=$2
    "$ctl" -M 2>/dev/null | grep -q "[[:space:]]${want}_module"
}

# akconnect_apache_fallback installs the proxy configuration for ONE site.
#
#   $1  the deploy/apache directory holding both forms of it
#   $2  the panel URL, which names the site this belongs to
#   $3  a function to print progress with, e.g. say
#
# Returns 0 when the fallback is configured and every other site still answers
# exactly as it did, 1 when Apache is not on this machine (which is not a
# failure — the relay may be on its own server), 2 when Apache is here and the
# change did not hold, and 3 when Apache is here and does not serve this site
# over TLS, which is a thing for a person to decide rather than for a script to
# invent.
#
# Nothing outside the panel's own virtual host is edited except the module
# loader, which loads code and proxies nothing by itself. Every file touched is
# copied first, and the copies are kept.
akconnect_apache_fallback() {
    local conf_dir=$1 panel=$2 report=${3:-echo}
    local service enabler ctl mainconf layout version source_conf mods
    local domain vhost stamp backup before after changed

    layout="$(akconnect_apache_layout)" || {
        $report "no Apache on this machine, so the HTTPS fallback is not proxied here"

        return 1
    }

    read -r service enabler ctl mainconf <<<"$layout"

    domain="$(akconnect_panel_host "$panel")"
    [ -n "$domain" ] || {
        $report "cannot tell which site to configure from '$panel'"

        return 3
    }

    vhost="$(akconnect_vhost_file "$domain")" || {
        $report "no virtual host on this Apache serves $domain, so there is nothing to add the"
        $report "fallback to. Create the site first, or configure the fallback on whichever"
        $report "machine serves it."

        return 3
    }

    [ -n "$(akconnect_vhost_tls_line "$vhost")" ] || {
        $report "$domain is served by $vhost, which has no TLS virtual host. The fallback is a"
        $report "wss:// address, so it needs one — issue a certificate for the site first."

        return 3
    }

    version="$(akconnect_apache_version "$ctl")" || version=0

    # mod_proxy_wstunnel is deprecated from 2.4.47, where mod_proxy_http
    # carries the upgrade itself, and some builds no longer ship it. Choosing
    # by version rather than writing one file and hoping is the difference
    # between a configuration that works on this VPS and one that works on a
    # customer's.
    if [ "$version" -ge 2004047 ]; then
        source_conf="$conf_dir/akconnect-fallback-upgrade.conf"
        mods="proxy proxy_http"
    else
        source_conf="$conf_dir/akconnect-fallback.conf"
        mods="proxy proxy_http proxy_wstunnel"
    fi

    [ -f "$source_conf" ] || {
        $report "cannot find $source_conf"

        return 2
    }

    # What every site on this machine says right now. Taken before anything is
    # touched, because it is the only thing the result can be compared against.
    stamp="$(date -u +%Y%m%d-%H%M%S)"
    before="$(mktemp)"
    after="$(mktemp)"
    akconnect_site_probe "$before"
    $report "$(wc -l < "$before") site(s) on this Apache answered before the change"

    backup="$AKCONNECT_APACHE_BACKUPS/$stamp"
    akconnect_apache_backup "$vhost" "$stamp" >/dev/null || {
        $report "could not keep a copy of $vhost"
        rm -f "$before" "$after"

        return 2
    }
    [ "$mainconf" != "-" ] && akconnect_apache_backup "$mainconf" "$stamp" >/dev/null

    for mod in $mods; do
        akconnect_apache_has_module "$ctl" "$mod" && continue

        if [ "$enabler" != "-" ]; then
            "$enabler" "$mod" >/dev/null 2>&1
        else
            akconnect_apache_load_module "$mainconf" "$mod"
        fi
    done

    # Enabled is not the same as loaded, and only loaded carries a websocket.
    # Asked again after the attempt, because "a2enmod said yes" and "Apache is
    # serving with it" are different claims and only the second one matters.
    for mod in $mods; do
        akconnect_apache_has_module "$ctl" "$mod" || {
            $report "mod_$mod is not loaded, so the fallback would accept nothing"
            akconnect_apache_rollback "$backup"
            rm -f "$before" "$after"

            return 2
        }
    done
    $report "Apache $(( version / 1000000 )).$(( version / 1000 % 1000 )).$(( version % 1000 )) with ${mods// /, }"

    mkdir -p "$(dirname "$AKCONNECT_APACHE_CONF")"
    install -m 644 "$source_conf" "$AKCONNECT_APACHE_CONF" || {
        akconnect_apache_rollback "$backup"
        rm -f "$before" "$after"

        return 2
    }

    akconnect_vhost_insert "$vhost" "$AKCONNECT_APACHE_CONF" || {
        $report "could not add the fallback to $vhost"
        akconnect_apache_rollback "$backup"
        rm -f "$before" "$after"

        return 2
    }
    $report "added to the $domain virtual host in $vhost, and to nothing else"

    # Tested before it is reloaded, always. A broken configuration here would
    # take thirty other websites down with it.
    if ! akconnect_apache_test "$ctl"; then
        akconnect_apache_rollback "$backup"
        rm -f "$AKCONNECT_APACHE_CONF"
        $report "Apache refused the configuration, so it has been put back as it was"
        rm -f "$before" "$after"

        return 2
    fi

    akconnect_apache_reload "$service" "$ctl" || {
        akconnect_apache_rollback "$backup"
        rm -f "$AKCONNECT_APACHE_CONF"
        akconnect_apache_reload "$service" "$ctl"
        $report "Apache would not reload, so it has been put back as it was"
        rm -f "$before" "$after"

        return 2
    }

    # And the question this whole file exists to answer: is every other site on
    # this machine still saying exactly what it said a minute ago?
    akconnect_site_probe "$after"
    changed="$(akconnect_sites_changed "$before" "$after")"

    if [ -n "$changed" ]; then
        # Asked once more before anything is undone. A site that answered
        # differently because a cron job happened to be running is not a
        # regression this change caused, and rolling back somebody's production
        # server over one is its own kind of harm.
        akconnect_site_probe "$after"
        changed="$(akconnect_sites_changed "$before" "$after")"
    fi

    if [ -n "$changed" ]; then
        $report "another site on this server changed what it answers:"
        printf '%s\n' "$changed" | while read -r line; do $report "    $line"; done

        akconnect_apache_rollback "$backup"
        rm -f "$AKCONNECT_APACHE_CONF"
        akconnect_apache_reload "$service" "$ctl"

        $report "everything has been put back and Apache reloaded again"
        rm -f "$before" "$after"

        return 2
    fi

    $report "all $(wc -l < "$after") site(s) answer exactly as they did before"
    $report "a copy of every file changed is in $backup"
    $report "Apache proxies https://$domain/fallback to the relay on 127.0.0.1:9443"

    rm -f "$before" "$after"

    return 0
}

# akconnect_may_configure_apache answers the one question that keeps an hourly
# timer out of somebody's production web server.
#
#   $1  what an option asked for: 1, 0, or empty for "decide"
#   $2  whether this run is unattended: 1 or 0
#
# A function rather than three lines inline, because this decision is the whole
# of requirement 2 and a decision that cannot be exercised is a decision
# nobody checks. The gate calls exactly this.
#
# An explicit option always wins, in both directions: somebody who types
# --configure-apache from a cron entry has said what they want, and somebody
# who types --no-configure-apache by hand has too. With no option it comes
# down to whether anybody is watching.
akconnect_may_configure_apache() {
    local asked=${1:-} unattended=${2:-0}

    case "$asked" in
        1) printf '1\n'; return 0 ;;
        0) printf '0\n'; return 0 ;;
    esac

    if [ "$unattended" = "1" ]; then printf '0\n'; else printf '1\n'; fi
}

# akconnect_apache_configured reports whether the fallback is already proxied
# for one site.
#
#   $1  the panel URL
#
# Both halves have to be true: our own configuration file has to exist, and the
# site's virtual host has to name it. Either alone is a half-finished state
# that would answer nothing — a file nothing includes, or an include pointing
# at a file that is not there.
akconnect_apache_configured() {
    local domain vhost

    [ -f "$AKCONNECT_APACHE_CONF" ] || return 1

    domain="$(akconnect_panel_host "${1:-}")"
    [ -n "$domain" ] || return 1

    vhost="$(akconnect_vhost_file "$domain")" || return 1

    akconnect_vhost_has "$vhost"
}

# akconnect_panel_host takes the hostname out of a panel URL.
akconnect_panel_host() {
    printf '%s' "${1:-}" | sed -E 's|^[a-z]+://||; s|/.*$||; s|:[0-9]+$||'
}

# akconnect_apache_test runs the syntax check with whichever control program
# this Apache has — including one that is not on the path at all, which is how
# aaPanel installs it.
#
# One form only, and deliberately. apachectl also understands "configtest", and
# trying it after "-t" fails looks like tolerance and is not: a wrapper that
# exits 0 for arguments it does not recognise would turn a refused
# configuration into an accepted one, and the thing being guarded here is the
# customer's panel. Every Apache control program since 2.0 passes -t through to
# httpd, so refusing when it is not understood is both safe and correct.
akconnect_apache_test() {
    "$1" -t >/dev/null 2>&1
}

# akconnect_apache_restore puts the main configuration back.
akconnect_apache_restore() {
    local conf=$1 backup=${2:-}

    [ "$conf" = "-" ] && return 0
    [ -n "$backup" ] && [ -f "$backup" ] || return 0

    cp -p "$backup" "$conf" && rm -f "$backup"
}

# akconnect_apache_reload asks Apache to re-read its configuration.
#
# systemd first, because that is how a packaged Apache is run, and the control
# program second, because aaPanel's is sometimes not a unit at all.
akconnect_apache_reload() {
    local service=$1 ctl=$2

    systemctl reload "$service" >/dev/null 2>&1 && return 0
    systemctl restart "$service" >/dev/null 2>&1 && return 0
    "$ctl" -k graceful >/dev/null 2>&1 && return 0
    "$ctl" graceful >/dev/null 2>&1
}

# akconnect_fallback_reachable checks the path end to end, the way an agent
# on a customer network would: through TLS on 443, by name.
#
#   $1  the panel URL, e.g. https://net.akdwk.in
#
# The relay answers the health path with a line of text, so a reply that is
# not ours — a captive portal, a default vhost, a 404 from a panel that does
# not know about this — is told apart from a reply that is.
akconnect_fallback_reachable() {
    local panel=$1 body

    [ -n "$panel" ] || return 1

    body="$(curl -fsS --max-time 10 "${panel%/}/fallback/health" 2>/dev/null)" || return 1

    case "$body" in
        ok*) return 0 ;;
        *)   return 1 ;;
    esac
}
