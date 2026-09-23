#!/bin/sh
#
# Argument handling shared by the deploy scripts.
#
# One function, in a file of its own, because every one of these scripts has to
# source it BEFORE its own option loop — and add-relay.sh does not source the
# vhost library at all.

# akconnect_need_value refuses an option whose value is missing.
#
#   $1  the option name, for the message
#   $2  the number of arguments left, "$#"
#
# `--panel)  PANEL="${2:-}"; shift 2 ;;` looks safe under set -u and is not.
# With --panel as the last argument $# is 1, and `shift 2` is out of range:
# bash returns non-zero and leaves the positional parameters COMPLETELY
# UNCHANGED rather than shifting by one. None of these scripts sets -e, so the
# status is dropped, $1 is still --panel, and the while loop runs that arm
# again for ever — builtins only, so it pins a core at 100% on a machine
# serving thirty customer sites, before the root check, with no output to say
# what is happening. `--panel "$URL"` with URL unset and unquoted does it too.
akconnect_need_value() {
    [ "${2:-0}" -ge 2 ] && return 0

    printf '\n  \033[31m✗ %s needs a value, e.g. %s <value>\033[0m\n\n' "$1" "$1" >&2
    exit 2
}

