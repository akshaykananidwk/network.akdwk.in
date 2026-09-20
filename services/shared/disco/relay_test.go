package disco

import (
	"bytes"
	"testing"
	"time"
)

func TestPairIDIsTheSameFromBothEnds(t *testing.T) {
	a := [32]byte{1, 2, 3}
	b := [32]byte{9, 8, 7}

	if PairID(a, b) != PairID(b, a) {
		t.Fatal("the two ends of one pair derived different ids; the relay could never match them")
	}

	c := [32]byte{1, 2, 4}
	if PairID(a, b) == PairID(a, c) {
		t.Fatal("different pairs collided")
	}
}

// The property the relay's safety rests on: a ticket cannot be altered, and
// cannot be minted by anyone without the secret.
func TestTicketCannotBeForgedOrAltered(t *testing.T) {
	secret := []byte("coordinator and relay share this")
	now := time.Now()

	ticket := &Ticket{
		TenantID:  42,
		ExpiresAt: now.Add(5 * time.Minute).Unix(),
		Self:      [32]byte{1},
		Peer:      [32]byte{2},
	}
	ticket.Sign(secret)

	if err := ticket.Verify(secret, now); err != nil {
		t.Fatalf("a freshly signed ticket did not verify: %v", err)
	}

	// Wrong secret.
	if err := ticket.Verify([]byte("some other secret entirely!!!!!!"), now); err == nil {
		t.Error("a ticket verified under the wrong secret")
	}

	// Tampering with any field must break it.
	for name, tamper := range map[string]func(*Ticket){
		"tenant": func(x *Ticket) { x.TenantID = 43 },
		"expiry": func(x *Ticket) { x.ExpiresAt += 3600 },
		"self":   func(x *Ticket) { x.Self = [32]byte{99} },
		"peer":   func(x *Ticket) { x.Peer = [32]byte{99} },
	} {
		t.Run("altered "+name, func(t *testing.T) {
			altered := *ticket
			tamper(&altered)

			if err := altered.Verify(secret, now); err == nil {
				t.Errorf("a ticket with an altered %s still verified", name)
			}
		})
	}
}

func TestTicketExpires(t *testing.T) {
	secret := []byte("s")
	past := time.Now().Add(-time.Hour)

	ticket := &Ticket{ExpiresAt: past.Unix(), Self: [32]byte{1}, Peer: [32]byte{2}}
	ticket.Sign(secret)

	if err := ticket.Verify(secret, time.Now()); err == nil {
		t.Fatal("an expired ticket verified")
	}
}

func TestTicketRoundTrip(t *testing.T) {
	secret := []byte("s")
	in := &Ticket{TenantID: 7, ExpiresAt: time.Now().Add(time.Minute).Unix(),
		Self: [32]byte{3}, Peer: [32]byte{4}}
	in.Sign(secret)

	encoded := in.Encode()
	if len(encoded) != TicketLen {
		t.Fatalf("encoded to %d bytes, want %d", len(encoded), TicketLen)
	}

	out, err := DecodeTicket(encoded)
	if err != nil {
		t.Fatal(err)
	}
	if err := out.Verify(secret, time.Now()); err != nil {
		t.Fatalf("a round-tripped ticket did not verify: %v", err)
	}
	if out.Pair() != in.Pair() {
		t.Error("pair id did not survive the round trip")
	}
}

func TestRelayOfferRoundTrip(t *testing.T) {
	in := &RelayOffer{
		Peer:     [32]byte{5},
		Endpoint: "relay.example.com:9000",
		Ticket:   bytes.Repeat([]byte{0xAB}, TicketLen),
	}

	encoded, err := in.Encode()
	if err != nil {
		t.Fatal(err)
	}

	out, err := DecodeRelayOffer(encoded)
	if err != nil {
		t.Fatal(err)
	}
	if out.Endpoint != in.Endpoint || out.Peer != in.Peer || !bytes.Equal(out.Ticket, in.Ticket) {
		t.Errorf("offer did not survive: %+v", out)
	}
}

func TestRejectsTruncatedRelayInput(t *testing.T) {
	if _, err := DecodeTicket([]byte{1, 2, 3}); err == nil {
		t.Error("a three-byte ticket decoded")
	}
	if _, err := DecodeRelayRequest([]byte{1}); err == nil {
		t.Error("a one-byte relay request decoded")
	}
	if _, err := DecodeRelayOffer([]byte{1}); err == nil {
		t.Error("a one-byte relay offer decoded")
	}
}
