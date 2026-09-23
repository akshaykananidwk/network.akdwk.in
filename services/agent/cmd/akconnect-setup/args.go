package main

import "strings"

// Windows-style switches, because this is a Windows installer and every
// deployment tool on Windows speaks that dialect.
//
// An IT person automating a rollout writes
//
//	akconnect-setup.exe /S /CODE=ABCD-1234
//
// because that is what NSIS, Inno Setup and every installer they have ever
// scripted accept. Being told to write -silent -code=ABCD-1234 instead is a
// small thing that makes a product look like it was built by someone who does
// not work on Windows. Both spellings work; neither is documented as the
// "real" one.
//
// Kept in its own file, away from the Windows-only code, so it can be tested
// on the machine the tests run on.
func normaliseArgs(args []string) []string {
	out := make([]string, 0, len(args))

	for _, arg := range args {
		out = append(out, normaliseArg(arg))
	}

	return out
}

// switches maps a Windows-style switch to the flag it means. The key is
// upper-case; the comparison is case-insensitive, because /s and /S are the
// same switch to everyone who has ever typed one.
var switches = map[string]string{
	"S":         "-silent",
	"SILENT":    "-silent",
	"Q":         "-silent",
	"QUIET":     "-silent",
	"UNINSTALL": "-uninstall",
	"X":         "-uninstall",
	"VERSION":   "-version",
	"FORCE":     "-force",
	"?":         "-h",
	"HELP":      "-h",
}

// valueSwitches maps a Windows-style /NAME=value switch to its flag.
var valueSwitches = map[string]string{
	"CODE":       "-code",
	"JOINCODE":   "-code",
	"PANEL":      "-panel",
	"NAME":       "-name",
	"DEVICENAME": "-name",
}

func normaliseArg(arg string) string {
	if !strings.HasPrefix(arg, "/") || arg == "/" {
		return arg
	}

	body := arg[1:]

	if key, value, found := strings.Cut(body, "="); found {
		if flag, ok := valueSwitches[strings.ToUpper(key)]; ok {
			return flag + "=" + value
		}

		// An unknown /KEY=VALUE is passed through unchanged rather than
		// mangled, so the error a customer sees names what they typed.
		return arg
	}

	if flag, ok := switches[strings.ToUpper(body)]; ok {
		return flag
	}

	return arg
}
