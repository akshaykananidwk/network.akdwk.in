package disco

import (
	"encoding/binary"
	"net/netip"
)

// Hello is an agent announcing itself. It is sealed to the coordinator, so the
// device token inside it never crosses the wire in cleartext.
type Hello struct {
	DeviceUID string
	// Token is the device token the panel issued. The coordinator checks it so
	// that a device revoked in the panel stops being introduced to peers.
	Token string
	// LocalEndpoints are the addresses this agent can be reached on inside its
	// own network. A peer on the same LAN can use one of these directly and
	// skip the public path entirely (R2).
	LocalEndpoints []netip.AddrPort
}

// Encode renders a Hello.
func (h *Hello) Encode() ([]byte, error) {
	var b []byte
	var err error

	if b, err = AppendString(b, h.DeviceUID); err != nil {
		return nil, err
	}
	if b, err = AppendString(b, h.Token); err != nil {
		return nil, err
	}

	return appendEndpoints(b, h.LocalEndpoints)
}

// DecodeHello parses a Hello body.
func DecodeHello(b []byte) (*Hello, error) {
	var h Hello
	var err error

	if h.DeviceUID, b, err = ReadString(b); err != nil {
		return nil, err
	}
	if h.Token, b, err = ReadString(b); err != nil {
		return nil, err
	}
	if h.LocalEndpoints, _, err = readEndpoints(b); err != nil {
		return nil, err
	}

	return &h, nil
}

// HelloAck tells an agent how the coordinator sees it. The reflexive endpoint
// is the agent's public address as translated by whatever NAT is between them,
// and it is the thing the agent cannot discover for itself.
type HelloAck struct {
	Reflexive netip.AddrPort
}

// Encode renders a HelloAck.
func (a *HelloAck) Encode() ([]byte, error) {
	return appendEndpoints(nil, []netip.AddrPort{a.Reflexive})
}

// DecodeHelloAck parses a HelloAck body.
func DecodeHelloAck(b []byte) (*HelloAck, error) {
	eps, _, err := readEndpoints(b)
	if err != nil {
		return nil, err
	}
	if len(eps) == 0 {
		return nil, ErrMalformed
	}

	return &HelloAck{Reflexive: eps[0]}, nil
}

// PeerInfo is one peer's candidate addresses.
type PeerInfo struct {
	PublicKey [32]byte
	// Candidates are every address worth trying, best first: LAN addresses
	// before the reflexive one, because a peer on the same switch should never
	// be routed out to the internet and back (R2).
	Candidates []netip.AddrPort
}

// Peers is the coordinator's answer: who is online and where to find them.
type Peers struct {
	Peers []PeerInfo
}

// Encode renders a Peers message.
func (p *Peers) Encode() ([]byte, error) {
	b := AppendUint16(nil, uint16(len(p.Peers)))

	for _, peer := range p.Peers {
		b = append(b, peer.PublicKey[:]...)

		var err error
		if b, err = appendEndpoints(b, peer.Candidates); err != nil {
			return nil, err
		}
	}

	return b, nil
}

// DecodePeers parses a Peers body.
func DecodePeers(b []byte) (*Peers, error) {
	if len(b) < 2 {
		return nil, ErrMalformed
	}

	count := int(binary.BigEndian.Uint16(b[:2]))
	b = b[2:]

	out := &Peers{Peers: make([]PeerInfo, 0, count)}

	for i := 0; i < count; i++ {
		if len(b) < 32 {
			return nil, ErrMalformed
		}

		var info PeerInfo
		copy(info.PublicKey[:], b[:32])
		b = b[32:]

		var err error
		if info.Candidates, b, err = readEndpoints(b); err != nil {
			return nil, err
		}

		out.Peers = append(out.Peers, info)
	}

	return out, nil
}

// --------------------------------------------------------------- endpoints

// Endpoints are encoded as a count, then for each one a length-prefixed
// address and a port. Addresses go over the wire in their binary form, so a
// v4 address is 4 bytes and a v6 address is 16.
func appendEndpoints(b []byte, eps []netip.AddrPort) ([]byte, error) {
	b = AppendUint16(b, uint16(len(eps)))

	for _, ep := range eps {
		raw := ep.Addr().AsSlice()
		b = append(b, byte(len(raw)))
		b = append(b, raw...)
		b = AppendUint16(b, ep.Port())
	}

	return b, nil
}

func readEndpoints(b []byte) ([]netip.AddrPort, []byte, error) {
	if len(b) < 2 {
		return nil, nil, ErrMalformed
	}

	count := int(binary.BigEndian.Uint16(b[:2]))
	b = b[2:]

	out := make([]netip.AddrPort, 0, count)

	for i := 0; i < count; i++ {
		if len(b) < 1 {
			return nil, nil, ErrMalformed
		}

		size := int(b[0])
		b = b[1:]

		if size != 4 && size != 16 {
			return nil, nil, ErrMalformed
		}
		if len(b) < size+2 {
			return nil, nil, ErrMalformed
		}

		addr, ok := netip.AddrFromSlice(b[:size])
		if !ok {
			return nil, nil, ErrMalformed
		}
		b = b[size:]

		port := binary.BigEndian.Uint16(b[:2])
		b = b[2:]

		out = append(out, netip.AddrPortFrom(addr.Unmap(), port))
	}

	return out, b, nil
}
