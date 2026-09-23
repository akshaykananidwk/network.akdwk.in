package acl

import (
	"os"
	"testing"

	"golang.zx2c4.com/wireguard/tun"
)

// fakeTun stands in for the kernel interface: it hands out a fixed batch of
// packets on Read and records what reaches it on Write.
type fakeTun struct {
	outgoing [][]byte
	written  [][]byte
}

func (f *fakeTun) File() *os.File           { return nil }
func (f *fakeTun) MTU() (int, error)        { return 1420, nil }
func (f *fakeTun) Name() (string, error)    { return "fake0", nil }
func (f *fakeTun) Events() <-chan tun.Event { return nil }
func (f *fakeTun) Close() error             { return nil }
func (f *fakeTun) BatchSize() int           { return 8 }

func (f *fakeTun) Read(bufs [][]byte, sizes []int, offset int) (int, error) {
	n := 0
	for i, p := range f.outgoing {
		if i >= len(bufs) {
			break
		}
		copy(bufs[i][offset:], p)
		sizes[i] = len(p)
		n++
	}
	f.outgoing = nil

	return n, nil
}

func (f *fakeTun) Write(bufs [][]byte, offset int) (int, error) {
	for _, b := range bufs {
		f.written = append(f.written, append([]byte(nil), b[offset:]...))
	}

	return len(bufs), nil
}

func restrictedDevice(inner tun.Device) *Device {
	d := Wrap(inner, nil)

	table := NewTable()
	table.AddSelf(self)
	table.Add(nvr, []Filter{{Action: "allow", Protocol: ProtoTCP, PortFrom: port(554), RuleID: 7}})
	d.SetTable(table)

	return d
}

// The survivors of a filtered batch have to be compacted forward. Leaving a
// hole and returning a smaller count hands wireguard-go a buffer it will
// encrypt and send as if it were a packet.
func TestFilteringABatchCompactsTheSurvivors(t *testing.T) {
	inner := &fakeTun{outgoing: [][]byte{
		ipv4(ipProtoTCP, self, nvr, 40000, 445),  // blocked
		ipv4(ipProtoTCP, self, nvr, 40001, 554),  // allowed
		ipv4(ipProtoTCP, self, nvr, 40002, 3389), // blocked
		ipv4(ipProtoTCP, self, nvr, 40003, 554),  // allowed
	}}
	d := restrictedDevice(inner)

	bufs := make([][]byte, 4)
	for i := range bufs {
		bufs[i] = make([]byte, 128)
	}
	sizes := make([]int, 4)

	n, err := d.Read(bufs, sizes, 0)
	if err != nil {
		t.Fatal(err)
	}
	if n != 2 {
		t.Fatalf("kept %d packets, want 2", n)
	}

	// Both survivors must be in the first two slots and must be the allowed
	// ones, not whatever happened to be left in the buffer.
	for i := 0; i < n; i++ {
		p, ok := parse(bufs[i][:sizes[i]])
		if !ok {
			t.Fatalf("packet %d is not readable after compaction", i)
		}
		if p.dstPort != 554 {
			t.Fatalf("packet %d has destination port %d, want 554", i, p.dstPort)
		}
	}

	if d.Dropped() != 2 {
		t.Fatalf("counted %d drops, want 2", d.Dropped())
	}
}

// Inbound is filtered too. A rule that only stopped what this device sends
// would leave a tampered peer free to send whatever it liked.
func TestInboundPacketsAreFilteredAsWell(t *testing.T) {
	inner := &fakeTun{}
	d := restrictedDevice(inner)

	// Open a conversation the rules permit, so there is a real flow for the
	// reply to belong to. Without this the reply would be an unsolicited
	// packet from port 554, which is exactly what must not be let through.
	outbound := &fakeTun{outgoing: [][]byte{
		ipv4Flags(ipProtoTCP, self, nvr, 40001, 554, tcpSYN),
	}}
	d.Device = outbound
	bufs := [][]byte{make([]byte, 128)}
	if n, _ := d.Read(bufs, make([]int, 1), 0); n != 1 {
		t.Fatal("precondition: the allowed request was blocked")
	}
	d.Device = inner

	_, err := d.Write([][]byte{
		ipv4(ipProtoTCP, nvr, self, 40000, 445), // peer probing a port it may not
		ipv4(ipProtoTCP, nvr, self, 554, 40001), // the reply to the request above
	}, 0)
	if err != nil {
		t.Fatal(err)
	}

	if len(inner.written) != 1 {
		t.Fatalf("delivered %d packets to the kernel, want 1", len(inner.written))
	}
	p, _ := parse(inner.written[0])
	if p.srcPort != 554 {
		t.Fatalf("the wrong packet was delivered (source port %d)", p.srcPort)
	}
}

// A batch where nothing survives must not reach the kernel at all, and must
// not be reported as an error either: the packets stopped where the
// configuration said they should.
func TestABatchWhereNothingSurvivesWritesNothing(t *testing.T) {
	inner := &fakeTun{}
	d := restrictedDevice(inner)

	n, err := d.Write([][]byte{
		ipv4(ipProtoTCP, nvr, self, 40000, 445),
		ipv4(ipProtoTCP, nvr, self, 40001, 3389),
	}, 0)
	if err != nil {
		t.Fatalf("an entirely filtered batch reported an error: %v", err)
	}
	if n != 0 {
		t.Fatalf("reported %d written, want 0", n)
	}
	if len(inner.written) != 0 {
		t.Fatal("a blocked packet reached the kernel")
	}
}

// A rule change has to take effect on the next packet, not the next
// reconnection: the requirement is ten seconds and a reconnection is not that.
func TestReplacingTheTableTakesEffectImmediately(t *testing.T) {
	inner := &fakeTun{}
	d := restrictedDevice(inner)

	blocked := ipv4Flags(ipProtoTCP, nvr, self, 40000, 3389, tcpSYN)
	if _, _ = d.Write([][]byte{blocked}, 0); len(inner.written) != 0 {
		t.Fatal("precondition: that port should start blocked")
	}

	relaxed := NewTable()
	relaxed.AddSelf(self)
	relaxed.Add(nvr, []Filter{{Action: "allow", Protocol: ProtoAny, RuleID: 1}})
	d.SetTable(relaxed)

	if _, _ = d.Write([][]byte{blocked}, 0); len(inner.written) != 1 {
		t.Fatal("the new rules did not apply to the very next packet")
	}
}
