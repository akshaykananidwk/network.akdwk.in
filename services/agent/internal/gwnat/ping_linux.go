//go:build linux

package gwnat

import (
	"context"
	"net"
	"net/netip"
	"time"

	"golang.org/x/net/icmp"
	"golang.org/x/net/ipv4"
)

// platformPing sends one echo from this machine with a raw ICMP socket (the
// agent runs as root on Linux) and waits for the matching reply.
func platformPing(ctx context.Context, dst netip.Addr, id, seq uint16, data []byte) (bool, error) {
	conn, err := icmp.ListenPacket("ip4:icmp", "0.0.0.0")
	if err != nil {
		return false, err
	}
	defer conn.Close()

	msg := icmp.Message{Type: ipv4.ICMPTypeEcho, Body: &icmp.Echo{ID: int(id), Seq: int(seq), Data: data}}
	raw, err := msg.Marshal(nil)
	if err != nil {
		return false, err
	}
	if _, err := conn.WriteTo(raw, &net.IPAddr{IP: dst.AsSlice()}); err != nil {
		return false, err
	}

	deadline, ok := ctx.Deadline()
	if !ok {
		deadline = time.Now().Add(pingTimeout)
	}
	conn.SetReadDeadline(deadline)

	buf := make([]byte, 1500)
	for {
		n, from, err := conn.ReadFrom(buf)
		if err != nil {
			return false, nil // timed out: unanswered, not an error
		}
		if ip, ok := from.(*net.IPAddr); !ok || !ip.IP.Equal(dst.AsSlice()) {
			continue
		}
		reply, err := icmp.ParseMessage(1, buf[:n])
		if err != nil || reply.Type != ipv4.ICMPTypeEchoReply {
			continue
		}
		if echo, ok := reply.Body.(*icmp.Echo); ok && echo.ID == int(id) && echo.Seq == int(seq) {
			return true, nil
		}
	}
}
