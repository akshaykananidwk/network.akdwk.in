//go:build linux

package state

import (
	"fmt"
	"os"
	"path/filepath"
)

func platformStateDir() (string, error) {
	if os.Geteuid() == 0 {
		return "/etc/akconnect", nil
	}

	home, err := os.UserConfigDir()
	if err != nil {
		return "", fmt.Errorf("locating a config directory: %w", err)
	}

	return filepath.Join(home, "akconnect"), nil
}
