//go:build !windows

package disconn

// ignorableReceiveError: an unconnected UDP socket outside Windows does not
// report ICMP errors on receive, so nothing is ignorable here.
func ignorableReceiveError(error) bool { return false }
