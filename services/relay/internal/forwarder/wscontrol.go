package forwarder

import (
	"context"
	"fmt"
	"net"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/shared/disco"
	"github.com/akshaykananidwk/network.akdwk.in/services/shared/fallback"
)

// toCoordinator proxies one sealed control packet and starts listening for the
// answer.
//
// The coordinator is not modified for any of this and does not know the
// fallback exists. It receives ordinary UDP from the relay and replies to the
// source, which is the socket opened here. Everything inside is sealed to the
// coordinator's key by the agent, so this hop reads none of it and could not
// alter it undetected if it tried.
func (c *wsClient) toCoordinator(ctx context.Context, payload []byte) error {
	if !c.edge.coordinator.IsValid() {
		return fmt.Errorf("this relay carries no control traffic")
	}

	if len(payload) > disco.MaxPacket {
		return fmt.Errorf("control packet of %d bytes is too large", len(payload))
	}

	conn, err := c.controlSocket(ctx)
	if err != nil {
		return err
	}

	if _, err := conn.WriteToUDPAddrPort(payload, c.edge.coordinator); err != nil {
		return fmt.Errorf("proxying to the coordinator: %w", err)
	}

	return nil
}

// controlSocket opens the per-agent UDP socket on first use.
//
// One socket per agent, not one shared by all of them: the coordinator replies
// to the source address, and a shared socket would leave the relay guessing
// which agent an answer belongs to. A port per fallback agent is affordable
// precisely because the fallback is the exception.
func (c *wsClient) controlSocket(ctx context.Context) (*net.UDPConn, error) {
	c.mu.Lock()
	defer c.mu.Unlock()

	if c.control != nil {
		return c.control, nil
	}

	conn, err := net.ListenUDP("udp", &net.UDPAddr{})
	if err != nil {
		return nil, fmt.Errorf("opening a control socket: %w", err)
	}

	c.control = conn

	go c.fromCoordinator(ctx, conn)

	return conn, nil
}

// fromCoordinator returns the coordinator's answers to the agent.
func (c *wsClient) fromCoordinator(ctx context.Context, conn *net.UDPConn) {
	defer conn.Close()

	go func() {
		<-ctx.Done()
		_ = conn.Close()
	}()

	buf := make([]byte, disco.MaxPacket)

	for {
		if err := conn.SetReadDeadline(time.Now().Add(controlIdle)); err != nil {
			return
		}

		n, from, err := conn.ReadFromUDPAddrPort(buf)
		if err != nil {
			return
		}

		// Only the coordinator we were configured with. Without this an
		// off-path sender who guessed the port could inject packets that the
		// agent would treat as the coordinator's — they would still have to
		// be sealed with its key to be read, but refusing early is cheaper
		// than proving that every time.
		if from.Addr().Unmap() != c.edge.coordinator.Addr() || from.Port() != c.edge.coordinator.Port() {
			continue
		}

		msg, err := fallback.Encode(fallback.KindControl, buf[:n])
		if err != nil {
			continue
		}

		if err := c.writeMessage(ctx, msg); err != nil {
			return
		}
	}
}
