package main

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/state"
)

// Defect 8, from the first run on a real Windows laptop.
//
// `up` refused to start while the local state said "pending", and so did the
// service: it started, exited within a second, and left the machine looking
// broken. Approving the device five minutes later changed nothing, because the
// only thing that would have noticed had already stopped. The customer's fix
// was to run the command again and hope.
//
// The agent has to sit in the waiting room instead, and pick the approval up
// on its own.

// answer is one /api/v1/agent/claim response.
type answer struct {
	status     int
	body       string
	pollAfter  int
	errCode    string
	errMessage string
}

func claimServer(t *testing.T, answers []answer, calls *int64) *httptest.Server {
	t.Helper()

	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/agent/claim" {
			t.Errorf("unexpected path %s", r.URL.Path)
			w.WriteHeader(http.StatusNotFound)

			return
		}

		n := atomic.AddInt64(calls, 1) - 1
		a := answers[len(answers)-1]
		if int(n) < len(answers) {
			a = answers[n]
		}

		envelope := map[string]any{
			"success": a.errCode == "",
			"meta":    map[string]any{"poll_after": a.pollAfter},
		}
		if a.errCode != "" {
			envelope["error"] = map[string]any{"code": a.errCode, "message": a.errMessage}
		} else {
			envelope["data"] = json.RawMessage(a.body)
		}

		w.Header().Set("Content-Type", "application/json")
		if a.status != 0 {
			w.WriteHeader(a.status)
		}
		_ = json.NewEncoder(w).Encode(envelope)
	}))
}

func waitingSession(t *testing.T, baseURL string) (*session, *state.Store) {
	t.Helper()

	t.Setenv("AKCONNECT_STATE_DIR", t.TempDir())

	store, err := state.Open()
	if err != nil {
		t.Fatalf("state.Open: %v", err)
	}

	st := &state.State{PanelURL: baseURL, DeviceUID: "dev_test_0001"}
	if err := store.Save(st); err != nil {
		t.Fatalf("saving state: %v", err)
	}

	client, err := panel.New(panel.Options{BaseURL: baseURL, Timeout: 5 * time.Second})
	if err != nil {
		t.Fatalf("panel.New: %v", err)
	}

	return &session{
		client:  client,
		stateSt: store,
		st:      st,
		logf:    func(string, ...any) {},
	}, store
}

// The whole point: pending is not fatal, and approval arrives without anyone
// restarting anything.
func TestTheAgentWaitsForApprovalAndPicksItUp(t *testing.T) {
	var calls int64

	server := claimServer(t, []answer{
		{body: `{"status":"pending","authorized":false}`, pollAfter: 1},
		{body: `{"status":"pending","authorized":false}`, pollAfter: 1},
		{body: `{"status":"authorized","authorized":true,"device_token":"tok_live_0001","virtual_ip":"10.50.0.7"}`, pollAfter: 1},
	}, &calls)
	defer server.Close()

	s, store := waitingSession(t, server.URL)

	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()

	if err := s.awaitApproval(ctx, "cHVibGljLWtleS0zMi1ieXRlcy1wbGFjZWhvbGRlcg=="); err != nil {
		t.Fatalf("awaitApproval: %v", err)
	}

	if calls < 3 {
		t.Fatalf("the agent asked %d time(s); it has to keep asking", calls)
	}

	// The token has to be on disk, or a restart before the next poll would put
	// the machine straight back in the waiting room.
	saved, err := store.Load()
	if err != nil {
		t.Fatalf("reloading state: %v", err)
	}
	if saved.DeviceToken != "tok_live_0001" {
		t.Fatalf("the device token was not saved: %q", saved.DeviceToken)
	}
	if saved.VirtualIP != "10.50.0.7" {
		t.Fatalf("the overlay address was not saved: %q", saved.VirtualIP)
	}
	if !saved.Approved() {
		t.Fatal("the state does not report the device as approved")
	}

	// And the session must now be using the token, not still unauthenticated.
	if s.st.DeviceToken != "tok_live_0001" {
		t.Fatalf("the session kept the old state: %q", s.st.DeviceToken)
	}
}

// A panel that cannot be reached is a train, a restart, a renewing
// certificate. None of them is a reason to give up on a machine that is
// enrolled and waiting.
func TestATransientPanelFailureDoesNotEndTheWait(t *testing.T) {
	var calls int64

	server := claimServer(t, []answer{
		{status: http.StatusBadGateway, errCode: "upstream", errMessage: "bad gateway", pollAfter: 1},
		{status: http.StatusServiceUnavailable, errCode: "maintenance", errMessage: "down for maintenance", pollAfter: 1},
		{body: `{"status":"authorized","authorized":true,"device_token":"tok_live_0002","virtual_ip":"10.50.0.8"}`, pollAfter: 1},
	}, &calls)
	defer server.Close()

	s, _ := waitingSession(t, server.URL)

	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()

	if err := s.awaitApproval(ctx, "cHVibGljLWtleS0zMi1ieXRlcy1wbGFjZWhvbGRlcg=="); err != nil {
		t.Fatalf("a transient failure ended the wait: %v", err)
	}
	if calls < 3 {
		t.Fatalf("the agent gave up after %d call(s)", calls)
	}
}

// The one case that is fatal: the panel has no record of this key. Nothing the
// agent can do alone fixes that, so it says which command a person has to run
// rather than waiting forever.
func TestAForgottenDeviceStopsWithAnActionableMessage(t *testing.T) {
	var calls int64

	server := claimServer(t, []answer{
		{status: http.StatusNotFound, errCode: "not_enrolled", errMessage: "unknown device"},
	}, &calls)
	defer server.Close()

	s, _ := waitingSession(t, server.URL)

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()

	err := s.awaitApproval(ctx, "cHVibGljLWtleS0zMi1ieXRlcy1wbGFjZWhvbGRlcg==")
	if err == nil {
		t.Fatal("an unknown device waited instead of stopping")
	}
	if !strings.Contains(err.Error(), "reset") {
		t.Fatalf("the message does not name the command that fixes it: %v", err)
	}
}

// Stopping the service while it waits must be a clean stop, not an error: the
// device is still enrolled and still waiting, and reporting a failure would
// put a red cross in the Windows service manager for correct behaviour.
func TestCancellingTheWaitIsNotAFailure(t *testing.T) {
	var calls int64

	server := claimServer(t, []answer{
		{body: `{"status":"pending","authorized":false}`, pollAfter: 1},
	}, &calls)
	defer server.Close()

	s, _ := waitingSession(t, server.URL)

	ctx, cancel := context.WithTimeout(context.Background(), 1500*time.Millisecond)
	defer cancel()

	if err := s.awaitApproval(ctx, "cHVibGljLWtleS0zMi1ieXRlcy1wbGFjZWhvbGRlcg=="); err != nil {
		t.Fatalf("a cancelled wait reported a failure: %v", err)
	}
}

// Defect 9's other half. Apache drops the Authorization header on its way to
// PHP-FPM unless it is told not to, so a working install produced the same 401
// as a revoked device — and the agent told the customer to run `reset`, which
// throws away the device's identity and fixes nothing.
func TestAMissingHeaderIsNotReportedAsARevokedDevice(t *testing.T) {
	s := &session{logf: func(string, ...any) {}}

	noCredential := s.explain(&panel.APIError{
		StatusCode: 401,
		Code:       "no_credential",
		Message:    "Provide the device token as a Bearer token.",
	})

	if noCredential == nil {
		t.Fatal("explain swallowed the error")
	}
	if strings.Contains(noCredential.Error(), "revoked") {
		t.Fatalf("a missing header was reported as a revocation: %v", noCredential)
	}
	for _, want := range []string{"Authorization", "CGIPassAuth", "Do NOT run 'reset'"} {
		if !strings.Contains(noCredential.Error(), want) {
			t.Fatalf("the message does not name the real cause (%q missing): %v", want, noCredential)
		}
	}

	// A token the panel actually looked at and rejected still says so.
	revoked := s.explain(&panel.APIError{
		StatusCode: 401,
		Code:       "invalid_token",
		Message:    "That device token is not valid.",
	})
	if !strings.Contains(revoked.Error(), "revoked") || !strings.Contains(revoked.Error(), "reset") {
		t.Fatalf("a genuinely rejected token no longer says what to do: %v", revoked)
	}
}
