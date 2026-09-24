package hub

import (
	"bytes"
	"crypto/rand"
	"math"
	"net/netip"
	"testing"

	"golang.org/x/crypto/curve25519"
)

func keypair(t *testing.T) (priv, pub [32]byte) {
	t.Helper()
	rand.Read(priv[:])
	p, err := curve25519.X25519(priv[:], curve25519.Basepoint)
	if err != nil {
		t.Fatal(err)
	}
	copy(pub[:], p)
	return
}

// Both ends derive the same keys without exchanging anything: the device from
// its private key and the coordinator's public, the coordinator the other way.
func TestBothEndsDeriveTheSameSessionKeys(t *testing.T) {
	devPriv, devPub := keypair(t)
	coordPriv, coordPub := keypair(t)

	a, err := Shared(&devPriv, &coordPub)
	if err != nil {
		t.Fatal(err)
	}
	b, err := Shared(&coordPriv, &devPub)
	if err != nil {
		t.Fatal(err)
	}
	upA, downA := SessionKeys(a, 7)
	upB, downB := SessionKeys(b, 7)
	if upA != upB || downA != downB {
		t.Fatal("the two ends derived different keys")
	}
	if upA == downA {
		t.Fatal("the two directions share a key")
	}
	if up8, _ := SessionKeys(a, 8); up8 == upA {
		t.Fatal("a new session id did not give new keys")
	}
}

func TestALowOrderKeyIsRefused(t *testing.T) {
	priv, _ := keypair(t)
	var zero [32]byte
	if _, err := Shared(&priv, &zero); err == nil {
		t.Fatal("a low-order public key gave a 'shared' secret everyone knows")
	}
}

func frame(payload []byte, typ byte, sid, ctr uint32, peer PeerID, key *[32]byte) []byte {
	buf := make([]byte, HeaderLen+len(payload))
	copy(buf[HeaderLen:], payload)
	WriteHeader(buf, typ, sid, ctr, peer, key)
	return buf
}

func TestAFrameRoundTripsAndAnyTamperingIsCaught(t *testing.T) {
	var key [32]byte
	rand.Read(key[:])
	peer := PeerID{1, 2, 3, 4, 5, 6, 7, 8}
	payload := bytes.Repeat([]byte{4, 0, 0, 0}, 360) // a 1440-byte transport message

	buf := frame(payload, TypeData, 42, 9, peer, &key)
	if !IsData(buf) || !Verify(buf, &key) {
		t.Fatal("a fresh frame does not verify")
	}
	f, err := Parse(buf)
	if err != nil || f.Type != TypeData || f.SID != 42 || f.Ctr != 9 || f.Peer != peer || !bytes.Equal(f.Payload, payload) {
		t.Fatalf("round trip: %+v %v", f, err)
	}

	// Every header byte and the covered payload bytes are protected.
	for _, i := range []int{4, 5, 8, 9, 12, 13, 20, HeaderLen, HeaderLen + 15} {
		bad := append([]byte(nil), buf...)
		bad[i] ^= 1
		if Verify(bad, &key) {
			t.Fatalf("flipping byte %d went unnoticed", i)
		}
	}
	// A truncated frame too: the length is covered.
	if Verify(buf[:len(buf)-1], &key) {
		t.Fatal("a truncated frame verified")
	}
	var other [32]byte
	rand.Read(other[:])
	if Verify(buf, &other) {
		t.Fatal("a frame verified under another session's key")
	}
}

// The coordinator rewrites a Data frame into a Deliver frame in place, in the
// same buffer: the payload must come out untouched.
func TestRewritingAFrameInPlaceKeepsThePayload(t *testing.T) {
	var up, down [32]byte
	rand.Read(up[:])
	rand.Read(down[:])
	payload := []byte("wireguard ciphertext, 32 bytes at least, honestly")
	buf := frame(payload, TypeData, 1, 1, PeerID{9}, &up)

	WriteHeader(buf, TypeDeliver, 2, 5, PeerID{7}, &down)
	f, err := Parse(buf)
	if err != nil || !Verify(buf, &down) || f.Type != TypeDeliver || f.SID != 2 || f.Peer != (PeerID{7}) || !bytes.Equal(f.Payload, payload) {
		t.Fatalf("rewritten frame: %+v %v", f, err)
	}
}

func TestParseRefusesWhatIsNotAHubFrame(t *testing.T) {
	var key [32]byte
	good := frame(make([]byte, 32), TypeData, 1, 1, PeerID{}, &key)
	for name, pkt := range map[string][]byte{
		"short":   good[:HeaderLen-1],
		"magic":   append([]byte("AKC2"), good[4:]...),
		"type":    append(append([]byte(nil), good[:4]...), append([]byte{TypeChallenge}, good[5:]...)...),
		"too big": make([]byte, MaxFrame+1),
	} {
		if _, err := Parse(pkt); err == nil {
			t.Fatalf("%s: parsed", name)
		}
	}
}

func FuzzParseNeverPanics(f *testing.F) {
	var key [32]byte
	f.Add(frame(make([]byte, 40), TypeDeliver, 3, 3, PeerID{1}, &key))
	f.Add([]byte("AKC1"))
	f.Fuzz(func(t *testing.T, pkt []byte) {
		if fr, err := Parse(pkt); err == nil {
			if len(fr.Payload) != len(pkt)-HeaderLen {
				t.Fatal("payload length wrong")
			}
		}
		Verify(pkt, &key)
		ParseChallenge(pkt, &key)
		ParsePeerProbe(pkt, &key)
		SIDOf(pkt)
	})
}

func TestTheReplayWindow(t *testing.T) {
	var r Replay
	steps := []struct {
		ctr  uint32
		want bool
	}{
		{0, false},            // counters start at 1
		{1, true}, {1, false}, // no replay
		{3, true}, {2, true}, // reordering inside the window is fine
		{2, false},
		{5000, true},
		{5000 - replayBits + 1, true}, // the oldest still inside
		{5000 - replayBits, false},    // just outside
		{4999, true},
		{math.MaxUint32, true}, // must not loop for ever
		{math.MaxUint32, false},
	}
	for i, s := range steps {
		if got := r.Accept(s.ctr); got != s.want {
			t.Fatalf("step %d: Accept(%d) = %v, want %v", i, s.ctr, got, s.want)
		}
	}

	// Resumed from a persisted high-water mark: nothing at or below it passes.
	var q Replay
	q.Resume(100)
	if q.Accept(100) || q.Accept(50) || !q.Accept(101) {
		t.Fatal("a resumed window accepted a counter it may have seen, or refused a new one")
	}
}

func TestPseudoEndpointsAreStatelessAndDistinct(t *testing.T) {
	a, b := PeerID{1, 2, 3, 4, 5, 6, 7, 8}, PeerID{1, 2, 3, 4, 5, 6, 7, 9}
	pa, pb := Pseudo(a), Pseudo(b)
	if pa.Addr() == pb.Addr() {
		t.Fatal("two peers share a pseudo address: wireguard-go would rate-limit them together")
	}
	if got, ok := FromPseudo(pa); !ok || got != a {
		t.Fatal("a pseudo endpoint does not name its peer")
	}
	if pa.String() != "[fd61:6b63:102:304:506:708::]:1" {
		t.Fatalf("pseudo endpoint changed: %s", pa)
	}
	reparsed, err := netip.ParseAddrPort(pa.String())
	if err != nil || reparsed != pa {
		t.Fatal("a pseudo endpoint does not survive UAPI's text round trip")
	}
	for _, s := range []string{"192.0.2.1:1", "[fd61:6b63:102:304:506:708::]:2", "[fd61:6b64::]:1", "[fd61:6b63:102:304:506:708::1]:1"} {
		if _, ok := FromPseudo(netip.MustParseAddrPort(s)); ok {
			t.Fatalf("%s taken for a pseudo endpoint", s)
		}
	}
}

func TestHubFramesFitThePathAtTheLargestAllowedMTU(t *testing.T) {
	// IPv6 + UDP + WireGuard + hub + PPPoE must fit 1500.
	if MaxOverlayMTU+WireGuardOverhead+HeaderLen+40+8+8 > 1500 {
		t.Fatal("MaxOverlayMTU does not fit a 1500-byte path")
	}
	if 1280+WireGuardOverhead+HeaderLen+8+40 != 1389 {
		t.Fatal("the default MTU arithmetic changed")
	}
	if MaxOverlayMTU+WireGuardOverhead+HeaderLen > MaxFrame {
		t.Fatal("MaxFrame is smaller than a frame at MaxOverlayMTU")
	}
}

func TestChallengeAndResponse(t *testing.T) {
	var up, down [32]byte
	rand.Read(up[:])
	rand.Read(down[:])
	nonce := [8]byte{1, 2, 3}

	buf := make([]byte, ChallengeLen)
	WriteChallenge(buf, TypeChallenge, 11, nonce, &down)
	if typ, sid, n, ok := ParseChallenge(buf, &down); !ok || typ != TypeChallenge || sid != 11 || n != nonce {
		t.Fatal("challenge did not round trip")
	}
	if _, _, _, ok := ParseChallenge(buf, &up); ok {
		t.Fatal("a challenge verified under the other direction's key")
	}

	unk := make([]byte, UnknownLen)
	WriteUnknown(unk, 11)
	if sid, _ := SIDOf(unk); sid != 11 || len(unk) >= HeaderLen {
		t.Fatal("HubUnknown is not small, or lost its sid")
	}
}

func TestPeerProbesProveOnlyWhatTheyShould(t *testing.T) {
	aPriv, aPub := keypair(t)
	bPriv, bPub := keypair(t)
	sa, _ := Shared(&aPriv, &bPub)
	sb, _ := Shared(&bPriv, &aPub)
	ka, kb := PairKey(sa), PairKey(sb)
	if ka != kb {
		t.Fatal("the two agents derived different pair keys")
	}

	challenge := [8]byte{9, 9, 9}
	ping := make([]byte, PeerProbeLen)
	WritePeerProbe(ping, TypePeerPing, aPub, challenge, &ka)
	if sender, ok := PeerProbeSender(ping); !ok || sender != aPub {
		t.Fatal("sender not readable")
	}
	typ, got, ok := ParsePeerProbe(ping, &kb)
	if !ok || typ != TypePeerPing || got != challenge {
		t.Fatal("B could not verify A's ping")
	}

	// A ping cannot be turned into a pong: the type is covered.
	forged := append([]byte(nil), ping...)
	forged[4] = TypePeerPong
	if _, _, ok := ParsePeerProbe(forged, &kb); ok {
		t.Fatal("a ping relabelled as a pong verified")
	}

	// A third party's key proves nothing.
	cPriv, _ := keypair(t)
	sc, _ := Shared(&cPriv, &bPub)
	kc := PairKey(sc)
	if _, _, ok := ParsePeerProbe(ping, &kc); ok {
		t.Fatal("a probe verified under another pair's key")
	}
}
