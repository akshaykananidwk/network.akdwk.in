package netmap

import (
	"sync/atomic"

	"golang.zx2c4.com/wireguard/tun"
)

// Device wraps the tunnel interface and translates mapped addresses to real
// ones on the way in, and back on the way out.
//
// # Where this sits, and why it matters
//
// It goes *inside* the ACL filter:
//
//	acl.Wrap( netmap.Wrap( tun ) )
//
// so that everything above it lives entirely in mapped address space. The
// panel compiles rules about 192.168.1.50 into rules about 10.201.5.50 before
// it sends them, the client routes and filters on 10.201.5.0/24, and the
// gateway's filter sees the same addresses its peers do. Only the operating
// system below this layer, and the LAN beyond it, ever see the customer's real
// range.
//
// Putting the translation above the filter instead would mean the gateway
// judged packets by addresses no other device in the network uses, which is
// how a rule comes to mean two different things at two ends of one tunnel.
type Device struct {
	tun.Device

	// table is swapped wholesale when the configuration changes, the same way
	// the filter's is: a route withdrawn in the panel must stop being
	// translated on the next packet, not the next reconnection.
	table atomic.Pointer[Table]

	// refused counts packets from peers addressed to a translated LAN by its
	// real address (Table.addressedToReal), which are dropped.
	refused atomic.Uint64
}

// Refused is how many packets were dropped for naming a LAN by its real
// address rather than its mapped one.
func (d *Device) Refused() uint64 { return d.refused.Load() }

// Wrap puts address translation around an existing tunnel device.
func Wrap(inner tun.Device) *Device {
	d := &Device{Device: inner}
	d.table.Store(NewTable())

	return d
}

// SetTable replaces the mappings. Safe to call while traffic is flowing.
func (d *Device) SetTable(t *Table) {
	if t == nil {
		t = NewTable()
	}

	d.table.Store(t)
}

// Active is how many mappings are in force, for logging and the status file.
func (d *Device) Active() int { return d.table.Load().Len() }

// Read takes packets from the kernel and puts real source addresses into
// mapped space before anything above sees them.
func (d *Device) Read(bufs [][]byte, sizes []int, offset int) (int, error) {
	n, err := d.Device.Read(bufs, sizes, offset)
	if n == 0 || err != nil {
		return n, err
	}

	table := d.table.Load()
	if table.Len() == 0 {
		// Every device that is not a subnet router, which is nearly all of
		// them. One atomic load and a comparison.
		return n, nil
	}

	for i := 0; i < n; i++ {
		table.Rewrite(ToOverlay, bufs[i][offset:offset+sizes[i]])
	}

	return n, nil
}

// Write takes packets from peers and puts mapped destinations back onto the
// real LAN before the operating system routes them.
//
// A packet naming a translated LAN by its real address is dropped: see
// Table.addressedToReal. The rest of the batch goes on.
func (d *Device) Write(bufs [][]byte, offset int) (int, error) {
	table := d.table.Load()
	if table.Len() == 0 {
		return d.Device.Write(bufs, offset)
	}

	var kept [][]byte // built only once something is dropped
	for i, b := range bufs {
		if len(b) > offset && table.addressedToReal(b[offset:]) {
			d.refused.Add(1)
			if kept == nil {
				kept = append(make([][]byte, 0, len(bufs)), bufs[:i]...)
			}
			continue
		}
		if len(b) > offset {
			table.Rewrite(ToLAN, b[offset:])
		}
		if kept != nil {
			kept = append(kept, b)
		}
	}

	if kept == nil {
		return d.Device.Write(bufs, offset)
	}
	if len(kept) > 0 {
		if _, err := d.Device.Write(kept, offset); err != nil {
			return 0, err
		}
	}

	return len(bufs), nil
}
