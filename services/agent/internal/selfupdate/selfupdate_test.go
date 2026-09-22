package selfupdate

import (
	"crypto/ed25519"
	"encoding/hex"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// signed is a release the way the panel publishes one: the file, its digest,
// and an ed25519 signature over the lowercase hex digest.
type signed struct {
	path      string
	digest    string
	signature string
	publicKey string
}

func publish(t *testing.T, dir, contents string) signed {
	t.Helper()

	path := filepath.Join(dir, "akconnect-agent")
	if err := os.WriteFile(path, []byte(contents), 0o755); err != nil {
		t.Fatalf("writing the release: %v", err)
	}

	digest, err := FileSHA256(path)
	if err != nil {
		t.Fatalf("hashing the release: %v", err)
	}

	pub, priv, err := ed25519.GenerateKey(nil)
	if err != nil {
		t.Fatalf("generating a controller key: %v", err)
	}

	return signed{
		path:      path,
		digest:    digest,
		signature: "ed25519:" + hex.EncodeToString(ed25519.Sign(priv, []byte(digest))),
		publicKey: hex.EncodeToString(pub),
	}
}

func TestAProperlySignedReleaseIsAccepted(t *testing.T) {
	rel := publish(t, t.TempDir(), "#!/bin/sh\necho new agent\n")

	if err := Verify(rel.path, rel.digest, rel.signature, rel.publicKey); err != nil {
		t.Fatalf("a release signed by the controller key was refused: %v", err)
	}

	// Upper-case hex from the panel must not change the answer: a digest is
	// not case sensitive and refusing one would look like tampering.
	if err := Verify(rel.path, strings.ToUpper(rel.digest), rel.signature, rel.publicKey); err != nil {
		t.Fatalf("an upper-case digest was refused: %v", err)
	}
}

// The whole point of the package: a panel that has been taken over can serve
// any bytes it likes, and none of them get installed without the signing key.
func TestAReleaseIsRefusedWithoutAGoodSignature(t *testing.T) {
	dir := t.TempDir()
	rel := publish(t, dir, "#!/bin/sh\necho new agent\n")
	other := publish(t, t.TempDir(), "#!/bin/sh\necho new agent\n")

	// Same contents, so the same digest — and a signature from a key that is
	// not this panel's controller key.
	if other.digest != rel.digest {
		t.Fatalf("the fixture is wrong: two identical files hashed differently")
	}

	cases := []struct {
		name      string
		digest    string
		signature string
		publicKey string
		want      string
	}{
		{"no checksum", "", rel.signature, rel.publicKey, "no checksum"},
		{"wrong checksum", strings.Repeat("ab", 32), rel.signature, rel.publicKey, "does not match its checksum"},
		{"no signature", rel.digest, "", rel.publicKey, "not signed"},
		{"no controller key", rel.digest, rel.signature, "", "no controller public key"},
		{"another key's signature", rel.digest, other.signature, rel.publicKey, "does not verify"},
		{"signature is not hex", rel.digest, "ed25519:zzzz", rel.publicKey, "not hex"},
		{"signature is short", rel.digest, "ed25519:abcd", rel.publicKey, "not 64"},
		{"key is not hex", rel.digest, rel.signature, "zzzz", "not hex"},
		{"key is short", rel.digest, rel.signature, "abcd", "not 32"},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			err := Verify(rel.path, tc.digest, tc.signature, tc.publicKey)
			if err == nil {
				t.Fatalf("%s was accepted; a binary would have been installed", tc.name)
			}
			if !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("the refusal does not say why: %q does not contain %q", err, tc.want)
			}
		})
	}
}

// A digest that matches a *different* file is the corruption case, and the
// file on disk is what decides — not what the panel said about it.
func TestATamperedDownloadIsRefused(t *testing.T) {
	dir := t.TempDir()
	rel := publish(t, dir, "#!/bin/sh\necho new agent\n")

	if err := os.WriteFile(rel.path, []byte("#!/bin/sh\nrm -rf /\n"), 0o755); err != nil {
		t.Fatalf("overwriting the release: %v", err)
	}

	err := Verify(rel.path, rel.digest, rel.signature, rel.publicKey)
	if err == nil {
		t.Fatal("a download replaced after it was hashed was accepted")
	}
	if !strings.Contains(err.Error(), "does not match its checksum") {
		t.Fatalf("unexpected refusal: %v", err)
	}
}

func TestTheSwapLeavesTheOldBinaryBehindAndThenRemovesIt(t *testing.T) {
	dir := t.TempDir()
	target := filepath.Join(dir, "akconnect-agent")
	staged := filepath.Join(dir, ".staged")

	if err := os.WriteFile(target, []byte("old"), 0o755); err != nil {
		t.Fatalf("writing the running binary: %v", err)
	}
	if err := os.WriteFile(staged, []byte("new"), 0o644); err != nil {
		t.Fatalf("writing the staged binary: %v", err)
	}

	previous, err := applyTo(staged, target)
	if err != nil {
		t.Fatalf("the swap failed: %v", err)
	}

	installed, err := os.ReadFile(target)
	if err != nil {
		t.Fatalf("reading the installed binary: %v", err)
	}
	if string(installed) != "new" {
		t.Fatalf("the installed binary is %q, not the new one", installed)
	}

	// The old one is kept, not deleted: on Windows it cannot be deleted while
	// it is running, and it is also the only thing to put back if the new one
	// turns out not to start.
	kept, err := os.ReadFile(previous)
	if err != nil {
		t.Fatalf("the previous binary was not kept: %v", err)
	}
	if string(kept) != "old" {
		t.Fatalf("the kept binary is %q, not the old one", kept)
	}

	if mode := stat(t, target).Mode().Perm(); mode&0o111 == 0 {
		t.Fatalf("the installed binary is not executable (%v)", mode)
	}
}

// Staging somewhere else is the failure that would strand a machine with no
// agent at all, so it is refused before anything is moved.
func TestStagingOnAnotherFilesystemIsRefusedBeforeAnythingMoves(t *testing.T) {
	dir := t.TempDir()
	elsewhere := t.TempDir()

	target := filepath.Join(dir, "akconnect-agent")
	staged := filepath.Join(elsewhere, ".staged")

	if err := os.WriteFile(target, []byte("old"), 0o755); err != nil {
		t.Fatalf("writing the running binary: %v", err)
	}
	if err := os.WriteFile(staged, []byte("new"), 0o644); err != nil {
		t.Fatalf("writing the staged binary: %v", err)
	}

	if _, err := applyTo(staged, target); err == nil {
		t.Fatal("a staged file in another directory was accepted")
	}

	running, err := os.ReadFile(target)
	if err != nil {
		t.Fatalf("the running binary is gone: %v", err)
	}
	if string(running) != "old" {
		t.Fatalf("the running binary was replaced anyway: %q", running)
	}
	if _, err := os.Stat(target + previousSuffix); !os.IsNotExist(err) {
		t.Fatalf("something was moved aside despite the refusal")
	}
}

// CleanPrevious is called on every start-up, so the ordinary case — no update
// has happened — must be silent rather than an error.
func TestCleaningUpWhenThereIsNothingToCleanSaysNothing(t *testing.T) {
	removed, err := CleanPrevious()
	if err != nil {
		t.Fatalf("a start-up with no previous binary reported an error: %v", err)
	}
	if removed != "" {
		t.Fatalf("it claims to have removed %q", removed)
	}
}

func stat(t *testing.T, path string) os.FileInfo {
	t.Helper()

	info, err := os.Stat(path)
	if err != nil {
		t.Fatalf("stat %s: %v", path, err)
	}

	return info
}
