//go:build windows

package state

import (
	"golang.org/x/sys/windows"
)

// The two files a customer's support bundle is made of, and why they need an
// ACL of their own.
//
// The state directory holds the device key and the device token, so the
// keystore strips its inherited permissions and grants only SYSTEM and the
// local Administrators group. That is right for a bearer credential and wrong
// for everything else in the same folder: the status file and the service log
// inherit the same lockout, and the tray — which runs as the person sitting at
// the machine, not as an administrator — cannot read either of them.
//
// The result was a diagnostics bundle from a real customer laptop containing
// three files, two of which said "Access is denied". The one artifact that
// exists to explain a fault could not see the fault.
//
// So these two files, and only these two, carry an explicit grant of read
// access to the local Users group. Neither holds a secret: the status file has
// public keys, addresses and counters, and the log is redacted at the point it
// is written. The key and the state file are untouched and stay
// administrators-only.
func allowLocalRead(path string) error {
	users, err := windows.CreateWellKnownSid(windows.WinBuiltinUsersSid)
	if err != nil {
		return err
	}

	// Merged into whatever is already there rather than replacing it, so the
	// SYSTEM and Administrators entries the keystore set are kept.
	security, err := windows.GetNamedSecurityInfo(
		path, windows.SE_FILE_OBJECT, windows.DACL_SECURITY_INFORMATION)
	if err != nil {
		return err
	}

	existing, _, err := security.DACL()
	if err != nil {
		return err
	}

	acl, err := windows.ACLFromEntries([]windows.EXPLICIT_ACCESS{{
		AccessPermissions: windows.GENERIC_READ,
		AccessMode:        windows.GRANT_ACCESS,
		Inheritance:       windows.NO_INHERITANCE,
		Trustee: windows.TRUSTEE{
			TrusteeForm:  windows.TRUSTEE_IS_SID,
			TrusteeType:  windows.TRUSTEE_IS_WELL_KNOWN_GROUP,
			TrusteeValue: windows.TrusteeValueFromSID(users),
		},
	}}, existing)
	if err != nil {
		return err
	}

	return windows.SetNamedSecurityInfo(
		path, windows.SE_FILE_OBJECT, windows.DACL_SECURITY_INFORMATION,
		nil, nil, acl, nil)
}
