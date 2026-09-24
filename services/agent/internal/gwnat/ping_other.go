//go:build !linux && !windows

package gwnat

import (
	"context"
	"errors"
	"net/netip"
)

func platformPing(context.Context, netip.Addr, uint16, uint16, []byte) (bool, error) {
	return false, errors.New("ping is not implemented on this platform")
}
