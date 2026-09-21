package acl

import (
	"container/list"
	"net/netip"
	"sync"
	"time"
)

// Connection tracking.
//
// Rules name a *service* port: "AK Support may reach the NVR on tcp/554"
// means 554 is the port being connected to. Matching that rule against a
// packet's source port as well — which an earlier version of this filter did,
// and which was written up as a stateless trade-off rather than the hole it
// is — lets a device bind source port 554 and reach every port on the target.
// The rule then permits exactly what it was written to forbid.
//
// So rules match the destination port only, and replies are allowed because
// they belong to a flow the rules already permitted, not because their port
// numbers look familiar.

// Flow lifetimes.
//
// The TCP figure is deliberately short for a firewall: a shop PC is not a
// datacentre and an idle RDP session that has to re-handshake after an hour is
// a smaller problem than a table full of flows nobody is using. The closing
// figure covers the last ACK after a FIN without keeping the entry for
// minutes.
const (
	tcpIdleTimeout    = 60 * time.Minute
	tcpClosingTimeout = 15 * time.Second
	udpIdleTimeout    = 60 * time.Second
	icmpIdleTimeout   = 30 * time.Second
)

// maxFlows bounds the table.
//
// Ten thousand flows is far more than a shop PC opens and costs a few
// megabytes; see TestMemoryAtTenThousandFlows. The bound exists because a
// device on a hostile network can otherwise be made to allocate without limit
// by a peer that is already permitted to send it traffic.
const maxFlows = 10000

// flowKey identifies one conversation, oriented as the side that opened it.
//
// Both directions of a conversation reduce to the same key: the opener's
// tuple. An inbound packet is looked up by reversing its addresses and ports,
// which is what makes a reply recognisable without storing it twice.
type flowKey struct {
	proto      uint8
	localAddr  netip.Addr
	remoteAddr netip.Addr
	localPort  uint16
	remotePort uint16
}

// reversed is the key as the other end would write it.
func (k flowKey) reversed() flowKey {
	return flowKey{
		proto:      k.proto,
		localAddr:  k.remoteAddr,
		remoteAddr: k.localAddr,
		localPort:  k.remotePort,
		remotePort: k.localPort,
	}
}

type flow struct {
	key      flowKey
	expires  time.Time
	closing  bool
	element  *list.Element
	openedBy Direction
}

// conntrack is a bounded, LRU-evicted flow table.
type conntrack struct {
	mu    sync.Mutex
	flows map[flowKey]*flow
	// order is most-recently-used at the front, so eviction takes the back.
	order *list.List
	max   int
	now   func() time.Time
}

func newConntrack(max int) *conntrack {
	if max <= 0 {
		max = maxFlows
	}

	return &conntrack{
		flows: make(map[flowKey]*flow, 64),
		order: list.New(),
		max:   max,
		now:   time.Now,
	}
}

// keyFor builds the flow key for a packet travelling in one direction.
//
// "local" is always this device's end, whichever way the packet is going, so
// the two directions of one conversation produce keys that are each other's
// reverse.
func keyFor(dir Direction, p packet) flowKey {
	if dir == Outbound {
		return flowKey{
			proto:      p.proto,
			localAddr:  p.src,
			remoteAddr: p.dst,
			localPort:  p.srcPort,
			remotePort: p.dstPort,
		}
	}

	return flowKey{
		proto:      p.proto,
		localAddr:  p.dst,
		remoteAddr: p.src,
		localPort:  p.dstPort,
		remotePort: p.srcPort,
	}
}

// belongsToFlow reports whether this packet is part of a conversation the
// rules already permitted, and refreshes it if so.
func (c *conntrack) belongsToFlow(dir Direction, p packet) bool {
	key := keyFor(dir, p)

	c.mu.Lock()
	defer c.mu.Unlock()

	now := c.now()
	c.expireLocked(now)

	existing, ok := c.flows[key]
	if !ok {
		// A reply carries the opener's tuple reversed.
		existing, ok = c.flows[key.reversed()]
	}
	if !ok {
		return false
	}

	if now.After(existing.expires) {
		c.removeLocked(existing)

		return false
	}

	c.touchLocked(existing, p, now)

	return true
}

// open records a flow the rules have just permitted.
func (c *conntrack) open(dir Direction, p packet) {
	if !p.hasPorts && p.proto != ipProtoICMP {
		// Nothing to track: without ports there is no flow to recognise a
		// reply against, and a rule will have to permit the reply on its own.
		return
	}

	key := keyFor(dir, p)

	c.mu.Lock()
	defer c.mu.Unlock()

	now := c.now()
	c.expireLocked(now)

	if existing, ok := c.flows[key]; ok {
		c.touchLocked(existing, p, now)

		return
	}

	for len(c.flows) >= c.max {
		// Evict the least recently used. A bounded table that refused new
		// flows instead would turn a burst into an outage.
		back := c.order.Back()
		if back == nil {
			break
		}
		c.removeLocked(back.Value.(*flow))
	}

	f := &flow{key: key, openedBy: dir}
	f.element = c.order.PushFront(f)
	c.flows[key] = f
	c.touchLocked(f, p, now)
}

// touchLocked refreshes a flow's deadline from the packet that just used it.
func (c *conntrack) touchLocked(f *flow, p packet, now time.Time) {
	c.order.MoveToFront(f.element)

	switch p.proto {
	case ipProtoTCP:
		// RST closes immediately; FIN starts the short closing timer. Without
		// this a table fills with conversations that ended minutes ago.
		if p.tcpFlags&tcpRST != 0 {
			f.expires = now.Add(tcpClosingTimeout)
			f.closing = true

			return
		}
		if p.tcpFlags&tcpFIN != 0 {
			f.closing = true
		}
		if f.closing {
			f.expires = now.Add(tcpClosingTimeout)

			return
		}
		f.expires = now.Add(tcpIdleTimeout)
	case ipProtoUDP:
		f.expires = now.Add(udpIdleTimeout)
	default:
		f.expires = now.Add(icmpIdleTimeout)
	}
}

func (c *conntrack) removeLocked(f *flow) {
	delete(c.flows, f.key)
	c.order.Remove(f.element)
}

// expireLocked drops flows whose deadline has passed.
//
// Walking from the back, where the least recently used sit, and stopping at
// the first live one: the list is ordered by use rather than by deadline, so
// this is approximate. It is bounded work per packet, which matters more —
// an exact sweep on a full table would cost more than forwarding the packet.
func (c *conntrack) expireLocked(now time.Time) {
	const maxSweep = 8

	for i := 0; i < maxSweep; i++ {
		back := c.order.Back()
		if back == nil {
			return
		}

		f := back.Value.(*flow)
		if now.Before(f.expires) {
			return
		}
		c.removeLocked(f)
	}
}

// Flows is how many conversations are currently tracked, for the status file.
func (c *conntrack) Flows() int {
	c.mu.Lock()
	defer c.mu.Unlock()

	return len(c.flows)
}
