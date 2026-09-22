package fallback

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"time"

	"github.com/coder/websocket"

	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
	wire "github.com/akshaykananidwk/network.akdwk.in/services/shared/fallback"
)

// wsConn is the part of a websocket connection this package uses.
//
// An interface so a test can drive the whole client — handshake, binds,
// tunnel traffic, reconnection — without a server, and so the concurrency
// rule the real connection guarantees is stated in one place: every method
// here may be called from several goroutines except Read, which has exactly
// one caller.
type wsConn interface {
	Read(ctx context.Context) (websocket.MessageType, []byte, error)
	Write(ctx context.Context, typ websocket.MessageType, p []byte) error
	CloseNow() error
}

// writeTimeout bounds one frame's write, so a connection whose far end has
// stopped reading is noticed rather than blocking the agent.
const writeTimeout = 10 * time.Second

// dialTimeout bounds one connection attempt. Generous: the networks this runs
// on are the bad ones, and a captive portal can take its time refusing.
const dialTimeout = 20 * time.Second

// Run keeps the fallback connected until the context is cancelled.
//
// It never gives up. A device on a network that only passes 443 has no other
// way to reach us at all, so backing off to the point of silence would be
// abandoning exactly the customer this exists for.
func (c *Client) Run(ctx context.Context) {
	c.since.Store(time.Now().Unix())
	c.opts.Router.DivertControl(c.opts.Coordinator)

	backoff := time.Second

	for ctx.Err() == nil {
		err := c.session(ctx)

		if ctx.Err() != nil {
			return
		}

		if err != nil {
			c.opts.Logf("fallback: %v; retrying in %s", err, backoff)
		}

		select {
		case <-ctx.Done():
			return
		case <-time.After(backoff):
		}

		if backoff < 30*time.Second {
			backoff *= 2
		}
	}
}

// session runs one connection from dial to close.
func (c *Client) session(ctx context.Context) error {
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()

	conn, err := c.dial(ctx)
	if err != nil {
		return err
	}

	defer func() {
		c.detach(conn)
		_ = conn.CloseNow()
	}()

	if err := c.handshake(ctx, conn); err != nil {
		return err
	}

	c.attach(conn)
	c.opts.Logf("fallback: connected to %s; this device is seen as %s", c.opts.URL, c.Observed())

	go c.keepalive(ctx)

	for {
		typ, msg, err := conn.Read(ctx)
		if err != nil {
			return fmt.Errorf("the connection ended: %w", err)
		}
		if typ != websocket.MessageBinary {
			continue
		}

		c.handle(msg)
	}
}

// dial opens the websocket.
func (c *Client) dial(ctx context.Context) (wsConn, error) {
	if c.dialer != nil {
		return c.dialer(ctx)
	}

	attempt, cancel := context.WithTimeout(ctx, dialTimeout)
	defer cancel()

	conn, resp, err := websocket.Dial(attempt, c.opts.URL, &websocket.DialOptions{
		Subprotocols: []string{wire.Subprotocol},
		HTTPHeader:   http.Header{"User-Agent": []string{"akconnect-agent"}},
	})
	if err != nil {
		return nil, fmt.Errorf("connecting to %s: %w", c.opts.URL, err)
	}
	if resp != nil && resp.Body != nil {
		_ = resp.Body.Close()
	}

	if conn.Subprotocol() != wire.Subprotocol {
		_ = conn.CloseNow()

		// A captive portal or a proxy that answers everything will not
		// negotiate a subprotocol it has never heard of. Saying so is more use
		// than a connection that carries nothing.
		return nil, errors.New("whatever answered on that address is not a relay")
	}

	conn.SetReadLimit(wire.MaxFrame + 64)

	return conn, nil
}

// handshake announces this device and waits to be told how it is seen.
func (c *Client) handshake(ctx context.Context, conn wsConn) error {
	deadline, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()

	hello, err := wire.EncodeHello(c.opts.SelfKey)
	if err != nil {
		return err
	}

	if err := conn.Write(deadline, websocket.MessageBinary, hello); err != nil {
		return fmt.Errorf("announcing this device: %w", err)
	}

	typ, msg, err := conn.Read(deadline)
	if err != nil {
		return fmt.Errorf("waiting to be acknowledged: %w", err)
	}
	if typ != websocket.MessageBinary {
		return errors.New("the relay answered with something that was not a frame")
	}

	kind, payload, err := wire.Decode(msg)
	if err != nil || kind != wire.KindReady {
		return errors.New("the relay did not acknowledge this device")
	}

	observed, err := wire.DecodeReady(payload)
	if err != nil {
		return err
	}

	c.observed.Store(&observed)

	return nil
}

// attach makes the connection live and routes traffic through it.
func (c *Client) attach(conn wsConn) {
	c.mu.Lock()
	c.conn = conn
	c.mu.Unlock()

	c.up.Store(true)
	c.opts.Router.UseTunnel(c)
}

// detach takes it out of service, but only if it is still the live one.
func (c *Client) detach(conn wsConn) {
	c.mu.Lock()
	live := c.conn == conn
	if live {
		c.conn = nil
	}
	c.mu.Unlock()

	if !live {
		return
	}

	c.up.Store(false)

	// The peers routed through this connection are addressed by endpoints that
	// mean nothing without it, so the diversion goes with it. Discovery
	// re-binds within seconds and the endpoints are handed out again.
	c.opts.Router.UseTunnel(nil)
	c.opts.Logf("fallback: disconnected")
}

// write sends one already-encoded frame.
func (c *Client) write(msg []byte) error {
	c.mu.Lock()
	conn := c.conn
	c.mu.Unlock()

	if conn == nil {
		return errors.New("the fallback is not connected")
	}

	ctx, cancel := context.WithTimeout(context.Background(), writeTimeout)
	defer cancel()

	return conn.Write(ctx, websocket.MessageBinary, msg)
}

// send encodes and writes one frame.
func (c *Client) send(kind wire.Kind, payload []byte) error {
	msg, err := wire.Encode(kind, payload)
	if err != nil {
		return err
	}

	return c.write(msg)
}

// keepalive holds the connection open through proxies and NATs that forget
// idle TCP flows.
func (c *Client) keepalive(ctx context.Context) {
	ticker := time.NewTicker(20 * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			if err := c.send(wire.KindKeepalive, nil); err != nil {
				return
			}
		}
	}
}

// handle dispatches one frame from the relay.
func (c *Client) handle(msg []byte) {
	kind, payload, err := wire.Decode(msg)
	if err != nil {
		return
	}

	switch kind {
	case wire.KindControl:
		// Handed to discovery as though it had arrived on the socket from the
		// coordinator, which as far as the coordinator is concerned it did.
		c.opts.Router.Inject(payload, c.opts.Coordinator)
	case wire.KindBindAck:
		c.bound(payload)
	case wire.KindData:
		c.tunnelled(payload)
	case wire.KindKeepalive:
	}
}

// tunnelled delivers one relayed WireGuard packet.
func (c *Client) tunnelled(payload []byte) {
	peer, pkt, err := wire.DecodeData(payload)
	if err != nil {
		return
	}

	at, ok := c.endpointOf(peer)
	if !ok {
		// Traffic for a peer this end has not bound for. It arrives when the
		// other end binds first, which is common, and there is nothing useful
		// to do with it until discovery has an endpoint to attribute it to.
		return
	}

	c.lastData.Store(time.Now().Unix())
	c.opts.Router.InjectTunnel(pkt, at)
}

// bound turns a fallback bind acknowledgement into the one discovery expects.
//
// Discovery knows nothing about any of this. It asked a relay to bind a pair
// and is waiting for that relay to answer with a port; it is given an answer
// from that relay's address carrying a port that routes through the tunnel.
// Everything downstream — adopting the path, reporting it, retrying it later —
// then works exactly as it does over UDP.
func (c *Client) bound(payload []byte) {
	peer, err := wire.DecodeBindAck(payload)
	if err != nil {
		return
	}

	relay, ok := c.relayOf(peer)
	if !ok {
		return
	}

	at, fresh := c.pseudoFor(peer, relay)
	if !at.IsValid() {
		return
	}

	c.opts.Router.RoutePeer(at, peer)

	if fresh {
		c.opts.Logf("fallback: carrying %x… over HTTPS", peer[:6])
	}

	ack := make([]byte, disco.HeaderLen)
	disco.WriteHeader(ack, disco.TypeRelayBindAck, [32]byte{})
	ack = disco.AppendUint16(ack, at.Port())

	c.opts.Router.Inject(ack, relay)
}
