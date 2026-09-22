#!/usr/bin/env bash
#
# Take the HTTPS fallback back out of Apache, completely.
#
# The counterpart to install-edge.sh's Apache step, and it exists because a
# change to a web server serving thirty other businesses should be one you can
# walk back in one command without reading any of this.
#
# It removes:
#
#   - the three-line block from EVERY virtual host on this machine that has
#     one, not only the one we expect — a domain that moved, or an earlier run
#     against a different panel URL, can leave a block behind in a file nothing
#     looks at any more;
#   - our own configuration file;
#
# and it leaves alone:
#
#   - the LoadModule lines. Other sites on this server may be proxying too, and
#     turning mod_proxy off underneath them to tidy up after ourselves would be
#     precisely the kind of thing this script exists to apologise for. It says
#     which ones it left.
#
# Apache is syntax-checked before it is reloaded, and every site on the machine
# is asked for a status code before and after, exactly as installing does.
#
#   ./remove-apache-fallback.sh --panel https://network.akdwk.in
#   ./remove-apache-fallback.sh --panel https://network.akdwk.in --dry-run
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# shellcheck source=lib-edge-apache.sh
. "$HERE/lib-edge-apache.sh"
# shellcheck source=lib-edge-vhost.sh
. "$HERE/lib-edge-vhost.sh"
# shellcheck source=lib-edge-probe.sh
. "$HERE/lib-edge-probe.sh"

PANEL=""
DRY_RUN=0

die()  { printf '\n  \033[31m✗ %s\033[0m\n\n' "$*" >&2; exit 1; }
say()  { printf '  %s\n' "$*"; }
step() { printf '\n\033[1m── %s\033[0m\n' "$*"; }

while [ $# -gt 0 ]; do
    case "$1" in
        --panel)   PANEL="${2:-}"; shift 2 ;;
        --dry-run) DRY_RUN=1; shift ;;
        *) die "unknown option: $1
    Valid options are --panel <url> and --dry-run." ;;
    esac
done

[ "$(id -u)" -eq 0 ] || die "run this as root: sudo $0 --panel <url>"

step "what is there"

LAYOUT="$(akconnect_apache_layout)" || die "no Apache on this machine."
read -r SERVICE ENABLER CTL MAINCONF <<<"$LAYOUT"

mapfile -t CARRYING < <(akconnect_vhost_files_with)

if [ "${#CARRYING[@]}" -eq 0 ] && [ ! -f "$AKCONNECT_APACHE_CONF" ]; then
    say "nothing to remove: no virtual host carries the fallback and"
    say "$AKCONNECT_APACHE_CONF is not there."
    printf '\n  \033[32mnothing to do\033[0m\n\n'
    exit 0
fi

for file in ${CARRYING[@]+"${CARRYING[@]}"}; do
    say "the fallback block is in $file"
done
[ -f "$AKCONNECT_APACHE_CONF" ] && say "the proxy directives are in $AKCONNECT_APACHE_CONF"

if [ "$DRY_RUN" -eq 1 ]; then
    printf '\n  \033[33mdry run\033[0m — nothing was changed.\n\n'
    exit 0
fi

akconnect_confirm "Remove it from the file(s) above and reload Apache?" \
    || { say "nothing was changed"; exit 1; }

step "removing it"

STAMP="$(date -u +%Y%m%d-%H%M%S)-removal"
BACKUP="$AKCONNECT_APACHE_BACKUPS/$STAMP"

BEFORE="$(mktemp)"; AFTER="$(mktemp)"
trap 'rm -f "$BEFORE" "$AFTER"' EXIT

akconnect_site_probe "$BEFORE"
say "$(wc -l < "$BEFORE") site(s) answered before the change"

OK=1
for file in ${CARRYING[@]+"${CARRYING[@]}"}; do
    akconnect_apache_backup "$file" "$STAMP" || OK=0
done
akconnect_apache_backup "$AKCONNECT_APACHE_CONF" "$STAMP" || OK=0
[ "$OK" -eq 1 ] || die "could not keep a copy of every file, so nothing was edited."

for file in ${CARRYING[@]+"${CARRYING[@]}"}; do
    akconnect_vhost_remove "$file" || die "could not edit $file. Nothing has been reloaded."
    say "removed from $file"
done

rm -f "$AKCONNECT_APACHE_CONF"
say "removed $AKCONNECT_APACHE_CONF"

if ! akconnect_apache_test "$CTL"; then
    akconnect_apache_undo "$BACKUP" say "Apache refused the result"
    die "Apache would not accept the configuration with the fallback removed.
    Everything has been put back. Nothing was reloaded."
fi

akconnect_apache_reload "$SERVICE" "$CTL" || {
    akconnect_apache_undo "$BACKUP" say "Apache would not reload"
    akconnect_apache_reload "$SERVICE" "$CTL"
    die "Apache would not reload. Everything has been put back."
}

step "checking the other sites"

akconnect_site_probe "$AFTER"
CHANGED="$(akconnect_sites_changed "$BEFORE" "$AFTER")"

# The panel losing /fallback is the change. Anything else is not.
DOMAIN="$(akconnect_panel_host "$PANEL")"
[ -n "$DOMAIN" ] && CHANGED="$(grep -v "^$DOMAIN /fallback " <<<"$CHANGED" | grep -v '^$')"

if [ -n "$CHANGED" ]; then
    printf '%s\n' "$CHANGED" | while read -r row; do say "    $row"; done
    akconnect_apache_undo "$BACKUP" say "another site changed what it answers"
    akconnect_apache_reload "$SERVICE" "$CTL"
    die "Removing the fallback changed another site, so it has been put back."
fi

say "all $(wc -l < "$AFTER") site(s) answer exactly as they did before"

step "what was left alone"

LEFT=""
for mod in proxy proxy_http proxy_wstunnel; do
    akconnect_apache_has_module "$CTL" "$mod" && LEFT="$LEFT mod_$mod"
done

if [ -n "$LEFT" ]; then
    say "these are still loaded, and were not turned off:$LEFT"
    say "other sites on this server may be proxying too, and switching them off"
    say "underneath somebody would be worse than leaving them on. They serve"
    say "nothing by themselves."
fi

say "a copy of every file changed is in $BACKUP"

printf '\n  \033[32mremoved\033[0m — the fallback is out of Apache and every other site is unchanged.\n\n'
