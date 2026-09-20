// Package keystore stores the device's private key using whatever the host
// operating system provides for the purpose.
//
// The private key is the device's identity: anyone holding it can impersonate
// the device on the overlay. It is written once at enrolment and read at every
// start, and it must never appear in a log, a config file the user edits, or a
// backup that leaves the machine (R5).
//
// Each platform file in this package implements Store. The contract is
// deliberately narrow — load, save, clear — so that a new platform has one
// obvious thing to get right.
package keystore

import (
	"errors"
	"fmt"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/wgkey"
)

// Store persists exactly one device private key.
type Store interface {
	// Load returns the stored key, or wgkey.ErrNoKey if none exists.
	Load() (wgkey.Private, error)
	// Save writes the key, replacing any existing one.
	Save(wgkey.Private) error
	// Clear removes the key. Removing a key that is not there is not an error.
	Clear() error
	// Describe says where the key lives, for the status command. It must never
	// include the key itself.
	Describe() string
}

// Open returns the keystore for this platform.
func Open() (Store, error) { return open() }

// LoadOrCreate returns the device identity, generating one on first run.
//
// The bool reports whether a new identity was created, because that is the
// difference between "starting up" and "this machine has never enrolled".
func LoadOrCreate(s Store) (wgkey.Private, wgkey.Public, bool, error) {
	priv, err := s.Load()
	if err == nil {
		pub, pubErr := priv.Public()
		if pubErr != nil {
			return wgkey.Private{}, wgkey.Public{}, false, pubErr
		}

		return priv, pub, false, nil
	}

	if !errors.Is(err, wgkey.ErrNoKey) {
		return wgkey.Private{}, wgkey.Public{}, false, fmt.Errorf("reading the device key: %w", err)
	}

	priv, pub, err := wgkey.Generate()
	if err != nil {
		return wgkey.Private{}, wgkey.Public{}, false, err
	}

	if err := s.Save(priv); err != nil {
		return wgkey.Private{}, wgkey.Public{}, false, fmt.Errorf("storing the device key: %w", err)
	}

	return priv, pub, true, nil
}
