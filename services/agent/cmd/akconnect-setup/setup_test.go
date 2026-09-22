package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// The installer cannot be run here, and most of what it does is call Windows.
// What can be tested is the part that decides whether to do anything at all —
// and that part exists because the alternative is a customer's machine with a
// placeholder registered as a service.

func TestABuildWithoutARealPayloadRefusesToInstall(t *testing.T) {
	// The committed payload files are placeholders, which is the state this
	// test runs in: the pack script replaces them at build time.
	err := checkPayload()
	if err == nil {
		t.Fatal("checkPayload accepted the placeholder payload")
	}

	// The message has to name the file and say what to do, because whoever
	// sees it is holding a build that should not have left the machine it was
	// made on.
	for _, want := range []string{"akconnect-agent.exe", "build-windows-pack.sh"} {
		if !strings.Contains(err.Error(), want) {
			t.Fatalf("the refusal does not mention %q: %s", want, err)
		}
	}
}

// -silent is for deployment tools, and a deployment tool cannot answer a
// dialog. Asking for a join code it cannot be given has to be an error with a
// reason rather than a hang.
func TestSilentWithoutAJoinCodeFailsRatherThanWaiting(t *testing.T) {
	ui := &console{silent: true}

	_, err := ui.askJoinCode()
	if err == nil {
		t.Fatal("a silent run asked for a join code")
	}
	if !strings.Contains(err.Error(), "-silent") {
		t.Fatalf("the error does not explain why: %s", err)
	}
}

// A failure message carries how far the install got. "Could not start the
// service" means something different depending on whether enrolment had
// already succeeded, and a customer cannot be expected to know that.
func TestFailuresCarryTheStepsThatCameBefore(t *testing.T) {
	ui := &console{silent: true}
	ui.step("Installing to C:\\Program Files\\AKConnect")
	ui.step("Joining the network")

	if len(ui.steps) != 2 {
		t.Fatalf("steps = %v", ui.steps)
	}
}

// Output from the agent is what makes a failure actionable — "that join code
// has expired" rather than "exit status 1" — but a dialog cannot show a
// hundred lines.
func TestOutputIsTrimmedForADialog(t *testing.T) {
	long := strings.Repeat("a line of output\n", 40)

	got := trimForDialog(long, errNotWindowsForTest{})
	if strings.Count(got, "\n") > 8 {
		t.Fatalf("trimForDialog kept %d lines", strings.Count(got, "\n"))
	}
	if !strings.HasSuffix(got, "…") {
		t.Fatal("a trimmed message does not say it was trimmed")
	}

	// With nothing printed, the error itself is all there is to show.
	if got := trimForDialog("", errNotWindowsForTest{}); got != "boom" {
		t.Fatalf("trimForDialog on empty output = %q", got)
	}
}

type errNotWindowsForTest struct{}

func (errNotWindowsForTest) Error() string { return "boom" }

// ---------------------------------------------------------------- 1.9.1

// Defect 7. The first real customer run of akconnect-setup.exe copied its
// files and then stopped with "--panel and --join-code are both required".
// The join code does not carry the panel address, and a customer holding a
// join code has no way to know one is missing — so the pack script stamps the
// address into the binary at build time, and the installer asks for it when
// the stamp is empty rather than failing after it has written files.
func TestThePanelAddressIsCheckedBeforeAnythingIsWritten(t *testing.T) {
	if err := checkPanelURL(""); err == nil {
		t.Fatal("an empty panel address was accepted")
	} else {
		// Whoever sees this is holding a build that should have been stamped.
		for _, want := range []string{"-panel", "-code"} {
			if !strings.Contains(err.Error(), want) {
				t.Fatalf("the refusal does not say how to supply it (%q missing): %s", want, err)
			}
		}
	}

	if err := checkPanelURL("http://panel.example.com"); err == nil {
		t.Fatal("a plain-HTTP panel was accepted; the agent will not send a token over it")
	} else if !strings.Contains(err.Error(), "https://") {
		t.Fatalf("the refusal does not name the fix: %s", err)
	}

	for _, bad := range []string{"panel.example.com", "not a url at all", "ftp://panel.example.com"} {
		if err := checkPanelURL(bad); err == nil {
			t.Fatalf("checkPanelURL accepted %q", bad)
		}
	}

	if err := checkPanelURL("https://net.example.com"); err != nil {
		t.Fatalf("a good address was refused: %v", err)
	}
}

// The stamp itself. A build with an empty defaultPanel is the one that shipped
// and failed; the pack script sets it with -X and verifies the string is in
// the binary, and this is the compile-time half of that contract.
func TestTheDefaultPanelIsAStampableVariable(t *testing.T) {
	// Assigning to it is what -ldflags -X does; a constant would not compile.
	saved := defaultPanel
	defer func() { defaultPanel = saved }()

	defaultPanel = "https://net.example.com"
	if err := checkPanelURL(defaultPanel); err != nil {
		t.Fatalf("a stamped address is not usable: %v", err)
	}
}

// The uninstaller removes a network adapter by name, and the name it uses is
// spelled out in the installer rather than imported — the installer does not
// link the agent's packages. If the agent ever renames its interface, the
// uninstall stops removing it and a customer is left with an adapter named
// after software they no longer have.
//
// It used to match '*Wintun*' as well. That is not our name: it is the name of
// the driver, which WireGuard's own client and Tailscale also create adapters
// on. On a machine running either, uninstalling this would have taken their
// adapter with it.
func TestTheAdapterNameMatchesTheAgents(t *testing.T) {
	source, err := os.ReadFile(filepath.Join("..", "..", "internal", "tunnel", "name_windows.go"))
	if err != nil {
		t.Fatal(err)
	}

	want := `defaultInterfaceName = "` + adapterName + `"`
	if !strings.Contains(string(source), want) {
		t.Fatalf("the agent's Windows interface name is not %q; the uninstall would miss the adapter", adapterName)
	}
}

// And nothing in the uninstall matches by driver.
//
// It used to match '*Wintun*'. That is not our name: it is the name of the
// driver, which WireGuard's own client and Tailscale also create adapters on.
// On a machine running either, uninstalling this would have taken their
// adapter with it, from software the customer still uses.
func TestTheUninstallRemovesOnlyOurAdapter(t *testing.T) {
	query := adapterQuery(adapterName)

	if !strings.Contains(query, `-eq '`+adapterName+`'`) {
		t.Error("the uninstall does not match our adapter by its exact name")
	}

	for _, theirs := range []string{"Wintun", "WireGuard Tunnel", "Tailscale", "OpenVPN TAP"} {
		if strings.Contains(query, theirs) {
			t.Errorf("the uninstall statement mentions %q, which is not ours to remove", theirs)
		}
	}

	// A second copy of OUR adapter, left by a crash, is ours.
	if !strings.Contains(query, adapterName+` #*`) {
		t.Error("a duplicate of our own adapter would be left behind")
	}
}

// What the last dialog says, for each thing the installer can find.
//
// Every one of these is a real state a customer's computer is in a minute
// after the install finishes, and they used to produce one sentence: "is
// installed and running". That sentence is true in all of them and answers
// the question in none — a machine waiting to be approved, a machine that is
// the only one on its network, and a machine that genuinely cannot reach us
// all read the same and all get sent away.
func TestTheFinalDialogSaysWhichSituationThisIs(t *testing.T) {
	cases := []struct {
		name    string
		found   outcome
		ok      bool
		wants   []string
		refuses []string
	}{
		{
			name:  "the service did not start",
			found: outcome{Running: false},
			ok:    false,
			wants: []string{"not running", "Desktop"},
		},
		{
			name:  "running but not yet configured",
			found: outcome{Running: true},
			ok:    true,
			wants: []string{"has not finished starting"},
			// It will sort itself out, so it must not send anybody anywhere.
			refuses: []string{"supplier"},
		},
		{
			name:  "running, configured, nothing answering",
			found: outcome{Running: true, Published: true},
			ok:    true,
			wants: []string{"waiting to be approved", "nothing to do here"},
		},
		{
			name:  "the only computer on the network",
			found: outcome{Running: true, Published: true, CoordinatorUp: true, Address: "10.80.0.4"},
			ok:    true,
			wants: []string{"10.80.0.4", "nothing else on it yet"},
			// Nothing is wrong, so nothing may suggest anything is.
			refuses: []string{"Desktop", "supplier"},
		},
		{
			name: "a peer it has not reached yet",
			found: outcome{
				Running: true, Published: true, CoordinatorUp: true,
				Address: "10.80.0.4", Peers: 1,
			},
			ok:    true,
			wants: []string{"1 other computer", "not reached it yet"},
		},
		{
			name: "connected to both",
			found: outcome{
				Running: true, Published: true, CoordinatorUp: true,
				Address: "10.80.0.4", Peers: 2, Reachable: 2,
			},
			ok:      true,
			wants:   []string{"10.80.0.4", "2 of 2 other computers"},
			refuses: []string{"Desktop", "browser"},
		},
		{
			name: "connected, but only over the HTTPS path",
			found: outcome{
				Running: true, Published: true, CoordinatorUp: true, OverHTTPS: true,
				Address: "10.80.0.4", Peers: 1, Reachable: 1,
			},
			ok: true,
			// Said plainly, and closed off: this is not something the customer
			// is being asked to fix.
			wants:   []string{"web browser uses", "nothing needs changing"},
			refuses: []string{"firewall"},
		},
	}

	for _, c := range cases {
		text, ok := c.found.message()

		if ok != c.ok {
			t.Errorf("%s: success was %v, wanted %v", c.name, ok, c.ok)
		}
		for _, want := range c.wants {
			if !strings.Contains(text, want) {
				t.Errorf("%s: the dialog does not mention %q:\n%s", c.name, want, text)
			}
		}
		for _, refuse := range c.refuses {
			if strings.Contains(text, refuse) {
				t.Errorf("%s: the dialog mentions %q and should not:\n%s", c.name, refuse, text)
			}
		}
	}
}

// settled decides when the installer stops waiting. Waiting longer than there
// is any point costs a customer a minute at the end of every install; not
// waiting long enough reports "no peers" on a machine that was seconds away.
func TestTheInstallerWaitsForTheRightThings(t *testing.T) {
	keepWaiting := []outcome{
		{Running: true},
		{Running: true, Published: true},
		{Running: true, Published: true, CoordinatorUp: true, Peers: 1},
	}
	for _, found := range keepWaiting {
		if found.settled() {
			t.Errorf("the installer stopped waiting at %+v", found)
		}
	}

	done := []outcome{
		{Running: true, Published: true, CoordinatorUp: true},
		{Running: true, Published: true, CoordinatorUp: true, Peers: 2, Reachable: 1},
	}
	for _, found := range done {
		if !found.settled() {
			t.Errorf("the installer kept waiting at %+v", found)
		}
	}
}

// Which previous installation counts as one to clear away.
//
// The rule has to be narrow. A service pointing at a program that is not there
// is wreckage and makes the next install fail in the middle; a service
// pointing at a program that IS there is somebody's working installation, and
// removing it is not this installer's business.
func TestOnlyWreckageIsClearedAway(t *testing.T) {
	missing := func(string) bool { return false }
	present := func(string) bool { return true }

	if !staleService(`"C:\Program Files\AKConnect\akconnect-agent.exe" service run`, missing) {
		t.Error("a service whose program is gone was not recognised as stale")
	}
	if staleService(`"C:\Program Files\AKConnect\akconnect-agent.exe" service run`, present) {
		t.Error("a working installation was treated as wreckage")
	}
	if staleService("", missing) {
		t.Error("no service at all was treated as a stale one")
	}
}

// The registration is a command line, not a path, and the path is quoted
// because it always contains a space — it lives under Program Files. Taking
// the whole line would test a file name that cannot exist and remove a
// service that was working.
func TestTheServiceProgramIsReadOutOfItsCommandLine(t *testing.T) {
	cases := map[string]string{
		`"C:\Program Files\AKConnect\akconnect-agent.exe" service run`: `C:\Program Files\AKConnect\akconnect-agent.exe`,
		`"C:\Program Files\AKConnect\akconnect-agent.exe"`:             `C:\Program Files\AKConnect\akconnect-agent.exe`,
		`C:\akconnect\agent.exe service run`:                           `C:\akconnect\agent.exe`,
		`C:\akconnect\agent.exe`:                                       `C:\akconnect\agent.exe`,
		``:                                                             ``,
		`   `:                                                          ``,
	}

	for line, want := range cases {
		if got := programOf(line); got != want {
			t.Errorf("programOf(%q) = %q, wanted %q", line, got, want)
		}
	}
}

// The uninstaller has to know every firewall rule any version of this ever
// made, because the path that uses this list is the one where the agent's
// binary is already gone and cannot be asked.
//
// The rules changed shape in 1.9.6 — from one naming a port to four naming the
// program — and an uninstall that left four rules behind would be exactly the
// thing a customer's IT person finds months later.
func TestTheUninstallerKnowsEveryFirewallRuleEverMade(t *testing.T) {
	source, err := os.ReadFile("windows.go")
	if err != nil {
		t.Fatal(err)
	}

	// The names the agent creates, read from the agent's own file so the two
	// cannot drift apart without this failing.
	rules, err := os.ReadFile("../../internal/winenv/firewall_windows.go")
	if err != nil {
		t.Fatal(err)
	}

	for _, suffix := range []string{"inbound UDP", "inbound TCP", "outbound UDP", "outbound TCP"} {
		if !strings.Contains(string(rules), `{"`+suffix+`"`) {
			t.Fatalf("the agent no longer creates a rule for %q; this test is out of date", suffix)
		}
		if !strings.Contains(string(source), `"AK Connect (`+suffix+`)"`) {
			t.Errorf("the uninstaller does not remove the %q rule", suffix)
		}
	}

	// And the one every installation before 1.9.6 has.
	if !strings.Contains(string(source), `"AKConnect Agent (WireGuard UDP)"`) {
		t.Error("the uninstaller no longer removes the rule versions up to 1.9.5 created")
	}
}
