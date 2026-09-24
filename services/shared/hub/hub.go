// Package hub is the wire format of the PC hub: every device's permanent
// session to the server, carrying WireGuard packets between devices that have
// not found a direct path — and that path's silent upgrade and fall back.
//
// The coordinator forwards; it never decrypts. What passes through it is the
// pair's own WireGuard ciphertext, framed so the coordinator can tell which
// session sent it and which peer it is for, and can prove both:
//
//	off len
//	0   4   "AKC1"   (a disco packet, so the agent's socket already sifts it out)
//	4   1   type     0x20 Data (agent → coordinator), 0x21 Deliver (coordinator → agent)
//	5   4   sid      the session: the sender's on Data, the receiver's on Deliver
//	9   4   ctr      per session and direction, big-endian, from 1; a replay window
//	13  8   peer     first 8 bytes of the OTHER device's key: destination, or source
//	21  8   tag      BLAKE2s-128, truncated to 8, keyed per session and direction
//	29  …   the WireGuard message, unmodified
//
// The tag covers the header, the payload's length and its first 16 bytes —
// the WireGuard type, receiver index and counter. The rest is authenticated
// end to end by WireGuard itself, and a MAC over 1.4 KB per packet would
// double the coordinator's work for nothing.
//
// Keys come from the X25519 secret the device and the coordinator already
// share (the one disco's sealed Hello uses), expanded with HKDF and salted
// with the session id. Nothing secret needs storing to re-derive them after a
// restart: the coordinator's private key, the device's public key and the sid.
package hub

import (
	"crypto/sha256"
	"crypto/subtle"
	"encoding/binary"
	"errors"
	"io"
	"net/netip"

	"golang.org/x/crypto/blake2s"
	"golang.org/x/crypto/curve25519"
	"golang.org/x/crypto/hkdf"
)

// Frame types. 0x20-0x25 are the hub's own; 0x09 and 0x0A are the direct-path
// probe between two agents. None collides with a disco type (disco/wire.go,
// relay.go, rtt.go, usage.go use 0x01-0x08 and 0x10-0x18).
const (
	TypeData      byte = 0x20
	TypeDeliver   byte = 0x21
	TypeChallenge byte = 0x22
	TypeResponse  byte = 0x23
	TypeUnknown   byte = 0x24
	TypeNeedHello byte = 0x25

	TypePeerPing byte = 0x09
	TypePeerPong byte = 0x0A
)

var magic = [4]byte{'A', 'K', 'C', '1'}

const (
	// HeaderLen is the frame header in front of a WireGuard message.
	HeaderLen = 29
	tagLen    = 8

	// MaxFrame bounds a hub frame on the wire and every buffer that reads
	// one. WireGuard's largest transport message at the largest overlay MTU
	// this package allows is well inside it.
	MaxFrame = 2048

	// MaxOverlayMTU is the largest overlay MTU at which a hub frame still
	// fits a 1500-byte path over IPv6 and PPPoE:
	// 1500 − 40 (IPv6) − 8 (UDP) − 32 (WireGuard) − 29 (hub) − 8 (PPPoE) = 1383.
	MaxOverlayMTU = 1380

	// WireGuardOverhead is what WireGuard adds to an inner packet.
	WireGuardOverhead = 32

	// ChallengeLen is the size of a HubChallenge and of a HubResponse.
	ChallengeLen = 4 + 1 + 4 + 8 + tagLen
	// UnknownLen is the size of a HubUnknown.
	UnknownLen = 4 + 1 + 4

	// PeerProbeLen is the size of a PeerPing and of a PeerPong.
	PeerProbeLen = 4 + 1 + 32 + 8 + 16
)

// PeerID names a device inside a frame: the first 8 bytes of its public key.
type PeerID [8]byte

// IDOf is the PeerID of a public key.
func IDOf(pub [32]byte) PeerID {
	var id PeerID
	copy(id[:], pub[:8])

	return id
}

// Frame is a decoded hub data frame. Payload aliases the buffer it came from.
type Frame struct {
	Type    byte
	SID     uint32
	Ctr     uint32
	Peer    PeerID
	Payload []byte
}

var (
	errShort = errors.New("hub: frame too short")
	errMagic = errors.New("hub: not a hub frame")
	errType  = errors.New("hub: not a data frame")
	errBig   = errors.New("hub: frame too large")
)

// IsData reports whether pkt is a Data or Deliver frame, cheaply, without
// checking anything else. For the socket's first sift.
func IsData(pkt []byte) bool {
	return len(pkt) >= HeaderLen && pkt[0] == magic[0] && pkt[1] == magic[1] &&
		pkt[2] == magic[2] && pkt[3] == magic[3] && (pkt[4] == TypeData || pkt[4] == TypeDeliver)
}

// Parse decodes a Data or Deliver frame. It does NOT verify the tag.
func Parse(pkt []byte) (Frame, error) {
	if len(pkt) < HeaderLen {
		return Frame{}, errShort
	}
	if len(pkt) > MaxFrame {
		return Frame{}, errBig
	}
	if pkt[0] != magic[0] || pkt[1] != magic[1] || pkt[2] != magic[2] || pkt[3] != magic[3] {
		return Frame{}, errMagic
	}
	if pkt[4] != TypeData && pkt[4] != TypeDeliver {
		return Frame{}, errType
	}

	var f Frame
	f.Type = pkt[4]
	f.SID = binary.BigEndian.Uint32(pkt[5:9])
	f.Ctr = binary.BigEndian.Uint32(pkt[9:13])
	copy(f.Peer[:], pkt[13:21])
	f.Payload = pkt[HeaderLen:]

	return f, nil
}

// WriteHeader fills the first HeaderLen bytes of buf, whose payload must
// already be in place at buf[HeaderLen:], and tags it with key. It is how both
// the agent frames a packet and the coordinator rewrites one in place for
// delivery.
func WriteHeader(buf []byte, typ byte, sid, ctr uint32, peer PeerID, key *[32]byte) {
	copy(buf[0:4], magic[:])
	buf[4] = typ
	binary.BigEndian.PutUint32(buf[5:9], sid)
	binary.BigEndian.PutUint32(buf[9:13], ctr)
	copy(buf[13:21], peer[:])

	tag := dataTag(buf[:21], buf[HeaderLen:], key)
	copy(buf[21:29], tag[:])
}

// Verify checks a frame's tag under key, in constant time.
func Verify(pkt []byte, key *[32]byte) bool {
	if len(pkt) < HeaderLen {
		return false
	}
	want := dataTag(pkt[:21], pkt[HeaderLen:], key)

	return subtle.ConstantTimeCompare(want[:], pkt[21:29]) == 1
}

func dataTag(header, payload []byte, key *[32]byte) [tagLen]byte {
	mac, _ := blake2s.New128(key[:]) // only errors on a key longer than 32 bytes

	mac.Write(header)
	var length [2]byte
	binary.BigEndian.PutUint16(length[:], uint16(len(payload)))
	mac.Write(length[:])
	if len(payload) > 16 {
		payload = payload[:16]
	}
	mac.Write(payload)

	var tag [tagLen]byte
	copy(tag[:], mac.Sum(nil))

	return tag
}

// ---------------------------------------------------------------- keys

// Shared is the X25519 secret between a private and a public key. It refuses
// a low-order public key, whose output would be all zeros and known to anyone.
func Shared(priv, pub *[32]byte) ([32]byte, error) {
	out, err := curve25519.X25519(priv[:], pub[:])
	if err != nil {
		return [32]byte{}, err
	}

	var s [32]byte
	copy(s[:], out)

	return s, nil
}

// SessionKeys derives the two directions' tag keys for session sid.
// Up authenticates Data (agent → coordinator), down authenticates Deliver
// and Challenge (coordinator → agent).
func SessionKeys(shared [32]byte, sid uint32) (up, down [32]byte) {
	var salt [4]byte
	binary.BigEndian.PutUint32(salt[:], sid)

	expand := func(info string) [32]byte {
		var k [32]byte
		io.ReadFull(hkdf.New(sha256.New, shared[:], salt[:], []byte(info)), k[:])

		return k
	}

	return expand("akc-hub-up"), expand("akc-hub-down")
}

// PairKey is what two agents tag their direct-path probes with: derived from
// their own X25519 secret, which only those two can compute.
func PairKey(shared [32]byte) [32]byte {
	var k [32]byte
	io.ReadFull(hkdf.New(sha256.New, shared[:], nil, []byte("akc-pair")), k[:])

	return k
}

// ---------------------------------------------------------------- replay

// replayBits is the window: a counter up to this far behind the newest one
// seen is still accepted once. WireGuard's own is 8192; the coordinator only
// has to tolerate the reordering one UDP path produces.
const replayBits = 2048

// Replay is a sliding-window replay filter, like WireGuard's. Not safe for
// concurrent use: the coordinator's single reader owns each one.
type Replay struct {
	newest uint32
	bits   [replayBits / 64]uint64
}

// Accept reports whether ctr is new, and records it. 0 is never valid:
// counters start at 1.
func (r *Replay) Accept(ctr uint32) bool {
	if ctr == 0 {
		return false
	}

	if ctr > r.newest {
		shift := ctr - r.newest
		if shift >= replayBits {
			r.bits = [replayBits / 64]uint64{}
		} else {
			// By count, not by counter value: `i <= ctr` never ends when ctr
			// is the largest uint32.
			for k := uint32(1); k <= shift; k++ {
				r.clear(r.newest + k)
			}
		}
		r.newest = ctr
		r.set(ctr)

		return true
	}

	if r.newest-ctr >= replayBits {
		return false
	}
	if r.has(ctr) {
		return false
	}
	r.set(ctr)

	return true
}

// Newest is the highest counter accepted, for persisting a high-water mark.
func (r *Replay) Newest() uint32 { return r.newest }

// Resume starts a fresh window whose accepted counters must be above high.
func (r *Replay) Resume(high uint32) {
	*r = Replay{newest: high}
	for i := range r.bits {
		r.bits[i] = ^uint64(0)
	}
}

func (r *Replay) index(ctr uint32) (int, uint64) {
	bit := ctr % replayBits

	return int(bit / 64), 1 << (bit % 64)
}

func (r *Replay) set(ctr uint32)      { w, m := r.index(ctr); r.bits[w] |= m }
func (r *Replay) clear(ctr uint32)    { w, m := r.index(ctr); r.bits[w] &^= m }
func (r *Replay) has(ctr uint32) bool { w, m := r.index(ctr); return r.bits[w]&m != 0 }

// ---------------------------------------------------------------- pseudo endpoints

// pseudoPrefix is the ULA /32 this package reserves for WireGuard's view of a
// peer: fd61:6b63::/32 ("ak", "c"). Nothing is ever sent to it; the agent's
// socket layer translates it, per packet, into the hub or a direct address.
var pseudoPrefix = [4]byte{0xfd, 0x61, 0x6b, 0x63}

// PseudoPort is the port of every pseudo endpoint.
const PseudoPort = 1

// Pseudo is the fixed endpoint WireGuard is given for a peer. Stateless in
// both directions, so no table has to be filled before the first packet, and
// distinct per peer, so wireguard-go's per-endpoint rate limiter and cookies
// never lump two peers together.
func Pseudo(peer PeerID) netip.AddrPort {
	var a [16]byte
	copy(a[0:4], pseudoPrefix[:])
	copy(a[4:12], peer[:])

	return netip.AddrPortFrom(netip.AddrFrom16(a), PseudoPort)
}

// FromPseudo returns the peer a pseudo endpoint stands for.
func FromPseudo(ap netip.AddrPort) (PeerID, bool) {
	addr := ap.Addr()
	if !addr.Is6() || addr.Is4In6() || ap.Port() != PseudoPort {
		return PeerID{}, false
	}
	a := addr.As16()
	if a[0] != pseudoPrefix[0] || a[1] != pseudoPrefix[1] || a[2] != pseudoPrefix[2] || a[3] != pseudoPrefix[3] {
		return PeerID{}, false
	}
	for _, b := range a[12:] {
		if b != 0 {
			return PeerID{}, false
		}
	}

	var id PeerID
	copy(id[:], a[4:12])

	return id, true
}

// IsPseudo reports whether an address is in the pseudo prefix at all.
func IsPseudo(addr netip.Addr) bool {
	if !addr.Is6() || addr.Is4In6() {
		return false
	}
	a := addr.As16()

	return a[0] == pseudoPrefix[0] && a[1] == pseudoPrefix[1] && a[2] == pseudoPrefix[2] && a[3] == pseudoPrefix[3]
}

// ---------------------------------------------------------------- challenge

// WriteChallenge and WriteResponse fill a ChallengeLen buffer: the coordinator
// proving a new address can receive (Challenge, tagged down), and the agent
// answering from it (Response, tagged up). Return-routability: an address the
// coordinator has not seen answer is never sent more than a small multiple of
// what it sent, so a stolen frame cannot make the hub a reflector.
func WriteChallenge(buf []byte, typ byte, sid uint32, nonce [8]byte, key *[32]byte) {
	copy(buf[0:4], magic[:])
	buf[4] = typ
	binary.BigEndian.PutUint32(buf[5:9], sid)
	copy(buf[9:17], nonce[:])
	tag := shortTag(buf[:17], key)
	copy(buf[17:25], tag[:])
}

// ParseChallenge decodes and verifies a Challenge or Response.
func ParseChallenge(pkt []byte, key *[32]byte) (typ byte, sid uint32, nonce [8]byte, ok bool) {
	if len(pkt) != ChallengeLen || pkt[0] != magic[0] || pkt[1] != magic[1] || pkt[2] != magic[2] || pkt[3] != magic[3] {
		return 0, 0, nonce, false
	}
	if pkt[4] != TypeChallenge && pkt[4] != TypeResponse {
		return 0, 0, nonce, false
	}
	want := shortTag(pkt[:17], key)
	if subtle.ConstantTimeCompare(want[:], pkt[17:25]) != 1 {
		return 0, 0, nonce, false
	}
	copy(nonce[:], pkt[9:17])

	return pkt[4], binary.BigEndian.Uint32(pkt[5:9]), nonce, true
}

// SIDOf reads the session id of a Challenge, Response or Unknown without
// verifying anything — to pick the key to verify with.
func SIDOf(pkt []byte) (uint32, bool) {
	if len(pkt) < 9 {
		return 0, false
	}

	return binary.BigEndian.Uint32(pkt[5:9]), true
}

// WriteUnknown fills a UnknownLen buffer: the coordinator's answer to a Data
// frame from a session it does not have. Unauthenticated on purpose — it is
// smaller than what provoked it, and all it can make an agent do is send the
// coordinator a sealed Ping.
func WriteUnknown(buf []byte, sid uint32) {
	copy(buf[0:4], magic[:])
	buf[4] = TypeUnknown
	binary.BigEndian.PutUint32(buf[5:9], sid)
}

func shortTag(msg []byte, key *[32]byte) [tagLen]byte {
	mac, _ := blake2s.New128(key[:])
	mac.Write(msg)

	var tag [tagLen]byte
	copy(tag[:], mac.Sum(nil))

	return tag
}

// ---------------------------------------------------------------- direct path probes

// WritePeerProbe fills a PeerProbeLen buffer: a PeerPing, or the PeerPong that
// echoes its challenge. Only a pong to this side's own ping proves the path
// works in the direction that matters — outbound. Inbound traffic proves only
// the other direction, and a path kept on that basis black-holes a one-way
// failure.
func WritePeerProbe(buf []byte, typ byte, sender [32]byte, challenge [8]byte, pairKey *[32]byte) {
	copy(buf[0:4], magic[:])
	buf[4] = typ
	copy(buf[5:37], sender[:])
	copy(buf[37:45], challenge[:])

	mac, _ := blake2s.New128(pairKey[:])
	mac.Write(buf[:45])
	copy(buf[45:61], mac.Sum(nil))
}

// PeerProbeSender reads the sender key of a probe, unverified — to find the
// pair key to verify it with.
func PeerProbeSender(pkt []byte) ([32]byte, bool) {
	var k [32]byte
	if len(pkt) != PeerProbeLen || pkt[0] != magic[0] || pkt[1] != magic[1] || pkt[2] != magic[2] || pkt[3] != magic[3] ||
		(pkt[4] != TypePeerPing && pkt[4] != TypePeerPong) {
		return k, false
	}
	copy(k[:], pkt[5:37])

	return k, true
}

// ParsePeerProbe verifies a probe under pairKey.
func ParsePeerProbe(pkt []byte, pairKey *[32]byte) (typ byte, challenge [8]byte, ok bool) {
	if _, valid := PeerProbeSender(pkt); !valid {
		return 0, challenge, false
	}

	mac, _ := blake2s.New128(pairKey[:])
	mac.Write(pkt[:45])
	if subtle.ConstantTimeCompare(mac.Sum(nil), pkt[45:61]) != 1 {
		return 0, challenge, false
	}
	copy(challenge[:], pkt[37:45])

	return pkt[4], challenge, true
}
