//go:build windows

package gwnat

import (
	"context"
	"fmt"
	"net/netip"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"
)

var (
	iphlpapi         = windows.NewLazySystemDLL("iphlpapi.dll")
	procIcmpCreate   = iphlpapi.NewProc("IcmpCreateFile")
	procIcmpClose    = iphlpapi.NewProc("IcmpCloseHandle")
	procIcmpSendEcho = iphlpapi.NewProc("IcmpSendEcho")
)

// icmpEchoReply is ICMP_ECHO_REPLY (32-bit layout on both architectures'
// IPv4 API; the pointer is the only field whose size varies).
type icmpEchoReply struct {
	Address       uint32
	Status        uint32
	RoundTripTime uint32
	DataSize      uint16
	Reserved      uint16
	Data          uintptr
	Options       [8]byte
}

// platformPing uses IcmpSendEcho, the call ping.exe itself makes: it needs no
// raw socket, no privilege and no firewall rule, and the reply is matched by
// the system. The id and sequence are chosen by Windows; the peer's are put
// back on the reply we synthesise.
func platformPing(ctx context.Context, dst netip.Addr, _, _ uint16, data []byte) (bool, error) {
	if !dst.Is4() {
		return false, fmt.Errorf("only IPv4 is translated")
	}

	h, _, callErr := procIcmpCreate.Call()
	if h == uintptr(windows.InvalidHandle) {
		return false, fmt.Errorf("IcmpCreateFile: %v", callErr)
	}
	defer procIcmpClose.Call(h)

	timeout := pingTimeout
	if deadline, ok := ctx.Deadline(); ok {
		timeout = time.Until(deadline)
	}
	if timeout < 100*time.Millisecond {
		timeout = 100 * time.Millisecond
	}

	a := dst.As4()
	addr := uint32(a[0]) | uint32(a[1])<<8 | uint32(a[2])<<16 | uint32(a[3])<<24

	reply := make([]byte, int(unsafe.Sizeof(icmpEchoReply{}))+len(data)+8+64)
	var dataPtr uintptr
	if len(data) > 0 {
		dataPtr = uintptr(unsafe.Pointer(&data[0]))
	}

	n, _, _ := procIcmpSendEcho.Call(
		h, uintptr(addr), dataPtr, uintptr(len(data)), 0,
		uintptr(unsafe.Pointer(&reply[0])), uintptr(len(reply)), uintptr(timeout/time.Millisecond))
	if n == 0 {
		return false, nil
	}

	r := (*icmpEchoReply)(unsafe.Pointer(&reply[0]))

	return r.Status == 0, nil // IP_SUCCESS
}
