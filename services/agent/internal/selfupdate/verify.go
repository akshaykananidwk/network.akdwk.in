// Package selfupdate replaces the agent's own binary, when the panel offers a
// newer one and the offer can be proved genuine.
//
// The whole of the security is in this file. An agent that replaces its own
// executable because an HTTP response told it to is a remote code execution
// path into every customer machine, so the download is not trusted for being
// authenticated or for arriving over TLS: it is trusted for matching a digest
// that the panel's *offline* signing key has signed.
//
// That distinction is the point. The panel is on the public internet and holds
// the customer database; its private signing key is the one thing an attacker
// who owns the panel still does not have, and it is the only thing standing
// between a compromised panel and code on a thousand PCs.
package selfupdate

import (
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"os"
	"strings"
)

// Verify checks a downloaded file against the digest and signature the panel
// published.
//
// Both, always. A digest alone proves the download was not corrupted; only the
// signature proves who chose it.
func Verify(path, wantSHA256, signature, controllerPublicKey string) error {
	if wantSHA256 == "" {
		return fmt.Errorf("the panel published no checksum for this release")
	}

	actual, err := FileSHA256(path)
	if err != nil {
		return err
	}

	if !strings.EqualFold(actual, wantSHA256) {
		return fmt.Errorf("the download does not match its checksum (got %s, expected %s)",
			actual[:16]+"…", strings.ToLower(wantSHA256)[:16]+"…")
	}

	if signature == "" {
		return fmt.Errorf(
			"this release is not signed, so it will not be installed. " +
				"Publish it from a panel with a controller signing key configured")
	}

	if controllerPublicKey == "" {
		return fmt.Errorf(
			"this device has no controller public key, so it cannot check the signature. " +
				"The panel publishes one in the agent configuration")
	}

	if err := verifySignature(actual, signature, controllerPublicKey); err != nil {
		return err
	}

	return nil
}

// verifySignature checks an ed25519 signature over the digest string.
//
// The signed message is the lowercase hex digest, which is what the panel
// signs — see EdgeRelease::registerAgentRelease. Signing the digest rather
// than the file means the signature can be checked before the download starts
// and again after it finishes.
func verifySignature(digest, signature, publicKeyHex string) error {
	signature = strings.TrimPrefix(signature, "ed25519:")

	sig, err := hex.DecodeString(signature)
	if err != nil {
		return fmt.Errorf("the release signature is not hex: %w", err)
	}
	if len(sig) != ed25519.SignatureSize {
		return fmt.Errorf("the release signature is %d bytes, not %d", len(sig), ed25519.SignatureSize)
	}

	key, err := hex.DecodeString(publicKeyHex)
	if err != nil {
		return fmt.Errorf("the controller public key is not hex: %w", err)
	}
	if len(key) != ed25519.PublicKeySize {
		return fmt.Errorf("the controller public key is %d bytes, not %d", len(key), ed25519.PublicKeySize)
	}

	if !ed25519.Verify(ed25519.PublicKey(key), []byte(strings.ToLower(digest)), sig) {
		return fmt.Errorf("the release signature does not verify against this panel's controller key")
	}

	return nil
}

// FileSHA256 is the lowercase hex digest of a file.
func FileSHA256(path string) (string, error) {
	file, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer file.Close()

	hash := sha256.New()
	if _, err := io.Copy(hash, file); err != nil {
		return "", err
	}

	return hex.EncodeToString(hash.Sum(nil)), nil
}
