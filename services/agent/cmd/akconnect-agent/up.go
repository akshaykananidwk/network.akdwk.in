package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"log"
	"os"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/discovery"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/keystore"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/netcfg"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/state"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/tunnel"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/wgkey"
)

func runUp(ctx context.Context, args []string) error {
	fs := flag.NewFlagSet("up", flag.ExitOnError)
	verbose := fs.Bool("verbose", false, "log WireGuard handshakes")
	iface := fs.String("iface", "", "interface name")
	port := fs.Int("port", tunnel.DefaultListenPort, "UDP listen port")
	if err := fs.Parse(args); err != nil {
		return err
	}

	// Checked before anything is created, so a missing driver or a
	// non-elevated prompt is reported as itself rather than as a failure deep
	// inside the tunnel.
	if err := checkEnvironment(); err != nil {
		return err
	}

	logger := log.New(os.Stdout, "", log.LstdFlags)
	logf := func(format string, a ...any) { logger.Printf(format, a...) }

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
	client    *panel.Client
	stateSt   *state.Store
	st        *state.State
	keyStore  keystore.Store
	tun       *tunnel.Tunnel
	plan      *netcfg.Plan
	discovery *discovery.Client
	// peerMeta maps a peer's hex public key to the names the panel gave it,
	// which the WireGuard device itself does not carry.
	peerMeta  map[string]peerNames
	logf      func(string, ...any)
	iface     string
	port      int
	verbose   bool
	applied   bool
	startedAt time.Time
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
	if !st.Approved() {
		return nil, errors.New("this device has not been approved yet; an administrator must authorise it (R4)")
	}

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
	if s.tun != nil {
		if s.applied && s.plan != nil {
			_ = netcfg.Remove(s.tun.Name(), s.plan)
		}
		_ = s.tun.Close()
	}
}

// run fetches configuration, brings the tunnel up, then keeps both current.
func (s *session) run(ctx context.Context) error {
	priv, _, _, err := keystore.LoadOrCreate(s.keyStore)
	if err != nil {
		return err
	}

	cfg, pollAfter, err := s.client.FetchConfig(ctx, 0)
	if err != nil {
		return s.explain(err)
	}

	if err := s.applyConfig(priv, cfg); err != nil {
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
func (s *session) applyConfig(priv wgPrivate, cfg *panel.Config) error {
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
		})
		if err != nil {
			return err
		}
		s.tun = t
	}

	if err := s.tun.Apply(priv, cfg, plan); err != nil {
		return err
	}

	if err := netcfg.Apply(s.tun.Name(), plan); err != nil {
		return fmt.Errorf("configuring the interface: %w", err)
	}

	s.plan = plan
	s.applied = true
	s.rememberPeerNames(cfg)
	s.st.Revision = cfg.Revision
	s.st.VirtualIP = cfg.Device.VirtualIP

	return s.stateSt.Save(s.st)
}

// peerNames is the descriptive half of a peer, kept so the status file can
// say "shop-till-2" rather than only a base64 key.
type peerNames struct {
	name      string
	virtualIP string
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
		s.peerMeta[key.Hex()] = peerNames{name: p.Name, virtualIP: p.VirtualIP}
	}
}

// explain turns an API failure into something an operator can act on.
func (s *session) explain(err error) error {
	var apiErr *panel.APIError
	if errors.As(err, &apiErr) && apiErr.Unauthorized() {
		return fmt.Errorf(
			"the panel rejected this device's token (%s) — it has most likely been revoked; "+
				"run 'akconnect-agent reset' and enrol again if that is unexpected", apiErr.Message)
	}

	return err
}
