// Package disco is the discovery protocol agents and the coordinator speak.
//
// Discovery shares the agent's WireGuard UDP socket. That is not a
// convenience: NAT traversal only works if the coordinator observes the same
// address translation that WireGuard's own traffic will use, and a second
// socket would get a second mapping and teach peers an endpoint that does not
// work.
//
// Sharing a socket means every packet must be attributable to one protocol or
// the other on sight. WireGuard's first four bytes are a little-endian message
// type of 1 to 4, so its first byte is always 0x01 to 0x04. Discovery packets
// start with Magic, whose first byte is 'A' (0x41), which those four values
// can never be.
package disco

import (
	"encoding/binary"
	"errors"
	"fmt"
)

// Magic prefixes every discovery packet.
var Magic = [4]byte{'A', 'K', 'C', '1'}

// MessageType identifies a discovery packet.
type MessageType uint8

const (
	// TypeHello is an agent announcing itself to the coordinator. Sealed.
	TypeHello MessageType = 0x01
	// TypeHelloAck tells an agent the address the coordinator saw it from,
	// which is its own reflexive endpoint. Sealed.
	TypeHelloAck MessageType = 0x02
	// TypePeers carries peer endpoints from the coordinator. Sealed.
	TypePeers MessageType = 0x03
	// TypePunch is sent agent to agent to open a NAT mapping. Not sealed:
	// it carries nothing worth hiding and must be cheap to send in volume.
	TypePunch MessageType = 0x04
	// TypePunchAck answers a punch, confirming a usable path exists.
	TypePunchAck MessageType = 0x05
	// TypePing refreshes a NAT mapping with the coordinator. Sealed.
	TypePing MessageType = 0x06
)

// HeaderLen is Magic plus the type byte plus the sender's public key.
const HeaderLen = 4 + 1 + 32

// MaxPacket bounds a discovery datagram. Anything larger is not ours.
const MaxPacket = 1200

var (
	// ErrNotDisco means the packet is not a discovery packet. Callers use it
	// to decide the bytes belong to WireGuard instead.
	ErrNotDisco = errors.New("not a discovery packet")
	// ErrMalformed means it claimed to be ours and was not usable.
	ErrMalformed = errors.New("malformed discovery packet")
)

// Header is the cleartext prefix every discovery packet carries.
type Header struct {
	Type MessageType
	// Sender is the public key of whoever sent this. For sealed packets it is
	// also the key the payload must be opened with, so it cannot be forged
	// without also breaking the seal.
	Sender [32]byte
}

// ParseHeader reads the cleartext prefix, returning the remaining bytes.
func ParseHeader(pkt []byte) (Header, []byte, error) {
	if len(pkt) < HeaderLen {
		return Header{}, nil, ErrNotDisco
	}
	if pkt[0] != Magic[0] || pkt[1] != Magic[1] || pkt[2] != Magic[2] || pkt[3] != Magic[3] {
		return Header{}, nil, ErrNotDisco
	}

	var h Header
	h.Type = MessageType(pkt[4])
	copy(h.Sender[:], pkt[5:HeaderLen])

	return h, pkt[HeaderLen:], nil
}

// IsDisco reports whether a packet is ours, cheaply, for the socket demux.
func IsDisco(pkt []byte) bool {
	return len(pkt) >= HeaderLen &&
		pkt[0] == Magic[0] && pkt[1] == Magic[1] && pkt[2] == Magic[2] && pkt[3] == Magic[3]
}

// WriteHeader puts the cleartext prefix into a buffer.
func WriteHeader(buf []byte, t MessageType, sender [32]byte) int {
	copy(buf[0:4], Magic[:])
	buf[4] = byte(t)
	copy(buf[5:HeaderLen], sender[:])

	return HeaderLen
}

// AppendUint16 and friends keep the body encoding explicit and symmetric,
// rather than reaching for reflection-based serialisation in a hot path that
// runs on every packet.
func AppendUint16(b []byte, v uint16) []byte {
	return binary.BigEndian.AppendUint16(b, v)
}

// AppendString writes a length-prefixed string.
func AppendString(b []byte, s string) ([]byte, error) {
	if len(s) > 0xffff {
		return nil, fmt.Errorf("%w: string of %d bytes", ErrMalformed, len(s))
	}

	b = AppendUint16(b, uint16(len(s)))

	return append(b, s...), nil
}

// ReadString reads a length-prefixed string, returning the rest.
func ReadString(b []byte) (string, []byte, error) {
	if len(b) < 2 {
		return "", nil, ErrMalformed
	}

	n := int(binary.BigEndian.Uint16(b[:2]))
	if len(b) < 2+n {
		return "", nil, ErrMalformed
	}

	return string(b[2 : 2+n]), b[2+n:], nil
}
