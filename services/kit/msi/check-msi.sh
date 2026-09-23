#!/usr/bin/env bash
# Read a built MSI back and check it says what it is meant to say.
#
# This is not a substitute for installing it on Windows, and does not pretend
# to be: it cannot tell you whether msiexec will run the agent. What it does
# catch is the class of fault that is invisible in the source and obvious in
# the built table — a deferred action numbered 1, a join code that never
# reaches the elevated half, a command that lost its property.
#
# Every check here exists because the build got it wrong once.
set -euo pipefail

MSI="${1:?usage: check-msi.sh <file.msi>}"

pass=0
fail=0
ok()   { echo "  ✓ $*"; pass=$((pass + 1)); }
bad()  { echo "  ✗ $*"; fail=$((fail + 1)); }

command -v msiinfo >/dev/null 2>&1 || { echo "  ✗ msiinfo is not installed"; exit 1; }
[ -s "$MSI" ] || { echo "  ✗ $MSI is missing or empty"; exit 1; }

actions="$(msiinfo export "$MSI" CustomAction)"
sequence="$(msiinfo export "$MSI" InstallExecuteSequence)"
props="$(msiinfo export "$MSI" Property)"

# msiinfo writes the tables with CRLF line endings, so every field taken from
# the last column arrives with a carriage return attached. A sequence number
# read that way is not a number, and `[ "$n" -gt 1500 ]` says "integer
# expression expected" rather than failing the check it was asked about.
field() { awk -F'\t' -v a="$2" -v c="$3" '$1 == a { gsub(/\r/, "", $c); print $c }' <<< "$1"; }

seq_of() { field "$sequence" "$1" 3; }
type_of() { field "$actions" "$1" 2; }
target_of() { field "$actions" "$1" 4; }

# A deferred action runs in the elevated service, after InstallInitialize at
# 1500. Numbered below that it does not run at all, and wixl produced exactly
# that when the actions around it changed.
for action in RunSetupNow RunUninstall; do
    n="$(seq_of "$action")"
    if [ -n "$n" ] && [ "$n" -gt 1500 ] && [ "$n" -lt 6600 ]; then
        ok "$action is sequenced at $n, inside the installation transaction"
    else
        bad "$action is sequenced at '${n:-nothing}'; a deferred action there never runs"
    fi
done

# Order: the uninstaller has to run before its own file is removed, and the
# installer after its file is written.
if [ "$(seq_of RunUninstall)" -lt "$(seq_of RemoveFiles)" ]; then
    ok "the uninstaller runs before its own file is removed"
else
    bad "the uninstaller is sequenced after RemoveFiles and would be gone by then"
fi

if [ "$(seq_of RunSetupNow)" -gt "$(seq_of InstallFiles)" ]; then
    ok "the installer runs after its file is on disk"
else
    bad "the installer is sequenced before InstallFiles; there is nothing to run yet"
fi

if [ "$(seq_of RunSetup)" -lt "$(seq_of RunSetupNow)" ]; then
    ok "the join code is set before the action that uses it"
else
    bad "the join code is set after the action that uses it"
fi

# A deferred action cannot read properties. Its command must be
# [CustomActionData] and the property-setting action must carry the join code.
if [ "$(target_of RunSetupNow)" = "[CustomActionData]" ]; then
    ok "the deferred action reads CustomActionData, not a property it cannot see"
else
    bad "the deferred action's command is '$(target_of RunSetupNow)'; deferred actions see no properties"
fi

case "$(target_of RunSetup)" in
    *'[JOINCODE]'*) ok "the join code given to msiexec reaches the command" ;;
    *) bad "the command does not mention JOINCODE: '$(target_of RunSetup)'" ;;
esac

case "$(target_of RunSetup)" in
    *-managed*) ok "the installer is told the MSI owns the Apps & features entry" ;;
    *) bad "without -managed the program is listed twice in Settings" ;;
esac

case "$(target_of RunUninstall)" in
    *-uninstall*-silent*) ok "removal runs the uninstaller without asking anybody anything" ;;
    *) bad "removal command is '$(target_of RunUninstall)'" ;;
esac

# 51 is a property-setting action; 3090/3154 are deferred, no-impersonate exes.
[ "$(type_of RunSetup)" = "51" ] \
    && ok "the join code is carried by a property-setting action" \
    || bad "RunSetup is type $(type_of RunSetup), not a property-setting action"

# A per-machine install must not impersonate the signed-in user: the service,
# the driver and Program Files all need the elevated half.
for action in RunSetupNow RunUninstall; do
    t="$(type_of "$action")"
    if [ $(( t & 1024 )) -ne 0 ] && [ $(( t & 2048 )) -ne 0 ]; then
        ok "$action is deferred and does not impersonate (type $t)"
    else
        bad "$action is type $t; it needs to be deferred and no-impersonate"
    fi
done

case "$props" in
    *SecureCustomProperties*JOINCODE*) ok "JOINCODE is secure, so it survives into the elevated half" ;;
    *) bad "JOINCODE is not in SecureCustomProperties; msiexec drops it at the boundary" ;;
esac

printf '%s\n' "$props" | grep -q "^ALLUSERS	1" \
    && ok "the package installs for the whole machine" \
    || bad "the package is not marked per-machine"

echo
if [ "$fail" -eq 0 ]; then
    echo "  $pass checks, all passed."
    exit 0
fi

echo "  $fail of $((pass + fail)) checks FAILED."
exit 1
