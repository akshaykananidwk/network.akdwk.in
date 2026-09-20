//go:build linux

package keystore

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/wgkey"
)

// Linux has no single system-wide secret store that is present everywhere:
// the kernel keyring does not survive a reboot, and libsecret needs a logged-in
// desktop session, which a machine running a network agent may not have. What
// every Linux host does have is a filesystem with owner-only permissions, and a
// daemon that runs as root.
//
// So the key lives in a 0600 file inside a 0700 directory, both owned by the
// user running the agent. That is the same protection sshd gives a host key,
// and it is honest about what it is rather than implying hardware backing.
type linuxStore struct{ path string }

const (
	linuxDirPerm  os.FileMode = 0o700
	linuxFilePerm os.FileMode = 0o600
)

func open() (Store, error) {
	dir := os.Getenv("AKCONNECT_STATE_DIR")
	if dir == "" {
		dir = "/etc/akconnect"
		if os.Geteuid() != 0 {
			home, err := os.UserConfigDir()
			if err != nil {
				return nil, fmt.Errorf("locating a config directory: %w", err)
			}
			dir = filepath.Join(home, "akconnect")
		}
	}

	if err := os.MkdirAll(dir, linuxDirPerm); err != nil {
		return nil, fmt.Errorf("creating %s: %w", dir, err)
	}
	// MkdirAll leaves an existing directory's mode alone, so tighten it.
	if err := os.Chmod(dir, linuxDirPerm); err != nil {
		return nil, fmt.Errorf("securing %s: %w", dir, err)
	}

	return &linuxStore{path: filepath.Join(dir, "device.key")}, nil
}

func (s *linuxStore) Load() (wgkey.Private, error) {
	raw, err := os.ReadFile(s.path)
	if errors.Is(err, os.ErrNotExist) {
		return wgkey.Private{}, wgkey.ErrNoKey
	}
	if err != nil {
		return wgkey.Private{}, err
	}

	info, err := os.Stat(s.path)
	if err != nil {
		return wgkey.Private{}, err
	}
	// Refuse to use a key anyone else can read. Silently carrying on would
	// leave the device impersonable by any local user.
	if info.Mode().Perm()&0o077 != 0 {
		return wgkey.Private{}, fmt.Errorf(
			"%s is mode %#o: the device key must not be readable by other users; "+
				"fix it with chmod 600 or delete it to re-enrol", s.path, info.Mode().Perm())
	}

	return wgkey.ParsePrivate(strings.TrimSpace(string(raw)))
}

func (s *linuxStore) Save(priv wgkey.Private) error {
	// Written to a temporary file and renamed, so a crash mid-write cannot
	// leave a truncated key that silently fails to parse on next boot.
	tmp := s.path + ".tmp"

	if err := os.WriteFile(tmp, []byte(priv.Base64()+"\n"), linuxFilePerm); err != nil {
		return err
	}
	if err := os.Chmod(tmp, linuxFilePerm); err != nil {
		_ = os.Remove(tmp)
		return err
	}
	if err := os.Rename(tmp, s.path); err != nil {
		_ = os.Remove(tmp)
		return err
	}

	return nil
}

func (s *linuxStore) Clear() error {
	err := os.Remove(s.path)
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}

	return err
}

func (s *linuxStore) Describe() string {
	return fmt.Sprintf("%s (owner-only file, mode %#o)", s.path, linuxFilePerm)
}
