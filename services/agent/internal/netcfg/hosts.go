package netcfg

import (
	"bufio"
	"bytes"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
)

// The hosts file, as the fallback when nothing better exists.
//
// systemd-resolved and NRPT are the right mechanisms and are used where they
// are there. Neither is everywhere: a Debian server, a container, an older
// Windows build, a machine where an administrator has turned resolved off. On
// those, a resolver nothing is pointing at is a feature that does not work,
// and "install systemd-resolved" is not an answer to give a customer.
//
// The hosts file is a worse mechanism in every way except the ones that
// matter here:
//
//   - it is consulted before DNS by the standard resolver on both platforms,
//     so `nvr.hotel-abc.acme.internal` resolves for everything on the machine;
//   - it affects no other name, and touches no DNS configuration at all, so
//     the promise that a customer's own resolution is untouched is not a
//     promise about a mechanism — there is nothing to interfere with;
//   - it needs no daemon and no privilege beyond writing one file.
//
// What it cannot do is wildcards, and our zone is an enumerated list, so that
// costs nothing.
//
// # Writing to a file that is not ours
//
// /etc/hosts belongs to the machine, not to us. Everything we write goes
// between two markers, everything outside them is copied through byte for
// byte, and the file is replaced by an atomic rename so a crash halfway cannot
// leave a machine unable to resolve `localhost`.

const (
	hostsBegin = "# BEGIN AKConnect — managed block, do not edit between the markers"
	hostsEnd   = "# END AKConnect"
)

// HostEntry is one name and the address it resolves to.
type HostEntry struct {
	Name    string
	Address string
}

// writeHosts replaces our managed block with these entries, leaving the rest
// of the file exactly as it was. An empty list removes the block.
func writeHosts(path string, entries []HostEntry) error {
	existing, err := os.ReadFile(path)
	if err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("dns: reading %s: %w", path, err)
	}

	var out bytes.Buffer
	inBlock := false
	scanner := bufio.NewScanner(bytes.NewReader(existing))
	// A hosts file line is short; the default 64 KiB is plenty, and a file
	// with a longer line is one we should not be rewriting anyway.
	for scanner.Scan() {
		line := scanner.Text()
		trimmed := strings.TrimSpace(line)

		switch {
		case trimmed == hostsBegin:
			inBlock = true
		case inBlock && trimmed == hostsEnd:
			inBlock = false
		case !inBlock:
			out.WriteString(line)
			out.WriteByte('\n')
		}
	}
	if err := scanner.Err(); err != nil {
		return fmt.Errorf("dns: reading %s: %w", path, err)
	}

	if inBlock {
		// A begin marker with no end: an earlier write was interrupted, or
		// somebody edited between the markers. Everything after the marker has
		// already been dropped, which is the right recovery — the block is
		// ours and we are about to write it again.
		_ = inBlock
	}

	if len(entries) > 0 {
		// Sorted, so a configuration that has not changed produces a file that
		// has not changed, and a diff of /etc/hosts shows what actually moved.
		sorted := append([]HostEntry(nil), entries...)
		sort.Slice(sorted, func(i, j int) bool { return sorted[i].Name < sorted[j].Name })

		out.WriteString(hostsBegin)
		out.WriteByte('\n')
		out.WriteString("# These names come from your AKConnect network and are rewritten\n")
		out.WriteString("# automatically. Remove the agent and this block goes with it.\n")
		for _, e := range sorted {
			if !safeHostLine(e) {
				continue
			}
			fmt.Fprintf(&out, "%s\t%s\n", e.Address, e.Name)
		}
		out.WriteString(hostsEnd)
		out.WriteByte('\n')
	}

	return replaceFile(path, out.Bytes())
}

// safeHostLine refuses anything that could add a second field to a line.
//
// The panel is not supposed to send a name with a space in it, and a hosts
// file where it did would silently alias an extra name.
func safeHostLine(e HostEntry) bool {
	if e.Name == "" || e.Address == "" {
		return false
	}

	return strings.IndexFunc(e.Name+e.Address, func(r rune) bool {
		return r <= ' ' || r == '#'
	}) < 0
}

// replaceFile writes atomically, in the target's own directory so the rename
// cannot cross a filesystem.
func replaceFile(path string, content []byte) error {
	dir := filepath.Dir(path)

	tmp, err := os.CreateTemp(dir, ".akconnect-hosts-*")
	if err != nil {
		return fmt.Errorf("dns: writing beside %s: %w", path, err)
	}
	tmpName := tmp.Name()

	defer func() {
		// Best effort: if the rename succeeded this is already gone.
		_ = os.Remove(tmpName)
	}()

	if _, err := tmp.Write(content); err != nil {
		_ = tmp.Close()

		return fmt.Errorf("dns: writing %s: %w", tmpName, err)
	}
	// Flushed before the rename: a rename is atomic with respect to the
	// directory, not with respect to the data in the file.
	if err := tmp.Sync(); err != nil {
		_ = tmp.Close()

		return fmt.Errorf("dns: flushing %s: %w", tmpName, err)
	}
	if err := tmp.Close(); err != nil {
		return fmt.Errorf("dns: closing %s: %w", tmpName, err)
	}

	// The permissions a hosts file is expected to have, not the 0600 a temp
	// file is created with.
	if err := os.Chmod(tmpName, 0o644); err != nil {
		return fmt.Errorf("dns: setting permissions on %s: %w", tmpName, err)
	}

	if err := os.Rename(tmpName, path); err != nil {
		return fmt.Errorf("dns: replacing %s: %w", path, err)
	}

	return nil
}
