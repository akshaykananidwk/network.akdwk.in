#!/usr/bin/env bash
#
# Put the fallback in front of ONE virtual host, and prove it went nowhere else.
#
# This file exists because of a production server with thirty-odd live websites
# on one Apache, and it has been rewritten once already after an adversarial
# review found three ways the first version could write into somebody else's
# site. All three came from the same mistake: matching text in a file instead
# of parsing the blocks in it.
#
#   - a file was chosen because the domain appeared anywhere in it, on a
#     ServerName or a ServerAlias. aaPanel lets any site bind any domain, so a
#     parked alias on another customer's site captured the proxy;
#   - the TLS block was the first one in the file whose body mentioned SSL,
#     whichever host that block served;
#   - and "mentioned SSL" included commented-out lines, which is exactly the
#     shape aaPanel writes: a #SSL-START … #SSL-END stanza inside the :80
#     block. The include went into port 80, where the tunnel would have run in
#     cleartext and the wss:// address would have answered nothing.
#
# So: blocks are parsed, comments are not configuration, and the target must be
# a TLS block whose own ServerName is exactly the panel's domain. An alias
# match on somebody else's site is refused rather than used.

# akconnect_vhost_dirs lists where this machine keeps per-site configuration.
akconnect_vhost_dirs() {
    printf '%s\n' \
        /www/server/panel/vhost/apache \
        /etc/apache2/sites-available \
        /etc/apache2/sites-enabled \
        /etc/httpd/conf.d \
        /etc/httpd/vhost.d \
        /etc/httpd/sites-available
}

# akconnect_vhost_blocks parses one file into its virtual hosts.
#
# Prints one tab-separated row per block:
#
#   file <TAB> opening-line <TAB> closing-line <TAB> tls|plain <TAB> ServerName <TAB> alias,alias
#
# A line whose first non-blank character is # is not configuration and is
# skipped entirely — including a commented </VirtualHost>, which would
# otherwise end a block that is still open.
akconnect_vhost_blocks() {
    local file=$1

    [ -f "$file" ] || return 1

    awk -v path="$file" '
        function flush(  kind) {
            if (!start) return
            kind = tls ? "tls" : "plain"
            printf "%s\t%d\t%d\t%s\t%s\t%s\n", path, start, NR, kind, name, aliases
            start = 0; tls = 0; name = ""; aliases = ""
        }

        # Apache strips a :port from a ServerName or ServerAlias, and the
        # stock httpd.conf documents "ServerName www.example.com:80". Keeping
        # the port made the name unprobeable, and a name that cannot be probed
        # is a site silently exempt from the before/after comparison.
        function bare(word) {
            sub(/:[0-9]+$/, "", word)

            return word
        }

        # A directive continued with a trailing backslash is one directive.
        # Read as separate lines, "ServerAlias a \\" and "   b" are a name
        # list that stops at a and a stray line that matches nothing.
        {
            line = $0
            while (line ~ /\\[[:space:]]*$/) {
                sub(/\\[[:space:]]*$/, "", line)
                if ((getline nxt) <= 0) break
                line = line nxt
            }
            $0 = line
        }

        # Comments are not configuration.
        /^[[:space:]]*#/ { next }

        /^[[:space:]]*<VirtualHost/ {
            flush()
            start = NR; tls = 0; name = ""; aliases = ""
            next
        }

        !start { next }

        /^[[:space:]]*<\/VirtualHost>/ { flush(); next }

        /^[[:space:]]*SSLEngine[[:space:]]+[Oo][Nn][[:space:]]*$/ { tls = 1 }
        /^[[:space:]]*SSLCertificateFile[[:space:]]/              { tls = 1 }

        # Last wins, as Apache does with a single-valued directive repeated in
        # one context.
        /^[[:space:]]*ServerName[[:space:]]/ {
            name = bare($2)
            next
        }

        /^[[:space:]]*ServerAlias[[:space:]]/ {
            for (i = 2; i <= NF; i++) {
                aliases = aliases (aliases == "" ? "" : ",") bare($i)
            }
            next
        }

        END { flush() }
    ' "$file"
}

# akconnect_vhost_target finds the block the fallback belongs in.
#
#   $1  the panel domain
#
# Prints "file<TAB>opening-line" for the TLS virtual host whose own ServerName
# is exactly that domain, and nothing at all otherwise.
#
# Exactly that, and nothing more forgiving. A domain that appears only as a
# ServerAlias on another site IS served by that site — and writing a proxy into
# a virtual host somebody else's customers reach is the failure this whole file
# is shaped around. Refusing and saying so is the only safe answer.
akconnect_vhost_target() {
    local domain=$1 dir file row

    [ -n "$domain" ] || return 1

    while read -r dir; do
        [ -d "$dir" ] || continue

        for file in "$dir"/*.conf; do
            [ -f "$file" ] || continue

            while IFS=$'\t' read -r path start end kind name aliases; do
                [ "$kind" = tls ] || continue
                [ "$name" = "$domain" ] || continue

                printf '%s\t%s\n' "$path" "$start"

                return 0
            done < <(akconnect_vhost_blocks "$file")
        done
    done < <(akconnect_vhost_dirs)

    return 1
}

# akconnect_vhost_untls_target reports a block named for this domain that is
# not a TLS block.
#
# Also for the refusal message, and the commonest of the three cases: a site
# exists, it is the right site, and nobody has issued it a certificate. That
# is an afternoon's work in aaPanel and a completely different instruction
# from "another customer's site claims your domain".
akconnect_vhost_untls_target() {
    local domain=$1 dir file

    [ -n "$domain" ] || return 1

    while read -r dir; do
        [ -d "$dir" ] || continue

        for file in "$dir"/*.conf; do
            [ -f "$file" ] || continue

            while IFS=$'\t' read -r path start end kind name aliases; do
                [ "$kind" = tls ] && continue
                [ "$name" = "$domain" ] || continue

                printf '%s\t%s\n' "$path" "$start"

                return 0
            done < <(akconnect_vhost_blocks "$file")
        done
    done < <(akconnect_vhost_dirs)

    return 1
}

# akconnect_vhost_alias_only reports the file and host that serve this domain
# as an alias, when no virtual host names it directly.
#
# For the refusal message. "There is no virtual host for this domain" and
# "another customer's site claims it as an alias" need completely different
# responses, and only one of them is something to go and fix.
akconnect_vhost_alias_only() {
    local domain=$1 dir file

    while read -r dir; do
        [ -d "$dir" ] || continue

        for file in "$dir"/*.conf; do
            [ -f "$file" ] || continue

            while IFS=$'\t' read -r path start end kind name aliases; do
                # A block that is itself named for this domain is not somebody
                # else claiming it as an alias, even when it lists the domain
                # among its own aliases as well — which aaPanel does. Saying
                # "that is somebody else's site" about the panel's own file
                # sends an operator looking for a hijack that is not there.
                [ "$name" = "$domain" ] && continue

                case ",$aliases," in
                    *",$domain,"*)
                        printf '%s\t%s\n' "$path" "$name"

                        return 0
                        ;;
                esac
            done < <(akconnect_vhost_blocks "$file")
        done
    done < <(akconnect_vhost_dirs)

    return 1
}

# The markers that make our edit findable and removable.
AKCONNECT_VHOST_BEGIN='# BEGIN AK Connect HTTPS fallback — managed by deploy/upgrade-edge.sh'
AKCONNECT_VHOST_END='# END AK Connect HTTPS fallback'

# akconnect_vhost_has reports whether our block is already in a file.
akconnect_vhost_has() {
    grep -qF "$AKCONNECT_VHOST_BEGIN" "$1" 2>/dev/null
}

# akconnect_vhost_files_with lists every file on this machine carrying our
# block, so a stale one in a site we no longer use can be found and removed.
akconnect_vhost_files_with() {
    local dir file

    while read -r dir; do
        [ -d "$dir" ] || continue

        for file in "$dir"/*.conf; do
            [ -f "$file" ] || continue
            akconnect_vhost_has "$file" && printf '%s\n' "$file"
        done
    done < <(akconnect_vhost_dirs)

    return 0
}

# akconnect_vhost_write replaces a file's contents atomically.
#
# Through a temporary file in the same directory and a rename, never by
# truncating the original. A production virtual host that is cut in half by an
# interrupt or a full disk keeps serving from memory and fails at the next
# reload — which may be aaPanel's, for an unrelated site, hours later, and may
# be a restart rather than a reload. Then thirty websites are down and nothing
# connects it to us.
akconnect_vhost_write() {
    local file=$1 source=$2 tmp

    tmp="$(mktemp "$file.akconnect.XXXXXX")" || return 1

    if ! cat "$source" > "$tmp"; then
        rm -f "$tmp"

        return 1
    fi

    # The original's mode and ownership, not the temporary file's.
    chmod --reference="$file" "$tmp" 2>/dev/null
    chown --reference="$file" "$tmp" 2>/dev/null

    mv -f "$tmp" "$file" || { rm -f "$tmp"; return 1; }
}

# akconnect_vhost_tls_line_for prints the opening line of the TLS block in one
# file whose own ServerName is the given domain.
akconnect_vhost_tls_line_for() {
    local file=$1 domain=$2

    while IFS=$'\t' read -r path start end kind name aliases; do
        [ "$kind" = tls ] || continue
        [ "$name" = "$domain" ] || continue

        printf '%s\n' "$start"

        return 0
    done < <(akconnect_vhost_blocks "$file")

    return 1
}

# akconnect_vhost_insert puts an include inside one virtual host.
#
#   $1  the vhost file
#   $2  the domain whose TLS block it goes in
#   $3  the file to include
#
# Idempotent: any existing block is removed first, and the target line is
# worked out AFTER that — removing three lines moves every line below them, so
# a line number taken beforehand points somewhere else by the time it is used.
#
# The include is one line and the directives live in our own file, so an
# aaPanel that rewrites the site's configuration costs one line, and never
# touches what that line points at.
akconnect_vhost_insert() {
    local file=$1 domain=$2 include=$3 work line

    akconnect_vhost_remove "$file" || return 1

    line="$(akconnect_vhost_tls_line_for "$file" "$domain")" || return 1
    [ -n "$line" ] || return 1

    work="$(mktemp)" || return 1

    # "target" and not "include": gawk reserves the word include as a builtin
    # and refuses a variable of that name outright.
    awk -v at="$line" -v begin="$AKCONNECT_VHOST_BEGIN" -v end="$AKCONNECT_VHOST_END" \
        -v target="$include" '
        { print }
        NR == at {
            print "    " begin
            print "    IncludeOptional " target
            print "    " end
        }
    ' "$file" > "$work" || { rm -f "$work"; return 1; }

    akconnect_vhost_write "$file" "$work"
    local status=$?
    rm -f "$work"

    return $status
}

# akconnect_vhost_remove takes our block out, leaving everything else.
akconnect_vhost_remove() {
    local file=$1 work status

    akconnect_vhost_has "$file" || return 0

    work="$(mktemp)" || return 1

    awk -v begin="$AKCONNECT_VHOST_BEGIN" -v end="$AKCONNECT_VHOST_END" '
        index($0, begin) { skipping = 1 }
        !skipping { print }
        index($0, end) { skipping = 0 }
    ' "$file" > "$work" || { rm -f "$work"; return 1; }

    akconnect_vhost_write "$file" "$work"
    status=$?
    rm -f "$work"

    return $status
}
