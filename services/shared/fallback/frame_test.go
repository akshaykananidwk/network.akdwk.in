package fallback

import (
	"bytes"
	"strings"
	"testing"
)

func TestAFrameSurvivesTheRoundTrip(t *testing.T) {
	for _, kind := range []Kind{KindControl, KindData, KindKeepalive} {
		payload := []byte("a sealed datagram")

		msg, err := Encode(kind, payload)
		if err != nil {
			t.Fatal(err)
		}

		gotKind, gotPayload, err := Decode(msg)
		if err != nil {
			t.Fatal(err)
		}
		if gotKind != kind {
			t.Errorf("kind = %#x, want %#x", gotKind, kind)
		}
		if !bytes.Equal(gotPayload, payload) {
			t.Errorf("payload = %q, want %q", gotPayload, payload)
		}
	}
}

// An empty payload is a real frame: a keepalive is exactly that.
func TestAnEmptyPayloadIsAValidFrame(t *testing.T) {
	msg, err := Encode(KindKeepalive, nil)
	if err != nil {
		t.Fatal(err)
	}

	kind, payload, err := Decode(msg)
	if err != nil {
		t.Fatal(err)
	}
	if kind != KindKeepalive || len(payload) != 0 {
		t.Fatalf("kind = %#x, payload = %d bytes", kind, len(payload))
	}
}

// An empty message is not. Refusing it here is what stops a zero-length read
// from being treated as a control frame with no body.
func TestAnEmptyMessageIsRefused(t *testing.T) {
	if _, _, err := Decode(nil); err == nil {
		t.Fatal("an empty message was accepted as a frame")
	}
}

// The limit is enforced on the way in as well as the way out. It is the one
// thing between a hostile peer and this process's memory.
func TestAnOversizedPayloadIsRefused(t *testing.T) {
	if _, err := Encode(KindData, make([]byte, MaxFrame)); err == nil {
		t.Fatal("a payload larger than the frame limit was encoded")
	}

	// And the largest legal one still works, so the limit is not off by one in
	// the direction that drops real packets.
	if _, err := Encode(KindData, make([]byte, MaxFrame-1)); err != nil {
		t.Fatalf("the largest legal payload was refused: %v", err)
	}
}

// A WireGuard packet on this product's MTU has to fit, or the fallback carries
// control traffic and silently drops the tunnel.
func TestARealWireGuardPacketFits(t *testing.T) {
	// 1420-byte MTU plus WireGuard's own 32-byte overhead, rounded up.
	if _, err := Encode(KindData, make([]byte, 1500)); err != nil {
		t.Fatalf("a full-size tunnel packet does not fit: %v", err)
	}
}

func TestHelloCarriesTheKey(t *testing.T) {
	var key [32]byte
	copy(key[:], "this is a thirty-two byte key!!!")

	msg, err := EncodeHello(key)
	if err != nil {
		t.Fatal(err)
	}

	kind, payload, err := Decode(msg)
	if err != nil || kind != KindHello {
		t.Fatalf("kind = %#x, err = %v", kind, err)
	}

	got, err := DecodeHello(payload)
	if err != nil {
		t.Fatal(err)
	}
	if got != key {
		t.Error("the key did not survive the round trip")
	}
}

// A hello of the wrong length is refused rather than padded or truncated into
// somebody else's key.
func TestAMalformedHelloIsRefused(t *testing.T) {
	for _, n := range []int{0, 31, 33, 64} {
		if _, err := DecodeHello(make([]byte, n)); err == nil {
			t.Errorf("a %d-byte hello was accepted as a 32-byte key", n)
		}
	}
}

func TestReadyCarriesTheObservedAddress(t *testing.T) {
	msg, err := EncodeReady("150.129.167.50:41234")
	if err != nil {
		t.Fatal(err)
	}

	_, payload, err := Decode(msg)
	if err != nil {
		t.Fatal(err)
	}

	got, err := DecodeReady(payload)
	if err != nil {
		t.Fatal(err)
	}
	if got != "150.129.167.50:41234" {
		t.Errorf("observed address = %q", got)
	}
}

// A truncated ready frame is an error, not a silently empty address.
func TestATruncatedReadyIsRefused(t *testing.T) {
	msg, _ := EncodeReady("150.129.167.50:41234")
	_, payload, _ := Decode(msg)

	for _, cut := range []int{0, 1, 5} {
		if _, err := DecodeReady(payload[:cut]); err == nil {
			t.Errorf("a %d-byte ready frame was accepted", cut)
		}
	}
}

// A long address cannot make the frame refuse to encode.
func TestAnAbsurdObservedAddressIsTrimmed(t *testing.T) {
	if _, err := EncodeReady(strings.Repeat("x", 500)); err != nil {
		t.Fatalf("a long observed address broke the frame: %v", err)
	}
}

// A data frame names its peer, because one connection carries every session
// this client has and there are no ports here to tell them apart.
func TestADataFrameNamesItsPeer(t *testing.T) {
	var peer [32]byte
	copy(peer[:], "peer-key-exactly-thirty-two-byte")
	tunnel := bytes.Repeat([]byte{0xAB}, 1420)

	msg, err := EncodeData(peer, tunnel)
	if err != nil {
		t.Fatal(err)
	}

	kind, payload, err := Decode(msg)
	if err != nil || kind != KindData {
		t.Fatalf("kind = %#x, err = %v", kind, err)
	}

	gotPeer, gotTunnel, err := DecodeData(payload)
	if err != nil {
		t.Fatal(err)
	}
	if gotPeer != peer {
		t.Error("the peer key did not survive")
	}
	if !bytes.Equal(gotTunnel, tunnel) {
		t.Error("the tunnel bytes did not survive")
	}
}

// A data frame too short to hold a key is refused rather than read past.
func TestATruncatedDataFrameIsRefused(t *testing.T) {
	for _, n := range []int{0, 1, 31} {
		if _, _, err := DecodeData(make([]byte, n)); err == nil {
			t.Errorf("a %d-byte data payload was accepted", n)
		}
	}
}
