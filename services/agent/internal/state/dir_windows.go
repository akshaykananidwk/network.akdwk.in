//go:build windows

package state

import (
	"os"
	"path/filepath"
)

func platformStateDir() (string, error) {
	programData := os.Getenv("ProgramData")
	if programData == "" {
		programData = `C:\ProgramData`
	}

	return filepath.Join(programData, "AKConnect"), nil
}
