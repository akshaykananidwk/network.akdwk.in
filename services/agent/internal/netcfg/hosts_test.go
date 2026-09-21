package netcfg

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// /etc/hosts belongs to the machine, not to us. Every test here is about
// leaving the rest of it exactly as it was found — a networking agent that
// breaks `localhost` has done more damage than the feature was worth.

const existingHosts = `127.0.0.1	localhost
::1	localhost ip6-localhost ip6-loopback
192.168.1.10	nas.home  # the customer's own entry
`

func tempHosts(t *testing.T, content string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "hosts")
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatalf("writing the fixture: %v", err)
	}

	return path
}

func read(t *testing.T, path string) string {
	t.Helper()
	b, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("reading back: %v", err)
	}

	return string(b)
}

func TestTheRestOfTheFileIsUntouched(t *testing.T) {
	path := tempHosts(t, existingHosts)

	err := writeHosts(path, []HostEntry{
		{Name: "nvr.hotel-abc.acme.internal", Address: "10.128.0.50"},
		{Name: "laptop.acme.internal", Address: "10.99.0.2"},
	})
	if err != nil {
		t.Fatalf("writeHosts: %v", err)
	}

	got := read(t, path)
	for _, line := range strings.Split(strings.TrimSpace(existingHosts), "\n") {
		if !strings.Contains(got, line) {
			t.Fatalf("the machine's own line was lost: %q", line)
		}
	}
	if !strings.Contains(got, "10.128.0.50\tnvr.hotel-abc.acme.internal") {
		t.Fatal("our entry was not written")
	}
}

// Rewriting must replace our block, not accumulate a second one.
func TestRewritingReplacesTheBlock(t *testing.T) {
	path := tempHosts(t, existingHosts)

	for i := 0; i < 3; i++ {
		if err := writeHosts(path, []HostEntry{{Name: "nvr.acme.internal", Address: "10.128.0.50"}}); err != nil {
			t.Fatalf("pass %d: %v", i, err)
		}
	}

	got := read(t, path)
	if n := strings.Count(got, hostsBegin); n != 1 {
		t.Fatalf("%d managed blocks, want 1", n)
	}
	if n := strings.Count(got, "nvr.acme.internal"); n != 1 {
		t.Fatalf("the entry appears %d times", n)
	}
}

// An address that changed must not leave the old one behind, or a technician
// reaches yesterday's machine.
func TestAChangedAddressReplacesTheOld(t *testing.T) {
	path := tempHosts(t, existingHosts)

	_ = writeHosts(path, []HostEntry{{Name: "nvr.acme.internal", Address: "10.128.0.50"}})
	_ = writeHosts(path, []HostEntry{{Name: "nvr.acme.internal", Address: "10.128.7.50"}})

	got := read(t, path)
	if strings.Contains(got, "10.128.0.50") {
		t.Fatal("the old address is still in the file")
	}
	if !strings.Contains(got, "10.128.7.50\tnvr.acme.internal") {
		t.Fatal("the new address was not written")
	}
}

// Removing everything must leave the file as though we had never been there.
func TestRemovingLeavesTheFileAsFound(t *testing.T) {
	path := tempHosts(t, existingHosts)

	_ = writeHosts(path, []HostEntry{{Name: "nvr.acme.internal", Address: "10.128.0.50"}})
	if err := writeHosts(path, nil); err != nil {
		t.Fatalf("writeHosts(nil): %v", err)
	}

	if got := read(t, path); got != existingHosts {
		t.Fatalf("the file was not restored:\n--- got ---\n%s\n--- want ---\n%s", got, existingHosts)
	}
}

// A block whose end marker is missing — an interrupted write, or somebody
// editing inside it — must be recovered from, not duplicated around.
func TestAnUnterminatedBlockIsRecovered(t *testing.T) {
	path := tempHosts(t, existingHosts+hostsBegin+"\n10.0.0.1\tstale.acme.internal\n")

	if err := writeHosts(path, []HostEntry{{Name: "nvr.acme.internal", Address: "10.128.0.50"}}); err != nil {
		t.Fatalf("writeHosts: %v", err)
	}

	got := read(t, path)
	if strings.Contains(got, "stale.acme.internal") {
		t.Fatal("the orphaned block's contents survived")
	}
	if n := strings.Count(got, hostsBegin); n != 1 {
		t.Fatalf("%d begin markers, want 1", n)
	}
	if !strings.Contains(got, "127.0.0.1\tlocalhost") {
		t.Fatal("the machine's own entries were lost during recovery")
	}
}

// A name with whitespace in it would alias an extra name on the same line.
// The panel is not supposed to send one; the file is not the place to find out
// that it did.
func TestEntriesThatCouldAliasAreRefused(t *testing.T) {
	path := tempHosts(t, existingHosts)

	_ = writeHosts(path, []HostEntry{
		{Name: "evil.acme.internal localhost", Address: "10.0.0.1"},
		{Name: "tabbed\tname.acme.internal", Address: "10.0.0.2"},
		{Name: "commented.acme.internal", Address: "10.0.0.3 # nope"},
		{Name: "good.acme.internal", Address: "10.0.0.4"},
	})

	got := read(t, path)
	for _, bad := range []string{"evil.acme.internal", "tabbed", "commented.acme.internal"} {
		if strings.Contains(got, bad) {
			t.Fatalf("%q was written", bad)
		}
	}
	if !strings.Contains(got, "10.0.0.4\tgood.acme.internal") {
		t.Fatal("the well-formed entry was dropped along with the others")
	}
}

// A hosts file that does not exist yet is created rather than erroring.
func TestAMissingFileIsCreated(t *testing.T) {
	path := filepath.Join(t.TempDir(), "hosts")

	if err := writeHosts(path, []HostEntry{{Name: "nvr.acme.internal", Address: "10.128.0.50"}}); err != nil {
		t.Fatalf("writeHosts: %v", err)
	}
	if !strings.Contains(read(t, path), "nvr.acme.internal") {
		t.Fatal("nothing was written")
	}
}

// The file has to stay readable by everything on the machine. A hosts file
// written 0600 makes every non-root process unable to resolve `localhost`.
func TestPermissionsAreLeftUsable(t *testing.T) {
	path := tempHosts(t, existingHosts)

	_ = writeHosts(path, []HostEntry{{Name: "nvr.acme.internal", Address: "10.128.0.50"}})

	info, err := os.Stat(path)
	if err != nil {
		t.Fatalf("stat: %v", err)
	}
	if info.Mode().Perm() != 0o644 {
		t.Fatalf("mode is %v, want 0644", info.Mode().Perm())
	}
}

// Sorted output, so an unchanged configuration produces an unchanged file and
// a diff shows what actually moved.
func TestOutputIsStable(t *testing.T) {
	a := tempHosts(t, existingHosts)
	b := tempHosts(t, existingHosts)

	entries := []HostEntry{
		{Name: "zeta.acme.internal", Address: "10.99.0.9"},
		{Name: "alpha.acme.internal", Address: "10.99.0.1"},
		{Name: "mid.acme.internal", Address: "10.99.0.5"},
	}
	reversed := []HostEntry{entries[2], entries[0], entries[1]}

	_ = writeHosts(a, entries)
	_ = writeHosts(b, reversed)

	if read(t, a) != read(t, b) {
		t.Fatal("the same records in a different order produced a different file")
	}
}
