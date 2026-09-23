package disco

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/binary"
	"errors"
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
	// TypeRelayBindRefused tells the agent WHY a bind was refused.
	//
	// Silence was the old answer, and silence is indistinguishable from a
	// relay that has stopped running — so an agent whose ticket had expired
	// concluded its relay was dead and asked the coordinator for a different
	// one, every twenty seconds, until the only relay in the fleet was taken
	// out of rotation by its own complaints. Saying "your ticket expired" is
	// the difference between renewing and failing over.
	//
	// 0x18, not 0x14. It was introduced at 0x14 in 1.9.7-dev.6, which
	// TypeRelayProbe already held. Nothing broke, entirely by luck of
	// direction: agents send probes to relays and never receive them, and
	// relays send refusals to agents and never receive them, so each side's
	// switch only ever saw one meaning of the number. That is not a property
	// to rely on — the next message type either direction gains would have
	// landed on it — so the number is corrected while only one release has
	// carried it.
	//
	// See handleRelayBindRefused for why the agent still accepts 0x14: a
	// relay running dev.6 through dev.10 sends refusals at that number, and
	// the relay and the agents update on separate schedules.
	TypeRelayBindRefused MessageType = 0x18

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

// ErrTicketExpired is a ticket whose MAC verifies and whose time has passed.
//
// Separate from a ticket that does not verify at all, and the difference
// decides whether the relay answers. A holder of an expired ticket proved, by
// presenting a valid MAC, that the coordinator once issued it one — telling
// that party to renew costs nothing. A bad MAC is an unauthenticated
// stranger, and the relay stays silent.
var ErrTicketExpired = errors.New("ticket expired")

// Verify checks the MAC and the expiry. Called by the relay on every bind.
func (t *Ticket) Verify(secret []byte, now time.Time) error {
	// Constant time: a relay verifying thousands of tickets must not leak the
	// expected MAC through timing.
	if !hmac.Equal(t.MAC[:], t.mac(secret)) {
		return fmt.Errorf("%w: ticket signature does not verify", ErrMalformed)
	}

	if now.Unix() > t.ExpiresAt {
		return fmt.Errorf("%w: %w at %s", ErrMalformed, ErrTicketExpired,
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
	// Avoid names a relay the agent was already given and could not use.
	//
	// Without it a failover asks the same question and gets the same answer:
	// the agent has no way to say "not that one" and the coordinator has no
	// way to know the offer it made did not work. Empty on a first request.
	Avoid string
}

// Encode renders a RelayRequest.
//
// The Avoid field is appended rather than fixed in place, so a coordinator
// built before it existed reads the peer key and stops, exactly as it did.
func (r *RelayRequest) Encode() ([]byte, error) {
	return AppendString(append([]byte{}, r.Peer[:]...), r.Avoid)
}

// DecodeRelayRequest parses one.
func DecodeRelayRequest(b []byte) (*RelayRequest, error) {
	if len(b) < 32 {
		return nil, ErrMalformed
	}

	var out RelayRequest
	copy(out.Peer[:], b[:32])

	// Absent on requests from an older agent, which is not an error: it simply
	// has no relay it wants avoided.
	if avoid, _, err := ReadString(b[32:]); err == nil {
		out.Avoid = avoid
	}

	return &out, nil
}

// RelayOffer tells an agent where to bind and with what.
type RelayOffer struct {
	Peer     [32]byte
	Endpoint string
	Ticket   []byte
	// Name is the relay's name in the fleet. The agent needs it to say which
	// relay failed when it asks for another one — an address is not enough,
	// because the coordinator knows relays by name and a NAT can make the
	// address the agent sees differ from the one configured.
	Name string
}

// Encode renders a RelayOffer.
func (r *RelayOffer) Encode() ([]byte, error) {
	b := append([]byte{}, r.Peer[:]...)

	var err error
	if b, err = AppendString(b, r.Endpoint); err != nil {
		return nil, err
	}

	if b, err = AppendString(b, base64.StdEncoding.EncodeToString(r.Ticket)); err != nil {
		return nil, err
	}

	return AppendString(b, r.Name)
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

	encoded, rest, err := ReadString(b)
	if err != nil {
		return nil, err
	}

	if out.Ticket, err = base64.StdEncoding.DecodeString(encoded); err != nil {
		return nil, ErrMalformed
	}

	// Absent from an offer made by an older coordinator, which costs the agent
	// only the ability to name this relay when asking for a different one.
	if name, _, err := ReadString(rest); err == nil {
		out.Name = name
	}

	return &out, nil
}

// Reasons a relay refuses a bind.
//
// A byte rather than a string: this is a datagram on the wire, and the agent
// needs to branch on it rather than show it to anybody.
const (
	// RefusedTicketExpired means the ticket was valid and is now too old.
	// The agent should ask the coordinator for a fresh one and keep using
	// this relay.
	RefusedTicketExpired byte = 1
	// RefusedTicketInvalid means it did not verify at all — wrong key, wrong
	// pair, corrupt. A fresh ticket is still the right move; it is separate
	// because the two mean very different things in a log.
	RefusedTicketInvalid byte = 2
)
