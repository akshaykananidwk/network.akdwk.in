package disco

import (
	"net/netip"
	"testing"
)

// The hub fields ride in a trailer, so both directions of version skew work:
// an old decoder stops before it, and a new decoder given an old body sees
// no hub at all.
func TestHubTrailersRoundTripAndAreOptional(t *testing.T) {
	h := &Hello{DeviceUID: "d1", Token: "t", LocalEndpoints: []netip.AddrPort{netip.MustParseAddrPort("192.168.1.5:5000")},
		Hub: HelloHub{Capable: true, Instance: [8]byte{1, 2, 3, 4, 5, 6, 7, 8}, TS: 1_800_000_000_123}}
	b, err := h.Encode()
	if err != nil {
		t.Fatal(err)
	}
	got, err := DecodeHello(b)
	if err != nil || got.Hub != h.Hub || got.DeviceUID != "d1" || len(got.LocalEndpoints) != 1 {
		t.Fatalf("hello: %+v %v", got, err)
	}

	legacy := &Hello{DeviceUID: "d1", Token: "t"}
	lb, _ := legacy.Encode()
	if got, err := DecodeHello(lb); err != nil || got.Hub.Capable {
		t.Fatal("a legacy Hello reads as hub-capable")
	}

	ack := &HelloAck{Reflexive: netip.MustParseAddrPort("203.0.113.9:40000"), Forwards: true, SID: 77}
	ab, _ := ack.Encode()
	if got, err := DecodeHelloAck(ab); err != nil || !got.Forwards || got.SID != 77 || got.Reflexive != ack.Reflexive {
		t.Fatalf("ack: %+v %v", got, err)
	}
	oldAck, _ := (&HelloAck{Reflexive: ack.Reflexive}).Encode()
	if got, _ := DecodeHelloAck(oldAck); got.Forwards {
		t.Fatal("an older coordinator's HelloAck reads as forwarding")
	}

	peers := &Peers{Peers: []PeerInfo{
		{PublicKey: [32]byte{1}, Candidates: []netip.AddrPort{netip.MustParseAddrPort("198.51.100.1:1")}, HubCapable: true},
		{PublicKey: [32]byte{2}},
	}}
	pb, _ := peers.Encode()
	gp, err := DecodePeers(pb)
	if err != nil || len(gp.Peers) != 2 || !gp.Peers[0].HubCapable || gp.Peers[1].HubCapable {
		t.Fatalf("peers: %+v %v", gp, err)
	}
	// An older coordinator's Peers has no trailer: nobody is hub-capable.
	if gp, err := DecodePeers(pb[:len(pb)-4]); err != nil || gp.Peers[0].HubCapable {
		t.Fatal("a Peers without the trailer marks a peer hub-capable")
	}
}
