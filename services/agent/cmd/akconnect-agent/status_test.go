package main

import (
	"context"
	"io"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/state"
)

// A step that hangs must not leave the technician looking at an empty
// prompt: status gives up, names the step, and has already printed its
// version line — and --out has the same text.
func TestStatusNamesTheStepThatHangsAndAlwaysPrints(t *testing.T) {
	oldBody, oldDeadline := statusBody, statusDeadline
	defer func() { statusBody, statusDeadline = oldBody, oldDeadline }()

	statusDeadline = 200 * time.Millisecond
	statusBody = func(_ io.Writer, step *atomic.Value) error {
		step.Store("reading the key store")
		select {} // never returns
	}

	out := filepath.Join(t.TempDir(), "status.txt")
	err := runStatus(context.Background(), []string{"--out", out})
	if err == nil || !strings.Contains(err.Error(), "reading the key store") {
		t.Fatalf("a hang was not reported with its step: %v", err)
	}

	got, _ := os.ReadFile(out)
	if !strings.Contains(string(got), "akconnect-agent") || !strings.Contains(string(got), "reading the key store") {
		t.Fatalf("--out is missing the version line or the step:\n%s", got)
	}
}

func TestStatusReportsAPanicInsteadOfDyingSilently(t *testing.T) {
	oldBody := statusBody
	defer func() { statusBody = oldBody }()

	statusBody = func(_ io.Writer, step *atomic.Value) error {
		step.Store("reading runtime.json")
		panic("boom")
	}

	err := runStatus(context.Background(), nil)
	if err == nil || !strings.Contains(err.Error(), "reading runtime.json") || !strings.Contains(err.Error(), "boom") {
		t.Fatalf("a panic was not reported: %v", err)
	}
}

// An empty or unreadable live status file is described — its size and age —
// rather than failing the whole command: the field report was "runtime.json
// prints nothing", and "0 bytes, written 3s ago" and "missing" have
// different causes.
func TestStatusDescribesAnEmptyLiveStatusFile(t *testing.T) {
	dir := t.TempDir()
	t.Setenv("AKCONNECT_STATE_DIR", dir)
	if err := os.WriteFile(filepath.Join(dir, "runtime.json"), nil, 0o644); err != nil {
		t.Fatal(err)
	}

	st, err := state.Open()
	if err != nil {
		t.Fatal(err)
	}
	var b strings.Builder
	if err := printRuntime(&b, st); err != nil {
		t.Fatalf("status failed on an empty live status file: %v", err)
	}
	if got := b.String(); !strings.Contains(got, "0 bytes") || !strings.Contains(got, "cannot be read") {
		t.Fatalf("the empty file was not described:\n%s", got)
	}
}

// A gateway's section says how it translates and what it has done, and a
// failed gateway says NOT working with the reason.
func TestStatusShowsTheGateway(t *testing.T) {
	dir := t.TempDir()
	t.Setenv("AKCONNECT_STATE_DIR", dir)
	st, err := state.Open()
	if err != nil {
		t.Fatal(err)
	}

	for _, tc := range []struct {
		gw   state.RuntimeGateway
		want []string
	}{
		{state.RuntimeGateway{Mode: "agent", Diverted: 12, PingsOK: 4, TCPOpened: 2},
			[]string{"translated by the agent itself", "arrived for the LAN 12", "pings answered 4", "TCP opened 2"}},
		{state.RuntimeGateway{Mode: "agent", Problem: "gateway could not start its NAT: boom"},
			[]string{"NOT working — gateway could not start its NAT: boom"}},
	} {
		gw := tc.gw
		if err := st.SaveRuntime(&state.Runtime{Gateway: &gw}); err != nil {
			t.Fatal(err)
		}
		var b strings.Builder
		if err := printRuntime(&b, st); err != nil {
			t.Fatal(err)
		}
		for _, w := range tc.want {
			if !strings.Contains(b.String(), w) {
				t.Fatalf("missing %q in:\n%s", w, b.String())
			}
		}
	}
}
