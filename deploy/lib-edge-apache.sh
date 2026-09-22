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

# akconnect_apache_layout prints where this machine's Apache keeps things, as
# three words: the service name, the directory a config drops into, and the
# command that enables a module, or "-" when modules are not enabled that way.
akconnect_apache_layout() {
    if command -v apache2ctl >/dev/null 2>&1 && [ -d /etc/apache2 ]; then
        printf 'apache2 /etc/apache2/conf-available a2enmod\n'

        return 0
    fi

    if command -v httpd >/dev/null 2>&1 && [ -d /etc/httpd ]; then
        # Red Hat builds ship the proxy modules loaded already, so there is
        # nothing to enable — only a file to drop in.
        printf 'httpd /etc/httpd/conf.d -\n'

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
    local service confdir enabler layout ctl version source_conf mods

    layout="$(akconnect_apache_layout)" || {
        $report "no Apache on this machine, so the HTTPS fallback is not proxied here"

        return 1
    }

    read -r service confdir enabler <<<"$layout"

    # apache2ctl on Debian, httpd on Red Hat. Both answer -v and -M.
    ctl="$service"
    [ "$service" = "apache2" ] && ctl=apache2ctl

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

    if [ "$enabler" != "-" ]; then
        for mod in $mods; do
            "$enabler" "$mod" >/dev/null 2>&1 || {
                $report "Apache would not enable mod_$mod"

                return 2
            }
        done
    fi

    # Enabled is not the same as loaded, and only loaded carries a websocket.
    for mod in $mods; do
        akconnect_apache_has_module "$ctl" "$mod" || {
            $report "mod_$mod is not loaded, so the fallback would accept nothing"

            return 2
        }
    done
    $report "Apache $(( version / 1000000 )).$(( version / 1000 % 1000 )).$(( version % 1000 )) with ${mods// /, }"

    install -m 644 "$source_conf" "$confdir/akconnect-fallback.conf" || return 2

    if command -v a2enconf >/dev/null 2>&1; then
        a2enconf akconnect-fallback >/dev/null 2>&1 || {
            $report "Apache would not enable the akconnect-fallback configuration"

            return 2
        }
    fi

    # Tested before it is reloaded, always. A broken configuration here would
    # take the panel down with it, and the panel is how anybody would find out.
    if ! akconnect_apache_test "$service"; then
        rm -f "$confdir/akconnect-fallback.conf"
        command -v a2disconf >/dev/null 2>&1 && a2disconf akconnect-fallback >/dev/null 2>&1
        $report "Apache refused the configuration, so it has been removed again"

        return 2
    fi

    systemctl reload "$service" >/dev/null 2>&1 || systemctl restart "$service" >/dev/null 2>&1 || {
        $report "Apache would not reload"

        return 2
    }

    $report "Apache proxies /fallback to the relay on 127.0.0.1:9443"

    return 0
}

# akconnect_apache_test runs the syntax check for whichever Apache this is.
akconnect_apache_test() {
    case "$1" in
        apache2) apache2ctl configtest >/dev/null 2>&1 ;;
        httpd)   httpd -t >/dev/null 2>&1 ;;
        *)       return 1 ;;
    esac
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
