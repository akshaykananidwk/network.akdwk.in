// Package dnsd answers names inside this network's zone, and nothing else.
//
// §18 asks for `nvr.hotel-abc.acme.internal` rather than an address the panel
// invented. That matters more since subnet mapping: the address a technician
// connects to is 10.128.0.50 rather than the 192.168.1.50 printed on the
// recorder, and expecting anyone to carry that in their head, per site, is how
// a feature goes unused.
//
// # What this deliberately is not
//
// It is not a resolver. It answers from a table the panel sent and **never
// forwards**: a name outside the zone gets REFUSED, not a lookup somewhere
// else. That is the whole safety argument. A forwarding resolver on a
// customer's machine is a thing that can be pointed at, misconfigured into the
// path of their ordinary browsing, or blamed when their bank's website is
// slow. This cannot be any of those, because there is no code in it that
// speaks to another server.
//
// The wire format is implemented here rather than taken from a library. It is
// a hundred lines for the one question type that matters, the project has no
// other third-party dependency to speak of, and a DNS library is a large
// amount of parsing exposed to the network for a feature that needs almost
// none of it.
package dnsd

import (
	"encoding/binary"
	"errors"
	"strings"
)

// Response codes, in the DNS sense.
const (
	rcodeSuccess  = 0
	rcodeFormErr  = 1
	rcodeNXDomain = 3
	rcodeRefused  = 5
)

// Query types we answer. Everything else in our zone gets an empty success,
// which is what "this name exists but has no record of that kind" means — a
// resolver asking for AAAA must not be told the name does not exist, or it
// will stop asking for A as well.
const (
	typeA    = 1
	typeAAAA = 28
	classIN  = 1
)

const (
	maxMessage = 512
	maxName    = 255
	maxLabel   = 63
)

var errMalformed = errors.New("dns: malformed message")

// query is the one question a message asks.
type query struct {
	id     uint16
	name   string
	qtype  uint16
	qclass uint16
	// recursionDesired is echoed back, because a stub resolver that does not
	// see its own RD bit reflected treats the answer as suspect.
	recursionDesired bool
	// questionRaw is the question section exactly as it arrived, so the reply
	// can repeat it byte for byte rather than re-encoding it.
	questionRaw []byte
}

// parse reads a query. Anything that is not a single-question standard query
// is rejected here rather than being half-understood further in.
func parse(msg []byte) (query, error) {
	if len(msg) < 12 {
		return query{}, errMalformed
	}

	var q query
	q.id = binary.BigEndian.Uint16(msg[0:2])
	flags := binary.BigEndian.Uint16(msg[2:4])
	q.recursionDesired = flags&0x0100 != 0

	if flags&0x8000 != 0 {
		// A response, not a question. Something is looping.
		return query{}, errMalformed
	}
	if (flags>>11)&0x0F != 0 {
		// Not a standard query: inverse queries and status requests are not
		// things we implement, and pretending to would be worse.
		return query{}, errMalformed
	}
	if binary.BigEndian.Uint16(msg[4:6]) != 1 {
		return query{}, errMalformed
	}

	name, offset, err := readName(msg, 12)
	if err != nil {
		return query{}, err
	}
	if offset+4 > len(msg) {
		return query{}, errMalformed
	}

	q.name = name
	q.qtype = binary.BigEndian.Uint16(msg[offset : offset+2])
	q.qclass = binary.BigEndian.Uint16(msg[offset+2 : offset+4])
	q.questionRaw = append([]byte(nil), msg[12:offset+4]...)

	return q, nil
}

// readName decodes a name into its lowercase dotted form.
//
// Compression pointers are refused rather than followed. A question section is
// the first thing in the message, so there is nothing before it for a pointer
// to point at: any pointer here is either a broken client or somebody probing
// for a decompression loop, and the correct answer to both is the same.
func readName(msg []byte, at int) (string, int, error) {
	var sb strings.Builder
	total := 0

	for {
		if at >= len(msg) {
			return "", 0, errMalformed
		}

		length := int(msg[at])
		if length == 0 {
			at++
			break
		}
		if length&0xC0 != 0 {
			return "", 0, errMalformed
		}
		if length > maxLabel {
			return "", 0, errMalformed
		}

		at++
		if at+length > len(msg) {
			return "", 0, errMalformed
		}

		total += length + 1
		if total > maxName {
			return "", 0, errMalformed
		}

		if sb.Len() > 0 {
			sb.WriteByte('.')
		}
		sb.Write(lower(msg[at : at+length]))
		at += length
	}

	return sb.String(), at, nil
}

// lower folds ASCII case, which is the only case folding DNS does.
func lower(b []byte) []byte {
	out := make([]byte, len(b))
	for i, c := range b {
		if c >= 'A' && c <= 'Z' {
			c += 'a' - 'A'
		}
		out[i] = c
	}

	return out
}
