// Package fallback carries AK Connect over a TLS connection on port 443 when
// UDP cannot get through.
//
// Why this exists, from the field: on one customer's office Wi-Fi a laptop's
// UDP announcements reached the coordinator every few seconds and not one
// reply ever came back. The same laptop worked on a phone hotspot. Outbound
// UDP passed; inbound did not. There is no setting we can ask a shopkeeper to
// change, and asking is the wrong answer anyway — Tailscale and ZeroTier both
// solve this by falling back to a TCP connection on 443, because a network
// where 443 does not work is a network where nothing works.
//
// The shape is deliberately the simplest thing that can carry what UDP was
// carrying:
//
//   - One WebSocket connection per agent, to a path an Apache in front of the
//     relay proxies through (mod_proxy_wstunnel).
//   - One WebSocket MESSAGE per datagram. WebSocket preserves message
//     boundaries where raw TCP does not, so nothing here needs a length
//     prefix and nothing can half-read a packet.
//   - One byte of framing, to say which of the two things this datagram is.
//
// Everything inside is already end-to-end encrypted — sealed disco messages
// and WireGuard packets — so this layer never needs to be trusted with
// anything, and never inspects a payload.
package fallback

import (
	"encoding/binary"
	"errors"
	"fmt"
)

// Kind is the first byte of every frame.
type Kind byte

const (
	// KindControl is a disco datagram for the coordinator: a Hello, a Ping, or
	// the coordinator's answer coming back. The relay proxies these to the
	// coordinator over ordinary UDP on its own machine, which is why the
	// coordinator needs no knowledge of this path at all.
	KindControl Kind = 0x01

	// KindData is relayed tunnel traffic — the same bytes that would have gone
	// to a relay data port over UDP.
	KindData Kind = 0x02

	// KindKeepalive carries nothing. It exists because a proxy, a load
	// balancer or a corporate middlebox will close a connection that has been
	// idle, and the customer's tunnel would go with it.
	KindKeepalive Kind = 0x03

	// KindHello is the first frame a client sends, naming itself so the relay
	// can attach it to the right sessions. It carries the client's public key.
	KindHello Kind = 0x04

	// KindReady is the relay's answer to KindHello. Until it arrives the
	// client does not consider the path usable.
	KindReady Kind = 0x05

	// KindBind carries the same RelayBind packet the agent would have sent to
	// the relay's control port over UDP, ticket and all. The relay verifies it
	// exactly as it verifies a UDP one — this path is not a way around the
	// ticket, it is a way around the customer's firewall.
	KindBind Kind = 0x06

	// KindBindAck answers it, echoing the peer the session is for.
	KindBindAck Kind = 0x07
)

// KeyLen is the length of the peer key that prefixes every data frame.
//
// A UDP-relayed pair is told apart by port: each side gets its own socket, so
// a packet's arrival port says which session it belongs to. There are no ports
// here — one connection carries everything — so the peer is named in the frame
// instead. Thirty-two bytes on a fifteen-hundred-byte packet is two per cent,
// which is a better trade than a channel table that both ends have to keep in
// step.
const KeyLen = 32

// EncodeBindAck confirms a fallback bind for one peer.
func EncodeBindAck(peer [32]byte) ([]byte, error) {
	return Encode(KindBindAck, peer[:])
}

// DecodeBindAck reads which peer was confirmed.
func DecodeBindAck(payload []byte) ([32]byte, error) {
	var peer [32]byte
	if len(payload) < KeyLen {
		return peer, ErrShort
	}

	copy(peer[:], payload[:KeyLen])

	return peer, nil
}

// EncodeData builds a data frame for, or from, one peer.
func EncodeData(peer [32]byte, tunnel []byte) ([]byte, error) {
	body := make([]byte, KeyLen+len(tunnel))
	copy(body, peer[:])
	copy(body[KeyLen:], tunnel)

	return Encode(KindData, body)
}

// DecodeData splits it again. The tunnel bytes alias the message.
func DecodeData(payload []byte) ([32]byte, []byte, error) {
	var peer [32]byte
	if len(payload) < KeyLen {
		return peer, nil, ErrShort
	}

	copy(peer[:], payload[:KeyLen])

	return peer, payload[KeyLen:], nil
}

// MaxFrame bounds a single message.
//
// A WireGuard packet on a 1420-byte MTU is at most about 1500 bytes; the
// margin covers headers and any future framing. It is enforced on both ends,
// because a read limit is the one thing standing between a hostile peer and
// this process's memory.
const MaxFrame = 2048

// Subprotocol is negotiated on the websocket handshake.
//
// It is how the relay tells our agent apart from anything else that finds the
// URL, and how the agent knows it reached a relay rather than a captive portal
// that answers every request with a login page. A portal cannot complete a
// websocket handshake naming a subprotocol it has never heard of.
const Subprotocol = "akconnect.fallback.v1"

// ErrShort means the message could not hold a frame.
var ErrShort = errors.New("fallback: frame is too short")

// Encode builds one frame.
func Encode(kind Kind, payload []byte) ([]byte, error) {
	if len(payload)+1 > MaxFrame {
		return nil, fmt.Errorf("fallback: payload of %d bytes exceeds the %d-byte limit",
			len(payload), MaxFrame-1)
	}

	out := make([]byte, 1+len(payload))
	out[0] = byte(kind)
	copy(out[1:], payload)

	return out, nil
}

// Decode splits a frame. The payload aliases the message, which is fine for
// every caller here: each one either forwards it immediately or copies it.
func Decode(msg []byte) (Kind, []byte, error) {
	if len(msg) < 1 {
		return 0, nil, ErrShort
	}

	return Kind(msg[0]), msg[1:], nil
}

// EncodeHello builds the opening frame: a client's public key.
func EncodeHello(publicKey [32]byte) ([]byte, error) {
	return Encode(KindHello, publicKey[:])
}

// DecodeHello reads it back.
func DecodeHello(payload []byte) ([32]byte, error) {
	var key [32]byte
	if len(payload) != len(key) {
		return key, fmt.Errorf("fallback: a hello carries a 32-byte key, not %d bytes", len(payload))
	}

	copy(key[:], payload)

	return key, nil
}

// EncodeReady builds the relay's answer, carrying the client's observed public
// address.
//
// The client has no other way to learn it on this path: a TCP connection
// through a proxy tells you nothing about how you look from outside, and the
// coordinator will see the relay's address rather than the client's. It is
// reported for the panel to display, never used as a path.
func EncodeReady(observed string) ([]byte, error) {
	if len(observed) > 64 {
		observed = observed[:64]
	}

	body := make([]byte, 2+len(observed))
	binary.BigEndian.PutUint16(body, uint16(len(observed)))
	copy(body[2:], observed)

	return Encode(KindReady, body)
}

// DecodeReady reads it back.
func DecodeReady(payload []byte) (string, error) {
	if len(payload) < 2 {
		return "", ErrShort
	}

	length := int(binary.BigEndian.Uint16(payload))
	if len(payload) < 2+length {
		return "", ErrShort
	}

	return string(payload[2 : 2+length]), nil
}
