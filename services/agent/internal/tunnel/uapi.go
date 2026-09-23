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
	"strings"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/netcfg"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/wgkey"
)

// buildUAPI renders a configuration in WireGuard's IPC format.
//
// The private key is written here, into a string that goes straight into the
// in-process device and is never logged or persisted. This is the only place
// in the agent where private key material is formatted for anything other than
// the keystore.
func buildUAPI(priv wgkey.Private, listenPort int, cfg *panel.Config, plan *netcfg.Plan) (string, error) {
	var b strings.Builder

	fmt.Fprintf(&b, "private_key=%s\n", priv.Hex())
	fmt.Fprintf(&b, "listen_port=%d\n", listenPort)
	// Every push replaces the peer set wholesale. Incremental updates would
	// leave a revoked peer configured if one update were ever missed, and a
	// peer that should be gone but is not is the worst kind of stale state.
	b.WriteString("replace_peers=true\n")

	for _, peer := range cfg.Peers {
		if err := appendPeer(&b, peer, cfg.Network.Keepalive, plan); err != nil {
			return "", err
		}
	}

	return b.String(), nil
}

func appendPeer(b *strings.Builder, peer panel.Peer, keepalive int, plan *netcfg.Plan) error {
	pub, err := wgkey.ParsePublic(peer.PublicKey)
	if err != nil {
		return fmt.Errorf("peer %s has an unusable public key: %w", peer.UID, err)
	}

	fmt.Fprintf(b, "public_key=%s\n", pub.Hex())
	b.WriteString("replace_allowed_ips=true\n")

	for _, allowed := range peer.AllowedIPs {
		// netcfg.Build has already refused any prefix that would capture the
		// default route, so anything reaching here is within the overlay.
		fmt.Fprintf(b, "allowed_ip=%s\n", allowed)
	}

	// An endpoint is a hint, not a requirement: without one the peer can still
	// reach us, and the coordinator will supply one shortly.
	if peer.Endpoint != "" {
		fmt.Fprintf(b, "endpoint=%s\n", peer.Endpoint)
	}

	if keepalive > 0 {
		fmt.Fprintf(b, "persistent_keepalive_interval=%d\n", keepalive)
	}

	return nil
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
