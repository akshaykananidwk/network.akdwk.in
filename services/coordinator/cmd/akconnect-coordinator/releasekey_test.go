package main

import (
	"bytes"
	"crypto/ed25519"
	"encoding/hex"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestReleaseKeyIsMadeOnceAndSignsWhatThePanelChecks(t *testing.T) {
	dir := t.TempDir()
	key := filepath.Join(dir, "release-signing.key")

	var first, second bytes.Buffer
	if err := runReleaseKey([]string{"init", key}, &first); err != nil {
		t.Fatal(err)
	}
	if err := runReleaseKey([]string{"init", key}, &second); err != nil {
		t.Fatal(err)
	}
	a, b := strings.Fields(first.String()), strings.Fields(second.String())
	if a[0] != b[0] || a[1] != "new" || b[1] != "existing" {
		t.Fatalf("init replaced the key or misreported: %q then %q", first.String(), second.String())
	}

	info, _ := os.Stat(key)
	if info.Mode().Perm() != 0o600 {
		t.Fatalf("key file mode %04o, want 0600", info.Mode().Perm())
	}

	file := filepath.Join(dir, "akconnect-agent.exe")
	os.WriteFile(file, []byte("MZ pretend agent"), 0o644)

	var out bytes.Buffer
	if err := runReleaseKey([]string{"sign", key, "windows-agent", "1.9.7-dev.23", file}, &out); err != nil {
		t.Fatal(err)
	}
	parts := strings.Fields(out.String())
	if len(parts) != 3 {
		t.Fatalf("sign printed %q", out.String())
	}
	digest, sigHex, pubHex := parts[0], parts[1], parts[2]
	if pubHex != a[0] {
		t.Fatal("sign used a different key from the one init reported")
	}
	sig, _ := hex.DecodeString(sigHex)
	pub, _ := hex.DecodeString(pubHex)

	want := "akconnect-edge-artifact/v1\nwindows-agent\n1.9.7-dev.23\n" + digest
	if !ed25519.Verify(pub, []byte(want), sig) {
		t.Fatal("the signature does not cover the message the panel rebuilds")
	}
	// The same bytes under another name or version are a different message.
	if ed25519.Verify(pub, []byte(strings.Replace(want, "1.9.7-dev.23", "9.9.9", 1)), sig) {
		t.Fatal("a signature for one version verified for another")
	}
}

func TestAReleaseKeyOthersCanReadIsRefused(t *testing.T) {
	dir := t.TempDir()
	key := filepath.Join(dir, "k")
	if err := runReleaseKey([]string{"init", key}, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	os.Chmod(key, 0o644)

	if err := runReleaseKey([]string{"public", key}, &bytes.Buffer{}); err == nil {
		t.Fatal("a world-readable key was used")
	}
}

func TestReleaseKeyRefusesStrangeKindsAndVersions(t *testing.T) {
	dir := t.TempDir()
	key := filepath.Join(dir, "k")
	runReleaseKey([]string{"init", key}, &bytes.Buffer{})
	file := filepath.Join(dir, "f")
	os.WriteFile(file, []byte("x"), 0o644)

	for _, args := range [][]string{
		{"sign", key, "linux-rootkit", "1.0", file},
		{"sign", key, "windows-agent", "1.0\nwindows-setup", file},
	} {
		if err := runReleaseKey(args, &bytes.Buffer{}); err == nil {
			t.Fatalf("signed %q", args)
		}
	}
}

func TestReleaseFingerprintMatchesThePanels(t *testing.T) {
	// The panel computes the same: first 10 bytes of sha256(raw key), hex, in
	// groups of four. Pinned so the two cannot drift apart unnoticed.
	pub := make([]byte, 32)
	if got := releaseFingerprint(pub); got != "6668 7aad f862 bd77 6c8f" {
		t.Fatalf("fingerprint of 32 zero bytes: %q", got)
	}
}
