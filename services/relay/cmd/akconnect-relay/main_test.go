package main

import "testing"

// A relay on a public server needs its data sockets inside a range somebody
// can open in a firewall. Without one the kernel hands out ephemeral ports —
// 32768 to 60999 on a normal Linux box — and DEPLOY.md would have to say "open
// most of the unprivileged port space", which is not an instruction to give
// anybody.

func TestPortRangeAccepted(t *testing.T) {
	from, to, err := parsePortRange("51900-52400")
	if err != nil {
		t.Fatalf("parsePortRange: %v", err)
	}
	if from != 51900 || to != 52400 {
		t.Fatalf("got %d-%d", from, to)
	}

	// Empty is the old behaviour, and stays supported: a lab does not need a
	// pinned range and should not have to think about one.
	if from, to, err := parsePortRange("  "); err != nil || from != 0 || to != 0 {
		t.Fatalf("empty gave %d-%d, %v", from, to, err)
	}
}

func TestPortRangeRefusals(t *testing.T) {
	for _, spec := range []string{
		"51900",       // not a range
		"abc-def",     // not numbers
		"52400-51900", // backwards
		"80-52400",    // reaches into privileged ports
		"51900-70000", // past the end of the port space
		"51900-51910", // room for five sessions
	} {
		if _, _, err := parsePortRange(spec); err == nil {
			t.Fatalf("%q was accepted", spec)
		}
	}
}

// The refusal has to say what to type instead. Somebody reading it is halfway
// through a deployment with a checklist in front of them.
func TestPortRangeRefusalsExplainThemselves(t *testing.T) {
	_, _, err := parsePortRange("51900")
	if err == nil {
		t.Fatal("no error")
	}
	if want := "51900-52400"; !contains(err.Error(), want) {
		t.Fatalf("the error does not show the expected shape: %s", err)
	}
}

func contains(haystack, needle string) bool {
	return len(haystack) >= len(needle) && (func() bool {
		for i := 0; i+len(needle) <= len(haystack); i++ {
			if haystack[i:i+len(needle)] == needle {
				return true
			}
		}

		return false
	})()
}
