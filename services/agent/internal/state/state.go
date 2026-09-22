// Package state persists what the agent needs to remember between runs.
//
// Everything here is recoverable: lose it and the agent re-enrols. The device
// private key is deliberately *not* here — it lives in the OS keystore, which
// has protections a JSON file does not.
//
// The device token is here, because it has to be: it is a bearer credential
// the agent must present on every call, it is rotated by asking the panel
// again, and unlike the private key its loss is an inconvenience rather than a
// compromise of identity. The file is still owner-only.
package state

import (
	"crypto/rand"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"time"
)

// State is the agent's persistent record.
type State struct {
	PanelURL   string `json:"panel_url"`
	DeviceUID  string `json:"device_uid"`
	NetworkUID string `json:"network_uid"`
	// DeviceToken is a bearer credential. Redact it before printing.
	DeviceToken string `json:"device_token,omitempty"`
	VirtualIP   string `json:"virtual_ip,omitempty"`
	Revision    int    `json:"revision,omitempty"`
	// ListenPort is the UDP port this device uses, chosen once and kept.
	//
	// Defect 23: every agent used a fixed 51820, so at a site with several PCs
	// behind one router only one of them could hold the external 51820 — and
	// the others were mapped somewhere else, or not mapped at all. A laptop
	// announced every twenty seconds for ever and reported "Coordinator: not
	// reachable" while its own log said it was announcing, and the two PCs in
	// one office could not reach each other until one was moved to a different
	// ISP. Several PCs behind one router is the commonest customer layout
	// there is.
	//
	// Kept rather than re-rolled because a port that changes on every start is
	// a NAT mapping that changes on every start, and a firewall rule that has
	// to be rewritten with it.
	ListenPort int `json:"listen_port,omitempty"`
}

// Enrolled reports whether this machine has an identity on a panel.
func (s *State) Enrolled() bool { return s.DeviceUID != "" && s.PanelURL != "" }

// Approved reports whether an admin has let the device in (R4).
func (s *State) Approved() bool { return s.DeviceToken != "" }

// ChooseListenPort returns this device's UDP port, picking one the first time.
//
// The range is the IANA dynamic range above 49152, avoiding the very top where
// Windows' own ephemeral allocations cluster. Deliberately not 51820: that is
// WireGuard's well-known port, it is what every other agent used, and a site
// with two PCs is exactly where that collides.
//
// crypto/rand rather than math/rand seeded from the clock, because two PCs
// imaged from the same disk and switched on together are precisely the pair
// that would draw the same "random" port.
func ChooseListenPort(current int) int {
	if current >= 1024 && current <= 65535 {
		return current
	}

	const (
		low  = 49152
		high = 60999
	)

	var buf [2]byte
	if _, err := rand.Read(buf[:]); err != nil {
		// Losing the entropy source is not a reason to fail to start; the
		// fallback still avoids 51820 and still differs per machine, because
		// the nanosecond a device boots is not the nanosecond another one did.
		return low + int(time.Now().UnixNano()%(high-low))
	}

	return low + int(binary.BigEndian.Uint16(buf[:]))%(high-low)
}

// Store reads and writes the state file.
type Store struct{ path string }

// Open locates the state file beside the keystore.
func Open() (*Store, error) {
	dir, err := stateDir()
	if err != nil {
		return nil, err
	}

	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, fmt.Errorf("creating %s: %w", dir, err)
	}

	return &Store{path: filepath.Join(dir, "state.json")}, nil
}

// Path is where the state lives, for the status command.
func (s *Store) Path() string { return s.path }

// Load reads the state, returning a zero State when there is none yet.
func (s *Store) Load() (*State, error) {
	raw, err := os.ReadFile(s.path)
	if errors.Is(err, os.ErrNotExist) {
		return &State{}, nil
	}
	if err != nil {
		return nil, err
	}

	var out State
	if err := json.Unmarshal(raw, &out); err != nil {
		return nil, fmt.Errorf("%s is corrupt: %w (delete it to re-enrol)", s.path, err)
	}

	return &out, nil
}

// Save writes the state atomically.
func (s *Store) Save(st *State) error {
	encoded, err := json.MarshalIndent(st, "", "  ")
	if err != nil {
		return err
	}

	tmp := s.path + ".tmp"
	if err := os.WriteFile(tmp, append(encoded, '\n'), 0o600); err != nil {
		return err
	}
	if err := os.Rename(tmp, s.path); err != nil {
		_ = os.Remove(tmp)
		return err
	}

	return nil
}

// Clear forgets the enrolment. The private key is untouched: clearing state is
// for re-enrolling the same identity, not for discarding it.
func (s *Store) Clear() error {
	err := os.Remove(s.path)
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}

	return err
}

func stateDir() (string, error) {
	if dir := os.Getenv("AKCONNECT_STATE_DIR"); dir != "" {
		return dir, nil
	}

	return platformStateDir()
}
