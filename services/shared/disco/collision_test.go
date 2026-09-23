package disco

import "testing"

// Every message type is a different number.
//
// TypeRelayBindRefused was introduced at 0x14 in 1.9.7-dev.6, which
// TypeRelayProbe already held. Nothing broke, entirely by luck of direction —
// agents send probes and never receive them, relays send refusals and never
// receive them — so each side's switch only ever saw one meaning of the
// number. The next message type either direction gained would have landed on
// it and produced a fault nobody could have read from a log.
//
// A protocol's numbering is exactly the kind of thing a person cannot check
// by eye across four files, so it is checked here instead — and because the
// values are map keys, a duplicate does not fail this test, it fails to
// COMPILE. The package will not build with two types on one number.
func TestEveryMessageTypeIsDistinct(t *testing.T) {
	named := map[MessageType]string{}

	for value, name := range map[MessageType]string{
		TypeHello:            "TypeHello",
		TypeHelloAck:         "TypeHelloAck",
		TypePing:             "TypePing",
		TypePeers:            "TypePeers",
		TypePunch:            "TypePunch",
		TypePunchAck:         "TypePunchAck",
		TypeRelayRequest:     "TypeRelayRequest",
		TypeRelayOffer:       "TypeRelayOffer",
		TypeRelayBind:        "TypeRelayBind",
		TypeRelayBindAck:     "TypeRelayBindAck",
		TypeRelayBindRefused: "TypeRelayBindRefused",
		TypeRelayProbe:       "TypeRelayProbe",
		TypeRelayProbeAck:    "TypeRelayProbeAck",
		TypeRelayRTT:         "TypeRelayRTT",
		TypeRelayUsage:       "TypeRelayUsage",
	} {
		if existing, clash := named[value]; clash {
			t.Fatalf("%s and %s are both 0x%02x", existing, name, byte(value))
		}

		named[value] = name
	}
}
