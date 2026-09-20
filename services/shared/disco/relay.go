package disco

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/binary"
	"fmt"
	"time"
)

// Relay message types. They continue the numbering in wire.go.
const (
	// TypeRelayRequest is an agent telling the coordinator it cannot reach a
	// peer directly and needs a relay. Sealed.
	TypeRelayRequest MessageType = 0x10
	// TypeRelayOffer carries a relay address and a ticket. Sealed.
	TypeRelayOffer MessageType = 0x11
	// TypeRelayBind is an agent presenting its ticket to a relay. Not sealed —
	// the relay has no key agreement with the agent; the ticket's MAC is what
	// authenticates it.
	TypeRelayBind MessageType = 0x12
	// TypeRelayBindAck tells the agent which port to send its tunnel traffic to.
	TypeRelayBindAck MessageType = 0x13
)

// TicketLen is the fixed size of an encoded ticket.
//
// Fixed rather than variable because a relay parses these from unauthenticated
// packets on a public port, and a length field there is one more thing to get
// wrong.
const TicketLen = 8 + 8 + 32 + 32 + 32

// Ticket authorises one agent to use one relay for one peer pair.
//
// It is minted by the coordinator and verified by the relay. The two share a
// secret; the agent carries the ticket but cannot alter it, because any change
// breaks the MAC. This is what stops a relay being an open reflector: no
// ticket, no forwarding, and a ticket names exactly one pair.
type Ticket struct {
	TenantID  uint64
	ExpiresAt int64
	// Self is the public key of the agent this ticket was issued to.
	Self [32]byte
	// Peer is the public key of the other end of the pair.
	Peer [32]byte
	// MAC over everything above.
	MAC [32]byte
}

// PairID identifies the peer pair, independent of which side is asking.
//
// Both agents must derive the same value, so the two keys are ordered before
// hashing rather than concatenated in the caller's order.
func PairID(a, b [32]byte) [32]byte {
	first, second := a, b
	for i := 0; i < 32; i++ {
		if a[i] != b[i] {
			if b[i] < a[i] {
				first, second = b, a
			}

			break
		}
	}

	h := sha256.New()
	h.Write(first[:])
	h.Write(second[:])

	var out [32]byte
	copy(out[:], h.Sum(nil))

	return out
}

// Encode renders a ticket for the wire.
func (t *Ticket) Encode() []byte {
	out := make([]byte, 0, TicketLen)
	out = binary.BigEndian.AppendUint64(out, t.TenantID)
	out = binary.BigEndian.AppendUint64(out, uint64(t.ExpiresAt))
	out = append(out, t.Self[:]...)
	out = append(out, t.Peer[:]...)

	return append(out, t.MAC[:]...)
}

// DecodeTicket parses one, without verifying it.
func DecodeTicket(b []byte) (*Ticket, error) {
	if len(b) < TicketLen {
		return nil, ErrMalformed
	}

	var t Ticket
	t.TenantID = binary.BigEndian.Uint64(b[0:8])
	t.ExpiresAt = int64(binary.BigEndian.Uint64(b[8:16]))
	copy(t.Self[:], b[16:48])
	copy(t.Peer[:], b[48:80])
	copy(t.MAC[:], b[80:112])

	return &t, nil
}

// Sign computes the MAC. Called by the coordinator.
func (t *Ticket) Sign(secret []byte) {
	copy(t.MAC[:], t.mac(secret))
}

// Verify checks the MAC and the expiry. Called by the relay on every bind.
func (t *Ticket) Verify(secret []byte, now time.Time) error {
	// Constant time: a relay verifying thousands of tickets must not leak the
	// expected MAC through timing.
	if !hmac.Equal(t.MAC[:], t.mac(secret)) {
		return fmt.Errorf("%w: ticket signature does not verify", ErrMalformed)
	}

	if now.Unix() > t.ExpiresAt {
		return fmt.Errorf("%w: ticket expired at %s", ErrMalformed,
			time.Unix(t.ExpiresAt, 0).UTC().Format(time.RFC3339))
	}

	return nil
}

func (t *Ticket) mac(secret []byte) []byte {
	m := hmac.New(sha256.New, secret)

	var scratch [16]byte
	binary.BigEndian.PutUint64(scratch[0:8], t.TenantID)
	binary.BigEndian.PutUint64(scratch[8:16], uint64(t.ExpiresAt))

	m.Write(scratch[:])
	m.Write(t.Self[:])
	m.Write(t.Peer[:])

	return m.Sum(nil)
}

// Pair is the pair this ticket belongs to.
func (t *Ticket) Pair() [32]byte { return PairID(t.Self, t.Peer) }

// RelayRequest asks the coordinator for a relay to reach one peer.
type RelayRequest struct {
	Peer [32]byte
}

// Encode renders a RelayRequest.
func (r *RelayRequest) Encode() []byte { return append([]byte{}, r.Peer[:]...) }

// DecodeRelayRequest parses one.
func DecodeRelayRequest(b []byte) (*RelayRequest, error) {
	if len(b) < 32 {
		return nil, ErrMalformed
	}

	var out RelayRequest
	copy(out.Peer[:], b[:32])

	return &out, nil
}

// RelayOffer tells an agent where to bind and with what.
type RelayOffer struct {
	Peer     [32]byte
	Endpoint string
	Ticket   []byte
}

// Encode renders a RelayOffer.
func (r *RelayOffer) Encode() ([]byte, error) {
	b := append([]byte{}, r.Peer[:]...)

	var err error
	if b, err = AppendString(b, r.Endpoint); err != nil {
		return nil, err
	}

	return AppendString(b, base64.StdEncoding.EncodeToString(r.Ticket))
}

// DecodeRelayOffer parses one.
func DecodeRelayOffer(b []byte) (*RelayOffer, error) {
	if len(b) < 32 {
		return nil, ErrMalformed
	}

	var out RelayOffer
	copy(out.Peer[:], b[:32])
	b = b[32:]

	var err error
	if out.Endpoint, b, err = ReadString(b); err != nil {
		return nil, err
	}

	encoded, _, err := ReadString(b)
	if err != nil {
		return nil, err
	}

	if out.Ticket, err = base64.StdEncoding.DecodeString(encoded); err != nil {
		return nil, ErrMalformed
	}

	return &out, nil
}
