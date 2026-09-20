package server

import (
	"context"
	"encoding/base64"
	"net/netip"

	"golang.org/x/crypto/curve25519"

	"github.com/akshaykananidwk/network.akdwk.in/services/coordinator/internal/panelapi"
	"github.com/akshaykananidwk/network.akdwk.in/services/coordinator/internal/registry"
	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
)

// handle processes one datagram.
//
// Every sealed message authenticates its own sender: the public key is in the
// cleartext header, and the body only opens if the sender holds the matching
// private key. So there is no session to hijack and no token to replay — a
// forged header simply fails to open.
func (s *Server) handle(ctx context.Context, pkt []byte, from netip.AddrPort) {
	header, rest, err := disco.ParseHeader(pkt)
	if err != nil {
		// Not ours. Silence is correct: this socket is on the public internet
		// and will be scanned.
		return
	}

	switch header.Type {
	case disco.TypeHello:
		s.handleHello(ctx, header, rest, from)
	case disco.TypePing:
		s.handlePing(header, rest, from)
	case disco.TypeRelayRequest:
		s.handleRelayRequest(header, rest, from)
	default:
		// Punch packets are peer to peer and never come here.
	}
}

func (s *Server) handleHello(ctx context.Context, header disco.Header, sealed []byte, from netip.AddrPort) {
	body, err := disco.Open(sealed, &header.Sender, &s.opts.PrivateKey)
	if err != nil {
		return
	}

	hello, err := disco.DecodeHello(body)
	if err != nil {
		return
	}

	publicKey := base64.StdEncoding.EncodeToString(header.Sender[:])

	// The panel decides, every time. Caching the answer would mean a revoked
	// device kept being introduced until the cache expired.
	result, err := s.opts.Panel.VerifyDevice(ctx, hello.DeviceUID, hello.Token, publicKey)
	if err != nil {
		s.opts.Logf("verifying %s failed: %v", hello.DeviceUID, err)
		return
	}

	if !result.Authorized {
		s.opts.Logf("refused %s: %s", hello.DeviceUID, result.Reason)
		// Forget it, so an already-present device that has just been revoked
		// stops being handed to its peers.
		s.reg.Forget(header.Sender)

		return
	}

	entry := &registry.Entry{
		PublicKey: header.Sender,
		DeviceUID: hello.DeviceUID,
		Reflexive: from,
		Local:     hello.LocalEndpoints,
		Peers:     peerSet(result),
		NetworkID: result.NetworkID,
		TenantID:  result.TenantID,
		Region:    result.Region,
	}
	s.reg.Upsert(entry)
	s.queueEndpoint(hello.DeviceUID, from, hello.LocalEndpoints)

	s.opts.Logf("hello from %s at %s (%d allowed peer(s))",
		hello.DeviceUID, from, len(entry.Peers))

	s.sendHelloAck(header.Sender, from)
	s.sendPeers(header.Sender, from)

	// The peers are told about this device too, so a device coming online is
	// discovered immediately rather than at the other end's next keepalive.
	// This is also what makes simultaneous open possible: both ends learn of
	// each other at nearly the same moment and punch towards each other.
	s.notifyPeersOf(header.Sender)
}

func (s *Server) handlePing(header disco.Header, sealed []byte, from netip.AddrPort) {
	if _, err := disco.Open(sealed, &header.Sender, &s.opts.PrivateKey); err != nil {
		return
	}

	// A ping refreshes the NAT mapping and the presence entry. A device the
	// coordinator has never seen must say hello properly first: accepting a
	// ping from an unknown key would let anyone keep an entry alive.
	previous, known := s.reg.Get(header.Sender)
	if !known {
		return
	}

	moved := previous.Reflexive != from

	if !s.reg.Touch(header.Sender, from) {
		return
	}

	s.sendHelloAck(header.Sender, from)

	// The peer list goes back on every ping, not only on hello.
	//
	// Addresses go stale: a laptop moves from 4G to wifi, a carrier reassigns,
	// a router is replaced. An agent whose candidate list was frozen at its
	// first hello would keep punching at addresses that no longer exist, and a
	// relayed pair would never find its way back to a direct path. Refreshing
	// here is what makes the silent upgrade possible at all.
	s.sendPeers(header.Sender, from)

	// And if this device itself moved, everyone allowed to see it needs the
	// new address — otherwise they are the ones left punching at a ghost.
	if moved {
		s.opts.Logf("%s moved to %s; telling its peers", previous.DeviceUID, from)
		s.notifyPeersOf(header.Sender)
	}
}

func (s *Server) sendHelloAck(to [32]byte, at netip.AddrPort) {
	ack := &disco.HelloAck{Reflexive: at}

	body, err := ack.Encode()
	if err != nil {
		return
	}

	pkt, err := s.sealTo(disco.TypeHelloAck, to, body)
	if err != nil {
		return
	}

	s.send(pkt, at)
}

// sendPeers tells one device about everyone it may reach.
func (s *Server) sendPeers(to [32]byte, at netip.AddrPort) {
	peers := s.reg.PeersOf(to)
	if len(peers) == 0 {
		return
	}

	msg := &disco.Peers{Peers: make([]disco.PeerInfo, 0, len(peers))}
	for _, peer := range peers {
		msg.Peers = append(msg.Peers, disco.PeerInfo{
			PublicKey: peer.PublicKey,
			// LAN addresses first: two devices on one switch should talk
			// across it, not out to the internet and back (R2).
			Candidates: append(append([]netip.AddrPort{}, peer.Local...), peer.Reflexive),
		})
	}

	body, err := msg.Encode()
	if err != nil {
		return
	}

	pkt, err := s.sealTo(disco.TypePeers, to, body)
	if err != nil {
		return
	}

	s.send(pkt, at)
}

// notifyPeersOf pushes the newcomer to everyone allowed to see it.
func (s *Server) notifyPeersOf(newcomer [32]byte) {
	for _, peer := range s.reg.PeersOf(newcomer) {
		s.sendPeers(peer.PublicKey, peer.Reflexive)
	}
}

func (s *Server) sealTo(t disco.MessageType, recipient [32]byte, body []byte) ([]byte, error) {
	sealed, err := disco.Seal(body, &recipient, &s.opts.PrivateKey)
	if err != nil {
		return nil, err
	}

	pkt := make([]byte, disco.HeaderLen, disco.HeaderLen+len(sealed))
	disco.WriteHeader(pkt, t, s.publicKey)

	return append(pkt, sealed...), nil
}

func peerSet(result *panelapi.VerifyResult) map[[32]byte]struct{} {
	out := make(map[[32]byte]struct{}, len(result.Peers))

	for _, p := range result.Peers {
		raw, err := base64.StdEncoding.DecodeString(p.PublicKey)
		if err != nil || len(raw) != 32 {
			continue
		}

		var key [32]byte
		copy(key[:], raw)
		out[key] = struct{}{}
	}

	return out
}

func publicOf(priv [32]byte) ([32]byte, error) {
	out, err := curve25519.X25519(priv[:], curve25519.Basepoint)
	if err != nil {
		return [32]byte{}, err
	}

	var pub [32]byte
	copy(pub[:], out)

	return pub, nil
}
