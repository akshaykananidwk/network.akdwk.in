package netcfg

import (
	"fmt"
	"strings"
)

// Deciding whether a prefix is already routed somewhere else.
//
// The PowerShell statement and the reading of its output live here, without a
// build tag, because the bug they exist to prevent is one of judgement rather
// than one of Windows: a check that answered "occupied" when it could not run,
// and that counted our own adapter as somebody else's. Both are decisions, and
// a decision that can only be exercised on a Windows laptop is a decision that
// is never exercised.

// routeQueryStatement builds the PowerShell that looks for a route to prefix
// on any adapter other than ours.
//
// Get-NetRoute rather than `netsh interface ipv4 show route`, because netsh
// prints localised column headings and the agent has to work on a Hindi or
// Gujarati Windows install as well as an English one. Get-NetRoute returns
// objects, so nothing is parsed out of translated text.
//
// Our own adapter is excluded by interface index *and* by alias. The first
// version filtered on alias alone, and on a real Windows laptop it refused the
// overlay with the only 10.x route in the table being 10.50.0.2/32 on the
// AKConnect adapter itself — ours. Whatever the exact mismatch was, one string
// comparison is too thin a thread for a check that can stop the product
// working.
//
// The second return is false when the interface name is not one a statement
// can be built from. That is our own name, so it should not happen; refusing
// is cheaper than reasoning about whether it can.
func routeQueryStatement(ifaceName string, prefix string) (string, bool) {
	if ifaceName == "" || strings.ContainsAny(ifaceName, "'`$\"\r\n") {
		return "", false
	}
	if prefix == "" || strings.ContainsAny(prefix, "'`$\"\r\n") {
		return "", false
	}

	// $ErrorActionPreference and the explicit exit 0 together stop PowerShell's
	// own exit code from being mistaken for an answer: a non-matching
	// Get-NetRoute leaves $? false, and `powershell -Command` exits 1 for that
	// alone.
	return fmt.Sprintf(`
$ErrorActionPreference = 'SilentlyContinue'
$ours = @()
$a = Get-NetAdapter -Name '%[1]s'
if ($a) { $ours += $a.ifIndex }
$i = Get-NetIPInterface -InterfaceAlias '%[1]s'
if ($i) { $ours += $i.ifIndex }
$r = Get-NetRoute -DestinationPrefix '%[2]s' | Where-Object {
    ($ours -notcontains $_.ifIndex) -and ($_.InterfaceAlias -ne '%[1]s')
}
if ($r) {
    Write-Output 'RESULT=occupied'
    foreach ($x in $r) { Write-Output ("  route " + $x.DestinationPrefix + " ifIndex " + $x.ifIndex + " alias " + $x.InterfaceAlias) }
} else {
    Write-Output 'RESULT=free'
}
exit 0
`, ifaceName, prefix), true
}

// interpretRouteQuery turns what PowerShell said into a verdict and an
// explanation.
//
// A check that cannot run must not answer "occupied". It used to: any failed
// invocation returned true, on the reasoning that a routing table we cannot
// read is one we do not overwrite. The consequence is worse than the risk it
// avoids. Refusing the overlay leaves a device that cannot reach anything,
// with a message blaming the customer's network for a fault that is ours —
// whereas a machine that genuinely is on the range keeps working, visibly, and
// can be fixed by changing the network's range. So a prefix is occupied only
// when a route for it was positively observed somewhere else.
//
// When it does refuse, the raw output is carried back, because the next person
// looking at it will be reading a bug report from a machine they cannot touch.
func interpretRouteQuery(out string, err error) (bool, string) {
	if err != nil {
		return false, fmt.Sprintf("the routing table could not be read (%v); the prefix was installed anyway", err)
	}

	if strings.Contains(out, "RESULT=occupied") {
		return true, strings.TrimSpace(out)
	}

	if !strings.Contains(out, "RESULT=free") {
		// Neither marker: the statement did not run to the end. Same reasoning
		// as an error.
		return false, "the routing table check produced no verdict; the prefix was installed anyway"
	}

	return false, ""
}
