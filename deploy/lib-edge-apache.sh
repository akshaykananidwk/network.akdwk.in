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

# A backup set is a directory of numbered copies plus a manifest naming what
# each one was.
#
# Numbered, and not the original path with the slashes turned into
# underscores, which is what this did before. That mapping is not invertible:
# /a/vhosts/panel_ssl.conf and /a/vhosts/panel/ssl.conf flatten to the same
# name, and restoring turned every underscore back into a slash — so a file
# with an underscore anywhere in its path was "restored" to a path that does
# not exist, the loop skipped it, and the caller printed "it has been put back
# as it was". A rollback that silently does nothing, on a production web
# server, reported as success.
AKCONNECT_MANIFEST=MANIFEST

# akconnect_apache_backup keeps a copy of one file and records where it came
# from. A file that does not exist yet is recorded too, as "absent", so a
# rollback removes what this run created rather than leaving it behind.
#
# Returns non-zero if anything could not be kept. Callers must check: editing a
# file whose backup failed is how an edit becomes permanent.
akconnect_apache_backup() {
    local file=$1 stamp=$2 dest index

    dest="$AKCONNECT_APACHE_BACKUPS/$stamp"
    mkdir -p "$dest" || return 1

    # Counted without a redirect from a file that may not be there: bash
    # reports a failed input redirection before the command's own 2>/dev/null
    # can take effect, so the "suppressed" error lands on the operator's
    # console in the middle of a change to their web server.
    if [ -f "$dest/$AKCONNECT_MANIFEST" ]; then
        index="$(printf '%03d' "$(( $(wc -l "$dest/$AKCONNECT_MANIFEST" | awk '{print $1}') + 1 ))")"
    else
        index=001
    fi

    if [ -f "$file" ]; then
        cp -p "$file" "$dest/$index" || return 1
        printf '%s\t%s\tpresent\n' "$index" "$file" >> "$dest/$AKCONNECT_MANIFEST" || return 1
    else
        printf '%s\t%s\tabsent\n' "$index" "$file" >> "$dest/$AKCONNECT_MANIFEST" || return 1
    fi
}

# akconnect_apache_rollback puts every file in one backup set back.
#
# Every file, and it does not stop at the first failure — the alphabetically
# first thing in the set used to abort the loop, leaving the production virtual
# host untouched while the caller announced a full restore. It now tries all of
# them, prints what it could not do, and returns the number of failures.
akconnect_apache_rollback() {
    local dest=$1 index original state failures=0

    [ -d "$dest" ] && [ -f "$dest/$AKCONNECT_MANIFEST" ] || return 1

    while IFS=$'\t' read -r index original state; do
        [ -n "$original" ] || continue

        if [ "$state" = absent ]; then
            rm -f "$original" || { printf 'could not remove %s\n' "$original" >&2; failures=$((failures + 1)); }

            continue
        fi

        if ! cp -p "$dest/$index" "$original"; then
            printf 'could not restore %s\n' "$original" >&2
            failures=$((failures + 1))
        fi
    done < "$dest/$AKCONNECT_MANIFEST"

    return "$failures"
}

# akconnect_apache_load_module makes one module loadable, in place.
#
# Uncommented where a commented line exists, appended only where the .so is
# really present — a LoadModule naming a file that is not there stops Apache
# starting, which on this machine means thirty websites.
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

# akconnect_apache_fallback installs the proxy configuration for ONE virtual
# host, after showing exactly what it will do and being told to go ahead.
#
#   $1  the deploy/apache directory holding both forms of it
#   $2  the panel URL, which names the site this belongs to
#   $3  a function to print progress with, e.g. say
#
# Returns 0 when the fallback is configured, every other site still answers
# exactly as it did, and no site but the panel's answers /fallback; 1 when
# Apache is not on this machine; 2 when the change did not hold and has been
# undone; 3 when this Apache does not serve that domain in a virtual host of
# its own; 4 when the operator said no.
akconnect_apache_fallback() {
    local conf_dir=$1 panel=$2 report=${3:-echo}
    local service enabler ctl mainconf layout version source_conf mods
    local domain target vhost line stamp backup before after changed leaked

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

    target="$(akconnect_vhost_target "$domain")" || {
        local claimed
        if claimed="$(akconnect_vhost_alias_only "$domain")"; then
            $report "no virtual host on this Apache is named $domain. It is served as an alias of"
            $report "$(cut -f2 <<<"$claimed") in $(cut -f1 <<<"$claimed")."
            $report "Refusing: that is somebody else's site, and putting a proxy inside it would"
            $report "publish /fallback on their domain. Give $domain a site of its own."
        else
            $report "no TLS virtual host on this Apache is named $domain, so there is nothing to"
            $report "add the fallback to. The fallback is a wss:// address, so the site needs a"
            $report "certificate — create it, or configure this on whichever machine serves it."
        fi

        return 3
    }

    IFS=$'\t' read -r vhost line <<<"$target"

    version="$(akconnect_apache_version "$ctl")" || version=0

    # mod_proxy_wstunnel is deprecated from 2.4.47, where mod_proxy_http
    # carries the upgrade itself, and some builds no longer ship it.
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

    # Exactly what is about to change, before anything changes.
    #
    # Named files and named lines, because the person reading this is about to
    # let a script edit the web server thirty other businesses are served by,
    # and "configuring Apache" is not something anybody can agree to.
    $report ""
    $report "This will add three lines to ONE virtual host:"
    $report ""
    $report "  file : $vhost"
    $report "  block: the <VirtualHost> opening at line $line, ServerName $domain, TLS"
    $report ""
    $report "      $AKCONNECT_VHOST_BEGIN"
    $report "      IncludeOptional $AKCONNECT_APACHE_CONF"
    $report "      $AKCONNECT_VHOST_END"
    $report ""
    $report "and write $AKCONNECT_APACHE_CONF, which holds the proxy directives."
    if [ "$mainconf" != "-" ]; then
        $report "It may also uncomment LoadModule lines in $mainconf for: ${mods// /, }."
        $report "Loading a module changes no site's behaviour by itself."
    fi
    $report ""
    $report "Nothing else on this server is edited. A copy of every file it touches is kept,"
    $report "and deploy/remove-apache-fallback.sh undoes all of it."
    $report ""

    akconnect_confirm "Go ahead?" || {
        $report "nothing was changed"

        return 4
    }

    # What every site on this machine says right now, including whether it
    # answers /fallback — which is the question the scope requirement is
    # really about, and the one the first version of this never asked.
    stamp="$(date -u +%Y%m%d-%H%M%S)"
    backup="$AKCONNECT_APACHE_BACKUPS/$stamp"
    before="$(mktemp)"
    after="$(mktemp)"
    akconnect_site_probe "$before"
    $report "$(wc -l < "$before") site(s) on this Apache answered before the change"

    # Every file that is about to be written, including our own configuration
    # file — which this used to install without a copy and delete on rollback,
    # so a re-run that tripped the check destroyed a working proxy.
    local file ok=1
    for file in "$vhost" "$AKCONNECT_APACHE_CONF"; do
        akconnect_apache_backup "$file" "$stamp" || ok=0
    done
    [ "$mainconf" != "-" ] && { akconnect_apache_backup "$mainconf" "$stamp" || ok=0; }

    if [ "$ok" -ne 1 ]; then
        $report "could not keep a copy of every file this would edit, so nothing was edited"
        rm -f "$before" "$after"

        return 2
    fi

    # From here until the end, an interrupt puts everything back. Without this
    # a dropped ssh session leaves a virtual host carrying an untested include
    # that nothing has syntax-checked, until aaPanel reloads Apache hours later
    # for an unrelated reason.
    trap 'akconnect_apache_rollback "'"$backup"'" >/dev/null 2>&1; trap - INT TERM EXIT' INT TERM EXIT

    for mod in $mods; do
        akconnect_apache_has_module "$ctl" "$mod" && continue

        if [ "$enabler" != "-" ]; then
            "$enabler" "$mod" >/dev/null 2>&1
        else
            akconnect_apache_load_module "$mainconf" "$mod"
        fi
    done

    for mod in $mods; do
        akconnect_apache_has_module "$ctl" "$mod" || {
            akconnect_apache_undo "$backup" "$report" "mod_$mod is not loaded, so the fallback would accept nothing"
            rm -f "$before" "$after"

            return 2
        }
    done
    $report "Apache $(( version / 1000000 )).$(( version / 1000 % 1000 )).$(( version % 1000 )) with ${mods// /, }"

    mkdir -p "$(dirname "$AKCONNECT_APACHE_CONF")"
    install -m 644 "$source_conf" "$AKCONNECT_APACHE_CONF" || {
        akconnect_apache_undo "$backup" "$report" "could not write $AKCONNECT_APACHE_CONF"
        rm -f "$before" "$after"

        return 2
    }

    akconnect_vhost_insert "$vhost" "$domain" "$AKCONNECT_APACHE_CONF" || {
        akconnect_apache_undo "$backup" "$report" "could not add the fallback to $vhost"
        rm -f "$before" "$after"

        return 2
    }
    $report "added to the $domain virtual host in $vhost, and to nothing else"

    if ! akconnect_apache_test "$ctl"; then
        akconnect_apache_undo "$backup" "$report" "Apache refused the configuration"
        rm -f "$before" "$after"

        return 2
    fi

    akconnect_apache_reload "$service" "$ctl" || {
        akconnect_apache_undo "$backup" "$report" "Apache would not reload"
        akconnect_apache_reload "$service" "$ctl"
        rm -f "$before" "$after"

        return 2
    }

    # Two questions now, not one. Did anything change, and did the tunnel
    # appear anywhere it should not have.
    akconnect_site_probe "$after"
    changed="$(akconnect_sites_changed "$before" "$after")"

    if [ -n "$changed" ]; then
        # Asked once more before anything is undone. A site that answered
        # differently because a cron job happened to be running is not a
        # regression this change caused, and rolling back somebody's
        # production server over one is its own kind of harm.
        akconnect_site_probe "$after"
        changed="$(akconnect_sites_changed "$before" "$after")"
    fi

    # The panel's own /fallback is expected to change — that is the change.
    changed="$(grep -v "^$domain /fallback " <<<"$changed" | grep -v '^$')"

    leaked="$(akconnect_fallback_exclusive "$domain" "$after")" || true

    if [ -n "$changed" ] || [ -n "$leaked" ]; then
        [ -n "$changed" ] && {
            $report "another site on this server changed what it answers:"
            printf '%s\n' "$changed" | while read -r row; do $report "    $row"; done
        }
        [ -n "$leaked" ] && {
            $report "the tunnel is not confined to $domain:"
            printf '%s\n' "$leaked" | while read -r row; do $report "    $row"; done
        }

        akconnect_apache_undo "$backup" "$report" "putting everything back"
        akconnect_apache_reload "$service" "$ctl"
        rm -f "$before" "$after"

        return 2
    fi

    trap - INT TERM EXIT

    $report "all $(wc -l < "$after") site(s) answer exactly as they did before"
    $report "and only $domain answers /fallback, over TLS and not on port 80"
    $report "a copy of every file changed is in $backup"
    $report "Apache proxies https://$domain/fallback to the relay on 127.0.0.1:9443"

    rm -f "$before" "$after"

    return 0
}

# akconnect_apache_undo puts everything back and says so honestly.
#
# Honestly is the point. The first version printed "it has been put back as it
# was" whatever happened, including when the restore had failed and left a
# production virtual host edited. An operator who is told the server was
# restored will not go and look.
akconnect_apache_undo() {
    local backup=$1 report=$2 why=$3 failures

    $report "$why"

    if akconnect_apache_rollback "$backup"; then
        trap - INT TERM EXIT
        $report "everything this run changed has been put back"

        return 0
    fi

    failures=$?
    trap - INT TERM EXIT

    $report "WARNING: $failures file(s) could NOT be put back. This server is part-way"
    $report "through a change. The copies are in $backup and"
    $report "$backup/MANIFEST says where each one belongs."
    $report "Restore them by hand before reloading Apache."

    return 1
}

# akconnect_may_configure_apache answers the one question that keeps an hourly
# timer out of somebody's production web server.
#
#   $1  what an option asked for: 1, 0, or empty for "nothing was asked"
#   $2  whether this run is unattended: 1 or 0
#
# A function rather than three lines inline, because this decision is the whole
# of the requirement and a decision that cannot be exercised is a decision
# nobody checks. The gate calls exactly this.
akconnect_may_configure_apache() {
    local asked=${1:-} unattended=${2:-0}

    # Unattended never, whatever was asked for. A --configure-apache in a cron
    # entry is still a job nobody is watching reaching into a web server that
    # serves other people's websites, and the flag is not a reason to allow it.
    [ "$unattended" = "1" ] && { printf '0\n'; return 0; }

    # And otherwise only when asked. There is no default that configures
    # Apache: this step came within one commented-out SSL stanza of carrying
    # customer tunnels in cleartext on port 80, and one parked ServerAlias of
    # writing a proxy into a stranger's virtual host. A change with that
    # failure mode is one somebody types on purpose.
    [ "$asked" = "1" ] && { printf '1\n'; return 0; }

    printf '0\n'
}

# akconnect_confirm asks, on the terminal, and refuses when there is not one.
#
# The absence of a terminal is itself an answer: nobody is there to confirm, so
# nothing is done. That is the second lock on the same door as the decision
# above, and it holds for a cron entry, a CI runner, and an ssh command with no
# tty — none of which can be talked out of it by an option.
akconnect_confirm() {
    local prompt=$1 reply

    if [ ! -r /dev/tty ]; then
        printf '  refusing: there is no terminal here to confirm on.\n' >&2

        return 1
    fi

    printf '\n  %s [y/N] ' "$prompt" > /dev/tty
    read -r reply < /dev/tty || return 1

    case "$reply" in
        y|Y|yes|YES) return 0 ;;
        *) return 1 ;;
    esac
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
    local domain target vhost

    [ -f "$AKCONNECT_APACHE_CONF" ] || return 1

    domain="$(akconnect_panel_host "${1:-}")"
    [ -n "$domain" ] || return 1

    target="$(akconnect_vhost_target "$domain")" || return 1
    vhost="$(cut -f1 <<<"$target")"

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
