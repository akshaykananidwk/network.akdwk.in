#!/usr/bin/env bash
#
# Ask every website on this machine what it says, before and after.
#
# The first version of this asked each site for "/" and compared the status
# codes. That guard cannot see the failure it exists to prevent: adding a
# ProxyPass for /fallback to somebody else's virtual host does not change what
# that site answers for "/", so a scope leak passed the check cleanly. It fired
# only for collateral damage the change was never likely to cause.
#
# So every name is asked three questions, and all three are compared:
#
#   /                     did this site keep working at all
#   /fallback/health      is the tunnel answering where it should not
#   /fallback over :80    is the tunnel answering in cleartext
#
# The second is the one that matters. On a correct installation exactly one
# name on the machine answers it, and every other name gives a 404 or a 403
# from its own document root.

# akconnect_probe_one prints "root fallback plain" for one name.
#
# Resolved to this machine explicitly, so the answer is what THIS Apache says
# and not what a load balancer or somebody else's DNS says. The certificate is
# not verified: a site whose certificate is wrong is wrong identically before
# and afterwards, and the question here is whether a reload changed anything.
#
# A name that cannot be reached gets 000, which compares equal to itself — an
# unreachable site that is still unreachable is not a regression we caused.
akconnect_probe_one() {
    local name=$1 root fallback plain

    root="$(akconnect_probe_code "https://$name/" "$name")"
    fallback="$(akconnect_probe_code "https://$name/fallback/health" "$name")"
    # Port 80 deliberately, and without following the redirect a panel site
    # sends: the question is whether the tunnel is reachable in cleartext, and
    # a 301 to https is the right answer, not a reason to keep looking.
    plain="$(akconnect_probe_code "http://$name/fallback/health" "$name")"

    printf '%s %s %s\n' "$root" "$fallback" "$plain"
}

akconnect_probe_code() {
    local url=$1 name=$2 code

    code="$(curl -k -s -o /dev/null --max-time 8 \
        --resolve "$name:443:127.0.0.1" --resolve "$name:80:127.0.0.1" \
        -w '%{http_code}' "$url" 2>/dev/null)"

    printf '%s' "${code:-000}"
}

# akconnect_site_names lists every name this Apache serves, from the parsed
# blocks rather than from a grep — a name inside a commented-out block is not
# served and must not be probed as though it were.
akconnect_site_names() {
    local dir file

    while read -r dir; do
        [ -d "$dir" ] || continue

        for file in "$dir"/*.conf; do
            [ -f "$file" ] || continue

            while IFS=$'\t' read -r path start end kind name aliases; do
                [ -n "$name" ] && printf '%s\n' "$name"
                [ -n "$aliases" ] && printf '%s\n' "${aliases//,/$'\n'}"
            done < <(akconnect_vhost_blocks "$file")
        done
    done < <(akconnect_vhost_dirs) \
        | grep -E '^[A-Za-z0-9_.-]+$' \
        | sort -u
}

# akconnect_site_probe writes "name root fallback plain" for every site.
akconnect_site_probe() {
    local out=${1:-/dev/stdout} name

    while read -r name; do
        [ -n "$name" ] || continue

        printf '%s %s\n' "$name" "$(akconnect_probe_one "$name")"
    done < <(akconnect_site_names) > "$out"
}

# akconnect_sites_changed prints every difference between two probes, one per
# line, as "name what before after".
#
# Names that appear or disappear between the two are differences too, and the
# first version could not see them: it joined the two files, and join discards
# unpaired lines. A vhost our own edit damaged would drop its ServerName out of
# the second probe entirely, and the check meant to catch that would have
# reported nothing changed.
akconnect_sites_changed() {
    local before=$1 after=$2

    awk '
        FNR == NR {
            was[$1] = $2 " " $3 " " $4
            next
        }
        {
            now[$1] = $2 " " $3 " " $4
            if (!($1 in was)) {
                print $1 " exists no yes"
                next
            }
            split(was[$1], b, " ")
            split($0, a, " ")
            if (b[1] != a[2]) print $1 " / " b[1] " " a[2]
            if (b[2] != a[3]) print $1 " /fallback " b[2] " " a[3]
            if (b[3] != a[4]) print $1 " /fallback-on-port-80 " b[3] " " a[4]
        }
        END {
            for (name in was) if (!(name in now)) print name " exists yes no"
        }
    ' "$before" "$after"
}

# akconnect_fallback_exclusive proves the tunnel answers on one name only.
#
#   $1  the panel domain
#   $2  a probe file
#
# Prints every name that should not be answering and is, and returns non-zero
# if any does — or if the panel's own name does not. This is the check the
# scope requirement actually needs: not "did another site break" but "did
# another site quietly gain a websocket proxy".
akconnect_fallback_exclusive() {
    local domain=$1 probe=$2 bad=0

    while read -r name root fallback plain; do
        [ -n "$name" ] || continue

        if [ "$name" = "$domain" ]; then
            case "$fallback" in
                200|101) ;;
                *) printf '%s should answer /fallback and gave %s\n' "$name" "$fallback"; bad=1 ;;
            esac

            # And not in cleartext. A redirect to https is the right answer on
            # port 80; carrying the tunnel there is the thing the whole design
            # says must not happen.
            case "$plain" in
                200|101) printf '%s serves /fallback over plain HTTP (%s)\n' "$name" "$plain"; bad=1 ;;
            esac

            continue
        fi

        case "$fallback" in
            200|101)
                printf '%s answers /fallback and is not the panel\n' "$name"
                bad=1
                ;;
        esac
        case "$plain" in
            200|101)
                printf '%s answers /fallback over plain HTTP and is not the panel\n' "$name"
                bad=1
                ;;
        esac
    done < "$probe"

    return "$bad"
}
