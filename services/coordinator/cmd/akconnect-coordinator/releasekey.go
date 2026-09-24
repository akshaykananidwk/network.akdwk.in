package main

// release-key: the edge's own signature on what it publishes.
//
// The panel used to publish any installer or agent binary that arrived with
// the coordinator's shared secret, and it signs an uploaded agent with its own
// key and offers it to every device as an update. The shared secret sits in
// coordinator.env, was printed by the installer up to 1.9.7-dev.21, and one
// copy of it ended up in a chat. Anyone holding it could have pushed code to
// every PC.
//
// So the edge now signs each upload with a key that never leaves this machine
// and is readable by root only, and the panel publishes an upload only when
// that signature checks out against the one edge key it has been told to
// trust — told locally, by root on the panel's own machine, or by an
// administrator in the panel. The shared secret alone gets an upload held,
// never offered.
//
//	akconnect-coordinator release-key init   <keyfile>
//	akconnect-coordinator release-key public <keyfile>
//	akconnect-coordinator release-key sign   <keyfile> <kind> <version> <file>
//
// The key file holds the 32-byte ed25519 seed, hex, on one line. It is not an
// environment variable of the coordinator service on purpose: the service runs
// as the akconnect user and has no business signing releases.

import (
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"regexp"
	"strings"
)

// releaseMessage is what is signed. The prefix keeps a signature made here
// from ever meaning anything to a verifier of something else; the panel builds
// the same string (EdgeRelease::artifactMessage).
func releaseMessage(kind, version, sha256hex string) []byte {
	return []byte("akconnect-edge-artifact/v1\n" + kind + "\n" + version + "\n" + sha256hex)
}

// releaseFingerprint is what an administrator compares by eye: the first 80
// bits of sha256 over the raw public key, in groups of four. The panel shows
// the same (EdgeRelease::fingerprint).
func releaseFingerprint(pub ed25519.PublicKey) string {
	sum := sha256.Sum256(pub)
	h := hex.EncodeToString(sum[:10])

	groups := make([]string, 0, 5)
	for i := 0; i < len(h); i += 4 {
		groups = append(groups, h[i:i+4])
	}

	return strings.Join(groups, " ")
}

var (
	releaseKinds   = map[string]bool{"windows-setup": true, "windows-agent": true, "windows-pack": true}
	releaseVersion = regexp.MustCompile(`^[\w.\-+]{1,32}$`)
)

func runReleaseKey(args []string, out io.Writer) error {
	if len(args) < 2 {
		return errors.New("usage: release-key init|public <keyfile>, or release-key sign <keyfile> <kind> <version> <file>")
	}

	command, path := args[0], args[1]

	switch command {
	case "init":
		if len(args) != 2 {
			return errors.New("usage: release-key init <keyfile>")
		}
		priv, created, err := initReleaseKey(path)
		if err != nil {
			return err
		}
		pub := priv.Public().(ed25519.PublicKey)
		state := "existing"
		if created {
			state = "new"
		}
		fmt.Fprintf(out, "%s %s %s\n", hex.EncodeToString(pub), state, releaseFingerprint(pub))

		return nil

	case "public":
		if len(args) != 2 {
			return errors.New("usage: release-key public <keyfile>")
		}
		priv, err := loadReleaseKey(path)
		if err != nil {
			return err
		}
		pub := priv.Public().(ed25519.PublicKey)
		fmt.Fprintf(out, "  public key   %s\n  fingerprint  %s\n", hex.EncodeToString(pub), releaseFingerprint(pub))

		return nil

	case "sign":
		if len(args) != 5 {
			return errors.New("usage: release-key sign <keyfile> <kind> <version> <file>")
		}
		kind, version, file := args[2], args[3], args[4]
		if !releaseKinds[kind] {
			return fmt.Errorf("unknown artefact kind %q", kind)
		}
		if !releaseVersion.MatchString(version) {
			return fmt.Errorf("%q is not a version", version)
		}
		priv, err := loadReleaseKey(path)
		if err != nil {
			return err
		}
		digest, err := fileSHA256(file)
		if err != nil {
			return err
		}
		sig := ed25519.Sign(priv, releaseMessage(kind, version, digest))
		pub := priv.Public().(ed25519.PublicKey)
		// One line: the digest the signature covers, the signature, the key.
		fmt.Fprintf(out, "%s %s %s\n", digest, hex.EncodeToString(sig), hex.EncodeToString(pub))

		return nil
	}

	return fmt.Errorf("unknown release-key command %q", command)
}

// initReleaseKey makes the key if there is none, and never replaces one: a new
// key means an administrator has to trust it again, so it is not something to
// do by accident.
func initReleaseKey(path string) (ed25519.PrivateKey, bool, error) {
	if priv, err := loadReleaseKey(path); err == nil {
		return priv, false, nil
	} else if !errors.Is(err, fs.ErrNotExist) {
		return nil, false, err
	}

	seed := make([]byte, ed25519.SeedSize)
	if _, err := rand.Read(seed); err != nil {
		return nil, false, err
	}

	// O_EXCL: two runs at once must not each write a key and leave the panel
	// trusting the one that lost.
	f, err := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
	if err != nil {
		if errors.Is(err, fs.ErrExist) {
			priv, lerr := loadReleaseKey(path)
			return priv, false, lerr
		}
		return nil, false, err
	}
	if _, err := f.WriteString(hex.EncodeToString(seed) + "\n"); err != nil {
		f.Close()
		os.Remove(path)
		return nil, false, err
	}
	if err := f.Close(); err != nil {
		os.Remove(path)
		return nil, false, err
	}

	return ed25519.NewKeyFromSeed(seed), true, nil
}

func loadReleaseKey(path string) (ed25519.PrivateKey, error) {
	info, err := os.Stat(path)
	if err != nil {
		return nil, err
	}
	// A signing key anyone else on the machine can read is not one to sign
	// with: on the production host that is thirty other people's websites.
	if info.Mode().Perm()&0o077 != 0 {
		return nil, fmt.Errorf("%s is readable by others (mode %04o); it must be 0600", path, info.Mode().Perm())
	}

	raw, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	seed, err := hex.DecodeString(strings.TrimSpace(string(raw)))
	if err != nil || len(seed) != ed25519.SeedSize {
		return nil, fmt.Errorf("%s does not hold an ed25519 seed", path)
	}

	return ed25519.NewKeyFromSeed(seed), nil
}

func fileSHA256(path string) (string, error) {
	f, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer f.Close()

	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", err
	}

	return hex.EncodeToString(h.Sum(nil)), nil
}
