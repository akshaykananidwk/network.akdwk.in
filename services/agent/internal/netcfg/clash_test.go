package netcfg

import (
	"errors"
	"strings"
	"testing"
)

// Defect 10, from the first run on a real Windows laptop:
//
//	the overlay 10.50.0.0/16 is not installed: this machine is already on
//	that network
//
// The only 10.x route in that machine's table was 10.50.0.2/32 — the address
// the agent had just put on its own adapter. Every peer on Windows was
// unreachable, and the message blamed the customer's network.
func TestOurOwnAdapterIsNotSomebodyElsesRoute(t *testing.T) {
	statement, ok := routeQueryStatement("AKConnect", "10.50.0.0/16")
	if !ok {
		t.Fatal("a normal interface name was refused")
	}

	// Excluded by index, because an alias can be reported differently by
	// Get-NetRoute than by Get-NetAdapter.
	for _, want := range []string{
		"Get-NetAdapter -Name 'AKConnect'",
		"Get-NetIPInterface -InterfaceAlias 'AKConnect'",
		"$ours -notcontains $_.ifIndex",
	} {
		if !strings.Contains(statement, want) {
			t.Fatalf("the statement does not exclude our own adapter by index (%q missing):\n%s", want, statement)
		}
	}

	// And by alias, as well — belt and braces, since this check can stop the
	// product working on every Windows machine at once.
	if !strings.Contains(statement, "$_.InterfaceAlias -ne 'AKConnect'") {
		t.Fatalf("the statement does not exclude our own adapter by alias:\n%s", statement)
	}

	// It must not let PowerShell's own exit code become the answer.
	if !strings.Contains(statement, "$ErrorActionPreference = 'SilentlyContinue'") ||
		!strings.Contains(statement, "exit 0") {
		t.Fatalf("the statement lets PowerShell's exit code be mistaken for a verdict:\n%s", statement)
	}
}

// A check that cannot run must not refuse the tunnel. The earlier version
// failed closed and produced a device that could reach nothing, blaming a
// network that was innocent.
func TestAnUnreadableRoutingTableMeansFree(t *testing.T) {
	cases := []struct {
		name string
		out  string
		err  error
	}{
		{"powershell failed", "", errors.New("exit status 1")},
		{"powershell missing", "", errors.New("executable file not found in %PATH%")},
		{"no verdict in the output", "some unrelated noise", nil},
		{"empty output", "", nil},
	}

	for _, tc := range cases {
		occupied, why := interpretRouteQuery(tc.out, tc.err)
		if occupied {
			t.Fatalf("%s: the prefix was refused although nothing was observed", tc.name)
		}
		if why == "" {
			t.Fatalf("%s: the decision does not explain itself", tc.name)
		}
		if !strings.Contains(why, "installed anyway") {
			t.Fatalf("%s: the explanation does not say what was done: %s", tc.name, why)
		}
	}
}

// The case the check exists for still has to work: a route on another adapter
// is a real clash, and the refusal carries the evidence.
func TestARouteOnAnotherAdapterIsStillAClash(t *testing.T) {
	out := "RESULT=occupied\n  route 10.50.0.0/16 ifIndex 12 alias Ethernet\n"

	occupied, why := interpretRouteQuery(out, nil)
	if !occupied {
		t.Fatal("a route on another adapter was not treated as a clash")
	}
	for _, want := range []string{"ifIndex 12", "alias Ethernet", "10.50.0.0/16"} {
		if !strings.Contains(why, want) {
			t.Fatalf("the refusal drops the evidence (%q missing): %s", want, why)
		}
	}
}

func TestAFreeVerdictIsSilent(t *testing.T) {
	occupied, why := interpretRouteQuery("RESULT=free\n", nil)
	if occupied {
		t.Fatal("RESULT=free was read as occupied")
	}
	if why != "" {
		t.Fatalf("a free prefix produced an explanation: %q", why)
	}
}

// Our own interface name, so this should not happen — but a name that could
// close the quote and run something else is refused rather than reasoned about.
func TestAnUnquotableNameIsRefusedRatherThanInterpolated(t *testing.T) {
	for _, name := range []string{"", "AK'Connect", "AK`Connect", "AK$Connect", "AK\nConnect"} {
		if _, ok := routeQueryStatement(name, "10.50.0.0/16"); ok {
			t.Fatalf("routeQueryStatement built a statement from %q", name)
		}
	}

	if _, ok := routeQueryStatement("AKConnect", "10.50.0.0/16'; Remove-Item C:\\ -Recurse; '"); ok {
		t.Fatal("routeQueryStatement built a statement from a prefix that closes the quote")
	}
}
