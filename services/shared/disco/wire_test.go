package disco

import (
	"bytes"
	"crypto/rand"
	"net/netip"
	"testing"

	"golang.org/x/crypto/nacl/box"
)

// The property the shared socket depends on: a WireGuard packet must never be
// mistaken for ours, and ours must never be mistaken for WireGuard's.
func TestDiscoIsDistinguishableFromWireGuard(t *testing.T) {
	// WireGuard message types are a little-endian uint32 of 1 to 4.
	for msgType := byte(1); msgType <= 4; msgType++ {
		pkt := make([]byte, 148)
		pkt[0] = msgType

		if IsDisco(pkt) {
			t.Fatalf("a WireGuard type-%d packet was taken for a discovery packet", msgType)
		}
	}

	ours := make([]byte, HeaderLen)
	WriteHeader(ours, TypeHello, [32]byte{})

	if !IsDisco(ours) {
		t.Fatal("our own packet was not recognised")
	}
	if ours[0] >= 1 && ours[0] <= 4 {
		t.Fatalf("magic starts with 0x%02x, which collides with WireGuard", ours[0])
	}
}

func TestHelloRoundTrip(t *testing.T) {
	in := &Hello{
		DeviceUID: "dev_0123456789abcdef",
		Token:     "a-secret-device-token",
		LocalEndpoints: []netip.AddrPort{
			netip.MustParseAddrPort("192.168.1.50:51820"),
			netip.MustParseAddrPort("[fd00::1]:51820"),
		},
	}

	encoded, err := in.Encode()
	if err != nil {
		t.Fatal(err)
	}

	out, err := DecodeHello(encoded)
	if err != nil {
		t.Fatal(err)
	}

	if out.DeviceUID != in.DeviceUID || out.Token != in.Token {
		t.Errorf("identity did not survive: %+v", out)
	}
	if len(out.LocalEndpoints) != 2 {
		t.Fatalf("got %d endpoints, want 2", len(out.LocalEndpoints))
	}
	for i := range in.LocalEndpoints {
		if out.LocalEndpoints[i] != in.LocalEndpoints[i] {
			t.Errorf("endpoint %d = %v, want %v", i, out.LocalEndpoints[i], in.LocalEndpoints[i])
		}
	}
}

func TestPeersRoundTrip(t *testing.T) {
	var key [32]byte
	copy(key[:], bytes.Repeat([]byte{7}, 32))

	in := &Peers{Peers: []PeerInfo{{
		PublicKey:  key,
		Candidates: []netip.AddrPort{netip.MustParseAddrPort("10.0.0.5:51820")},
	}}}

	encoded, err := in.Encode()
	if err != nil {
		t.Fatal(err)
	}

	out, err := DecodePeers(encoded)
	if err != nil {
		t.Fatal(err)
	}

	if len(out.Peers) != 1 || out.Peers[0].PublicKey != key {
		t.Fatalf("peer did not survive: %+v", out)
	}
	if out.Peers[0].Candidates[0].String() != "10.0.0.5:51820" {
		t.Errorf("candidate = %v", out.Peers[0].Candidates[0])
	}
}

// A sealed packet must be unopenable by anyone but its recipient, and
// unforgeable by anyone but its sender. This is what lets the coordinator
// trust the public key in a cleartext header.
func TestSealAuthenticatesTheSender(t *testing.T) {
	agentPub, agentPriv, err := box.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	coordPub, coordPriv, err := box.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	_, attackerPriv, err := box.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}

	sealed, err := Seal([]byte("the device token"), coordPub, agentPriv)
	if err != nil {
		t.Fatal(err)
	}

	body, err := Open(sealed, agentPub, coordPriv)
	if err != nil {
		t.Fatalf("the legitimate recipient could not open it: %v", err)
	}
	if string(body) != "the device token" {
		t.Errorf("body = %q", body)
	}

	// Someone else's private key must not open it.
	if _, err := Open(sealed, agentPub, attackerPriv); err == nil {
		t.Error("a third party opened a packet addressed to the coordinator")
	}

	// Claiming to be the agent must not work without the agent's key.
	forged, err := Seal([]byte("the device token"), coordPub, attackerPriv)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := Open(forged, agentPub, coordPriv); err == nil {
		t.Error("a packet forged under the agent's public key opened")
	}
}

func TestRejectsTruncatedInput(t *testing.T) {
	if _, _, err := ParseHeader([]byte{'A', 'K'}); err == nil {
		t.Error("a two-byte packet parsed as a header")
	}
	if _, err := DecodeHello([]byte{0, 5}); err == nil {
		t.Error("a truncated Hello decoded")
	}
	if _, err := DecodePeers([]byte{0, 9}); err == nil {
		t.Error("a Peers claiming nine peers with no bodies decoded")
	}
	if _, err := Open([]byte{1, 2, 3}, &[32]byte{}, &[32]byte{}); err == nil {
		t.Error("a three-byte sealed packet opened")
	}
}
