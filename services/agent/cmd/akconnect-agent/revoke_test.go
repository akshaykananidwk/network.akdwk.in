package main

import (
	"net/http"
	"testing"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/panel"
)

// A 401 that is not the panel saying "revoked" must never cut a tunnel.
//
// Apache not passing the Authorization header to PHP-FPM, a WAF answering
// with an HTML page, maintenance mode, a rate limiter, a panel whose database
// is down — every one of these answers 401 or 403, and the agent used to
// treat all of them as revocation. One unhealthy panel would disconnect every
// device on it, and none would come back without somebody re-enrolling by
// hand at each site.
func TestOnlyThePanelsOwnAnswerCountsAsRevocation(t *testing.T) {
	notRevocation := []*panel.APIError{
		{StatusCode: http.StatusUnauthorized, Code: "no_credential"},
		{StatusCode: http.StatusUnauthorized, Code: "unauthenticated"},
		{StatusCode: http.StatusForbidden, Code: ""},
		{StatusCode: http.StatusForbidden, Code: "account_inactive"},
		{StatusCode: http.StatusTooManyRequests, Code: "rate_limited"},
	}

	for _, err := range notRevocation {
		s := &session{logf: func(string, ...any) {}}

		// Even repeated, over any length of time, these never confirm.
		for i := 0; i < revokeConfirmations*3; i++ {
			s.revokedSince = time.Now().Add(-2 * revokeConfirmWindow)
			if s.confirmRevoked(err) {
				t.Fatalf("a %d/%q answer was treated as revocation", err.StatusCode, err.Code)
			}
		}
	}
}

// And the panel's own answer is believed only once it has been repeated.
func TestRevocationIsConfirmedBeforeItIsBelieved(t *testing.T) {
	s := &session{logf: func(string, ...any) {}}
	err := &panel.APIError{StatusCode: http.StatusUnauthorized, Code: "device_unauthorized"}

	// The first answers do not cut the tunnel, however many arrive quickly.
	for i := 0; i < revokeConfirmations+2; i++ {
		if s.confirmRevoked(err) {
			t.Fatalf("disconnected after %d fast answers, inside the %s window", i+1, revokeConfirmWindow)
		}
	}

	// Once they have spanned the window as well, it is believed.
	s.revokedSince = time.Now().Add(-2 * revokeConfirmWindow)
	if !s.confirmRevoked(err) {
		t.Fatal("never believed a genuine revocation; a revoked device would keep running")
	}
}

// One good answer in between clears the count: the evidence has to be
// consecutive to mean anything.
func TestASuccessfulCheckClearsTheRevocationCount(t *testing.T) {
	s := &session{logf: func(string, ...any) {}}
	revocation := &panel.APIError{StatusCode: http.StatusUnauthorized, Code: "device_unauthorized"}

	s.confirmRevoked(revocation)
	s.confirmRevoked(revocation)

	// A poll that worked.
	s.confirmRevoked(nil)

	if s.revokedSeen != 0 {
		t.Fatalf("the count is %d after a successful check, want 0", s.revokedSeen)
	}

	s.revokedSince = time.Now().Add(-2 * revokeConfirmWindow)
	if s.confirmRevoked(revocation) {
		t.Fatal("disconnected on the first answer after a success")
	}
}
