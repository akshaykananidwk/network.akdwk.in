package probe

import (
	"context"
	"errors"
	"net"
	"os"
	"time"

	"golang.org/x/net/icmp"
	"golang.org/x/net/ipv4"
)

// pingICMP sends one echo request and waits for its reply.
//
// One, not four. A person clicking Test wants an answer now, and four
// sequential two-second timeouts is eight seconds of a spinner to learn what
// the first one already said. The TCP fallback covers the case where a single
// datagram was unlucky.
//
// The socket is opened unprivileged first ("udp4", which Linux allows through
// net.ipv4.ping_group_range and macOS allows outright) and falls back to a raw
// socket. On Windows the agent is a service and has the raw socket; on Linux
// it may or may not, and a missing privilege is reported as such rather than
// as the device being unreachable — those are opposite conclusions and only
// one of them is about the customer's network.
func pingICMP(ctx context.Context, target string) (int, error) {
	addr, err := net.ResolveIPAddr("ip4", target)
	if err != nil {
		return 0, err
	}

	conn, network, err := listenICMP()
	if err != nil {
		return 0, err
	}
	defer conn.Close()

	deadline := time.Now().Add(Timeout)
	if d, ok := ctx.Deadline(); ok && d.Before(deadline) {
		deadline = d
	}
	_ = conn.SetDeadline(deadline)

	id := os.Getpid() & 0xFFFF
	message := icmp.Message{
		Type: ipv4.ICMPTypeEcho,
		Body: &icmp.Echo{ID: id, Seq: 1, Data: []byte("akconnect")},
	}

	encoded, err := message.Marshal(nil)
	if err != nil {
		return 0, err
	}

	destination := net.Addr(addr)
	if network == "udp4" {
		// An unprivileged socket addresses by UDP, and the kernel rewrites
		// the echo id — which is why the reply is matched on the address it
		// came from rather than on the id we put in.
		destination = &net.UDPAddr{IP: addr.IP}
	}

	start := time.Now()
	if _, err := conn.WriteTo(encoded, destination); err != nil {
		return 0, err
	}

	buf := make([]byte, 512)
	for {
		n, from, err := conn.ReadFrom(buf)
		if err != nil {
			return 0, err
		}

		if !sameHost(from, addr.IP) {
			continue
		}

		parsed, err := icmp.ParseMessage(ipv4.ICMPTypeEchoReply.Protocol(), buf[:n])
		if err != nil {
			continue
		}
		if parsed.Type != ipv4.ICMPTypeEchoReply {
			continue
		}

		return int(time.Since(start).Milliseconds()), nil
	}
}

// listenICMP opens the best socket this process is allowed.
func listenICMP() (*icmp.PacketConn, string, error) {
	if conn, err := icmp.ListenPacket("udp4", "0.0.0.0"); err == nil {
		return conn, "udp4", nil
	}

	conn, err := icmp.ListenPacket("ip4:icmp", "0.0.0.0")
	if err != nil {
		return nil, "", errors.New("this machine does not allow ping from the agent: " + err.Error())
	}

	return conn, "ip4:icmp", nil
}

func sameHost(addr net.Addr, want net.IP) bool {
	switch v := addr.(type) {
	case *net.UDPAddr:
		return v.IP.Equal(want)
	case *net.IPAddr:
		return v.IP.Equal(want)
	}

	return false
}
