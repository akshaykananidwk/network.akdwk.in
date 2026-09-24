// Package tunnel drives the WireGuard data plane.
//
// WireGuard itself is wireguard-go (MIT), running in userspace. That choice is
// deliberate on two counts: it needs no kernel module, so the agent installs
// the same way on a stock Windows box and a locked-down Linux host; and its
// licence permits closed-source distribution, which a GPL kernel-side
// alternative would not.
package tunnel

import (
	"fmt"
	"net/netip"
	"sort"
	"strconv"
	"strings"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/netcfg"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/wgkey"
)

// deviceState is what the running WireGuard device holds, read back from it
// with IpcGet: the thing Apply reconciles against.
type deviceState struct {
	privateKeyHex string
	listenPort    int
	peers         map[string]devicePeer // by lowercase hex public key
}

type devicePeer struct {
	endpoint  string
	allowed   []string // masked, sorted
	keepalive int
}

// parseDeviceState reads IpcGet's output.
func parseDeviceState(ipc string) deviceState {
	st := deviceState{peers: map[string]devicePeer{}}

	var current string
	for _, line := range strings.Split(ipc, "\n") {
		key, value, ok := strings.Cut(strings.TrimSpace(line), "=")
		if !ok {
			continue
		}

		switch key {
		case "private_key":
			st.privateKeyHex = strings.ToLower(value)
		case "listen_port":
			st.listenPort, _ = strconv.Atoi(value)
		case "public_key":
			current = strings.ToLower(value)
			st.peers[current] = devicePeer{}
		case "endpoint", "allowed_ip", "persistent_keepalive_interval":
			if current == "" {
				continue
			}
			p := st.peers[current]
			switch key {
			case "endpoint":
				p.endpoint = value
			case "allowed_ip":
				p.allowed = append(p.allowed, normalisePrefix(value))
			default:
				p.keepalive, _ = strconv.Atoi(value)
			}
			st.peers[current] = p
		}
	}

	for k, p := range st.peers {
		sort.Strings(p.allowed)
		st.peers[k] = p
	}

	return st
}

func normalisePrefix(s string) string {
	if prefix, err := netip.ParsePrefix(strings.TrimSpace(s)); err == nil {
		return prefix.Masked().String()
	}

	return strings.TrimSpace(s)
}

// buildApply renders the smallest UAPI push that turns what the device holds
// into what the panel's configuration says.
//
// It used to be the whole configuration every time: replace_peers=true and
// listen_port, on every revision. In wireguard-go the first removes and
// re-creates every peer — dropping its session keys, its staged packets and
// the endpoint discovery had found for it, which then reverted to the panel's
// stale hint — and the second closes and reopens the UDP socket. A revision
// moves on any device, ACL or route edit in the network, and every panel
// update moved every network's; so every pair in the fleet was reset, both
// ends within seconds of each other, and a relayed pair could stay dark until
// something else happened to repoint it.
//
// Now only what differs is sent, reconciled against the device itself rather
// than against the previous configuration, so a missed update cannot leave a
// revoked peer behind: any peer on the device and not in the configuration is
// removed, whatever the agent thinks it applied last time.
//
//   - private_key and listen_port only when they differ from the device's;
//   - a peer the device lacks is added in full, with the panel's endpoint hint;
//   - a peer whose allowed IPs or keepalive changed is updated in place
//     (update_only), and its endpoint and session are left alone;
//   - a peer the configuration no longer lists is removed — first, so that a
//     line wireguard-go refuses later cannot leave it in place;
//   - an unchanged peer is not mentioned at all.
//
// An empty string means there is nothing to do.
func buildApply(priv wgkey.Private, listenPort int, cur deviceState, cfg *panel.Config, plan *netcfg.Plan) (string, error) {
	var b strings.Builder

	if strings.ToLower(priv.Hex()) != cur.privateKeyHex {
		fmt.Fprintf(&b, "private_key=%s\n", priv.Hex())
	}
	if listenPort != cur.listenPort {
		fmt.Fprintf(&b, "listen_port=%d\n", listenPort)
	}

	keepalive := cfg.Network.Keepalive
	if keepalive < 0 {
		keepalive = 0
	}
	if keepalive > 65535 {
		keepalive = 65535
	}

	// Every key first, so removals can be written before anything else.
	//
	// wireguard-go applies a push line by line and stops at the first line it
	// refuses, keeping everything before it. Removals written last were
	// therefore skipped by any earlier failure — and a revoked peer stayed on
	// the device, able to handshake, with the old ACL still in force because
	// the failed apply never reached it.
	wanted := map[string]bool{}
	keys := make([]string, len(cfg.Peers))
	for i, peer := range cfg.Peers {
		pub, err := wgkey.ParsePublic(peer.PublicKey)
		if err != nil {
			return "", fmt.Errorf("peer %s has an unusable public key: %w", peer.UID, err)
		}
		keys[i] = strings.ToLower(pub.Hex())
		wanted[keys[i]] = true
	}

	// Sorted, so the same state always renders the same text.
	var gone []string
	for hexKey := range cur.peers {
		if !wanted[hexKey] {
			gone = append(gone, hexKey)
		}
	}
	sort.Strings(gone)
	for _, hexKey := range gone {
		fmt.Fprintf(&b, "public_key=%s\nremove=true\n", hexKey)
	}

	written := map[string]bool{}

	for i, peer := range cfg.Peers {
		hexKey := keys[i]
		if written[hexKey] {
			continue
		}
		written[hexKey] = true

		// The panel's endpoint is what another device reported about itself,
		// so it is not trusted to parse: one that does not would make
		// wireguard-go refuse the push at that line. A hint that is not an
		// IP and a port is simply not a hint; discovery supplies the address.
		hint := ""
		if ap, err := netip.ParseAddrPort(strings.TrimSpace(peer.Endpoint)); err == nil && ap.Port() != 0 {
			hint = ap.String()
		}

		allowed := make([]string, 0, len(peer.AllowedIPs))
		for _, a := range peer.AllowedIPs {
			// netcfg.Build has already refused any prefix that would capture
			// the default route, so anything reaching here is in the overlay.
			allowed = append(allowed, normalisePrefix(a))
		}
		sort.Strings(allowed)

		have, exists := cur.peers[hexKey]

		if !exists {
			fmt.Fprintf(&b, "public_key=%s\n", hexKey)
			b.WriteString("replace_allowed_ips=true\n")
			for _, a := range allowed {
				fmt.Fprintf(&b, "allowed_ip=%s\n", a)
			}
			// An endpoint is a hint, not a requirement: without one the peer
			// can still reach us, and discovery will supply one shortly.
			if hint != "" {
				fmt.Fprintf(&b, "endpoint=%s\n", hint)
			}
			if keepalive > 0 {
				fmt.Fprintf(&b, "persistent_keepalive_interval=%d\n", keepalive)
			}

			continue
		}

		sameAllowed := strings.Join(allowed, ",") == strings.Join(have.allowed, ",")
		sameKeepalive := keepalive == have.keepalive
		// A peer the device has never had an address for may take the hint;
		// one it has is left alone — that address is discovery's, and newer
		// than anything the panel knows.
		takeHint := have.endpoint == "" && hint != ""

		if sameAllowed && sameKeepalive && !takeHint {
			continue
		}

		fmt.Fprintf(&b, "public_key=%s\n", hexKey)
		b.WriteString("update_only=true\n")
		if !sameAllowed {
			b.WriteString("replace_allowed_ips=true\n")
			for _, a := range allowed {
				fmt.Fprintf(&b, "allowed_ip=%s\n", a)
			}
		}
		if takeHint {
			fmt.Fprintf(&b, "endpoint=%s\n", hint)
		}
		if !sameKeepalive {
			fmt.Fprintf(&b, "persistent_keepalive_interval=%d\n", keepalive)
		}
	}

	return b.String(), nil
}

// buildEndpointUpdate renders a minimal UAPI push that repoints one peer,
// leaving its allowed_ips and everything else untouched. This is what NAT
// traversal uses when it learns a better path to a peer mid-session.
func buildEndpointUpdate(publicKey, endpoint string) (string, error) {
	pub, err := wgkey.ParsePublic(publicKey)
	if err != nil {
		return "", fmt.Errorf("unusable public key: %w", err)
	}

	var b strings.Builder
	fmt.Fprintf(&b, "public_key=%s\n", pub.Hex())
	fmt.Fprintf(&b, "update_only=true\n")
	fmt.Fprintf(&b, "endpoint=%s\n", endpoint)

	return b.String(), nil
}
