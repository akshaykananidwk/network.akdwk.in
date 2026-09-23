package discovery

import (
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
)

// pathProbeInterval is how often the real socket is tested while the HTTPS
// fallback is carrying this device's control traffic.
//
// Five seconds, not the twenty the keepalive uses. This is the only thing
// deciding how long a device stays on the expensive path after its network
// starts carrying UDP again — for a customer that is the difference between
// a relayed tunnel over TCP and a direct one — and the cost is one small
// packet every five seconds on a device that is already in trouble.
const pathProbeInterval = 5 * time.Second

// DivertedControl tells discovery that control is going over the fallback.
//
// While it is set, the agent stops announcing over UDP and probes instead.
// The announcement used to go over both paths at once: the coordinator
// answered both, saw the device at two addresses, and recorded it as having
// MOVED on every tick — telling both peers to re-point, twice every twenty
// seconds, for as long as the fallback stayed open. A relayed pair spent the
// whole time being re-introduced and carried nothing. See TypePathProbe.
func (c *Client) DivertedControl(diverted bool) {
	c.mu.Lock()
	was := c.controlDiverted
	c.controlDiverted = diverted
	if !was && diverted {
		// Probe at once rather than at the next tick: the sooner the real
		// socket is tested, the sooner the device can come back off the
		// fallback if it never needed it.
		c.lastPathProbe = time.Time{}
	}
	c.mu.Unlock()
}

// maybeProbePath puts one packet on the real socket, if that is the only way
// left to find out whether UDP works.
func (c *Client) maybeProbePath() {
	c.mu.Lock()
	if !c.controlDiverted || time.Since(c.lastPathProbe) < pathProbeInterval {
		c.mu.Unlock()

		return
	}
	c.lastPathProbe = time.Now()
	c.mu.Unlock()

	// Sealed and empty. The body carries nothing because there is nothing to
	// say: the question is entirely "does a packet from this socket reach you,
	// and does your answer reach me". Sealing it is what stops the coordinator
	// being a reflector for anyone who can spell the magic bytes.
	sealed, err := disco.Seal(nil, &c.opts.CoordKey, &c.opts.SelfPrivate)
	if err != nil {
		return
	}

	pkt := make([]byte, disco.HeaderLen, disco.HeaderLen+len(sealed))
	disco.WriteHeader(pkt, disco.TypePathProbe, c.opts.SelfPublic)
	pkt = append(pkt, sealed...)

	// Sent through the same transport as everything else. The bind refuses to
	// divert this one type, so it goes out on the real socket even while every
	// other packet for the coordinator is going through the tunnel.
	_ = c.opts.Transport.SendTo(pkt, c.opts.Coordinator)
}

// handlePathProbeAck records nothing.
//
// Arriving at all is the whole message, and the bind has already stamped the
// socket as alive by the time this is called — that stamp is what the fallback
// watches. Handled explicitly rather than left to fall off the end of the
// switch, so that the next person to read the dispatch can see this type is
// expected and is meant to do nothing.
func (c *Client) handlePathProbeAck(header disco.Header, sealed []byte) {
	if header.Sender != c.opts.CoordKey {
		return
	}

	if _, err := disco.Open(sealed, &header.Sender, &c.opts.SelfPrivate); err != nil {
		return
	}

	// One thing is recorded, and it is about the coordinator rather than this
	// device: it understands path probes, so the agent can stop sending its
	// announcements over both paths. See Bind.PathProbeAnswered.
	if answered, ok := c.opts.Transport.(interface{ PathProbeAnswered() }); ok {
		answered.PathProbeAnswered()
	}
}
