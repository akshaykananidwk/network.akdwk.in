//go:build windows

package disconn

import (
	"fmt"
	"testing"

	"golang.org/x/sys/windows"
)

// An ICMP port-unreachable surfaces on the next receive as WSAECONNRESET, and
// wireguard-go ends its receive goroutine on any error that is not Temporary.
func TestAnICMPErrorOnReceiveIsNotFatal(t *testing.T) {
	for _, errno := range []windows.Errno{windows.WSAECONNRESET, windows.WSAENETRESET, windows.ERROR_PORT_UNREACHABLE} {
		if !ignorableReceiveError(fmt.Errorf("wrapped: %w", errno)) {
			t.Fatalf("%v would stop the receive goroutine", errno)
		}
	}
	if ignorableReceiveError(windows.WSAENOTSOCK) {
		t.Fatal("a closed socket was treated as nothing arriving")
	}
}
