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

# akconnect_apache_layout prints where this machine's Apache keeps things, as
# five words: the service name, the directory a config drops into, the command
# that enables a module (or "-"), the control program, and the main
# configuration file an include may have to be added to (or "-" when the
# directory is included automatically).
akconnect_apache_layout() {
    if command -v apache2ctl >/dev/null 2>&1 && [ -d /etc/apache2 ]; then
        printf 'apache2 /etc/apache2/conf-available a2enmod apache2ctl -\n'

        return 0
    fi

    if command -v httpd >/dev/null 2>&1 && [ -d /etc/httpd ]; then
        # Red Hat builds ship the proxy modules loaded already, so there is
        # nothing to enable — only a file to drop in, and conf.d is included
        # by the stock httpd.conf.
        printf 'httpd /etc/httpd/conf.d - httpd -\n'

        return 0
    fi

    if [ -x "$AAPANEL_APACHE/bin/apachectl" ]; then
        # Nothing here is automatic: the directory is not included unless we
        # say so, and the modules are present as .so files with their
        # LoadModule lines commented out.
        printf 'httpd %s/conf/extra - %s/bin/apachectl %s/conf/httpd.conf\n' \
            "$AAPANEL_APACHE" "$AAPANEL_APACHE" "$AAPANEL_APACHE"

        return 0
    fi

    return 1
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

# akconnect_apache_ensure_include makes the main configuration read our file.
#
# Idempotent by a marker rather than by the line itself, so a later change to
# the path does not leave two includes behind.
akconnect_apache_ensure_include() {
    local conf=$1 include=$2

    [ "$conf" = "-" ] && return 0

    grep -q 'AK Connect HTTPS fallback' "$conf" && return 0

    printf '\n# AK Connect HTTPS fallback — see deploy/apache/akconnect-fallback.conf\nIncludeOptional %s\n' \
        "$include" >> "$conf"
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

# akconnect_apache_fallback installs the proxy configuration.
#
#   $1  the deploy/apache directory holding both forms of it
#   $2  a function to print progress with, e.g. say
#
# Returns 0 when the fallback is configured and Apache reloaded, 1 when Apache
# is not on this machine (which is not a failure — the relay may be on its own
# server), and 2 when Apache is here and would not take the configuration.
akconnect_apache_fallback() {
    local conf_dir=$1 report=${2:-echo}
    local service confdir enabler ctl mainconf layout version source_conf mods backup

    layout="$(akconnect_apache_layout)" || {
        $report "no Apache on this machine, so the HTTPS fallback is not proxied here"

        return 1
    }

    read -r service confdir enabler ctl mainconf <<<"$layout"

    version="$(akconnect_apache_version "$ctl")" || version=0

    # The main configuration is edited on layouts that need it — aaPanel's,
    # where nothing is automatic. Kept first, and put back if Apache then
    # refuses the result: a broken httpd.conf takes the customer's panel down
    # with it, and the panel is how anybody would find out.
    if [ "$mainconf" != "-" ]; then
        backup="$mainconf.akconnect-$(date +%s)"
        cp -p "$mainconf" "$backup" || {
            $report "could not keep a copy of $mainconf"

            return 2
        }
    fi

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
            akconnect_apache_restore "$mainconf" "$backup"

            return 2
        }
    done
    $report "Apache $(( version / 1000000 )).$(( version / 1000 % 1000 )).$(( version % 1000 )) with ${mods// /, }"

    mkdir -p "$confdir"
    install -m 644 "$source_conf" "$confdir/akconnect-fallback.conf" || return 2

    if command -v a2enconf >/dev/null 2>&1; then
        a2enconf akconnect-fallback >/dev/null 2>&1 || {
            $report "Apache would not enable the akconnect-fallback configuration"

            return 2
        }
    fi

    # On a layout whose configuration directory is not read automatically, say
    # so explicitly. Without this the file is installed and ignored, which is
    # the worst of both: it looks done and does nothing.
    akconnect_apache_ensure_include "$mainconf" "$confdir/akconnect-fallback.conf"

    # Tested before it is reloaded, always. A broken configuration here would
    # take the panel down with it, and the panel is how anybody would find out.
    if ! akconnect_apache_test "$ctl"; then
        rm -f "$confdir/akconnect-fallback.conf"
        command -v a2disconf >/dev/null 2>&1 && a2disconf akconnect-fallback >/dev/null 2>&1
        akconnect_apache_restore "$mainconf" "$backup"
        $report "Apache refused the configuration, so it has been put back as it was"

        return 2
    fi

    akconnect_apache_reload "$service" "$ctl" || {
        $report "Apache would not reload"

        return 2
    }

    [ -n "${backup:-}" ] && rm -f "$backup"

    $report "Apache proxies /fallback to the relay on 127.0.0.1:9443"

    return 0
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
