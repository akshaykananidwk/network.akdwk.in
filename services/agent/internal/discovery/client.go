// Package discovery finds a direct path to each peer.
//
// The order of preference is R2's: a peer on the same LAN is reached across
// the switch, a peer elsewhere through whatever address NAT gives it, and
// only if neither works does anything else get considered. Nothing here
// touches a relay — that is Phase 3 — so a peer that cannot be reached
// directly simply stays unreachable, visibly, rather than silently degrading.
package discovery

import (
	"context"
	"encoding/base64"
	"fmt"
	"net/netip"
	"sync"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/disconn"
	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
)

// Transport is the socket discovery shares with WireGuard.
//
// SetHandler takes disconn.Handler rather than a bare func type so that the
// bind satisfies this interface directly, without an adapter whose only job
// would be to change a name.
type Transport interface {
	SendTo(pkt []byte, to netip.AddrPort) error
	SetHandler(disconn.Handler)
	Port() uint16
}

// PeerSetter applies a discovered path to the WireGuard device.
type PeerSetter interface {
	SetPeerEndpoint(publicKeyBase64, endpoint string) error
}

// Options configures a Client.
type Options struct {
	SelfPublic  [32]byte
	SelfPrivate [32]byte
	Coordinator netip.AddrPort
	CoordKey    [32]byte
	DeviceUID   string
	// Token is the device token, sealed into every announcement.
	Token string
	// LocalEndpoints are this machine's own addresses, offered to peers on the
	// same network so they can skip the public path entirely.
	LocalEndpoints []netip.AddrPort
	// Overlay is the tunnel's own prefix. No endpoint inside it is ever usable
	// as a path to a peer, whoever offers it.
	Overlay   netip.Prefix
	Transport Transport
	Peers     PeerSetter
	Logf      func(string, ...any)
	// Rebind reopens the shared socket. Optional; when it is nil a dead
	// socket is still reported, but nothing can be done about it.
	//
	// Needed because of what a dead socket looked like in the field: the agent
	// logged "use of closed network connection" every twenty seconds for as
	// long as anyone watched, was reachable by nobody, showed no fault
	// anywhere, and came back only when the service was restarted by hand.
	Rebind func() error
	// MovePort reopens the shared socket on a DIFFERENT port and reports the
	// port and this machine's addresses on it. Optional.
	//
	// Separate from Rebind because the two faults are separate: Rebind heals a
	// socket that died, MovePort escapes a port that a router will not carry.
	// Reopening on the same port is the right answer to the first and no
	// answer at all to the second. See port.go.
	MovePort func() (int, []netip.AddrPort, error)
	// Diverted reports whether a peer's traffic is currently being carried by
	// the HTTPS fallback, which owns that peer's endpoint while it is.
	//
	// Optional; nil means nothing is ever diverted.
	//
	// A relay bind is answered twice on a network with no UDP: once by the
	// relay naming a real port, once by the fallback naming a tunnelled one —
	// and the relay's answer arrives THROUGH the fallback, so it arrives even
	// though the port it names is unreachable. Whichever is applied last
	// decides where WireGuard sends. PreferUDP stops the fallback answering
	// once UDP works again; this is the other direction, and without it a
	// relay offer arriving mid-fallback points WireGuard at an address this
	// network cannot reach until the fallback puts it back.
	Diverted func([32]byte) bool
}

// Client keeps this agent announced and its peers reachable.
type Client struct {
	opts Options

	mu        sync.Mutex
	reflexive netip.AddrPort
	// lastAck is when the coordinator last answered. It decides whether the
	// next announcement is a full Hello or a cheap Ping.
	lastAck time.Time
	// lastHello is when a full announcement was last sent, so one happens
	// periodically even while the coordinator is answering cheerfully.
	lastHello time.Time
	// forceHello makes the next announcement a full one, whatever the timers
	// say. Set when the panel's configuration has moved, because a hello is
	// the only message that carries a token and therefore the only one that
	// makes the coordinator re-ask who this device may reach.
	forceHello bool
	// sendFailures counts consecutive announcement failures. A single one is
	// ordinary — a lost packet, an interface coming up — and a run of them
	// means the socket underneath is gone and will not heal on its own.
	sendFailures int
	// transportProblem is what to tell the panel about it, so a device nobody
	// can reach says so rather than looking healthy.
	transportProblem string
	// firstSendFailure is when the current run of them started, so how long
	// the socket has been refusing can be reported as well as how often.
	firstSendFailure time.Time
	// rebinds counts how many times the socket has been reopened without the
	// sends starting to work, so a recovery that is not recovering says so.
	rebinds int
	// unacked counts announcements that left the socket and were never
	// answered, lastSend paces their retries, and portMoves counts the ports
	// already tried. Together they are how a port a router will not carry is
	// told apart from a socket that is simply dead — see port.go.
	unacked   int
	lastSend  time.Time
	portMoves int
	// holdPort suspends port moves while something else is carrying this
	// device's control traffic. See HoldPort.
	holdPort bool
	// firstSend is when this agent first announced itself, so a device that
	// has NEVER been answered can say how long that has been true rather than
	// measuring from a lastAck it does not have.
	firstSend time.Time
	// candidates is every address currently being tried for a peer, so a punch
	// reply can be matched back to the peer it proves.
	candidates map[[32]byte][]netip.AddrPort
	// established records the address that answered, so a later duplicate
	// reply does not churn the WireGuard configuration.
	established map[[32]byte]netip.AddrPort
	// paths records how each peer is currently reached, which is what the
	// status file and the panel's indicator report.
	paths map[[32]byte]path
	// firstSeen is when a peer was introduced, so the punch deadline can be
	// measured from something real rather than from process start.
	firstSeen map[[32]byte]time.Time
	// relayControl and relayTicket hold what is needed to re-bind.
	relayControl map[[32]byte]netip.AddrPort
	relayTicket  map[[32]byte][]byte
	repunchCount map[[32]byte]int
	nextRepunch  map[[32]byte]time.Time
	// relayName is the fleet name of the relay each peer is on, and
	// relayMissed counts consecutive rebinds that went unanswered. Together
	// they are how the agent notices a relay has stopped working: nothing else
	// on this side can see it, because a dead relay simply goes quiet.
	relayName   map[[32]byte]string
	relayMissed map[[32]byte]int
	// relays is the fleet this device measures against, and relayRTT is what
	// it measured. Reported to the coordinator, which is the only place that
	// can compare one device's view against its peer's.
	relays   []RelayTarget
	relayRTT map[string]uint16
	// probesInFlight matches a reply to the probe that asked for it, so a late
	// answer to an abandoned probe is not timed as if it were fresh.
	probesInFlight map[[disco.ProbeNonceLen]byte]pendingProbe
	// lastProbe paces the fleet measurement, lastReport paces sending it, and
	// rttDirty says whether anything has changed since the last one.
	lastProbe  time.Time
	lastReport time.Time
	rttDirty   bool
}

// path is how a peer is currently reached.
type path string

const (
	pathConnecting path = "connecting"
	pathDirect     path = "direct"
	pathRelay      path = "relay"
)

// Path reports how a peer is reached, for the status file.
func (c *Client) Path(peer [32]byte) string {
	c.mu.Lock()
	defer c.mu.Unlock()

	if p, ok := c.paths[peer]; ok {
		return string(p)
	}

	return string(pathConnecting)
}

// Paths reports every peer's current path, keyed by base64 public key.
func (c *Client) Paths() map[string]string {
	c.mu.Lock()
	defer c.mu.Unlock()

	out := make(map[string]string, len(c.paths))
	for peer, p := range c.paths {
		out[base64Key(peer)] = string(p)
	}

	return out
}

// pathOf reads the current path. The caller must hold the lock.
func (c *Client) pathOf(peer [32]byte) path {
	if p, ok := c.paths[peer]; ok {
		return p
	}

	return pathConnecting
}

// New builds a Client.
func New(opts Options) (*Client, error) {
	if opts.Logf == nil {
		return nil, fmt.Errorf("a logger is required")
	}
	if opts.Transport == nil || opts.Peers == nil {
		return nil, fmt.Errorf("a transport and a peer setter are required")
	}
	if !opts.Coordinator.IsValid() {
		return nil, fmt.Errorf("no coordinator address")
	}

	return &Client{
		opts:           opts,
		candidates:     make(map[[32]byte][]netip.AddrPort),
		established:    make(map[[32]byte]netip.AddrPort),
		paths:          make(map[[32]byte]path),
		firstSeen:      make(map[[32]byte]time.Time),
		relayControl:   make(map[[32]byte]netip.AddrPort),
		relayTicket:    make(map[[32]byte][]byte),
		relayName:      make(map[[32]byte]string),
		relayMissed:    make(map[[32]byte]int),
		relayRTT:       make(map[string]uint16),
		probesInFlight: make(map[[disco.ProbeNonceLen]byte]pendingProbe),
		repunchCount:   make(map[[32]byte]int),
		nextRepunch:    make(map[[32]byte]time.Time),
	}, nil
}

// Run announces this agent and keeps doing so until the context ends.
//
// The interval is short enough to hold a NAT mapping open: a typical UDP
// mapping expires after 30 seconds of silence, and a mapping that has expired
// is a peer that can no longer reach us.
func (c *Client) Run(ctx context.Context) {
	c.opts.Transport.SetHandler(c.handle)

	const keepalive = 20 * time.Second

	c.announce(c.needsHello(keepalive))
	// Measure the fleet immediately. A device that has to wait two minutes
	// before it knows which relay is nearest will have already been put on one
	// by then, and moving an established session costs a reconnection.
	c.maybeProbeRelays()

	keepaliveTick := time.NewTicker(keepalive)
	defer keepaliveTick.Stop()

	// Relay binds are re-presented much faster than the coordinator keepalive.
	// They are one small packet per relayed peer, and they are the only thing
	// that tells this agent its relay is still alive — at the keepalive's
	// twenty seconds, a relay that died cost a measured 37% of a 40-second
	// window before traffic came back.
	relayTick := time.NewTicker(relayRebindInterval)
	defer relayTick.Stop()

	// Faster than the keepalive, because the punch deadline is measured in
	// seconds: a pair that cannot punch should be on a relay quickly, not at
	// the next announcement.
	workTick := time.NewTicker(time.Second)
	defer workTick.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-keepaliveTick.C:
			c.announce(c.needsHello(keepalive))
			c.reportRelayRTT()
		case <-relayTick.C:
			c.rebindRelays()
		case <-workTick.C:
			c.maybeRetryUnacked(keepalive)
			c.maybeMovePort()
			c.escalateStalledPeers()
			c.repunch()
			c.expireProbes()
			c.maybeProbeRelays()
			c.maybeReportRelayRTT()
		}
	}
}

// escalateStalledPeers asks for a relay for any peer that has not connected
// directly within the punch deadline.
func (c *Client) escalateStalledPeers() {
	c.mu.Lock()

	var stalled [][32]byte
	now := time.Now()

	for peer, since := range c.firstSeen {
		if c.pathOf(peer) != pathConnecting {
			continue
		}
		if now.Sub(since) < punchDeadline {
			continue
		}
		if _, asked := c.relayControl[peer]; asked {
			continue
		}

		// Marked before the request so a slow coordinator does not produce a
		// request per second.
		c.relayControl[peer] = netip.AddrPort{}
		stalled = append(stalled, peer)
	}
	c.mu.Unlock()

	for _, peer := range stalled {
		c.opts.Logf("discovery: no direct path to %s… after %s; asking for a relay",
			base64Key(peer)[:12], punchDeadline)
		c.requestRelay(peer, "")
	}
}

// needsHello decides between a full announcement and a keepalive ping.
//
// A Ping only refreshes an entry the coordinator already has. If it has none —
// because the first Hello was lost, because the panel was briefly down when it
// arrived, or because the coordinator has restarted — pinging forever would
// leave the agent permanently undiscovered while looking healthy. So the
// agent re-introduces itself whenever it has not been acknowledged recently.
// helloInterval is how often a full announcement is sent regardless.
//
// A ping carries no token, so the coordinator cannot re-ask the panel who this
// device may reach — it can only repeat what it already believes. Until 1.9.2
// a settled agent sent nothing but pings, for as long as the coordinator kept
// answering, which meant the allowed set on the coordinator was whatever it
// was at the first announcement and stayed that way for the life of the
// process. A device approved into the network afterwards was invisible to
// everything already running.
//
// Five minutes is the backstop. The normal path is Rehello, below, which fires
// the moment the panel's configuration revision moves — this catches the case
// where that never happens, on an agent too old to do it or a panel that was
// unreachable at the moment it mattered.
const helloInterval = 5 * time.Minute

func (c *Client) needsHello(keepalive time.Duration) bool {
	c.mu.Lock()
	defer c.mu.Unlock()

	if c.forceHello {
		c.forceHello = false

		return true
	}

	if c.lastAck.IsZero() || time.Since(c.lastAck) > 2*keepalive {
		return true
	}

	return c.lastHello.IsZero() || time.Since(c.lastHello) > helloInterval
}

// Rehello makes the next announcement a full one, and sends it now.
//
// Called when the panel's configuration revision moves: an approval, a
// revocation or an ACL change has happened, so the coordinator's idea of who
// this device may reach is out of date and only a hello will refresh it.
func (c *Client) Rehello() {
	c.mu.Lock()
	c.forceHello = true
	c.mu.Unlock()

	c.announce(c.needsHello(20 * time.Second))
}

// announce sends a Hello (first time, and periodically) or a lighter Ping.
func (c *Client) announce(full bool) {
	msgType := disco.TypePing
	var body []byte
	var err error

	if full {
		hello := &disco.Hello{
			DeviceUID:      c.opts.DeviceUID,
			Token:          c.opts.Token,
			LocalEndpoints: c.opts.LocalEndpoints,
		}
		body, err = hello.Encode()
		msgType = disco.TypeHello

		c.mu.Lock()
		c.lastHello = time.Now()
		c.mu.Unlock()
	} else {
		// A ping carries nothing; it exists to refresh the mapping and the
		// coordinator's presence entry. It is still sealed, so it still proves
		// who sent it.
		body = []byte{}
	}

	if err != nil {
		c.opts.Logf("discovery: encoding announcement failed: %v", err)
		return
	}

	pkt, err := c.seal(msgType, c.opts.CoordKey, body)
	if err != nil {
		c.opts.Logf("discovery: sealing announcement failed: %v", err)
		return
	}

	// Counted BEFORE the send, not after.
	//
	// The coordinator's answer arrives on another goroutine, and on a fast
	// path it can be processed before this one gets to the next line — so
	// counting afterwards could set the counter to 1 immediately after the
	// ack had cleared it, leaving the agent convinced it had never been
	// answered. It then re-announces every five seconds for ever and, given
	// enough of them, moves to a different port on a link that was working.
	//
	// A send that fails is undone below, because a failed send is not an
	// announcement that went unanswered: that is the dead socket, and it has
	// its own detector.
	c.noteAnnouncementSent()

	if err := c.opts.Transport.SendTo(pkt, c.opts.Coordinator); err != nil {
		c.undoAnnouncementSent()
		c.noteSendFailure(err)

		return
	}

	c.mu.Lock()
	recovered := c.sendFailures > 0 || c.rebinds > 0
	c.sendFailures = 0
	c.rebinds = 0
	c.transportProblem = ""
	c.firstSendFailure = time.Time{}
	c.mu.Unlock()

	if recovered {
		c.opts.Logf("discovery: the socket is working again; announcements are getting out")
	}
}

// rebindAfter is how many consecutive failures mean the socket is gone rather
// than momentarily unhappy. Three, at a twenty-second keepalive, is about a
// minute of silence — long enough not to churn on a transient, short enough
// that a customer does not notice.
const rebindAfter = 3

// noteSendFailure decides whether this is bad luck or a dead socket.
//
// The whole point is that it does something. Until 1.9.4 this was a log line
// and nothing else, so an agent whose socket had been closed underneath it —
// by a network change, an adapter rebuild, a laptop waking up — announced into
// a closed file descriptor every twenty seconds forever. The coordinator never
// heard from it, its peers were never told where it was, and its own log was
// the only place the fault existed.
func (c *Client) noteSendFailure(err error) {
	c.mu.Lock()
	c.sendFailures++
	failures := c.sendFailures
	c.transportProblem = err.Error()
	if c.firstSendFailure.IsZero() {
		c.firstSendFailure = time.Now()
	}
	c.mu.Unlock()

	// Every failure is logged. A quiet agent that cannot be reached is worse
	// than a noisy one that says why.
	c.opts.Logf("discovery: announcing to the coordinator failed: %v (%d in a row)", err, failures)

	if failures < rebindAfter || c.opts.Rebind == nil {
		return
	}

	c.opts.Logf("discovery: %d announcements in a row could not be sent; reopening the socket", failures)

	if err := c.opts.Rebind(); err != nil {
		c.opts.Logf("discovery: reopening the socket failed: %v", err)

		return
	}

	// Counted from zero again so a rebind that did not help is retried rather
	// than attempted once and given up on.
	c.mu.Lock()
	c.sendFailures = 0
	c.rebinds++
	attempts := c.rebinds
	c.mu.Unlock()

	// Deliberately not "socket reopened". Rebind() returning nil means the
	// call succeeded, not that the socket works — and the first version of
	// this said "socket reopened; re-announcing" on the line immediately
	// before the next send failed with the same error. Claiming a recovery
	// that has not happened is the same defect this project keeps finding
	// elsewhere, written by me, in the code whose entire job is to recover.
	//
	// Whether it worked is decided by the next send, which logs either the
	// failure or "the socket is working again".
	c.opts.Logf("discovery: socket reopened (attempt %d); announcing again to find out whether it helped",
		attempts)

	// A socket that will not come back needs a human, and the only way to ask
	// for one is to keep saying so where an administrator can see it.
	if attempts == rebindGiveUp {
		c.opts.Logf("discovery: %d rebinds have not fixed the socket; this device cannot be reached "+
			"and the AKConnect service needs restarting", attempts)
	}

	c.Rehello()
}

// rebindGiveUp is how many fruitless rebinds are worth one loud line. It does
// not stop trying — a network that comes back deserves an agent that is still
// there — it stops the log reading as though everything is under control.
const rebindGiveUp = 3

// TransportProblem is why announcements are not getting out, or "" when they
// are. Reported to the panel with the other problems.
func (c *Client) TransportProblem() string {
	c.mu.Lock()
	defer c.mu.Unlock()

	return c.transportProblem
}

// Reflexive is this agent's public address as the coordinator sees it, or the
// zero value if it has not been told yet.
func (c *Client) Reflexive() netip.AddrPort {
	c.mu.Lock()
	defer c.mu.Unlock()

	return c.reflexive
}

func (c *Client) seal(t disco.MessageType, recipient [32]byte, body []byte) ([]byte, error) {
	sealed, err := disco.Seal(body, &recipient, &c.opts.SelfPrivate)
	if err != nil {
		return nil, err
	}

	pkt := make([]byte, disco.HeaderLen, disco.HeaderLen+len(sealed))
	disco.WriteHeader(pkt, t, c.opts.SelfPublic)

	return append(pkt, sealed...), nil
}

func base64Key(k [32]byte) string {
	return base64.StdEncoding.EncodeToString(k[:])
}
