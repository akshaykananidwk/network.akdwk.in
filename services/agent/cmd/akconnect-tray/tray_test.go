package main

import (
	"archive/zip"
	"io"
	"strings"
	"testing"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/state"
)

func TestWhatTheIconSays(t *testing.T) {
	now := time.Date(2026, 9, 22, 10, 0, 0, 0, time.UTC)
	fresh := now.Add(-3 * time.Second)

	cases := []struct {
		name     string
		st       *state.State
		rt       *state.Runtime
		headline string
		ok       bool
		ip       string
	}{
		{
			name:     "nothing on disk at all",
			headline: "AK Connect is not running",
		},
		{
			name:     "enrolled and waiting for an administrator",
			st:       &state.State{PanelURL: "https://p", DeviceUID: "dev_1"},
			headline: "Waiting for approval",
		},
		{
			name:     "approved but the service is not running",
			st:       &state.State{PanelURL: "https://p", DeviceUID: "dev_1", DeviceToken: "t", VirtualIP: "10.50.0.3"},
			headline: "AK Connect is not running",
			ip:       "10.50.0.3",
		},
		{
			name: "the status file is stale, so it is not running whatever it says",
			st:   &state.State{PanelURL: "https://p", DeviceUID: "dev_1", DeviceToken: "t", VirtualIP: "10.50.0.3"},
			rt: &state.Runtime{
				UpdatedAt: now.Add(-10 * time.Minute), VirtualIP: "10.50.0.3", ControlPlaneUp: true,
			},
			headline: "AK Connect is not running",
			ip:       "10.50.0.3",
		},
		{
			name: "running, alone on the network",
			rt: &state.Runtime{
				UpdatedAt: fresh, VirtualIP: "10.50.0.3", ControlPlaneUp: true,
			},
			headline: "Connected",
			ok:       true,
			ip:       "10.50.0.3",
		},
		{
			name: "running, everyone reachable",
			rt: &state.Runtime{
				UpdatedAt: fresh, VirtualIP: "10.50.0.3", ControlPlaneUp: true,
				Peers: []state.RuntimePeer{{Path: "direct"}, {Path: "relay"}},
			},
			headline: "Connected",
			ok:       true,
			ip:       "10.50.0.3",
		},
		{
			name: "running, nobody reachable yet",
			rt: &state.Runtime{
				UpdatedAt: fresh, VirtualIP: "10.50.0.3", ControlPlaneUp: true,
				Peers: []state.RuntimePeer{{Path: "connecting"}},
			},
			headline: "Connecting to the other computers",
			ip:       "10.50.0.3",
		},
		{
			name: "running, but the panel cannot be reached",
			rt: &state.Runtime{
				UpdatedAt: fresh, VirtualIP: "10.50.0.3", ControlPlaneUp: false,
			},
			headline: "No connection to AK Connect",
			ip:       "10.50.0.3",
		},
		{
			// The tray runs as the customer and the service as SYSTEM. A
			// permission on state.json must not turn a working network into a
			// broken-looking one.
			name: "the status file is readable and nothing else is",
			rt: &state.Runtime{
				UpdatedAt: fresh, VirtualIP: "10.50.0.3", ControlPlaneUp: true,
				Peers: []state.RuntimePeer{{Path: "direct"}},
			},
			headline: "Connected",
			ok:       true,
			ip:       "10.50.0.3",
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got := describe(tc.st, tc.rt, nil, now)

			if got.Headline != tc.headline {
				t.Errorf("headline = %q, want %q", got.Headline, tc.headline)
			}
			if got.OK != tc.ok {
				t.Errorf("OK = %v, want %v", got.OK, tc.ok)
			}
			if got.IP != tc.ip {
				t.Errorf("IP = %q, want %q", got.IP, tc.ip)
			}
			if tip := tooltip(got); len(tip) > 127 {
				t.Errorf("tooltip is %d characters; Windows truncates at 127", len(tip))
			}
		})
	}
}

// The bundle is emailed to a supplier by a person who will not read it first.
// A credential in it is a credential given away.
func TestDiagnosticsCarryNoCredentials(t *testing.T) {
	secrets := []string{"tok_live_abc123", "s3cr3t-join-code", "PRIVATEKEYBASE64=="}

	logText := strings.Join([]string{
		`{"device_token": "tok_live_abc123", "virtual_ip": "10.50.0.3"}`,
		`2026-09-22 enrolling with join_code=s3cr3t-join-code`,
		`GET /api/agent/config Authorization: Bearer tok_live_abc123`,
		`  private_key: PRIVATEKEYBASE64==`,
		`  this line is ordinary and must survive`,
	}, "\n")

	dir := t.TempDir()

	path, err := collect(dir, []source{{Name: "service.log", Text: logText}}, time.Now())
	if err != nil {
		t.Fatal(err)
	}

	archive, err := zip.OpenReader(path)
	if err != nil {
		t.Fatal(err)
	}
	defer archive.Close()

	if len(archive.File) != 1 {
		t.Fatalf("the bundle holds %d files, want 1", len(archive.File))
	}

	f, err := archive.File[0].Open()
	if err != nil {
		t.Fatal(err)
	}
	content, err := io.ReadAll(f)
	if err != nil {
		t.Fatal(err)
	}

	for _, secret := range secrets {
		if strings.Contains(string(content), secret) {
			t.Errorf("the bundle contains %q; it is about to be emailed", secret)
		}
	}

	if !strings.Contains(string(content), "this line is ordinary and must survive") {
		t.Error("redaction removed an ordinary line; the bundle has to stay useful")
	}
	if !strings.Contains(string(content), "10.50.0.3") {
		t.Error("the overlay address was redacted; it is the first thing support needs")
	}
}

// A log that has been running since the machine was installed is not more
// useful for being complete, and a 300 MB attachment does not get sent.
func TestALargeLogIsTrimmedFromTheEnd(t *testing.T) {
	dir := t.TempDir()
	big := strings.Repeat("old\n", 4096) + "THE-RECENT-PART\n"

	path := dir + "/big.log"
	if err := writeTestFile(path, big); err != nil {
		t.Fatal(err)
	}

	out, err := collect(dir, []source{{Name: "service.log", Path: path, Limit: 64}}, time.Now())
	if err != nil {
		t.Fatal(err)
	}

	content := readOnly(t, out, "service.log")
	if !strings.Contains(content, "THE-RECENT-PART") {
		t.Error("the trimmed log kept the beginning instead of the end")
	}
	if strings.Count(content, "old") > 20 {
		t.Error("the log was not trimmed")
	}
	if !strings.Contains(content, "are not included") {
		t.Error("the bundle does not say that it was trimmed")
	}
}

// A file that is not there is itself an answer, and the bundle has to say so
// rather than being quietly one file short.
func TestAMissingFileIsReportedInTheBundle(t *testing.T) {
	dir := t.TempDir()

	out, err := collect(dir, []source{{Name: "service.log", Path: dir + "/nope.log"}}, time.Now())
	if err != nil {
		t.Fatal(err)
	}

	if content := readOnly(t, out, "service.log"); !strings.Contains(content, "could not be read") {
		t.Errorf("a missing file produced %q", content)
	}
}
