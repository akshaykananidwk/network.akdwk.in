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
	UpdatedAt   time.Time `json:"updated_at"`
	PID         int       `json:"pid"`
	Interface   string    `json:"interface"`
	ListenPort  int       `json:"listen_port"`
	VirtualIP   string    `json:"virtual_ip"`
	OverlayCIDR string    `json:"overlay_cidr"`
	Revision    int       `json:"revision"`
	Reflexive   string    `json:"reflexive_endpoint"`
	// Coordinator is the address this device resolved and is announcing to.
	//
	// It lived only in one line of the service log, which is the first thing
	// lost to rotation and the single most useful field when comparing two
	// machines at one site: one working and one not, both "configured the
	// same", is usually two different answers to this.
	Coordinator   string `json:"coordinator"`
	CoordinatorUp bool   `json:"coordinator_reachable"`
	// Unanswered is how many announcements have gone out with nothing coming
	// back, and UnansweredFor is how long that has been true. Both are zero
	// when the coordinator is answering.
	//
	// In the status file because it is the one number that separates "this
	// device is still connecting" from "something on this network is dropping
	// the replies", and a customer's diagnostics bundle is often the only
	// place anybody can see it.
	Unanswered     int           `json:"unanswered,omitempty"`
	UnansweredFor  time.Duration `json:"unanswered_for_ns,omitempty"`
	ControlPlaneUp bool          `json:"control_plane_reachable"`
	// Fallback is the HTTPS path's state when this device is using it: where
	// it connected and how that relay sees its address. Absent on the
	// overwhelming majority of devices, which never open it at all.
	Fallback *RuntimeFallback `json:"fallback,omitempty"`
	Peers    []RuntimePeer    `json:"peers"`
	// Mappings are the LANs this device is the gateway for, each shown in both
	// address spaces. Empty on everything that is not a subnet router.
	//
	// It is in the status file because Windows has no way to show it: the
	// rewriting is done in the agent, not by netsh or New-NetNat, so
	// `akconnect-agent status` is the only place a technician can see which
	// overlay address reaches which machine.
	Mappings []RuntimeMapping `json:"mappings,omitempty"`

	// Names is the zone this device answers and where it answers it, so a
	// technician can see what to type and confirm nothing else was touched.
	Names *RuntimeNames `json:"names,omitempty"`
}

// RuntimeFallback is the HTTPS path, for a device that has had to use it.
type RuntimeFallback struct {
	// URL is where it connected.
	URL string `json:"url"`
	// Observed is the address the relay sees this device coming from, which
	// on a proxied path is the site's public address as our server sees it.
	// It is shown to a technician and never used as a path to anything: it is
	// a TCP mapping, and no UDP packet will ever arrive there.
	Observed string `json:"observed,omitempty"`
	// Since is when the connection was made.
	Since time.Time `json:"since"`
}

// RuntimeNames is the state of the local resolver.
type RuntimeNames struct {
	// Zone is the one domain answered. Everything else is refused.
	Zone string `json:"zone"`
	// Resolver is the loopback address it listens on.
	Resolver string `json:"resolver"`
	// Records is how many names it holds.
	Records int `json:"records"`
	// RoutedBy names the mechanism pointing the system at it —
	// "systemd-resolved", "NRPT" — or is empty when nothing is, in which case
	// Note says why.
	RoutedBy string `json:"routed_by"`
	Note     string `json:"note,omitempty"`
	// Refused counts queries for names outside the zone. It is here because
	// the number a drill wants is "how many did you forward", and the answer
	// is structurally zero: there is no forwarding code. This is the closest
	// observable thing — every such query was answered with REFUSED.
	Refused uint64 `json:"refused"`
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
	// Path is "direct" once a handshake has completed, "relay-udp" when the
	// endpoint is a relay reached the ordinary way, "relay-https" when this
	// network carries no UDP and the traffic is going over TCP 443, and
	// "connecting" before any of them.
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

	// Before the rename, so the file that lands is already readable. The
	// status file is what the tray shows and what a support bundle carries,
	// and it lives in a directory locked to administrators because the device
	// key is in there too. See readable_windows.go.
	if err := allowLocalRead(tmp); err != nil {
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
