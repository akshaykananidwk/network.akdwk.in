// Package wgkey handles the device's Curve25519 identity.
//
// The private half is generated here, on the device, and never leaves it (R5).
// Nothing in this package can serialise a private key to anywhere except the
// OS keystore, and the panel is only ever shown the public half.
package wgkey

import (
	"crypto/rand"
	"encoding/base64"
	"errors"
	"fmt"

	"golang.org/x/crypto/curve25519"
)

// KeyLen is the size of a Curve25519 scalar or point, in bytes.
const KeyLen = 32

// Private is a Curve25519 private key. It deliberately has no String method:
// the only way to render it is Base64, which is called in exactly one place.
type Private [KeyLen]byte

// Public is a Curve25519 public key.
type Public [KeyLen]byte

// Generate creates a new keypair from the system CSPRNG.
func Generate() (Private, Public, error) {
	var priv Private

	if _, err := rand.Read(priv[:]); err != nil {
		return Private{}, Public{}, fmt.Errorf("reading random bytes: %w", err)
	}

	clamp(&priv)

	pub, err := priv.Public()
	if err != nil {
		return Private{}, Public{}, err
	}

	return priv, pub, nil
}

// Public derives the public half.
func (p Private) Public() (Public, error) {
	out, err := curve25519.X25519(p[:], curve25519.Basepoint)
	if err != nil {
		return Public{}, fmt.Errorf("deriving public key: %w", err)
	}

	var pub Public
	copy(pub[:], out)

	return pub, nil
}

// Base64 renders the private key for the keystore. It is named so that every
// call site reads as an export of secret material and can be audited on sight.
func (p Private) Base64() string {
	return base64.StdEncoding.EncodeToString(p[:])
}

// Base64 renders the public key, which is what the panel stores.
func (p Public) Base64() string {
	return base64.StdEncoding.EncodeToString(p[:])
}

// Hex renders the key the way WireGuard's UAPI expects it.
func (p Private) Hex() string { return hexOf(p[:]) }

// Hex renders the key the way WireGuard's UAPI expects it.
func (p Public) Hex() string { return hexOf(p[:]) }

// ParsePrivate reads a private key back out of the keystore.
func ParsePrivate(encoded string) (Private, error) {
	raw, err := decode(encoded)
	if err != nil {
		return Private{}, err
	}

	var priv Private
	copy(priv[:], raw)

	return priv, nil
}

// ParsePublic reads a peer's public key from a configuration payload.
func ParsePublic(encoded string) (Public, error) {
	raw, err := decode(encoded)
	if err != nil {
		return Public{}, err
	}

	var pub Public
	copy(pub[:], raw)

	return pub, nil
}

func decode(encoded string) ([]byte, error) {
	raw, err := base64.StdEncoding.DecodeString(encoded)
	if err != nil {
		return nil, fmt.Errorf("key is not valid base64: %w", err)
	}
	if len(raw) != KeyLen {
		return nil, fmt.Errorf("key is %d bytes, want %d", len(raw), KeyLen)
	}

	return raw, nil
}

// clamp applies the Curve25519 bit-fixing that X25519 requires. Without it a
// key is still usable but not canonical, and two agents can derive different
// shared secrets from what looks like the same key.
func clamp(p *Private) {
	p[0] &= 248
	p[31] &= 127
	p[31] |= 64
}

func hexOf(b []byte) string {
	const digits = "0123456789abcdef"

	out := make([]byte, 0, len(b)*2)
	for _, c := range b {
		out = append(out, digits[c>>4], digits[c&0x0f])
	}

	return string(out)
}

// ErrNoKey is returned by a keystore that holds no identity yet.
var ErrNoKey = errors.New("no device key in the keystore")
