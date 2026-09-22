package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net/netip"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/acl"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/discovery"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/dnsd"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/keystore"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/netcfg"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/selfupdate"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/state"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/tunnel"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/wgkey"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/winenv"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/winsvc"
)

func runUp(ctx context.Context, args []string) error {
	fs := flag.NewFlagSet("up", flag.ExitOnError)
	verbose := fs.Bool("verbose", false, "log WireGuard handshakes")
	iface := fs.String("iface", "", "interface name")
	// Zero means "use this device's own port", which is chosen once and kept
	// in the state file. A number on the command line still wins, for an
	// operator who needs a specific port for a firewall rule.
	port := fs.Int("port", 0, "UDP listen port (default: this device's own)")
	if err := fs.Parse(args); err != nil {
		return err
	}

	// Checked before anything is created, so a missing driver or a
	// non-elevated prompt is reported as itself rather than as a failure deep
	// inside the tunnel.
	if err := checkEnvironment(); err != nil {
		return err
	}

	// Under the service control manager there is no console, so anything
	// written to stdout is written to a handle pointing at nothing. That is
	// how the first real Windows install presented: the service started,
	// failed and stopped, in silence. A service writes to a file instead.
	out := io.Writer(os.Stdout)
	if winsvc.IsService() {
		file, _, err := state.OpenServiceLog()
		if err == nil {
			defer file.Close()
			out = file
		}
	}

	logger := log.New(out, "", log.LstdFlags)
	logf := func(format string, a ...any) { logger.Printf(format, a...) }

	// An update renames the old binary aside rather than deleting it, because
	// on Windows a running executable cannot be deleted. This is the first
	// moment it is no longer running, so this is where it goes.
	if removed, err := selfupdate.CleanPrevious(); err != nil {
		logf("could not remove the previous binary: %v", err)
	} else if removed != "" {
		logf("removed the previous binary left by an update: %s", filepath.Base(removed))
	}

	session, err := newSession(*iface, *port, *verbose, logf)
	if err != nil {
		return err
	}
	defer session.close()

	return session.run(ctx)
}

// session holds everything one "up" needs, so the run loop reads as a sequence
// of steps rather than a pile of parameters.
type session struct {
	client   *panel.Client
	stateSt  *state.Store
	st       *state.State
	keyStore keystore.Store
	tun      *tunnel.Tunnel
	plan     *netcfg.Plan
	// filters is the ACL table currently in force, kept so the next one can
	// inherit its open flows.
	filters *acl.Table
	// gateway is the forwarding configuration in force, kept so it can be
	// withdrawn when the advertised prefixes change.
	gateway *netcfg.GatewayPlan
	// names answers the network's zone on a loopback address, and dnsZone is
	// what the operating system has been pointed at — kept so a zone that
	// changes can be un-pointed before the new one is applied.
	names   *dnsd.Server
	dnsZone string
	// dnsRoutedBy and dnsNote record what the operating system did with the
	// zone, so `status` can say "names work" or "names are served but nothing
	// is pointing at them" rather than leaving a technician to guess.
	dnsRoutedBy string
	dnsNote     string
	dnsProblem  string
	// fwProblem is why the overlay may not be reachable inbound, when that is
	// the case. Reported to the panel with the other problems: a tunnel that
	// carries traffic nobody can answer looks like a working tunnel from
	// every side except the customer's.
	fwProblem string
	// dnsRecords fingerprints the record set, so a device renamed in the panel
	// reaches the hosts file rather than waiting for the zone itself to change.
	dnsRecords string
	// refused is every prefix this device would not install because the
	// machine is already on that network, kept so each heartbeat repeats it:
	// a problem reported once and then forgotten is a problem nobody fixes.
	refused   []netip.Prefix
	discovery *discovery.Client
	// peerMeta maps a peer's hex public key to the names the panel gave it,
	// which the WireGuard device itself does not carry.
	peerMeta  map[string]peerNames
	logf      func(string, ...any)
	iface     string
	port      int
	verbose   bool
	applied   bool
	lastRX    int64
	lastTX    int64
	startedAt time.Time
	// controllerKey is the panel's signing identity, taken from the last
	// configuration it published. A release whose digest is not signed by it
	// is not installed — see internal/selfupdate.
	controllerKey string
	// lastUpdateCheck throttles the automatic update check in the poll loop.
	lastUpdateCheck time.Time
}

func newSession(iface string, port int, verbose bool, logf func(string, ...any)) (*session, error) {
	stateSt, err := state.Open()
	if err != nil {
		return nil, err
	}

	st, err := stateSt.Load()
	if err != nil {
		return nil, err
	}
	if !st.Enrolled() {
		return nil, errors.New("this device is not enrolled; run 'akconnect-agent enroll' first")
	}

	// Deliberately *not* refused when the device is enrolled and waiting for
	// approval. It used to be, and the consequence on Windows was that the
	// service started, exited within a second and left the machine looking
	// broken: an administrator approving the device five minutes later changed
	// nothing, because the only thing that would have noticed had already
	// stopped. run() waits instead — see awaitApproval.

	keyStore, err := keystore.Open()
	if err != nil {
		return nil, err
	}

	client, err := panel.New(panel.Options{
		BaseURL:   st.PanelURL,
		Token:     st.DeviceToken,
		UserAgent: "akconnect-agent/" + version,
	})
	if err != nil {
		return nil, err
	}

	// Defect 23: a fixed port for every device means a site with two PCs has
	// two devices fighting over one external mapping. The port is chosen once,
	// written down, and used for the life of the install.
	if port == 0 {
		chosen := state.ChooseListenPort(st.ListenPort)
		if chosen != st.ListenPort {
			st.ListenPort = chosen
			if err := stateSt.Save(st); err != nil {
				logf("could not record this device's port: %v", err)
			}
			logf("this device will use UDP %d", chosen)
		}
		port = chosen
	}

	return &session{
		client:    client,
		stateSt:   stateSt,
		st:        st,
		keyStore:  keyStore,
		logf:      logf,
		iface:     iface,
		port:      port,
		verbose:   verbose,
		startedAt: time.Now(),
	}, nil
}

func (s *session) close() {
	s.stopNames()

	if s.tun != nil {
		if s.applied && s.plan != nil {
			_ = netcfg.Remove(s.tun.Name(), s.plan)
		}
		_ = s.tun.Close()
	}
}

// run fetches configuration, brings the tunnel up, then keeps both current.
func (s *session) run(ctx context.Context) error {
	priv, pub, _, err := keystore.LoadOrCreate(s.keyStore)
	if err != nil {
		return err
	}

	// R4 says no device is trusted until an administrator approves it, and
	// this is the waiting room. A service that exited here instead left a
	// customer's machine looking broken for as long as it took somebody to
	// click Approve — and then still broken, because nothing was left running
	// to notice.
	if !s.st.Approved() {
		if err := s.awaitApproval(ctx, pub.Base64()); err != nil {
			return err
		}
		if ctx.Err() != nil {
			return nil
		}
	}

	cfg, pollAfter, err := s.client.FetchConfig(ctx, 0)
	if err != nil {
		return s.explain(err)
	}

	if err := s.applyConfig(ctx, priv, cfg); err != nil {
		return err
	}

	s.logf("interface %s is up at %s (revision %d, %d peer(s))",
		s.tun.Name(), cfg.Device.VirtualIP, cfg.Revision, len(cfg.Peers))
	s.logf("split tunnel: only %s and %d configured route(s) go through it",
		cfg.Network.CIDR, len(cfg.Routes))

	s.startDiscovery(ctx, cfg, priv)

	return s.loop(ctx, priv, pollAfter)
}

// applyConfig vets a configuration and installs it. R1 is enforced by
// netcfg.Build before anything touches the operating system.
func (s *session) applyConfig(ctx context.Context, priv wgPrivate, cfg *panel.Config) error {
	// Kept from every configuration, not just the first: rotating the
	// controller key must not leave running agents unable to verify a release.
	s.controllerKey = cfg.Controller.PublicKey

	plan, err := netcfg.Build(cfg)
	if err != nil {
		// A refused configuration is not a transient error. Failing loudly and
		// staying disconnected is the correct outcome: connecting anyway would
		// mean carrying the customer's whole internet over the overlay.
		return fmt.Errorf("refusing this configuration: %w", err)
	}

	if s.tun == nil {
		t, err := tunnel.Open(tunnel.Options{
			InterfaceName: s.iface,
			ListenPort:    s.port,
			Verbose:       s.verbose,
			Logf:          s.logf,
			OnFilterDrop:  newDropReporter(s.logf).report,
		})
		if err != nil {
			return err
		}
		s.tun = t
	}

	if err := s.tun.Apply(priv, cfg, plan); err != nil {
		return err
	}

	refused, err := netcfg.Apply(s.tun.Name(), plan)
	if err != nil {
		return fmt.Errorf("configuring the interface: %w", err)
	}
	s.refused = refused
	for _, prefix := range refused {
		// Loud, and repeated to the panel on every heartbeat. The customer
		// experiences this as "the camera at the hotel does not open" and
		// nothing in the panel would look wrong; the answer is to change the
		// network's CIDR or its mapping pool, and nobody can do that unless
		// they are told which prefix clashed.
		if prefix == plan.Overlay {
			s.logf("the overlay %s is not installed: this machine is already on that network. "+
				"Nothing on this network is reachable from here until the network's CIDR is changed",
				prefix)

			continue
		}

		s.logf("route %s not installed: this machine is already on that network. "+
			"Devices in that range are unreachable from here until the network's mapping pool is changed",
			prefix)
	}

	// Subnet mapping before the gateway's own forwarding rules, and before the
	// ACL: from here up, every address in this process is a mapped one.
	s.tun.SetMappings(buildMappings(cfg))

	// Names last of the three, because they are built from mapped addresses.
	// The overlay has to be reachable, not merely routed. Windows Firewall
	// drops inbound ICMP on a new adapter by default, so two machines with a
	// working tunnel could not ping each other until this rule existed.
	if err := winenv.EnsureOverlayFirewall(s.tun.Name(), cfg.Network.CIDR); err != nil {
		// Not fatal: the tunnel carries traffic either way, and a machine that
		// cannot be pinged is better than one that is not connected. Reported
		// so the panel can say so rather than leaving a technician guessing.
		s.logf("firewall: %v", err)
		s.fwProblem = err.Error()
	} else {
		s.fwProblem = winenv.OverlayFirewallNote()
		if s.fwProblem != "" {
			s.logf("firewall: %s", s.fwProblem)
		}
	}

	s.applyNames(ctx, cfg)

	if err := s.applyGateway(cfg); err != nil {
		// A gateway that cannot forward is not a gateway, and the site's
		// cameras are unreachable. Failing loudly beats a tunnel that looks
		// healthy and routes nothing.
		return err
	}

	// ACL last, and deliberately after the interface is configured: the table
	// is swapped atomically, so there is no window where traffic flows
	// unfiltered between a new configuration arriving and its rules applying.
	table := buildFilterTable(cfg)
	// Conversations the previous rules permitted and the new ones still do
	// stay open. An operator editing one rule should not drop the customer's
	// RDP session on every device in the network.
	table.AdoptFlows(s.filters)
	s.filters = table
	s.tun.SetFilters(table)
	if mapped := s.tun.Mapped(); mapped > 0 {
		s.logf("subnet mapping: %d LAN(s) translated so the overlay never carries the site's own range",
			mapped)
	}
	if table.Served() > 0 {
		s.logf("acl: forwarding for %d peer/subnet pair(s); rules about machines behind this gateway are enforced here too",
			table.Served())
	}
	if table.Restricted() > 0 {
		s.logf("acl: %d of %d peer(s) carry port rules, enforced in both directions",
			table.Restricted(), len(cfg.Peers))
	}

	s.plan = plan
	s.applied = true
	s.rememberPeerNames(cfg)
	s.st.Revision = cfg.Revision
	s.st.VirtualIP = cfg.Device.VirtualIP

	// Discovery is told that the world has changed.
	//
	// A configuration that arrives with a new revision means the panel has
	// approved, revoked or re-scoped something — so the coordinator's idea of
	// who this device may reach is out of date, and only a full announcement
	// refreshes it. Without this the agent kept pinging happily while the
	// coordinator introduced it to nobody, and restarting the service was the
	// only cure.
	//
	// The relay fleet is re-read for the same reason: a relay added in the
	// panel was invisible until the process was restarted.
	if s.discovery != nil {
		s.discovery.SetRelays(relayFleet(cfg, s.logf))
		s.discovery.Rehello()
	}

	return s.stateSt.Save(s.st)
}

// applyGateway turns this device into a subnet router, when the panel says it
// is one.
//
// Idempotent: the plan is rebuilt and reapplied on every configuration change,
// because the set of advertised prefixes can change and a prefix that has been
// withdrawn must stop being forwarded.
func (s *session) applyGateway(cfg *panel.Config) error {
	next, err := netcfg.BuildGatewayPlan(s.tun.Name(), cfg.Network.CIDR, advertisedBy(cfg))
	if err != nil {
		return fmt.Errorf("refusing this gateway configuration: %w", err)
	}

	// Withdraw what is no longer advertised before adding what is, so a
	// prefix moved from one gateway to another does not briefly exist on both.
	if s.gateway != nil {
		if err := netcfg.RemoveGateway(s.gateway); err != nil {
			s.logf("gateway: could not withdraw the previous routes: %v", err)
		}
	}
	s.gateway = next

	if next == nil {
		return nil
	}

	if err := netcfg.ApplyGateway(next); err != nil {
		return fmt.Errorf("configuring gateway mode: %w", err)
	}

	s.logf("gateway: forwarding for %s from the overlay %s",
		joinPrefixes(next.Advertised), next.Overlay)

	return nil
}

// advertisedBy is the list of prefixes this device routes for, and only this
// device: the routes list also carries prefixes other gateways advertise,
// which this machine installs as routes rather than forwards for.
func advertisedBy(cfg *panel.Config) []panel.Advertised {
	if !cfg.Device.IsGateway {
		return nil
	}

	if len(cfg.Device.Advertises) > 0 {
		return cfg.Device.Advertises
	}

	// Older panels do not send the explicit list, so fall back to the routes
	// pointing at this device's own overlay address.
	var out []panel.Advertised
	for _, route := range cfg.Routes {
		if route.Via == "" || route.Via != cfg.Device.VirtualIP {
			continue
		}

		real := route.RealDestination
		if real == "" {
			// A panel from before subnet mapping. The overlay prefix and the
			// LAN prefix were the same thing then, and treating them as such
			// keeps that gateway working rather than silently forwarding
			// nothing.
			real = route.Destination
		}

		out = append(out, panel.Advertised{Destination: route.Destination, RealDestination: real})
	}

	return out
}

func joinPrefixes(prefixes []netip.Prefix) string {
	parts := make([]string, 0, len(prefixes))
	for _, p := range prefixes {
		parts = append(parts, p.String())
	}

	return strings.Join(parts, ", ")
}

// peerNames is the descriptive half of a peer, kept so the status file can
// say "shop-till-2" rather than only a base64 key.
type peerNames struct {
	name      string
	virtualIP string
	publicKey [32]byte
}

// rememberPeerNames indexes the configuration by the hex key the WireGuard
// device reports, so the two can be joined when status is written.
func (s *session) rememberPeerNames(cfg *panel.Config) {
	s.peerMeta = make(map[string]peerNames, len(cfg.Peers))

	for _, p := range cfg.Peers {
		key, err := wgkey.ParsePublic(p.PublicKey)
		if err != nil {
			continue
		}
		s.peerMeta[key.Hex()] = peerNames{name: p.Name, virtualIP: p.VirtualIP, publicKey: [32]byte(key)}
	}
}

// explain turns an API failure into something an operator can act on.
func (s *session) explain(err error) error {
	var apiErr *panel.APIError
	if !errors.As(err, &apiErr) {
		return err
	}

	// The panel received no credential at all. The token is almost certainly
	// fine and the request never carried it — Apache drops the Authorization
	// header on its way to PHP-FPM unless it is configured not to. Telling a
	// customer to reset here destroys a working enrolment and changes nothing.
	if apiErr.NoCredential() {
		return fmt.Errorf(
			"the panel received no credential from this device, which usually means the web server "+
				"is not passing the Authorization header to PHP.\n"+
				"On Apache with PHP-FPM that is 'CGIPassAuth On' in the panel's .htaccess. "+
				"Do NOT run 'reset' — the token on this machine is probably fine.\n"+
				"The panel said: %s", apiErr.Message)
	}

	if apiErr.Unauthorized() {
		return fmt.Errorf(
			"the panel rejected this device's token (%s) — it has most likely been revoked; "+
				"run 'akconnect-agent reset' and enrol again if that is unexpected", apiErr.Message)
	}

	return err
}

// awaitApproval polls the panel until an administrator lets this device in.
//
// It honours the interval the panel asks for rather than choosing one, and it
// keeps going: a device left pending overnight has to be connected in the
// morning without anybody touching it.
//
// The panel client is rebuilt afterwards, because it was constructed without a
// device token — there was none — and every call after this needs one.
func (s *session) awaitApproval(ctx context.Context, publicKey string) error {
	s.logf("waiting for an administrator to approve this device; nothing is reachable until they do (R4)")

	attempts := 0

	for {
		claim, err := s.client.Claim(ctx, s.st.DeviceUID, publicKey)
		switch {
		case errors.Is(err, panel.ErrNotEnrolled):
			// The panel has no record of this key. Re-enrolling is the only
			// way forward, and it needs a person, so saying so and stopping is
			// the honest outcome.
			return fmt.Errorf("the panel no longer recognises this device; run 'akconnect-agent reset' and enrol again")
		case err != nil:
			// Everything else is transient until proven otherwise: a panel
			// being restarted, a certificate being renewed, a laptop on a
			// train. Reported at a decreasing rate so an overnight wait does
			// not fill the log.
			if attempts < 3 || attempts%20 == 0 {
				s.logf("could not reach the panel while waiting for approval: %v", s.explain(err))
			}
		case claim.Authorized:
			s.st.DeviceToken = claim.DeviceToken
			s.st.VirtualIP = claim.VirtualIP
			if err := s.stateSt.Save(s.st); err != nil {
				return err
			}

			client, err := panel.New(panel.Options{
				BaseURL:   s.st.PanelURL,
				Token:     s.st.DeviceToken,
				UserAgent: "akconnect-agent/" + version,
			})
			if err != nil {
				return err
			}
			s.client = client

			s.logf("approved; address on the overlay is %s", claim.VirtualIP)

			return nil
		case attempts == 0:
			s.logf("still waiting for approval")
		}

		attempts++

		delay := time.Duration(claim.PollAfterOrDefault()) * time.Second

		select {
		case <-ctx.Done():
			return nil
		case <-time.After(delay):
		}
	}
}
