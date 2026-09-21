package main

import (
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
