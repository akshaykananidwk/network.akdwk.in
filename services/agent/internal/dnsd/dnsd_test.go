package dnsd

import (
	"encoding/binary"
	"net/netip"
	"strings"
	"testing"
)

// ask builds a query message the way a stub resolver would.
func ask(name string, qtype uint16) []byte {
	msg := make([]byte, 12)
	binary.BigEndian.PutUint16(msg[0:2], 0x1234)
	binary.BigEndian.PutUint16(msg[2:4], 0x0100) // standard query, recursion desired
	binary.BigEndian.PutUint16(msg[4:6], 1)

	for _, label := range strings.Split(strings.Trim(name, "."), ".") {
		msg = append(msg, byte(len(label)))
		msg = append(msg, label...)
	}
	msg = append(msg, 0)
	msg = binary.BigEndian.AppendUint16(msg, qtype)
	msg = binary.BigEndian.AppendUint16(msg, classIN)

	return msg
}

func rcodeOf(t *testing.T, reply []byte) int {
	t.Helper()
	if len(reply) < 12 {
		t.Fatalf("reply is %d bytes", len(reply))
	}
	if reply[2]&0x80 == 0 {
		t.Fatal("reply does not have the response bit set")
	}

	return int(reply[3] & 0x0F)
}

func answerAddr(t *testing.T, reply []byte) netip.Addr {
	t.Helper()
	if binary.BigEndian.Uint16(reply[6:8]) != 1 {
		t.Fatalf("expected one answer, got %d", binary.BigEndian.Uint16(reply[6:8]))
	}

	// Skip the question, then the record's name pointer, type, class and TTL.
	at := 12
	for at < len(reply) && reply[at] != 0 {
		at += int(reply[at]) + 1
	}
	at += 5 // the zero byte, qtype and qclass
	at += 2 + 2 + 2 + 4

	if at+2 > len(reply) || binary.BigEndian.Uint16(reply[at:at+2]) != 4 {
		t.Fatal("answer is not a 4-byte A record")
	}
	at += 2

	return netip.AddrFrom4([4]byte(reply[at : at+4]))
}

func zone(t *testing.T) *Zone {
	t.Helper()
	z := NewZone("acme.internal")

	for name, addr := range map[string]string{
		"laptop.acme.internal":         "10.99.0.2",
		"reception.acme.internal":      "10.99.0.3",
		"nvr.hotel-abc.acme.internal":  "10.128.0.50",
		"till.hotel-abc.acme.internal": "10.128.0.60",
	} {
		if !z.Add(name, addr) {
			t.Fatalf("could not add %s", name)
		}
	}

	return z
}

// §18: a device by name.
func TestADeviceResolvesToItsOverlayAddress(t *testing.T) {
	z := zone(t)

	q, err := parse(ask("laptop.acme.internal", typeA))
	if err != nil {
		t.Fatalf("parse: %v", err)
	}

	reply := z.answer(q)
	if got := rcodeOf(t, reply); got != rcodeSuccess {
		t.Fatalf("rcode %d, want success", got)
	}
	if addr := answerAddr(t, reply); addr.String() != "10.99.0.2" {
		t.Fatalf("answered %s, want 10.99.0.2", addr)
	}
}

// §18 and §16: a machine behind a gateway resolves to its **mapped** address.
// Answering with the real one would send a technician to whatever sits at that
// address on their own LAN, which is the failure subnet mapping exists to stop.
func TestALanMachineResolvesToItsMappedAddress(t *testing.T) {
	z := zone(t)

	q, _ := parse(ask("nvr.hotel-abc.acme.internal", typeA))
	reply := z.answer(q)

	if got := rcodeOf(t, reply); got != rcodeSuccess {
		t.Fatalf("rcode %d, want success", got)
	}
	if addr := answerAddr(t, reply); addr.String() != "10.128.0.50" {
		t.Fatalf("answered %s, want the mapped address 10.128.0.50", addr)
	}
}

// The guarantee the whole design rests on. A name outside the zone is REFUSED,
// and there is no code path that would look it up anywhere.
func TestNamesOutsideTheZoneAreRefused(t *testing.T) {
	z := zone(t)

	for _, name := range []string{
		"www.google.com",
		"bank.example.com",
		"internal",
		"acme.internal.evil.com",
		"notacme.internal",
	} {
		q, err := parse(ask(name, typeA))
		if err != nil {
			t.Fatalf("parse %s: %v", name, err)
		}

		reply := z.answer(q)
		if got := rcodeOf(t, reply); got != rcodeRefused {
			t.Fatalf("%s got rcode %d, want REFUSED", name, got)
		}
		if binary.BigEndian.Uint16(reply[6:8]) != 0 {
			t.Fatalf("%s came back with an answer section", name)
		}
	}
}

// A name in the zone we do not hold does not exist. This device has the whole
// zone, so NXDOMAIN is the true answer and SERVFAIL would have stubs retrying
// a question that is already settled.
func TestUnknownNamesInTheZoneAreNXDOMAIN(t *testing.T) {
	z := zone(t)

	q, _ := parse(ask("printer.acme.internal", typeA))
	reply := z.answer(q)

	if got := rcodeOf(t, reply); got != rcodeNXDomain {
		t.Fatalf("rcode %d, want NXDOMAIN", got)
	}
	if reply[2]&0x04 == 0 {
		t.Fatal("the NXDOMAIN is not marked authoritative")
	}
}

// An AAAA query for a name that exists must be an empty success, not
// NXDOMAIN. A resolver told a name does not exist will not go on to ask for A,
// so getting this wrong makes every name unresolvable on a dual-stack host.
func TestAAAAForAKnownNameIsEmptySuccess(t *testing.T) {
	z := zone(t)

	q, _ := parse(ask("laptop.acme.internal", typeAAAA))
	reply := z.answer(q)

	if got := rcodeOf(t, reply); got != rcodeSuccess {
		t.Fatalf("rcode %d, want success", got)
	}
	if binary.BigEndian.Uint16(reply[6:8]) != 0 {
		t.Fatal("an AAAA query was answered with a record")
	}
}

// Case is folded, because DNS does and a technician's shell may not.
func TestNamesAreCaseInsensitive(t *testing.T) {
	z := zone(t)

	q, _ := parse(ask("NVR.Hotel-ABC.ACME.Internal", typeA))
	if addr := answerAddr(t, z.answer(q)); addr.String() != "10.128.0.50" {
		t.Fatalf("answered %s", addr)
	}
}

// The panel is not supposed to send a record outside the zone, and if it does
// the agent must not become authoritative for it.
func TestRecordsOutsideTheZoneAreNotAccepted(t *testing.T) {
	z := NewZone("acme.internal")

	if z.Add("www.google.com", "10.99.0.9") {
		t.Fatal("a record outside the zone was accepted")
	}
	if z.Add("acme.internal.evil.com", "10.99.0.9") {
		t.Fatal("a suffix-shaped impostor was accepted")
	}
	if z.Len() != 0 {
		t.Fatalf("zone holds %d records, want 0", z.Len())
	}
}

// A device with no zone configured answers nothing at all, rather than
// answering everything.
func TestAnEmptyZoneRefusesEverything(t *testing.T) {
	z := NewZone("")

	q, _ := parse(ask("laptop.acme.internal", typeA))
	if got := rcodeOf(t, z.answer(q)); got != rcodeRefused {
		t.Fatalf("rcode %d, want REFUSED", got)
	}
}

// Malformed input is answered, not crashed on and not ignored. A stub waiting
// out its timeout is indistinguishable from a server that has died.
func TestMalformedMessages(t *testing.T) {
	cases := [][]byte{
		nil,
		{},
		{0x12},
		make([]byte, 11),
		// A compression pointer in the question section, which has nothing
		// before it to point at.
		append(make([]byte, 12), 0xC0, 0x0C),
		// A response, not a query: something is looping.
		func() []byte { m := ask("laptop.acme.internal", typeA); m[2] |= 0x80; return m }(),
		// Two questions, which we do not implement.
		func() []byte { m := ask("laptop.acme.internal", typeA); m[5] = 2; return m }(),
		// A label longer than the message.
		{0, 0, 1, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0x40, 'x'},
	}

	for i, msg := range cases {
		if _, err := parse(msg); err == nil {
			t.Fatalf("case %d parsed when it should not have", i)
		}

		reply := refuseMalformed(msg)
		if len(reply) != 12 {
			t.Fatalf("case %d: reply is %d bytes", i, len(reply))
		}
		if reply[3]&0x0F != rcodeFormErr {
			t.Fatalf("case %d: rcode %d, want FORMERR", i, reply[3]&0x0F)
		}
	}
}

// A name that would overflow the 255-byte limit is refused rather than
// assembled.
func TestOverlongNamesAreRefused(t *testing.T) {
	long := strings.Repeat("verylonglabelindeed.", 20) + "acme.internal"

	if _, err := parse(ask(long, typeA)); err == nil {
		t.Fatal("an overlong name parsed")
	}
}

// The reply must echo the question byte for byte. A stub that cannot match the
// answer to its question discards it.
func TestTheQuestionIsEchoed(t *testing.T) {
	z := zone(t)
	msg := ask("laptop.acme.internal", typeA)

	q, _ := parse(msg)
	reply := z.answer(q)

	if string(reply[12:12+len(q.questionRaw)]) != string(msg[12:]) {
		t.Fatal("the question was not echoed exactly")
	}
	if binary.BigEndian.Uint16(reply[0:2]) != 0x1234 {
		t.Fatal("the transaction id was not echoed")
	}
}

// systemd-resolved owns 127.0.0.53 and 127.0.0.54, and refuses to be pointed
// at either: `resolvectl dns <link> 127.0.0.54` answers "Invalid DNS server
// address". They are bindable whenever its stub listener is off, which is the
// configuration of every machine running dnsmasq or Pi-hole beside it — so
// "it was free" is not a reason to take one. The agent would bind it, be
// refused by resolvectl, and silently fall back to the hosts file.
func TestResolvedsOwnAddressesAreNeverOffered(t *testing.T) {
	for _, reserved := range []string{"127.0.0.53", "127.0.0.54"} {
		for _, candidate := range candidates {
			if candidate == reserved {
				t.Fatalf("%s is systemd-resolved's own address and must not be a candidate", reserved)
			}
		}
	}

	if len(candidates) < 2 {
		t.Fatalf("one candidate is not a fallback; got %v", candidates)
	}
}
