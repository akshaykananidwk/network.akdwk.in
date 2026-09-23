package disco

import (
	"crypto/rand"
	"fmt"

	"golang.org/x/crypto/nacl/box"
)

// Sealing uses NaCl box: X25519 to agree a key, then XSalsa20-Poly1305.
//
// The device's own WireGuard key is the sealing key, which is what makes a
// sealed packet self-authenticating. Only the holder of the private half can
// produce a packet that opens under the public key named in the header, so the
// coordinator does not need a separate credential to know who it is talking
// to, and a device token never has to travel in cleartext over UDP.

const nonceLen = 24

// Seal encrypts a body for a recipient.
func Seal(body []byte, recipient, senderPriv *[32]byte) ([]byte, error) {
	var nonce [nonceLen]byte
	if _, err := rand.Read(nonce[:]); err != nil {
		return nil, fmt.Errorf("generating a nonce: %w", err)
	}

	out := make([]byte, 0, nonceLen+len(body)+box.Overhead)
	out = append(out, nonce[:]...)

	return box.Seal(out, body, &nonce, recipient, senderPriv), nil
}

// Open decrypts a body from a sender. A failure here means the packet was
// forged, corrupted, or meant for somebody else — all of which are the same
// answer to the caller: drop it.
func Open(sealed []byte, sender, recipientPriv *[32]byte) ([]byte, error) {
	if len(sealed) < nonceLen+box.Overhead {
		return nil, ErrMalformed
	}

	var nonce [nonceLen]byte
	copy(nonce[:], sealed[:nonceLen])

	body, ok := box.Open(nil, sealed[nonceLen:], &nonce, sender, recipientPriv)
	if !ok {
		return nil, fmt.Errorf("%w: seal did not open", ErrMalformed)
	}

	return body, nil
}
