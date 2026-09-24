//go:build windows

package disconn

import (
	"errors"

	"golang.org/x/sys/windows"
)

// ignorableReceiveError: an ICMP error about a datagram this socket sent
// earlier, reported on a later receive. See intercept.
func ignorableReceiveError(err error) bool {
	var errno windows.Errno
	if !errors.As(err, &errno) {
		return false
	}

	switch errno {
	case windows.WSAECONNRESET, windows.WSAENETRESET,
		windows.ERROR_PORT_UNREACHABLE, windows.ERROR_HOST_UNREACHABLE, windows.ERROR_NETWORK_UNREACHABLE:
		return true
	}

	return false
}
