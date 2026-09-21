package dnsd

import (
	"encoding/binary"
	"net/netip"
	"strings"
	"sync/atomic"
)

// Zone is the set of names this device answers for.
//
// Swapped wholesale when the configuration changes, the same way the filter
// table is: a device renamed in the panel should answer to its new name on the
// next query, not the next restart.
type Zone struct {
	// Suffix is the zone, without a leading or trailing dot, e.g.
	// "acme.internal". Empty means this device answers nothing at all.
	Suffix  string
	records map[string]netip.Addr
}

// NewZone compiles a record set.
func NewZone(suffix string) *Zone {
	return &Zone{
		Suffix:  strings.ToLower(strings.Trim(strings.TrimSpace(suffix), ".")),
		records: make(map[string]netip.Addr),
	}
}

// Add records one name. Names outside the zone are refused rather than stored:
// the panel is not supposed to send them, and quietly accepting one would make
// this device authoritative for a name it has no business answering.
func (z *Zone) Add(name, address string) bool {
	if z == nil || z.Suffix == "" {
		return false
	}

	name = strings.ToLower(strings.Trim(strings.TrimSpace(name), "."))
	if name == "" || !z.covers(name) {
		return false
	}

	addr, err := netip.ParseAddr(strings.TrimSpace(address))
	if err != nil || !addr.Is4() {
		return false
	}

	z.records[name] = addr.Unmap()

	return true
}

// Len is how many names this zone answers.
func (z *Zone) Len() int {
	if z == nil {
		return 0
	}

	return len(z.records)
}

// Names lists the zone's names, for the status file.
func (z *Zone) Names() []string {
	if z == nil {
		return nil
	}

	out := make([]string, 0, len(z.records))
	for name := range z.records {
		out = append(out, name)
	}

	return out
}

// covers reports whether a name belongs to this zone.
func (z *Zone) covers(name string) bool {
	if z == nil || z.Suffix == "" {
		return false
	}

	return name == z.Suffix || strings.HasSuffix(name, "."+z.Suffix)
}

// answer builds the reply to one query.
//
// Three outcomes, and the difference between them is the whole design:
//
//   - a name in the zone that we know: an A record
//   - a name in the zone that we do not: NXDOMAIN, authoritatively
//   - anything else: REFUSED, with no lookup attempted anywhere
//
// The third is what keeps this from being a resolver. A stub that receives
// REFUSED moves on to its next configured server, which is the customer's own,
// so a misdirected query costs a round trip rather than breaking their name
// resolution.
func (z *Zone) answer(q query) []byte {
	switch {
	case q.qclass != classIN:
		return reply(q, rcodeRefused, false, nil)
	case !z.covers(q.name):
		return reply(q, rcodeRefused, false, nil)
	}

	addr, known := z.records[q.name]
	if !known {
		// Authoritative: this device holds the whole zone, so a name it does
		// not have does not exist. Saying SERVFAIL instead would have stubs
		// retrying a question that is already settled.
		return reply(q, rcodeNXDomain, true, nil)
	}

	if q.qtype != typeA {
		// The name exists and has no record of that type. An AAAA query must
		// get an empty success, not NXDOMAIN — NXDOMAIN means "no name", and a
		// resolver told that will not go on to ask for A.
		return reply(q, rcodeSuccess, true, nil)
	}

	rr := make([]byte, 0, 16)
	rr = append(rr, 0xC0, 0x0C) // the question's name, by pointer
	rr = binary.BigEndian.AppendUint16(rr, typeA)
	rr = binary.BigEndian.AppendUint16(rr, classIN)
	// Half a minute. Long enough that a burst of lookups costs one query,
	// short enough that a device renamed or readdressed in the panel is
	// reachable by its new name within a poll rather than within an hour.
	rr = binary.BigEndian.AppendUint32(rr, 30)
	rr = binary.BigEndian.AppendUint16(rr, 4)
	v4 := addr.As4()
	rr = append(rr, v4[:]...)

	return reply(q, rcodeSuccess, true, rr)
}

// reply assembles a response message.
func reply(q query, rcode int, authoritative bool, answer []byte) []byte {
	var flags uint16 = 0x8000 // this is a response
	if authoritative {
		flags |= 0x0400
	}
	if q.recursionDesired {
		flags |= 0x0100
	}
	// Recursion is never available here. Advertising it would invite stubs to
	// send this server the rest of their traffic.
	flags |= uint16(rcode) & 0x000F

	count := uint16(0)
	if len(answer) > 0 {
		count = 1
	}

	msg := make([]byte, 0, 12+len(q.questionRaw)+len(answer))
	msg = binary.BigEndian.AppendUint16(msg, q.id)
	msg = binary.BigEndian.AppendUint16(msg, flags)
	msg = binary.BigEndian.AppendUint16(msg, 1) // question count
	msg = binary.BigEndian.AppendUint16(msg, count)
	msg = binary.BigEndian.AppendUint16(msg, 0) // authority
	msg = binary.BigEndian.AppendUint16(msg, 0) // additional
	msg = append(msg, q.questionRaw...)
	msg = append(msg, answer...)

	return msg
}

// refuseMalformed answers a message we could not parse.
//
// A reply is sent rather than silence, and it carries the id if one could be
// read, because a stub waiting out its timeout is indistinguishable from a
// server that has died — and this server not answering is a thing somebody
// would eventually blame the tunnel for.
func refuseMalformed(msg []byte) []byte {
	var id uint16
	if len(msg) >= 2 {
		id = binary.BigEndian.Uint16(msg[0:2])
	}

	out := make([]byte, 12)
	binary.BigEndian.PutUint16(out[0:2], id)
	binary.BigEndian.PutUint16(out[2:4], 0x8000|uint16(rcodeFormErr))

	return out
}

// counters are what the drill reads to prove the server did nothing it should
// not have.
type counters struct {
	answered  atomic.Uint64
	nxdomain  atomic.Uint64
	refused   atomic.Uint64
	malformed atomic.Uint64
}
