package state

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"time"
)

// Runtime is what a running agent knows about itself right now.
//
// It is written to disk because the only process that knows it is the one
// holding the tunnel open, and the person asking is running a second copy of
// the binary. A status file is the simplest thing that works across that gap,
// and unlike a local socket it needs no port, no permissions model of its own,
// and survives being read by a support script that knows nothing about us.
//
// It is disposable: stale contents are detected by age, not trusted.
type Runtime struct {
	UpdatedAt      time.Time     `json:"updated_at"`
	PID            int           `json:"pid"`
	Interface      string        `json:"interface"`
	ListenPort     int           `json:"listen_port"`
	VirtualIP      string        `json:"virtual_ip"`
	OverlayCIDR    string        `json:"overlay_cidr"`
	Revision       int           `json:"revision"`
	Reflexive      string        `json:"reflexive_endpoint"`
	CoordinatorUp  bool          `json:"coordinator_reachable"`
	ControlPlaneUp bool          `json:"control_plane_reachable"`
	Peers          []RuntimePeer `json:"peers"`
	// Mappings are the LANs this device is the gateway for, each shown in both
	// address spaces. Empty on everything that is not a subnet router.
	//
	// It is in the status file because Windows has no way to show it: the
	// rewriting is done in the agent, not by netsh or New-NetNat, so
	// `akconnect-agent status` is the only place a technician can see which
	// overlay address reaches which machine.
	Mappings []RuntimeMapping `json:"mappings,omitempty"`
}

// RuntimeMapping is one advertised LAN, in both address spaces.
type RuntimeMapping struct {
	// Overlay is the prefix peers use to reach this LAN.
	Overlay string `json:"overlay"`
	// LAN is the range as it exists on the site's own network.
	LAN string `json:"lan"`
}

// RuntimePeer is one peer's live state.
type RuntimePeer struct {
	PublicKey string `json:"public_key"`
	Name      string `json:"name"`
	VirtualIP string `json:"virtual_ip"`
	Endpoint  string `json:"endpoint"`
	// Path is "direct" once a handshake has completed, "relay" when the
	// endpoint is a relay, and "connecting" before either.
	Path string `json:"path"`
	// Relay is the fleet name of the relay carrying this peer, empty when
	// the path is direct. An operator diagnosing a slow site needs to know
	// which relay it is on, not only that it is on one.
	Relay            string `json:"relay,omitempty"`
	LastHandshakeAgo string `json:"last_handshake_ago"`
	RXBytes          int64  `json:"rx_bytes"`
	TXBytes          int64  `json:"tx_bytes"`
}

// Fresh reports whether the file was written recently enough to believe. A
// crashed agent leaves its last status behind, and reporting that as current
// would be worse than reporting nothing.
func (r *Runtime) Fresh() bool {
	return time.Since(r.UpdatedAt) < 90*time.Second
}

// SaveRuntime writes the live status beside the state file.
func (s *Store) SaveRuntime(r *Runtime) error {
	r.UpdatedAt = time.Now().UTC()
	r.PID = os.Getpid()

	encoded, err := json.MarshalIndent(r, "", "  ")
	if err != nil {
		return err
	}

	path := s.runtimePath()
	tmp := path + ".tmp"

	if err := os.WriteFile(tmp, append(encoded, '\n'), 0o644); err != nil {
		return err
	}

	return os.Rename(tmp, path)
}

// LoadRuntime reads it back, returning nil when there is none.
func (s *Store) LoadRuntime() (*Runtime, error) {
	raw, err := os.ReadFile(s.runtimePath())
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}

	var out Runtime
	if err := json.Unmarshal(raw, &out); err != nil {
		return nil, err
	}

	return &out, nil
}

// ClearRuntime removes the status file on a clean shutdown, so a stopped agent
// does not look like a running one whose clock is wrong.
func (s *Store) ClearRuntime() {
	_ = os.Remove(s.runtimePath())
}

// RuntimePath is where the status lives, for a support script to collect.
func (s *Store) RuntimePath() string { return s.runtimePath() }

func (s *Store) runtimePath() string {
	return filepath.Join(filepath.Dir(s.path), "runtime.json")
}
