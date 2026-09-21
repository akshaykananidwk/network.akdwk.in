package disco

import (
	"crypto/rand"
	"encoding/binary"
)

// Relay measurement message types. They continue the numbering in relay.go.
const (
	// TypeRelayProbe is an agent timing the round trip to a relay. Not sealed:
	// the relay has no key agreement with the agent, and a probe carries
	// nothing but a nonce.
	TypeRelayProbe MessageType = 0x14
	// TypeRelayProbeAck echoes that nonce back. It is deliberately the same
	// size as the probe: a relay answers these on a public port without
	// authenticating them, and a reply larger than its request is what turns a
	// service into a DDoS amplifier. One in, one out, same size.
	TypeRelayProbeAck MessageType = 0x15
	// TypeRelayRTT reports measured round trips to the coordinator, so relay
	// selection can be made on what the device actually observes rather than
	// on where someone guessed it is. Sealed.
	TypeRelayRTT MessageType = 0x16
)

// ProbeNonceLen is the size of a probe body.
//
// Random per probe, and checked on the way back: without it an agent behind a
// lossy link would happily time a reply to a probe it sent ten seconds ago and
// record a wildly optimistic RTT.
const ProbeNonceLen = 8

// RTTUnreachable is the sample value for a relay that did not answer.
//
// A number rather than an absent entry, because "I tried and got nothing" and
// "I have not tried" lead to different decisions: the first should push the
// coordinator towards another relay, the second should not.
const RTTUnreachable uint16 = 0xFFFF

// MaxRelaySamples bounds a report. A fleet larger than this is not a fleet
// one device needs to have an opinion about.
const MaxRelaySamples = 16

// NewProbeNonce returns a fresh nonce.
func NewProbeNonce() ([ProbeNonceLen]byte, error) {
	var nonce [ProbeNonceLen]byte
	_, err := rand.Read(nonce[:])

	return nonce, err
}

// DecodeProbeNonce reads a probe or probe-ack body.
func DecodeProbeNonce(b []byte) ([ProbeNonceLen]byte, error) {
	var nonce [ProbeNonceLen]byte
	if len(b) < ProbeNonceLen {
		return nonce, ErrMalformed
	}
	copy(nonce[:], b[:ProbeNonceLen])

	return nonce, nil
}

// RelaySample is one relay as one device measured it.
type RelaySample struct {
	// Name matches the relay's name in the coordinator's fleet list. Names
	// rather than indexes, because the two sides' lists are configured
	// separately and an index would silently mean different things.
	Name string
	// RTT in milliseconds, or RTTUnreachable.
	RTT uint16
}

// RelayRTT is a device's view of the relay fleet.
type RelayRTT struct {
	Samples []RelaySample
}

// Encode renders a report.
func (r *RelayRTT) Encode() ([]byte, error) {
	samples := r.Samples
	if len(samples) > MaxRelaySamples {
		samples = samples[:MaxRelaySamples]
	}

	b := []byte{byte(len(samples))}
	for _, sample := range samples {
		var err error
		if b, err = AppendString(b, sample.Name); err != nil {
			return nil, err
		}
		b = binary.BigEndian.AppendUint16(b, sample.RTT)
	}

	return b, nil
}

// DecodeRelayRTT parses one.
func DecodeRelayRTT(b []byte) (*RelayRTT, error) {
	if len(b) < 1 {
		return nil, ErrMalformed
	}

	count := int(b[0])
	if count > MaxRelaySamples {
		return nil, ErrMalformed
	}
	b = b[1:]

	out := &RelayRTT{Samples: make([]RelaySample, 0, count)}
	for i := 0; i < count; i++ {
		name, rest, err := ReadString(b)
		if err != nil {
			return nil, err
		}
		if len(rest) < 2 {
			return nil, ErrMalformed
		}
		out.Samples = append(out.Samples, RelaySample{
			Name: name,
			RTT:  binary.BigEndian.Uint16(rest[:2]),
		})
		b = rest[2:]
	}

	return out, nil
}
