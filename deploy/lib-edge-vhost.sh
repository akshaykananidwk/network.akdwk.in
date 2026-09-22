#!/usr/bin/env bash
#
# Put the fallback in front of ONE site, and prove the others still answer.
#
# This file exists because of a production server with thirty-odd live
# websites on one Apache. The first version of this dropped its configuration
# into the server-level directory and added an include to httpd.conf — which
# works, and which also publishes /fallback on every site on the machine,
# because a ProxyPass at server level is inherited by every virtual host that
# does not override it. Nobody asked for that and nobody would have noticed
# until they did.
#
# So the directives go inside one virtual host, the one serving the panel, and
# nothing else on the machine is edited except the module loader — which loads
# code and proxies nothing by itself.
#
# The other half is the same caution pointed the other way: after Apache
# reloads, every site on the box is asked for a status code and compared
# against what it said before. A single difference puts everything back.

# akconnect_vhost_dirs lists where this machine keeps per-site configuration,
# most specific first.
#
# Printed as candidates rather than resolved, because a server can have more
# than one of these and the answer is "wherever the file naming this site
# actually is".
akconnect_vhost_dirs() {
    printf '%s\n' \
        /www/server/panel/vhost/apache \
        /etc/apache2/sites-available \
        /etc/apache2/sites-enabled \
        /etc/httpd/conf.d \
        /etc/httpd/vhost.d \
        /etc/httpd/sites-available
}

# akconnect_vhost_file finds the file that serves one domain.
#
#   $1  the domain, e.g. network.akdwk.in
#
# Matched on ServerName or ServerAlias as a whole word, so example.com does
# not match notexample.com, and prints nothing when there is no such site —
# which is a refusal, not a reason to create one. Creating a virtual host on a
# server somebody else's sites are on is not this script's business.
akconnect_vhost_file() {
    local domain=$1 dir file

    [ -n "$domain" ] || return 1

    while read -r dir; do
        [ -d "$dir" ] || continue

        for file in "$dir"/*.conf; do
            [ -f "$file" ] || continue

            if grep -Eq "^[[:space:]]*Server(Name|Alias)[[:space:]]+([^[:space:]]+[[:space:]]+)*${domain//./\\.}([[:space:]]|$)" "$file"; then
                printf '%s\n' "$file"

                return 0
            fi
        done
    done < <(akconnect_vhost_dirs)

    return 1
}

# akconnect_vhost_tls_line prints the line number of the opening tag of the
# virtual host that serves TLS in a file, or nothing.
#
# The TLS one specifically. The fallback address is wss://, so a site whose
# only virtual host is port 80 cannot carry it, and saying so is better than
# installing directives into a block that will never see the traffic.
#
# Found by walking the blocks rather than by matching ":443" on the opening
# tag, because a virtual host can be declared on a port that is not 443 and
# still be the TLS one — and on aaPanel frequently is not written the obvious
# way.
akconnect_vhost_tls_line() {
    local file=$1

    awk '
        /^[[:space:]]*<VirtualHost/ { start = NR; body = ""; depth = 1; next }
        start && /^[[:space:]]*<\/VirtualHost>/ {
            if (body ~ /SSLEngine[[:space:]]+on/ || body ~ /SSLCertificateFile/) {
                print start
                exit
            }
            start = 0
            next
        }
        start { body = body "\n" $0 }
    ' "$file"
}

# The markers that make our edit findable and removable.
#
# A marker rather than matching the directive, so the directive can change
# between releases without leaving the old one behind, and so a human reading
# somebody else's virtual host knows at a glance what put it there.
AKCONNECT_VHOST_BEGIN='# BEGIN AK Connect HTTPS fallback — managed by deploy/upgrade-edge.sh'
AKCONNECT_VHOST_END='# END AK Connect HTTPS fallback'

# akconnect_vhost_has reports whether our block is already in a file.
akconnect_vhost_has() {
    grep -qF "$AKCONNECT_VHOST_BEGIN" "$1" 2>/dev/null
}

# akconnect_vhost_insert puts an include inside the TLS virtual host.
#
#   $1  the vhost file
#   $2  the file to include
#
# Idempotent: an existing block is replaced rather than added to. The include
# is one line, and the directives live in our own file — so an aaPanel that
# rewrites the site's configuration costs us one line we put back on the next
# run, and never touches what that line points at.
akconnect_vhost_insert() {
    local file=$1 include=$2 line

    akconnect_vhost_remove "$file"

    line="$(akconnect_vhost_tls_line "$file")"
    [ -n "$line" ] || return 1

    # "target" and not "include": gawk reserves the word include as a builtin
    # and refuses a variable of that name outright, which is a failure that
    # reads like a bug in the configuration rather than in the script.
    awk -v at="$line" -v begin="$AKCONNECT_VHOST_BEGIN" -v end="$AKCONNECT_VHOST_END" \
        -v target="$include" '
        { print }
        NR == at {
            print "    " begin
            print "    IncludeOptional " target
            print "    " end
        }
    ' "$file" > "$file.akconnect-new" || return 1

    cat "$file.akconnect-new" > "$file" && rm -f "$file.akconnect-new"
}

# akconnect_vhost_remove takes our block back out, leaving everything else.
akconnect_vhost_remove() {
    local file=$1

    akconnect_vhost_has "$file" || return 0

    awk -v begin="$AKCONNECT_VHOST_BEGIN" -v end="$AKCONNECT_VHOST_END" '
        index($0, begin) { skipping = 1 }
        !skipping { print }
        index($0, end) { skipping = 0 }
    ' "$file" > "$file.akconnect-new" || return 1

    cat "$file.akconnect-new" > "$file" && rm -f "$file.akconnect-new"
}

# akconnect_site_names lists every name this Apache serves.
#
# Both ServerName and ServerAlias, from every per-site file, deduplicated.
# This is the population that has to be unchanged afterwards, and it is read
# from the machine rather than from a list somebody keeps up to date.
akconnect_site_names() {
    local dir file

    while read -r dir; do
        [ -d "$dir" ] || continue

        for file in "$dir"/*.conf; do
            [ -f "$file" ] || continue

            sed -nE 's/^[[:space:]]*Server(Name|Alias)[[:space:]]+(.*)$/\2/p' "$file"
        done
    done < <(akconnect_vhost_dirs) \
        | tr -s '[:space:]' '\n' \
        | sed 's/[[:space:]]*$//' \
        | grep -E '^[A-Za-z0-9_*.-]+$' \
        | grep -v '^\*' \
        | sort -u
}

# akconnect_site_probe prints "name code" for every site on this machine.
#
#   $1  an optional file to write to; stdout otherwise
#
# Resolved to this machine explicitly, so the answer is what THIS Apache says
# and not what a load balancer, a CDN or somebody else's DNS says. The
# certificate is not verified: a site whose certificate is wrong is wrong
# identically before and afterwards, and the question here is only whether a
# reload changed anything.
#
# A name that cannot be reached at all gets a code of 000, which compares
# equal to itself — an unreachable site that is still unreachable is not a
# regression this change caused.
akconnect_site_probe() {
    local out=${1:-/dev/stdout} name code

    while read -r name; do
        [ -n "$name" ] || continue

        code="$(curl -k -s -o /dev/null --max-time 8 \
            --resolve "$name:443:127.0.0.1" --resolve "$name:80:127.0.0.1" \
            -w '%{http_code}' "https://$name/" 2>/dev/null)"

        printf '%s %s\n' "$name" "${code:-000}"
    done < <(akconnect_site_names) > "$out"
}

# akconnect_sites_changed prints the sites whose answer moved, one per line,
# as "name before after". Nothing printed means nothing changed.
akconnect_sites_changed() {
    local before=$1 after=$2

    join -j 1 -o '0,1.2,2.2' \
        <(sort -k1,1 "$before") <(sort -k1,1 "$after") 2>/dev/null \
        | awk '$2 != $3'
}
