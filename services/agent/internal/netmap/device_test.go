package netmap

import (
	"net/netip"
	"os"
	"testing"

	"golang.zx2c4.com/wireguard/tun"
)

type recordingTun struct{ written [][]byte }

func (r *recordingTun) File() *os.File                         { return nil }
func (r *recordingTun) Read([][]byte, []int, int) (int, error) { return 0, os.ErrClosed }
func (r *recordingTun) MTU() (int, error)                      { return 1280, nil }
func (r *recordingTun) Name() (string, error)                  { return "rec", nil }
func (r *recordingTun) Events() <-chan tun.Event               { return nil }
func (r *recordingTun) Close() error                           { return nil }
func (r *recordingTun) BatchSize() int                         { return 1 }
func (r *recordingTun) Write(bufs [][]byte, offset int) (int, error) {
	for _, b := range bufs {
		r.written = append(r.written, append([]byte(nil), b[offset:]...))
	}

	return len(bufs), nil
}

// A peer that addresses the LAN by its real range — which no client is ever
// given a route to — is building packets by hand to go around the mapping,
// and with it the rules, which are compiled against the mapped range. Those
// packets are dropped; the mapped ones in the same batch still go through.
func TestAPacketToTheRealLANAddressIsDropped(t *testing.T) {
	inner := &recordingTun{}
	d := Wrap(inner)
	d.SetTable(mappedTable(t))

	viaMapped := build(protoTCP, "10.99.0.2", "10.201.5.50", tcpSegment())
	viaReal := build(protoTCP, "10.99.0.2", "192.168.1.50", tcpSegment())
	toPeer := build(protoTCP, "10.99.0.2", "10.99.0.3", tcpSegment())

	n, err := d.Write([][]byte{viaReal, viaMapped, toPeer}, 0)
	if err != nil || n != 3 {
		t.Fatalf("Write = %d, %v; want the whole batch accounted for", n, err)
	}
	if len(inner.written) != 2 {
		t.Fatalf("%d packets reached the system, want 2 (the real-address one dropped)", len(inner.written))
	}
	if dst := netip.AddrFrom4([4]byte(inner.written[0][offsetDst : offsetDst+4])); dst.String() != "192.168.1.50" {
		t.Fatalf("the mapped packet arrived for %s, want 192.168.1.50", dst)
	}
	if dst := netip.AddrFrom4([4]byte(inner.written[1][offsetDst : offsetDst+4])); dst.String() != "10.99.0.3" {
		t.Fatalf("the overlay packet arrived for %s", dst)
	}
	if d.Refused() != 1 {
		t.Fatalf("refused %d, want 1", d.Refused())
	}

	// No mapping in force: nothing is judged, everything passes.
	plain := &recordingTun{}
	Wrap(plain).Write([][]byte{build(protoTCP, "10.99.0.2", "192.168.1.50", tcpSegment())}, 0)
	if len(plain.written) != 1 {
		t.Fatal("a device that is not a gateway dropped a packet")
	}
}
