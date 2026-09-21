package netcfg

import (
	"fmt"
	"net/netip"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
)

// Gateway (subnet-router) mode.
//
// An NVR, a printer and a DVR cannot run an agent. One PC at the site does,
// and forwards for the LAN behind it — which is the difference between a
// product a hotel can use and one it cannot.
//
// Two things have to be true on that PC: the kernel must forward packets
// between the tunnel and the LAN, and the LAN devices must be able to answer.
// The second is what needs NAT: an NVR at 192.168.1.50 has no route back to
// 10.99.0.0/24 and nobody is going to add one to a camera recorder, so traffic
// leaving the gateway towards the LAN is translated to the gateway's own LAN
// address and the answers come back to it.
//
// The alternative — routing properly, with a return route on the LAN — is
// cleaner and is not available at a hotel reception desk.

// GatewayPlan is what a gateway device has to set up beyond its own tunnel.
type GatewayPlan struct {
	// Interface is the tunnel interface traffic arrives on.
	Interface string
	// Advertised are the LAN prefixes this device routes for, as they exist on
	// the site's own network. These are what the kernel forwards into and
	// NATs for; the overlay never uses them.
	Advertised []netip.Prefix
	// Mapped pairs each advertised prefix with the one the overlay uses for
	// it, in the same order. The agent translates between the two above the
	// kernel, so nothing here ever sees a mapped address.
	Mapped []netip.Prefix
	// Overlay is the tunnel's own prefix — the source addresses that are
	// allowed to be forwarded. Anything else arriving on the tunnel is not
	// ours and is not forwarded.
	Overlay netip.Prefix
}

// BuildGatewayPlan works out what a device must do to route for its LAN.
//
// It refuses a prefix that is not really a LAN: a gateway advertising
// 0.0.0.0/0 would turn every client's split tunnel into a full one, which is
// the thing R1 exists to prevent, and it must be refused on the device as well
// as on the server that sent it.
func BuildGatewayPlan(ifaceName string, overlayCIDR string, advertised []panel.Advertised) (*GatewayPlan, error) {
	overlay, err := netip.ParsePrefix(overlayCIDR)
	if err != nil {
		return nil, fmt.Errorf("gateway: overlay %q is not a prefix: %w", overlayCIDR, err)
	}

	plan := &GatewayPlan{Interface: ifaceName, Overlay: overlay.Masked()}

	for _, entry := range advertised {
		prefix, err := netip.ParsePrefix(entry.RealDestination)
		if err != nil {
			return nil, fmt.Errorf("gateway: advertised route %q is not a prefix: %w", entry.RealDestination, err)
		}
		if err := reject(prefix, "the advertised routes"); err != nil {
			return nil, err
		}

		mapped, err := netip.ParsePrefix(entry.Destination)
		if err != nil {
			return nil, fmt.Errorf("gateway: mapped prefix %q is not a prefix: %w", entry.Destination, err)
		}
		if err := reject(mapped, "the mapped prefixes"); err != nil {
			return nil, err
		}
		if mapped.Bits() != prefix.Bits() {
			// Different sizes cannot map one-to-one, and approximating would
			// put half the LAN somewhere else.
			return nil, fmt.Errorf(
				"gateway: %s cannot be mapped to %s — the two are different sizes",
				entry.RealDestination, entry.Destination)
		}

		// A gateway advertising the overlay itself would have the kernel
		// forward tunnel traffic back into the tunnel. The mapped prefix is
		// checked too: it is the one that arrives on the interface.
		if prefix.Masked() == plan.Overlay || mapped.Masked() == plan.Overlay {
			return nil, fmt.Errorf("gateway: refusing to advertise the overlay prefix %s as a LAN route", prefix)
		}

		plan.Advertised = append(plan.Advertised, prefix.Masked())
		plan.Mapped = append(plan.Mapped, mapped.Masked())
	}

	if len(plan.Advertised) == 0 {
		return nil, nil
	}

	return plan, nil
}

// ApplyGateway turns on forwarding and NAT for the advertised prefixes.
func ApplyGateway(plan *GatewayPlan) error {
	if plan == nil {
		return nil
	}

	return applyGateway(plan)
}

// RemoveGateway undoes it, so an agent that stops being a gateway — or simply
// stops — does not leave a machine forwarding for a network it is no longer
// part of.
func RemoveGateway(plan *GatewayPlan) error {
	if plan == nil {
		return nil
	}

	return removeGateway(plan)
}
