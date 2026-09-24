package gwnat

import (
	"context"
	"encoding/binary"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"os"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"golang.zx2c4.com/wireguard/tun"
	"gvisor.dev/gvisor/pkg/buffer"
	"gvisor.dev/gvisor/pkg/tcpip"
	"gvisor.dev/gvisor/pkg/tcpip/adapters/gonet"
	"gvisor.dev/gvisor/pkg/tcpip/header"
	"gvisor.dev/gvisor/pkg/tcpip/link/channel"
	"gvisor.dev/gvisor/pkg/tcpip/network/ipv4"
	"gvisor.dev/gvisor/pkg/tcpip/stack"
	"gvisor.dev/gvisor/pkg/tcpip/transport/tcp"
	"gvisor.dev/gvisor/pkg/tcpip/transport/udp"
)

// fakeOS stands in for the operating system's side of the tunnel interface:
// what it is handed (packets the gateway did not translate) and what it has
// to say (packets that were never for the translation).
type fakeOS struct {
	mu      sync.Mutex
	written [][]byte
	toRead  chan []byte
	closed  chan struct{}
	once    sync.Once
}

func newFakeOS() *fakeOS { return &fakeOS{toRead: make(chan []byte, 16), closed: make(chan struct{})} }

func (f *fakeOS) File() *os.File           { return nil }
func (f *fakeOS) MTU() (int, error)        { return 1280, nil }
func (f *fakeOS) Name() (string, error)    { return "fake", nil }
func (f *fakeOS) Events() <-chan tun.Event { return nil }
func (f *fakeOS) BatchSize() int           { return 1 }
func (f *fakeOS) Close() error {
	f.once.Do(func() { close(f.closed) })
	return nil
}
func (f *fakeOS) Write(bufs [][]byte, offset int) (int, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	for _, b := range bufs {
		f.written = append(f.written, append([]byte(nil), b[offset:]...))
	}
	return len(bufs), nil
}
func (f *fakeOS) Read(bufs [][]byte, sizes []int, offset int) (int, error) {
	select {
	case p := <-f.toRead:
		sizes[0] = copy(bufs[0][offset:], p)
		return 1, nil
	case <-f.closed:
		return 0, os.ErrClosed
	}
}

// laptop is a peer's TCP/IP stack at 10.50.0.4, joined to the gateway the way
// the tunnel joins them: what it sends is written to the gateway device, and
// what the gateway device reads is delivered to it.
type laptop struct {
	stack *stack.Stack
	ep    *channel.Endpoint
}

func newLaptop(t *testing.T, gw *Device) *laptop {
	t.Helper()
	s := stack.New(stack.Options{
		NetworkProtocols:   []stack.NetworkProtocolFactory{ipv4.NewProtocol},
		TransportProtocols: []stack.TransportProtocolFactory{tcp.NewProtocol, udp.NewProtocol},
	})
	ep := channel.New(256, 1280, "")
	if err := s.CreateNIC(1, ep); err != nil {
		t.Fatal(err)
	}
	s.AddProtocolAddress(1, tcpip.ProtocolAddress{
		Protocol:          ipv4.ProtocolNumber,
		AddressWithPrefix: tcpip.AddrFrom4([4]byte{10, 50, 0, 4}).WithPrefix(),
	}, stack.AddressProperties{})
	s.SetRouteTable([]tcpip.Route{{Destination: header.IPv4EmptySubnet, NIC: 1}})

	l := &laptop{stack: s, ep: ep}

	// laptop -> gateway
	go func() {
		for {
			pkt := ep.ReadContext(context.Background())
			if pkt == nil {
				return
			}
			view := pkt.ToView()
			pkt.DecRef()
			gw.Write([][]byte{view.AsSlice()}, 0)
		}
	}()
	// gateway -> laptop
	go func() {
		bufs := [][]byte{make([]byte, 65535)}
		sizes := []int{0}
		for {
			n, err := gw.Read(bufs, sizes, 0)
			if err != nil {
				return
			}
			for i := 0; i < n; i++ {
				data := append([]byte(nil), bufs[i][:sizes[i]]...)
				if len(data) > 0 && data[0]>>4 == 4 && data[9] == 1 {
					icmpSeen <- data // the ICMP test reads these directly
					continue
				}
				pkb := stack.NewPacketBuffer(stack.PacketBufferOptions{Payload: buffer.MakeWithData(data)})
				ep.InjectInbound(header.IPv4ProtocolNumber, pkb)
				pkb.DecRef()
			}
		}
	}()
	t.Cleanup(func() { s.Close(); ep.Close() })

	return l
}

var icmpSeen = make(chan []byte, 8)

func (l *laptop) dialTCP(ctx context.Context, dst netip.AddrPort) (net.Conn, error) {
	return gonet.DialContextTCP(ctx, l.stack, tcpip.FullAddress{
		NIC: 1, Addr: tcpip.AddrFromSlice(dst.Addr().AsSlice()), Port: dst.Port(),
	}, ipv4.ProtocolNumber)
}

// onLAN is the LAN address a loopback test server stands for.
func onLAN(loopback string) netip.AddrPort {
	ap := netip.MustParseAddrPort(loopback)
	return netip.AddrPortFrom(netip.MustParseAddr("192.0.2.1"), ap.Port())
}

func gateway(t *testing.T, ping func(context.Context, netip.Addr, uint16, uint16, []byte) (bool, error)) (*Device, *fakeOS) {
	t.Helper()
	os := newFakeOS()
	// The "LAN" is 192.0.2.0/24, and dialling it reaches the same port on
	// loopback: real sockets on this machine stand in for the router and the
	// camera recorder. (Loopback itself cannot be the LAN: a stack drops
	// 127/8 arriving on an interface, as a real one does.)
	dial := func(ctx context.Context, network, addr string) (net.Conn, error) {
		ap := netip.MustParseAddrPort(addr)
		var d net.Dialer
		return d.DialContext(ctx, network, netip.AddrPortFrom(netip.MustParseAddr("127.0.0.1"), ap.Port()).String())
	}
	gw := Wrap(os, Options{Userspace: true, Ping: ping, Dial: dial})
	if err := gw.SetLANs(netip.MustParsePrefix("10.50.0.0/16"), []netip.Prefix{netip.MustParsePrefix("192.0.2.0/24")}, 1280); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { gw.Close() })

	return gw, os
}

// The field defect: a laptop reaches a web page on the gateway's LAN, and the
// LAN machine sees an ordinary connection from this machine — no NAT anywhere
// on the operating system, no route on the LAN.
func TestAPeerReachesAWebServerOnTheGatewaysLAN(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		fmt.Fprintf(w, "router says hello to %s", r.RemoteAddr)
	}))
	defer srv.Close()
	dst := onLAN(strings.TrimPrefix(srv.URL, "http://"))

	gw, _ := gateway(t, nil)
	lap := newLaptop(t, gw)

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	conn, err := lap.dialTCP(ctx, dst)
	if err != nil {
		t.Fatalf("the laptop could not connect through the gateway: %v", err)
	}
	defer conn.Close()

	fmt.Fprintf(conn, "GET / HTTP/1.0\r\nHost: router\r\n\r\n")
	conn.SetReadDeadline(time.Now().Add(10 * time.Second))
	body, _ := io.ReadAll(conn)
	if !strings.Contains(string(body), "200 OK") || !strings.Contains(string(body), "router says hello to 127.0.0.1:") {
		t.Fatalf("unexpected answer through the gateway:\n%s", body)
	}
	if gw.Counters().TCPOpened.Load() != 1 {
		t.Fatal("the connection was not counted")
	}
}

// A port nothing listens on is refused, as it would be without us in the way.
func TestAClosedPortOnTheLANIsRefusedNotHung(t *testing.T) {
	l, _ := net.Listen("tcp", "127.0.0.1:0")
	dst := onLAN(l.Addr().String())
	l.Close()

	gw, _ := gateway(t, nil)
	lap := newLaptop(t, gw)

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	start := time.Now()
	if _, err := lap.dialTCP(ctx, dst); err == nil {
		t.Fatal("a closed port accepted the connection")
	}
	if time.Since(start) > 4*time.Second {
		t.Fatal("a closed port was not refused promptly")
	}
}

func TestUDPReachesTheLANAndTheAnswerComesBack(t *testing.T) {
	pc, _ := net.ListenPacket("udp", "127.0.0.1:0")
	defer pc.Close()
	go func() {
		buf := make([]byte, 1500)
		for {
			n, from, err := pc.ReadFrom(buf)
			if err != nil {
				return
			}
			pc.WriteTo(append([]byte("echo:"), buf[:n]...), from)
		}
	}()
	dst := onLAN(pc.LocalAddr().String())

	gw, _ := gateway(t, nil)
	lap := newLaptop(t, gw)

	conn, err := gonet.DialUDP(lap.stack, nil, &tcpip.FullAddress{
		NIC: 1, Addr: tcpip.AddrFromSlice(dst.Addr().AsSlice()), Port: dst.Port(),
	}, ipv4.ProtocolNumber)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()

	conn.Write([]byte("dns?"))
	conn.SetReadDeadline(time.Now().Add(5 * time.Second))
	buf := make([]byte, 100)
	n, err := conn.Read(buf)
	if err != nil || string(buf[:n]) != "echo:dns?" {
		t.Fatalf("UDP through the gateway: %q %v", buf[:n], err)
	}
}

// ping 10.128.0.1 from the laptop: the gateway pings the router for real and
// answers with a reply that looks like the router's.
func TestAPingToTheLANRouterIsAnsweredFromTheRouter(t *testing.T) {
	var asked netip.Addr
	gw, _ := gateway(t, func(_ context.Context, dst netip.Addr, _, _ uint16, _ []byte) (bool, error) {
		asked = dst
		return true, nil
	})
	newLaptop(t, gw)

	router := netip.MustParseAddr("192.0.2.1")
	echo := echoRequest(netip.MustParseAddr("10.50.0.4"), router, 0x1234, 7, []byte("abcdefgh"))
	gw.Write([][]byte{echo}, 0)

	select {
	case reply := <-icmpSeen:
		if asked != router {
			t.Fatalf("the gateway pinged %v, not the router", asked)
		}
		src, _ := netip.AddrFromSlice(reply[12:16])
		dst, _ := netip.AddrFromSlice(reply[16:20])
		if src != router || dst != netip.MustParseAddr("10.50.0.4") {
			t.Fatalf("reply %v -> %v", src, dst)
		}
		if reply[20] != 0 || binary.BigEndian.Uint16(reply[24:26]) != 0x1234 || binary.BigEndian.Uint16(reply[26:28]) != 7 ||
			string(reply[28:]) != "abcdefgh" {
			t.Fatal("the reply does not echo the request")
		}
		if checksum(reply[:20]) != 0 || checksum(reply[20:]) != 0 {
			t.Fatal("a checksum is wrong: Windows would discard the reply")
		}
	case <-time.After(5 * time.Second):
		t.Fatal("no reply to the ping")
	}
}

// Everything that is not for a translated LAN goes to the operating system
// exactly as before, and what the operating system sends comes up.
func TestTrafficNotForTheLANIsUntouched(t *testing.T) {
	gw, fos := gateway(t, nil)

	toOverlay := echoRequest(netip.MustParseAddr("10.50.0.4"), netip.MustParseAddr("10.50.0.2"), 1, 1, nil)
	gw.Write([][]byte{toOverlay}, 0)
	fos.mu.Lock()
	n := len(fos.written)
	fos.mu.Unlock()
	if n != 1 {
		t.Fatalf("a packet for the gateway itself was not handed to the OS (%d)", n)
	}

	fos.toRead <- []byte{0x45, 0, 0, 20}
	bufs := [][]byte{make([]byte, 1500)}
	sizes := []int{0}
	got, err := gw.Read(bufs, sizes, 0)
	if err != nil || got != 1 || sizes[0] != 4 {
		t.Fatalf("the OS's packet did not come up: n=%d err=%v", got, err)
	}
}

func TestWithoutUserspaceTheWrapperIsInvisible(t *testing.T) {
	fos := newFakeOS()
	gw := Wrap(fos, Options{Userspace: false})
	gw.SetLANs(netip.MustParsePrefix("10.50.0.0/16"), []netip.Prefix{netip.MustParsePrefix("192.0.2.0/24")}, 1280)
	pkt := echoRequest(netip.MustParseAddr("10.50.0.4"), netip.MustParseAddr("192.0.2.1"), 1, 1, nil)
	gw.Write([][]byte{pkt}, 0)
	if len(fos.written) != 1 {
		t.Fatal("with userspace off, a LAN packet did not go to the OS")
	}
}

func echoRequest(src, dst netip.Addr, id, seq uint16, data []byte) []byte {
	p := echoReply(src, dst, id, seq, data)
	p[20] = 8 // echo request
	p[22], p[23] = 0, 0
	binary.BigEndian.PutUint16(p[22:24], checksum(p[20:]))
	return p
}

// A flood of pings to an address that never answers holds at most
// maxPingsInFlight goroutines and sockets; the rest are dropped, as a busy
// router drops them, and counted.
func TestAPingFloodIsBounded(t *testing.T) {
	var inFlight, peak atomic.Int64
	release := make(chan struct{})
	gw, _ := gateway(t, func(ctx context.Context, _ netip.Addr, _, _ uint16, _ []byte) (bool, error) {
		n := inFlight.Add(1)
		for {
			p := peak.Load()
			if n <= p || peak.CompareAndSwap(p, n) {
				break
			}
		}
		defer inFlight.Add(-1)
		select {
		case <-release:
		case <-ctx.Done():
		}

		return false, nil
	})
	defer close(release)

	const sent = maxPingsInFlight + 100
	for i := 0; i < sent; i++ {
		gw.Write([][]byte{echoRequest(netip.MustParseAddr("10.50.0.4"), netip.MustParseAddr("192.0.2.9"), 1, uint16(i), nil)}, 0)
	}

	deadline := time.Now().Add(2 * time.Second)
	for inFlight.Load() < maxPingsInFlight && time.Now().Before(deadline) {
		time.Sleep(5 * time.Millisecond)
	}
	if got := peak.Load(); got > maxPingsInFlight {
		t.Fatalf("%d pings in flight at once, the bound is %d", got, maxPingsInFlight)
	}
	if got := gw.Counters().Dropped.Load(); got < sent-maxPingsInFlight {
		t.Fatalf("dropped %d of the excess %d", got, sent-maxPingsInFlight)
	}
}

// Only a unicast host on the LAN is translated. A peer asking for the
// range's broadcast or network address, or for multicast, gets nothing — not
// a broadcast from the gateway onto the site's LAN — and the operating
// system, which an older agent left forwarding, never sees it either.
func TestBroadcastAndMulticastAreNotTranslated(t *testing.T) {
	var pinged atomic.Int64
	gw, fos := gateway(t, func(context.Context, netip.Addr, uint16, uint16, []byte) (bool, error) {
		pinged.Add(1)
		return true, nil
	})

	peer := netip.MustParseAddr("10.50.0.4")
	for _, dst := range []string{"192.0.2.255", "192.0.2.0", "224.0.0.251", "255.255.255.255"} {
		gw.Write([][]byte{echoRequest(peer, netip.MustParseAddr(dst), 1, 1, nil)}, 0)
	}
	time.Sleep(50 * time.Millisecond)

	if pinged.Load() != 0 {
		t.Fatalf("%d of them were pinged on the LAN", pinged.Load())
	}
	fos.mu.Lock()
	leaked := 0
	for _, p := range fos.written {
		if dst := netip.AddrFrom4([4]byte(p[16:20])); dst.String() != "224.0.0.251" && dst.String() != "255.255.255.255" {
			leaked++
		}
	}
	fos.mu.Unlock()
	if leaked != 0 {
		t.Fatalf("%d broadcast packets for the LAN were handed to the operating system", leaked)
	}

	for _, tc := range []struct {
		addr string
		want bool
	}{{"192.0.2.1", true}, {"192.0.2.254", true}, {"192.0.2.0", false}, {"192.0.2.255", false}} {
		if got := unicastHostOf(netip.MustParsePrefix("192.0.2.0/24"), netip.MustParseAddr(tc.addr)); got != tc.want {
			t.Fatalf("unicastHostOf(%s) = %v", tc.addr, got)
		}
	}
	if !unicastHostOf(netip.MustParsePrefix("192.0.2.7/32"), netip.MustParseAddr("192.0.2.7")) {
		t.Fatal("a host route's only address is refused")
	}
}

// A fragment of a ping is not answered: its data is split across packets,
// and echoing the first alone would hand back the wrong bytes.
func TestAFragmentedPingIsNotAnswered(t *testing.T) {
	var pinged atomic.Int64
	gw, _ := gateway(t, func(context.Context, netip.Addr, uint16, uint16, []byte) (bool, error) {
		pinged.Add(1)
		return true, nil
	})

	first := echoRequest(netip.MustParseAddr("10.50.0.4"), netip.MustParseAddr("192.0.2.1"), 1, 1, make([]byte, 64))
	first[6] |= 0x20 // more fragments
	gw.Write([][]byte{first}, 0)
	time.Sleep(50 * time.Millisecond)

	if pinged.Load() != 0 {
		t.Fatal("a fragment was pinged as if it were the whole echo")
	}
}

// An error from the interface that arrives behind packets Read has already
// taken is returned by the next Read, not dropped: wireguard-go stops reading
// only when it sees it.
func TestAnErrorBehindPacketsIsNotLost(t *testing.T) {
	gw, fos := gateway(t, nil)

	fos.toRead <- echoRequest(netip.MustParseAddr("10.50.0.2"), netip.MustParseAddr("10.50.0.4"), 1, 1, nil)
	bufs := [][]byte{make([]byte, 2048), make([]byte, 2048)}
	sizes := make([]int, 2)

	// Let the pump queue the packet, then close the interface under it.
	deadline := time.Now().Add(2 * time.Second)
	gw.pumpOnce.Do(func() { go gw.pump() })
	for len(gw.fromOS) == 0 && time.Now().Before(deadline) {
		time.Sleep(time.Millisecond)
	}
	fos.Close()
	for len(gw.fromOS) < 2 && time.Now().Before(deadline) {
		time.Sleep(time.Millisecond)
	}

	if n, err := gw.Read(bufs, sizes, 0); n != 1 || err != nil {
		t.Fatalf("first Read = %d, %v; want the packet", n, err)
	}
	if _, err := gw.Read(bufs, sizes, 0); err == nil {
		t.Fatal("the interface's error was lost; the next Read would block for ever")
	}
}
