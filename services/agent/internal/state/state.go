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
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
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
}

// Enrolled reports whether this machine has an identity on a panel.
func (s *State) Enrolled() bool { return s.DeviceUID != "" && s.PanelURL != "" }

// Approved reports whether an admin has let the device in (R4).
func (s *State) Approved() bool { return s.DeviceToken != "" }

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
