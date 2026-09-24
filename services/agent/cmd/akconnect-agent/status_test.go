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
