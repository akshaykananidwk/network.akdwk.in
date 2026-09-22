package forwarder

import (
	"context"
	"errors"
	"fmt"
	"net"
	"sync"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
	"github.com/akshaykananidwk/network.akdwk.in/services/shared/fallback"
	"github.com/coder/websocket"
)

// writeTimeout bounds one frame's write.
//
// A connection whose far end has stopped reading must not hold the write lock
// for ever: every other frame for that agent queues behind it. Ten seconds is
// long enough that a slow mobile link is not cut and short enough that a dead
// one is noticed.
const writeTimeout = 10 * time.Second

// controlIdle closes the proxy socket for an agent that has stopped talking to
// the coordinator, so a long-lived data session does not hold a UDP port open
// for nothing.
const controlIdle = 10 * time.Minute

// wsClient is one agent on the fallback path.
type wsClient struct {
	edge     *Edge
	conn     *websocket.Conn
	observed string

	// key is what the agent said it is. Nothing is authorised by the claim:
	// every bind must present a coordinator-signed ticket naming this key, and
	// an agent that lied simply cannot bind at all.
	key [32]byte

	control *net.UDPConn

	mu       sync.Mutex
	sessions map[[32]byte]*Session
	tun      *tunnel
}

// serve runs the connection to its end.
func (c *wsClient) serve(parent context.Context) {
	ctx, cancel := context.WithCancel(parent)
	defer cancel()

	defer c.cleanup()

	if err := c.handshake(ctx); err != nil {
		_ = c.conn.Close(websocket.StatusProtocolError, "handshake")

		return
	}

	c.tun = &tunnel{send: func(peer [32]byte, payload []byte) error {
		msg, err := fallback.EncodeData(peer, payload)
		if err != nil {
			return err
		}

		return c.writeMessage(ctx, msg)
	}}

	go c.keepalive(ctx)

	for {
		typ, msg, err := c.conn.Read(ctx)
		if err != nil {
			return
		}
		if typ != websocket.MessageBinary {
			continue
		}

		if err := c.dispatch(ctx, msg); err != nil {
			c.edge.logf("fallback client %x…: %v", c.key[:6], err)
		}
	}
}

// handshake reads the agent's opening frame and answers it.
func (c *wsClient) handshake(ctx context.Context) error {
	deadline, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()

	typ, msg, err := c.conn.Read(deadline)
	if err != nil {
		return err
	}
	if typ != websocket.MessageBinary {
		return fmt.Errorf("the first frame was not binary")
	}

	kind, payload, err := fallback.Decode(msg)
	if err != nil || kind != fallback.KindHello {
		return fmt.Errorf("the first frame was not a hello")
	}

	key, err := fallback.DecodeHello(payload)
	if err != nil {
		return err
	}

	c.key = key

	ready, err := fallback.EncodeReady(c.observed)
	if err != nil {
		return err
	}

	return c.writeMessage(deadline, ready)
}

// dispatch handles one frame.
func (c *wsClient) dispatch(ctx context.Context, msg []byte) error {
	kind, payload, err := fallback.Decode(msg)
	if err != nil {
		return err
	}

	switch kind {
	case fallback.KindControl:
		return c.toCoordinator(ctx, payload)
	case fallback.KindBind:
		return c.bind(ctx, payload)
	case fallback.KindData:
		return c.data(payload)
	case fallback.KindKeepalive:
		return nil
	default:
		return fmt.Errorf("unexpected frame %#x", byte(kind))
	}
}

// data forwards one tunnel frame to the peer named in it.
func (c *wsClient) data(payload []byte) error {
	peer, tunnelBytes, err := fallback.DecodeData(payload)
	if err != nil {
		return err
	}

	c.mu.Lock()
	session := c.sessions[peer]
	c.mu.Unlock()

	if session == nil {
		// Not bound for that peer. Silent: an agent whose ticket expired
		// re-binds within seconds and would otherwise fill the log first.
		return nil
	}

	return session.fromTunnel(c.key, tunnelBytes)
}

// bind authorises one pair over the fallback.
//
// The ticket is checked exactly as a UDP bind's is. This path is a way around
// the customer's firewall, not around the coordinator's authorisation.
func (c *wsClient) bind(ctx context.Context, payload []byte) error {
	header, rest, err := disco.ParseHeader(payload)
	if err != nil || header.Type != disco.TypeRelayBind {
		return fmt.Errorf("that was not a bind")
	}

	ticket, err := disco.DecodeTicket(rest)
	if err != nil {
		return err
	}

	if err := ticket.Verify(c.edge.relay.opts.Secret, time.Now()); err != nil {
		return fmt.Errorf("refused a fallback bind: %w", err)
	}

	if header.Sender != ticket.Self {
		return errors.New("refused a fallback bind: sender does not match the ticket")
	}

	// The key the agent announced must be the one the coordinator issued the
	// ticket to. Otherwise an agent could hold a legitimate ticket of its own
	// and still have its data frames filed under somebody else's key.
	if ticket.Self != c.key {
		return errors.New("refused a fallback bind: the ticket is for a different device")
	}

	session := c.edge.relay.sessionFor(ticket)

	if _, err := session.bindTunnel(c.key, c.tun, c.edge.relay.opts.Logf); err != nil {
		return fmt.Errorf("binding over the fallback: %w", err)
	}

	c.mu.Lock()
	c.sessions[ticket.Peer] = session
	c.mu.Unlock()

	ack, err := fallback.EncodeBindAck(ticket.Peer)
	if err != nil {
		return err
	}

	pair := ticket.Pair()
	c.edge.logf("bound %s to pair %x… over the HTTPS fallback", c.observed, pair[:6])

	return c.writeMessage(ctx, ack)
}

// writeMessage sends one frame, bounded in time.
func (c *wsClient) writeMessage(ctx context.Context, msg []byte) error {
	deadline, cancel := context.WithTimeout(ctx, writeTimeout)
	defer cancel()

	return c.conn.Write(deadline, websocket.MessageBinary, msg)
}

// keepalive pings the agent so an idle connection is not reaped by a proxy or
// a NAT that forgets TCP flows. Apache's own timeout is the shorter of the
// two, which is why this is well under a minute.
func (c *wsClient) keepalive(ctx context.Context) {
	ticker := time.NewTicker(25 * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			deadline, cancel := context.WithTimeout(ctx, writeTimeout)
			err := c.conn.Ping(deadline)
			cancel()

			if err != nil {
				_ = c.conn.CloseNow()

				return
			}
		}
	}
}

// cleanup detaches every session this connection was carrying.
func (c *wsClient) cleanup() {
	c.mu.Lock()
	if c.control != nil {
		_ = c.control.Close()
		c.control = nil
	}

	sessions := make([]*Session, 0, len(c.sessions))
	for _, s := range c.sessions {
		sessions = append(sessions, s)
	}
	c.sessions = nil
	c.mu.Unlock()

	for _, s := range sessions {
		s.dropTunnel(c.key, c.tun)
	}

	_ = c.conn.CloseNow()
}
