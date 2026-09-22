package main

import (
	"context"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/keystore"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/state"
)

// envelope answers with the shape the panel actually sends.
func envelope(status int, body string) http.HandlerFunc {
	return func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(status)
		w.Write([]byte(body))
	}
}

// A device deleted in the panel leaves a machine that still believes it is
// enrolled. The customer's remedy is the one thing they know how to do — run
// the installer again — and it used to print "already enrolled, nothing to do"
// and change nothing.
//
// The panel is asked instead. The rule that matters is which way it fails:
// only a clear "no such device" re-enrols, because discarding a working
// identity over a panel that was briefly down is a worse failure than the one
// being fixed, and it spends a single-use join code doing it.
func TestWhetherThePanelStillKnowsThisDevice(t *testing.T) {
	cases := []struct {
		name    string
		handler http.HandlerFunc
		want    knownness
	}{
		{
			name: "the panel knows it and it is approved",
			handler: envelope(http.StatusOK,
				`{"success":true,"data":{"status":"authorized","device_token":"t","virtual_ip":"10.50.0.3"}}`),
			want: known,
		},
		{
			name:    "the panel knows it and is still waiting for an administrator",
			handler: envelope(http.StatusOK, `{"success":true,"data":{"status":"pending"}}`),
			want:    known,
		},
		{
			name: "the panel has never heard of it",
			handler: envelope(http.StatusNotFound,
				`{"success":false,"error":{"code":"not_enrolled","message":"unknown device"}}`),
			want: forgotten,
		},
		{
			name: "the panel is in maintenance",
			handler: func(w http.ResponseWriter, _ *http.Request) {
				w.WriteHeader(http.StatusServiceUnavailable)
			},
			want: unreachable,
		},
		{
			name: "the panel is broken",
			handler: func(w http.ResponseWriter, _ *http.Request) {
				w.WriteHeader(http.StatusInternalServerError)
			},
			want: unreachable,
		},
		{
			name: "something that is not the panel answers",
			handler: func(w http.ResponseWriter, _ *http.Request) {
				w.Write([]byte("<html>hotel wifi login</html>"))
			},
			want: unreachable,
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			dir := t.TempDir()
			t.Setenv("AKCONNECT_STATE_DIR", dir)

			store, err := keystore.Open()
			if err != nil {
				t.Fatal(err)
			}
			if _, _, _, err := keystore.LoadOrCreate(store); err != nil {
				t.Fatal(err)
			}

			server := httptest.NewServer(tc.handler)
			defer server.Close()

			got := stillKnown(context.Background(), server.URL,
				&state.State{PanelURL: server.URL, DeviceUID: "dev_1"})

			if got != tc.want {
				t.Errorf("stillKnown = %v, want %v", got, tc.want)
			}
		})
	}
}

// A machine with no identity at all cannot be asked about, and must not be
// reported as forgotten — that verdict is what discards things.
func TestAMachineWithNoKeyIsNotReportedAsForgotten(t *testing.T) {
	t.Setenv("AKCONNECT_STATE_DIR", t.TempDir())

	server := httptest.NewServer(envelope(http.StatusNotFound,
		`{"success":false,"error":{"code":"not_enrolled"}}`))
	defer server.Close()

	if got := stillKnown(context.Background(), server.URL,
		&state.State{PanelURL: server.URL, DeviceUID: "dev_1"}); got == forgotten {
		t.Error("a machine with no key was reported as forgotten by the panel")
	}
}

// The inbound firewall rule has to name the port the agent actually uses.
//
// It was created once, at install time, for a fixed 51820 — right until
// defect 23 made every device pick its own. From that moment the rule named a
// port nothing was listening on, and Windows dropped every inbound discovery
// and WireGuard packet: a device that announced happily, looked healthy in the
// panel, and could be reached by nobody. The fix for two PCs behind one router
// would have broken the inbound path for every Windows device.
//
// This asserts the two places that must name the live port do so.
func TestTheFirewallRuleFollowsThePortInUse(t *testing.T) {
	for _, file := range []string{"up.go", "discovery.go"} {
		source, err := os.ReadFile(file)
		if err != nil {
			t.Fatal(err)
		}

		if !strings.Contains(string(source), "winenv.EnsureFirewallRule(") {
			t.Errorf("%s does not open the firewall for the port it is using", file)
		}
	}

	// And nothing pins the rule to the old fixed port.
	source, err := os.ReadFile("service.go")
	if err != nil {
		t.Fatal(err)
	}

	if strings.Contains(string(source), `fs.Int("port", tunnel.DefaultListenPort`) {
		t.Error("service install still opens the firewall for 51820 whatever this device uses")
	}
}
