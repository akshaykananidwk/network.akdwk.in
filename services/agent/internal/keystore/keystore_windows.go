//go:build windows

package keystore

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"unsafe"

	"golang.org/x/sys/windows"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/wgkey"
)

// On Windows the key is sealed with DPAPI under the local machine scope before
// it touches the disk, so the file on its own is useless: decrypting it
// requires running on the same machine. The file also sits in ProgramData with
// its inherited ACLs stripped and only SYSTEM and Administrators granted
// access, which is what stops an unprivileged local user simply asking DPAPI
// to unseal it for them.
//
// Machine scope rather than user scope is deliberate: the agent runs as a
// service under SYSTEM, and a user-scoped blob would not survive the account it
// was created under.
type windowsStore struct{ path string }

// entropy is mixed into the DPAPI blob so that a blob stolen from this agent
// cannot be unsealed by a different program running on the same machine.
var entropy = []byte("akconnect-device-key-v1")

func open() (Store, error) {
	dir := os.Getenv("AKCONNECT_STATE_DIR")
	if dir == "" {
		programData := os.Getenv("ProgramData")
		if programData == "" {
			programData = `C:\ProgramData`
		}
		dir = filepath.Join(programData, "AKConnect")
	}

	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, fmt.Errorf("creating %s: %w", dir, err)
	}
	if err := restrictToAdmins(dir); err != nil {
		return nil, fmt.Errorf("securing %s: %w", dir, err)
	}

	return &windowsStore{path: filepath.Join(dir, "device.key")}, nil
}

func (s *windowsStore) Load() (wgkey.Private, error) {
	sealed, err := os.ReadFile(s.path)
	if errors.Is(err, os.ErrNotExist) {
		return wgkey.Private{}, wgkey.ErrNoKey
	}
	if err != nil {
		return wgkey.Private{}, err
	}

	plain, err := dpapiUnprotect(sealed)
	if err != nil {
		return wgkey.Private{}, fmt.Errorf(
			"unsealing the device key: %w (a key sealed on another machine cannot be used here; "+
				"delete %s to re-enrol)", err, s.path)
	}
	defer zero(plain)

	return wgkey.ParsePrivate(strings.TrimSpace(string(plain)))
}

func (s *windowsStore) Save(priv wgkey.Private) error {
	plain := []byte(priv.Base64())
	defer zero(plain)

	sealed, err := dpapiProtect(plain)
	if err != nil {
		return fmt.Errorf("sealing the device key: %w", err)
	}

	tmp := s.path + ".tmp"
	if err := os.WriteFile(tmp, sealed, 0o600); err != nil {
		return err
	}
	if err := os.Rename(tmp, s.path); err != nil {
		_ = os.Remove(tmp)
		return err
	}

	return nil
}

func (s *windowsStore) Clear() error {
	err := os.Remove(s.path)
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}

	return err
}

func (s *windowsStore) Describe() string {
	return fmt.Sprintf("%s (DPAPI machine scope, SYSTEM and Administrators only)", s.path)
}

// --------------------------------------------------------------------- DPAPI

var (
	crypt32            = windows.NewLazySystemDLL("crypt32.dll")
	procCryptProtect   = crypt32.NewProc("CryptProtectData")
	procCryptUnprotect = crypt32.NewProc("CryptUnprotectData")
	kernel32           = windows.NewLazySystemDLL("kernel32.dll")
	procLocalFree      = kernel32.NewProc("LocalFree")
)

// cryptoAPIBlob mirrors DATA_BLOB from wincrypt.h.
type cryptoAPIBlob struct {
	cbData uint32
	pbData *byte
}

const cryptProtectLocalMachine = 0x4

func dpapiProtect(plain []byte) ([]byte, error) {
	return dpapiCall(procCryptProtect, plain)
}

func dpapiUnprotect(sealed []byte) ([]byte, error) {
	return dpapiCall(procCryptUnprotect, sealed)
}

func dpapiCall(proc *windows.LazyProc, in []byte) ([]byte, error) {
	if len(in) == 0 {
		return nil, errors.New("nothing to process")
	}

	inBlob := cryptoAPIBlob{cbData: uint32(len(in)), pbData: &in[0]}
	entBlob := cryptoAPIBlob{cbData: uint32(len(entropy)), pbData: &entropy[0]}
	var outBlob cryptoAPIBlob

	// CryptProtectData and CryptUnprotectData share this signature:
	//   (pDataIn, szDataDescr, pOptionalEntropy, pvReserved,
	//    pPromptStruct, dwFlags, pDataOut)
	ret, _, err := proc.Call(
		uintptr(unsafe.Pointer(&inBlob)),
		0,
		uintptr(unsafe.Pointer(&entBlob)),
		0,
		0,
		uintptr(cryptProtectLocalMachine),
		uintptr(unsafe.Pointer(&outBlob)),
	)
	if ret == 0 {
		return nil, err
	}
	defer procLocalFree.Call(uintptr(unsafe.Pointer(outBlob.pbData)))

	out := make([]byte, outBlob.cbData)
	copy(out, unsafe.Slice(outBlob.pbData, outBlob.cbData))

	return out, nil
}

// restrictToAdmins replaces the directory's inherited ACL with one granting
// only SYSTEM and the local Administrators group. Without this the key file
// inherits ProgramData's permissions, which let any authenticated user read it.
func restrictToAdmins(dir string) error {
	system, err := windows.CreateWellKnownSid(windows.WinLocalSystemSid)
	if err != nil {
		return err
	}
	admins, err := windows.CreateWellKnownSid(windows.WinBuiltinAdministratorsSid)
	if err != nil {
		return err
	}

	access := []windows.EXPLICIT_ACCESS{
		grantFull(system),
		grantFull(admins),
	}

	acl, err := windows.ACLFromEntries(access, nil)
	if err != nil {
		return err
	}

	return windows.SetNamedSecurityInfo(
		dir,
		windows.SE_FILE_OBJECT,
		windows.DACL_SECURITY_INFORMATION|windows.PROTECTED_DACL_SECURITY_INFORMATION,
		nil, nil, acl, nil,
	)
}

func grantFull(sid *windows.SID) windows.EXPLICIT_ACCESS {
	return windows.EXPLICIT_ACCESS{
		AccessPermissions: windows.GENERIC_ALL,
		AccessMode:        windows.GRANT_ACCESS,
		Inheritance:       windows.SUB_CONTAINERS_AND_OBJECTS_INHERIT,
		Trustee: windows.TRUSTEE{
			TrusteeForm:  windows.TRUSTEE_IS_SID,
			TrusteeType:  windows.TRUSTEE_IS_WELL_KNOWN_GROUP,
			TrusteeValue: windows.TrusteeValueFromSID(sid),
		},
	}
}

func zero(b []byte) {
	for i := range b {
		b[i] = 0
	}
}
