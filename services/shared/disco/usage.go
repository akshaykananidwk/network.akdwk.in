package disco

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/binary"
)

// TypeRelayUsage is a relay telling the coordinator how much it has carried,
// per tenant. Not sealed — the relay and the coordinator share a secret but no
// key agreement — and authenticated by a MAC over the whole body.
//
// This is the billing path. The panel's figure used to come from the agents'
// own heartbeats, which meant a customer running a modified agent could lower
// their bill by reporting less. A relay is our hardware; what it says it
// forwarded is the number that can be invoiced.
const TypeRelayUsage MessageType = 0x17

// MaxUsageTenants bounds one report. A relay serving more tenants than this in
// a single interval sends several reports rather than one oversized packet.
const MaxUsageTenants = 40

// TenantUsage is one tenant's cumulative total on one relay.
//
// Cumulative rather than a delta, so a lost report costs nothing: the next one
// carries the whole figure and the coordinator takes the difference. A delta
// that went missing would be money nobody could account for.
type TenantUsage struct {
	TenantID uint64
	Bytes    uint64
}

// RelayUsage is what one relay has carried since it started.
type RelayUsage struct {
	Relay   string
	Tenants []TenantUsage
}

// Encode renders a report with a MAC over the body.
func (r *RelayUsage) Encode(secret []byte) ([]byte, error) {
	tenants := r.Tenants
	if len(tenants) > MaxUsageTenants {
		tenants = tenants[:MaxUsageTenants]
	}

	body, err := AppendString(nil, r.Relay)
	if err != nil {
		return nil, err
	}

	body = append(body, byte(len(tenants)))
	for _, t := range tenants {
		body = binary.BigEndian.AppendUint64(body, t.TenantID)
		body = binary.BigEndian.AppendUint64(body, t.Bytes)
	}

	mac := hmac.New(sha256.New, secret)
	mac.Write(body)

	return append(body, mac.Sum(nil)...), nil
}

// DecodeRelayUsage parses a report without verifying it.
//
// The relay's name has to be read before the MAC can be checked, because the
// name is what selects the secret. Nothing is trusted until VerifyUsage says
// so — the name least of all.
func DecodeRelayUsage(b []byte) (*RelayUsage, error) {
	if len(b) < sha256.Size+3 {
		return nil, ErrMalformed
	}

	name, rest, err := ReadString(b[:len(b)-sha256.Size])
	if err != nil {
		return nil, err
	}
	if len(rest) < 1 {
		return nil, ErrMalformed
	}

	count := int(rest[0])
	if count > MaxUsageTenants {
		return nil, ErrMalformed
	}
	rest = rest[1:]

	if len(rest) < count*16 {
		return nil, ErrMalformed
	}

	out := &RelayUsage{Relay: name, Tenants: make([]TenantUsage, 0, count)}
	for i := 0; i < count; i++ {
		out.Tenants = append(out.Tenants, TenantUsage{
			TenantID: binary.BigEndian.Uint64(rest[i*16 : i*16+8]),
			Bytes:    binary.BigEndian.Uint64(rest[i*16+8 : i*16+16]),
		})
	}

	return out, nil
}

// VerifyUsage checks the MAC on an encoded report.
func VerifyUsage(b []byte, secret []byte) bool {
	if len(b) < sha256.Size {
		return false
	}

	body, want := b[:len(b)-sha256.Size], b[len(b)-sha256.Size:]

	mac := hmac.New(sha256.New, secret)
	mac.Write(body)

	// Constant time: a coordinator checking these from a public port must not
	// leak the expected MAC through how long the comparison takes.
	return hmac.Equal(mac.Sum(nil), want)
}
