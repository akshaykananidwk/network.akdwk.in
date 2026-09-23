//go:build windows

package main

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"

	"golang.org/x/sys/windows/registry"
)

// Apps & features, the way every other program on the machine does it.
//
// The 1.9.5 standard is "uninstall from Windows Settings like any normal app".
// That list is one registry key per program; software that does not write one
// is software a customer cannot remove without being told a command to type,
// which is precisely what a shopkeeper will never do. There is no MSI involved
// and none is needed — Settings reads this key, MSI or not.
//
// HKLM, not HKCU: this installs a service for the whole machine, so it must be
// listed for the whole machine.
const uninstallKey = `SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\` + productName

// registerInPrograms writes the Apps & features entry.
//
// setupPath is the copy of this installer kept in the program folder. The
// original may have been run from a Downloads folder, a USB stick or a network
// share, and an UninstallString that points at a file that is no longer there
// is an entry that cannot uninstall anything.
func registerInPrograms(dir, setupPath string) error {
	key, _, err := registry.CreateKey(registry.LOCAL_MACHINE, uninstallKey, registry.SET_VALUE)
	if err != nil {
		return fmt.Errorf("could not create the Apps & features entry: %w", err)
	}
	defer key.Close()

	quoted := `"` + setupPath + `"`

	values := []struct {
		name  string
		value string
	}{
		{"DisplayName", displayName},
		{"DisplayVersion", version},
		{"Publisher", publisher},
		{"DisplayIcon", setupPath},
		{"InstallLocation", dir},
		{"UninstallString", quoted + " -uninstall"},
		// Settings uses this one, and without it the uninstall opens a window
		// that asks a question nobody is there to answer.
		{"QuietUninstallString", quoted + " -uninstall -silent"},
		{"URLInfoAbout", aboutURL()},
		{"HelpLink", aboutURL()},
	}

	for _, v := range values {
		if v.value == "" {
			continue
		}
		if err := key.SetStringValue(v.name, v.value); err != nil {
			return fmt.Errorf("could not write %s: %w", v.name, err)
		}
	}

	// Greys out "Modify" and "Repair" in the list. There is nothing behind
	// either button, and offering a button that does nothing is worse than not
	// offering it.
	for _, v := range []struct {
		name  string
		value uint32
	}{
		{"NoModify", 1},
		{"NoRepair", 1},
		{"EstimatedSize", estimatedSizeKB(dir)},
	} {
		if err := key.SetDWordValue(v.name, v.value); err != nil {
			return fmt.Errorf("could not write %s: %w", v.name, err)
		}
	}

	return nil
}

// unregisterFromPrograms takes the entry away again. An uninstalled program
// still listed in Settings is the complaint people remember.
func unregisterFromPrograms() error {
	err := registry.DeleteKey(registry.LOCAL_MACHINE, uninstallKey)
	if err == nil || os.IsNotExist(err) || strings.Contains(err.Error(), "cannot find") {
		return nil
	}

	return err
}

// installedVersion is the version Apps & features currently lists, or "" when
// nothing is installed. It is what a downgrade is refused against.
func installedVersion() string {
	key, err := registry.OpenKey(registry.LOCAL_MACHINE, uninstallKey, registry.QUERY_VALUE)
	if err != nil {
		return ""
	}
	defer key.Close()

	value, _, err := key.GetStringValue("DisplayVersion")
	if err != nil {
		return ""
	}

	return value
}

// estimatedSizeKB is what Settings shows in the size column. Measured rather
// than guessed, because the number is right there on disk.
func estimatedSizeKB(dir string) uint32 {
	var total int64

	_ = filepath.Walk(dir, func(_ string, info os.FileInfo, err error) error {
		if err == nil && info != nil && !info.IsDir() {
			total += info.Size()
		}

		return nil
	})

	return uint32(total / 1024)
}
