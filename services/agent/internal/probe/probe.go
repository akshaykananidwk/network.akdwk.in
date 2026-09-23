// Package probe answers "can this machine reach that address?".
//
// The panel cannot answer it. It sits on the public internet, it has no route
// into the overlay and none at all into a customer's own network, so the only
// honest place to measure reachability is the machine somebody is asking
// about. The agent is already inside both.
//
// Two methods, in order, because neither alone is enough:
//
//   - ICMP echo, which is what a person means by "ping".
//   - A TCP connect, to the ports this equipment actually answers on. Plenty
//     of devices ignore ICMP — cameras and recorders especially, and Windows
//     blocks echo by default — so "no ping reply" alone would condemn a
//     device that is working perfectly. A completed handshake proves
//     reachability outright.
//
// The distinction matters on the page. "No answer to ping, but its web page
// answers on 80" is the ordinary state of a working NVR, and a support person
// needs to be told that rather than shown a red cross.
package probe

import (
	"context"
	"fmt"
	"net"
	"strconv"
	"time"
)

// Result is one answer.
type Result struct {
	OK        bool
	LatencyMS int
	// Method is how it was proved: "icmp", or "tcp/80" naming the port that
	// answered. Empty when nothing answered.
	Method string
	Error  string
}

// tcpPorts are tried in order when ICMP says nothing.
//
// The list is the equipment this product is sold around: a web interface on
// 80 or 443, the RTSP port every camera and recorder serves, the two
// proprietary ports Dahua and Hikvision recorders use, and SSH last because a
// Linux box answering nothing else will answer that.
var tcpPorts = []int{80, 443, 554, 8000, 37777, 22}

// Timeout is how long one attempt may take. Short, because somebody is
// watching a page and a slow answer is worse than a clear failure.
const Timeout = 2 * time.Second

// Do tests one address and says how it went.
func Do(ctx context.Context, target string) Result {
	if net.ParseIP(target) == nil {
		return Result{Error: "not an IP address"}
	}

	if ms, err := pingICMP(ctx, target); err == nil {
		return Result{OK: true, LatencyMS: ms, Method: "icmp"}
	}

	// ICMP said nothing, which is not the same as unreachable. Ask the ports
	// this equipment actually serves.
	for _, port := range tcpPorts {
		start := time.Now()

		dialer := net.Dialer{Timeout: Timeout}
		conn, err := dialer.DialContext(ctx, "tcp", net.JoinHostPort(target, strconv.Itoa(port)))
		if err != nil {
			continue
		}
		_ = conn.Close()

		return Result{
			OK:        true,
			LatencyMS: int(time.Since(start).Milliseconds()),
			Method:    "tcp/" + strconv.Itoa(port),
		}
	}

	return Result{Error: fmt.Sprintf(
		"no reply to ping, and nothing answered on %v", tcpPorts)}
}
