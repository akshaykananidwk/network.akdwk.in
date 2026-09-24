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

	// Hub is the agent's side of the PC hub (see package hub), carried in a
	// trailer an older coordinator ignores: it reads the endpoints and stops.
	Hub HelloHub
}

// HelloHub is what an agent says about the hub in its Hello.
type HelloHub struct {
	// Capable: this agent can send and receive hub frames.
	Capable bool
	// Instance is random per agent process. The same instance keeps the same
	// session id across its periodic re-Hellos; a new one gets a new session.
	Instance [8]byte
	// TS is the agent's clock in unix milliseconds, so a captured Hello
	// replayed later does not move the device's address.
	TS int64
}

// hubTrailer marks the hub trailer on Hello, HelloAck and Peers, followed by
// its version.
const (
	hubTrailer        = 'H'
	hubTrailerVersion = 1
)

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

	if b, err = appendEndpoints(b, h.LocalEndpoints); err != nil {
		return nil, err
	}
	if !h.Hub.Capable {
		return b, nil
	}

	b = append(b, hubTrailer, hubTrailerVersion, 1)
	b = append(b, h.Hub.Instance[:]...)

	return binary.BigEndian.AppendUint64(b, uint64(h.Hub.TS)), nil
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
	if h.LocalEndpoints, b, err = readEndpoints(b); err != nil {
		return nil, err
	}

	// The trailer, when there is one and it is a version this understands.
	if len(b) >= 3+8+8 && b[0] == hubTrailer && b[1] == hubTrailerVersion {
		h.Hub.Capable = b[2]&1 == 1
		copy(h.Hub.Instance[:], b[3:11])
		h.Hub.TS = int64(binary.BigEndian.Uint64(b[11:19]))
	}

	return &h, nil
}

// HelloAck tells an agent how the coordinator sees it. The reflexive endpoint
// is the agent's public address as translated by whatever NAT is between them,
// and it is the thing the agent cannot discover for itself.
type HelloAck struct {
	Reflexive netip.AddrPort

	// Forwards: this coordinator carries hub frames, for session SID. An
	// agent uses the hub only when it sees this; against an older
	// coordinator (no trailer) it punches and relays as it always did.
	Forwards bool
	SID      uint32
}

// Encode renders a HelloAck.
func (a *HelloAck) Encode() ([]byte, error) {
	b, err := appendEndpoints(nil, []netip.AddrPort{a.Reflexive})
	if err != nil || !a.Forwards {
		return b, err
	}

	b = append(b, hubTrailer, hubTrailerVersion, 1)

	return binary.BigEndian.AppendUint32(b, a.SID), nil
}

// DecodeHelloAck parses a HelloAck body.
func DecodeHelloAck(b []byte) (*HelloAck, error) {
	eps, rest, err := readEndpoints(b)
	if err != nil {
		return nil, err
	}
	if len(eps) == 0 {
		return nil, ErrMalformed
	}

	ack := &HelloAck{Reflexive: eps[0]}
	if len(rest) >= 3+4 && rest[0] == hubTrailer && rest[1] == hubTrailerVersion {
		ack.Forwards = rest[2]&1 == 1
		ack.SID = binary.BigEndian.Uint32(rest[3:7])
	}

	return ack, nil
}

// PeerInfo is one peer's candidate addresses.
type PeerInfo struct {
	PublicKey [32]byte
	// Candidates are every address worth trying, best first: LAN addresses
	// before the reflexive one, because a peer on the same switch should never
	// be routed out to the internet and back (R2).
	Candidates []netip.AddrPort

	// HubCapable: the peer can take hub frames. A pair uses the hub only
	// when both ends can; otherwise it punches and relays as before.
	HubCapable bool
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

	// One flags byte per peer, in the same order, after all of them: an older
	// agent reads its count of peers and stops before this.
	b = append(b, hubTrailer, hubTrailerVersion)
	for _, peer := range p.Peers {
		var flags byte
		if peer.HubCapable {
			flags = 1
		}
		b = append(b, flags)
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

	if len(b) >= 2+count && b[0] == hubTrailer && b[1] == hubTrailerVersion {
		for i := range out.Peers {
			out.Peers[i].HubCapable = b[2+i]&1 == 1
		}
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
