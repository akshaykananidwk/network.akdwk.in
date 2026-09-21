package acl

import (
	"sync/atomic"

	"golang.zx2c4.com/wireguard/tun"
)

// Device wraps the tunnel interface and drops packets the rules forbid.
//
// It sits between wireguard-go and the operating system, which is the one
// place on this machine where packets are plaintext *and* attributable to a
// peer. Filtering below it would mean filtering ciphertext; filtering above it
// would mean asking the operating system's firewall to hold rules that belong
// to us, on two platforms, with no way to know whether it did.
//
// Read is the outbound direction: packets the kernel is handing us to send.
// Write is the inbound direction: packets a peer sent, on their way to the
// kernel. Both are filtered, because a rule that only stopped what we send
// would leave a tampered peer free to send us whatever it liked.
type Device struct {
	tun.Device

	// table is swapped wholesale when the configuration changes, so a rule
	// change takes effect on the next packet rather than the next
	// reconnection. Atomic because packets are read and written on their own
	// goroutines and a configuration poll must not have to stop them.
	table atomic.Pointer[Table]

	dropped atomic.Uint64
	// onDrop reports a dropped packet. Rate limiting belongs to the caller:
	// a misconfigured rule can drop thousands of packets a second and the log
	// is not the place to find that out.
	onDrop func(dir Direction, verdict Verdict)
}

// Wrap puts a filter around an existing tunnel device.
func Wrap(inner tun.Device, onDrop func(Direction, Verdict)) *Device {
	d := &Device{Device: inner, onDrop: onDrop}
	d.table.Store(NewTable())

	return d
}

// SetTable replaces the rules. Safe to call while traffic is flowing.
func (d *Device) SetTable(t *Table) {
	if t != nil {
		d.table.Store(t)
	}
}

// Dropped is how many packets the rules have refused, for the status file.
func (d *Device) Dropped() uint64 { return d.dropped.Load() }

// Read takes packets from the kernel and removes the ones that may not go out.
//
// Survivors are compacted forward so the caller sees a dense batch, which is
// the same shape wireguard-go's own batching expects. Dropping in place and
// returning a shorter count would hand it a hole.
func (d *Device) Read(bufs [][]byte, sizes []int, offset int) (int, error) {
	n, err := d.Device.Read(bufs, sizes, offset)
	if n == 0 || err != nil {
		return n, err
	}

	kept := 0
	for i := 0; i < n; i++ {
		if !d.permit(Outbound, bufs[i][offset:offset+sizes[i]]) {
			continue
		}
		if kept != i {
			bufs[kept], bufs[i] = bufs[i], bufs[kept]
			sizes[kept] = sizes[i]
		}
		kept++
	}

	return kept, nil
}

// Write delivers packets from peers, minus the ones they may not send us.
func (d *Device) Write(bufs [][]byte, offset int) (int, error) {
	kept := bufs[:0]
	for _, b := range bufs {
		if len(b) <= offset {
			continue
		}
		if !d.permit(Inbound, b[offset:]) {
			continue
		}
		kept = append(kept, b)
	}

	if len(kept) == 0 {
		// Nothing survived. Reporting zero written is correct and is not an
		// error: the packets were delivered to the filter, which is where the
		// configuration said they should stop.
		return 0, nil
	}

	return d.Device.Write(kept, offset)
}

func (d *Device) permit(dir Direction, raw []byte) bool {
	verdict := d.table.Load().Check(dir, raw)
	if verdict.Allowed {
		return true
	}

	d.dropped.Add(1)
	if d.onDrop != nil {
		d.onDrop(dir, verdict)
	}

	return false
}
