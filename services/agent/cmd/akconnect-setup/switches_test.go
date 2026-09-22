package main

import "testing"

// A deployment tool on Windows writes /S /CODE=ABCD, because that is what
// every installer it has ever driven accepts. Being handed "flag provided but
// not defined: /S" is how a rollout stops.
func TestWindowsStyleSwitchesAreUnderstood(t *testing.T) {
	cases := []struct {
		in   []string
		want []string
	}{
		{[]string{"/S", "/CODE=ABCD-1234"}, []string{"-silent", "-code=ABCD-1234"}},
		{[]string{"/s", "/code=abcd"}, []string{"-silent", "-code=abcd"}},
		{[]string{"/QUIET", "/JOINCODE=XY-1"}, []string{"-silent", "-code=XY-1"}},
		{[]string{"/UNINSTALL"}, []string{"-uninstall"}},
		{[]string{"/uninstall", "/S"}, []string{"-uninstall", "-silent"}},
		{[]string{"/PANEL=https://net.akdwk.in"}, []string{"-panel=https://net.akdwk.in"}},
		{[]string{"/NAME=Front desk PC"}, []string{"-name=Front desk PC"}},
		{[]string{"/FORCE"}, []string{"-force"}},
		// The Go spelling still works. Nobody's script breaks.
		{[]string{"-silent", "-code", "ABCD"}, []string{"-silent", "-code", "ABCD"}},
		// An unknown switch is passed through rather than mangled, so the
		// error names what was actually typed.
		{[]string{"/WHATEVER"}, []string{"/WHATEVER"}},
		{[]string{"/UNKNOWN=1"}, []string{"/UNKNOWN=1"}},
		// A value containing an = survives: a panel URL with a query string.
		{[]string{"/PANEL=https://x/?a=b"}, []string{"-panel=https://x/?a=b"}},
	}

	for _, tc := range cases {
		got := normaliseArgs(tc.in)
		if len(got) != len(tc.want) {
			t.Fatalf("normaliseArgs(%v) = %v, want %v", tc.in, got, tc.want)
		}
		for i := range got {
			if got[i] != tc.want[i] {
				t.Errorf("normaliseArgs(%v)[%d] = %q, want %q", tc.in, i, got[i], tc.want[i])
			}
		}
	}
}

// The January installer, double-clicked in June, must not take a computer back
// to January. It is the same file in the same Downloads folder, and nothing
// about it looks wrong to the person clicking it.
func TestAnOlderInstallerIsADowngrade(t *testing.T) {
	cases := []struct {
		incoming, installed string
		want                bool
	}{
		{"1.9.4", "1.9.5", true},
		{"1.9.5", "1.9.5", false},
		{"1.9.6", "1.9.5", false},
		{"1.10.0", "1.9.9", false},
		{"1.9.9", "1.10.0", true},
		{"2.0.0", "1.9.5", false},
		{"1.9.5-rc1", "1.9.5", true},
		{"1.9.5", "1.9.5-rc1", false},
		// Nothing installed, or something we cannot read: not a downgrade. A
		// repair on a broken machine must not be refused over a version
		// string.
		{"1.9.4", "", false},
		{"1.9.4", "garbage", false},
		{"dev", "1.9.5", false},
		{"1.9.4", "dev", false},
	}

	for _, tc := range cases {
		if got := isDowngrade(tc.incoming, tc.installed); got != tc.want {
			t.Errorf("isDowngrade(%q, %q) = %v, want %v", tc.incoming, tc.installed, got, tc.want)
		}
	}
}

func TestVersionOrdering(t *testing.T) {
	cases := []struct {
		a, b string
		want int
	}{
		{"1.9.5", "1.9.5", 0},
		{"v1.9.5", "1.9.5", 0},
		{"1.9.5", "1.9.4", 1},
		{"1.9.4", "1.9.5", -1},
		{"1.10.0", "1.9.0", 1},
		{"1.9", "1.9.0", 0},
		{"1.9.5-rc2", "1.9.5-rc1", 1},
	}

	for _, tc := range cases {
		if got := compareVersions(tc.a, tc.b); got != tc.want {
			t.Errorf("compareVersions(%q, %q) = %d, want %d", tc.a, tc.b, got, tc.want)
		}
	}
}
