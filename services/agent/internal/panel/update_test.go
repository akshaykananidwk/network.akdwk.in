package panel

import (
	"bytes"
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// The download URL arrives inside a JSON body. An agent that simply followed
// it would fetch its next executable from wherever that body said — which is
// the whole attack, and it does not need the signing key.
func TestADownloadOffThisPanelIsRefused(t *testing.T) {
	elsewhere := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write([]byte("malware"))
	}))
	defer elsewhere.Close()

	client, err := New(Options{BaseURL: "https://net.example.com", Token: "t"})
	if err != nil {
		t.Fatal(err)
	}

	var got bytes.Buffer

	written, err := client.DownloadTo(context.Background(), elsewhere.URL+"/agent.exe", &got)
	if err == nil {
		t.Fatal("the agent downloaded a binary from another host")
	}
	if written != 0 || got.Len() != 0 {
		t.Fatalf("%d bytes were written before the refusal", got.Len())
	}
	if !strings.Contains(err.Error(), "enrolled with net.example.com") {
		t.Fatalf("the refusal does not name the enrolled panel: %v", err)
	}
}

// http:// to the same host is still a different origin: it is the one a
// downgrade attack can reach.
func TestADownloadOverPlainHTTPFromAnHTTPSPanelIsRefused(t *testing.T) {
	client, err := New(Options{BaseURL: "https://net.example.com", Token: "t"})
	if err != nil {
		t.Fatal(err)
	}

	var got bytes.Buffer

	if _, err := client.DownloadTo(context.Background(), "http://net.example.com/agent.exe", &got); err == nil {
		t.Fatal("a plain-HTTP download from an HTTPS panel was accepted")
	}
}

func TestADownloadFromThisPanelIsFetched(t *testing.T) {
	const body = "this is the new agent"

	var sawAuth string

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		sawAuth = r.Header.Get("Authorization")
		_, _ = w.Write([]byte(body))
	}))
	defer server.Close()

	client, err := New(Options{BaseURL: server.URL, Token: "device-token"})
	if err != nil {
		t.Fatal(err)
	}

	var got bytes.Buffer

	written, err := client.DownloadTo(context.Background(), server.URL+"/api/v1/agent/download/3", &got)
	if err != nil {
		t.Fatalf("downloading from the enrolled panel failed: %v", err)
	}
	if written != int64(len(body)) || got.String() != body {
		t.Fatalf("got %q (%d bytes), want %q", got.String(), written, body)
	}

	// The download endpoint is device-authenticated: it is the one route that
	// hands out code, so it must not be reachable without the token.
	if sawAuth != "Bearer device-token" {
		t.Fatalf("the download was sent without the device token (Authorization: %q)", sawAuth)
	}
}

// A panel that answers 404 or 500 must not leave an empty file looking like a
// release; the error is what the caller acts on.
func TestAFailedDownloadIsAnError(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusForbidden)
	}))
	defer server.Close()

	client, err := New(Options{BaseURL: server.URL, Token: "t"})
	if err != nil {
		t.Fatal(err)
	}

	var got bytes.Buffer

	if _, err := client.DownloadTo(context.Background(), server.URL+"/x", &got); err == nil {
		t.Fatal("a 403 was treated as a successful download")
	}
}
