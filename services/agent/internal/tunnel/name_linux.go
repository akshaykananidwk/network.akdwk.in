//go:build linux

package tunnel

// defaultInterfaceName is short because Linux caps interface names at 15
// characters and tooling output is easier to read when it fits.
const defaultInterfaceName = "akc0"
