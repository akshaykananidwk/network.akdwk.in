package main

// The statement that removes our network adapter.
//
// In its own file, without a build tag, because what it matches is a decision
// and not a Windows call — and a decision that can only be exercised on a
// Windows laptop is a decision that is never exercised. See
// removeWintunAdapter for what it is for.
func adapterQuery(name string) string {
	return `$found = Get-PnpDevice -Class Net -ErrorAction SilentlyContinue | ` +
		`Where-Object { $_.FriendlyName -eq '` + name + `' -or ` +
		// Windows appends " #2" when a second adapter takes the same name,
		// which happens after a crash left the first one behind.
		`$_.FriendlyName -like '` + name + ` #*' }; ` +
		`foreach ($d in $found) { & pnputil /remove-device $d.InstanceId 2>&1 | Out-Null }`
}
