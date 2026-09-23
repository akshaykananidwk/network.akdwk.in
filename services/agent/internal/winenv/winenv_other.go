//go:build !windows

package winenv

import "os"

// On Linux the equivalent checks live in the keystore and the tunnel: the key
// file's mode is verified when it is read, and creating a TUN device fails
// with a clear error if the process lacks CAP_NET_ADMIN.
func preflight() []Problem { return nil }

func isAdmin() bool { return os.Geteuid() == 0 }
