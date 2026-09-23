// Package panel talks to the control plane's agent API.
//
// Every request here is one the agent makes about itself. Nothing in this
// package can send the device's private key: the enrolment call takes a public
// key and there is no path that reads the keystore.
package panel

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// Client is an authenticated (or, before approval, anonymous) API client.
type Client struct {
	baseURL    string
	token      string
	httpClient *http.Client
	userAgent  string
}

// Options configures a Client.
type Options struct {
	// BaseURL is the panel root, e.g. https://net.example.com
	BaseURL string
	// Token is the device token. Empty until the device has been approved.
	Token string
	// Timeout bounds a single request. Zero means 30 seconds.
	Timeout time.Duration
	// UserAgent identifies the agent build to the panel.
	UserAgent string
}

// New builds a Client. It fails rather than guessing when the URL is unusable,
// because a typo here shows up much later as a confusing enrolment failure.
func New(opts Options) (*Client, error) {
	trimmed := strings.TrimRight(opts.BaseURL, "/")
	if trimmed == "" {
		return nil, fmt.Errorf("no panel URL configured")
	}

	parsed, err := url.Parse(trimmed)
	if err != nil {
		return nil, fmt.Errorf("panel URL %q is not a URL: %w", opts.BaseURL, err)
	}
	if parsed.Scheme != "https" && parsed.Scheme != "http" {
		return nil, fmt.Errorf("panel URL %q must be http or https", opts.BaseURL)
	}
	if parsed.Host == "" {
		return nil, fmt.Errorf("panel URL %q has no host", opts.BaseURL)
	}

	timeout := opts.Timeout
	if timeout == 0 {
		timeout = 30 * time.Second
	}

	agent := opts.UserAgent
	if agent == "" {
		agent = "akconnect-agent"
	}

	return &Client{
		baseURL:    trimmed,
		token:      opts.Token,
		httpClient: &http.Client{Timeout: timeout},
		userAgent:  agent,
	}, nil
}

// WithToken returns a copy carrying a device token, for use after approval.
func (c *Client) WithToken(token string) *Client {
	clone := *c
	clone.token = token

	return &clone
}

// HasToken reports whether this client can call authenticated endpoints.
func (c *Client) HasToken() bool { return c.token != "" }

// APIError is a structured error response from the panel.
type APIError struct {
	StatusCode int
	Code       string
	Message    string
}

func (e *APIError) Error() string {
	if e.Code != "" {
		return fmt.Sprintf("panel returned %d (%s): %s", e.StatusCode, e.Code, e.Message)
	}

	return fmt.Sprintf("panel returned %d: %s", e.StatusCode, e.Message)
}

// Unauthorized reports whether the device's token has stopped working, which
// is how a revocation reaches the agent.
func (e *APIError) Unauthorized() bool {
	return e.StatusCode == http.StatusUnauthorized || e.StatusCode == http.StatusForbidden
}

// Revoked reports that the PANEL said this device is no longer authorized.
//
// Not "the panel answered 401". That is the distinction this exists for, and
// getting it wrong takes every customer down at once.
//
// A 401 or a 403 arrives for many reasons that have nothing to do with a
// device being revoked: Apache not passing the Authorization header to
// PHP-FPM, a WAF or mod_security answering with an HTML page, maintenance
// mode, a rate limiter, a panel whose database is down. The agent used to
// treat all of them as revocation and tear the tunnel down — so a
// misconfigured or briefly unhealthy panel would disconnect every device on
// it, and none of them would come back without somebody re-enrolling by hand.
//
// R6 says the data plane outlives the control plane. So revocation is only
// the panel's own structured answer saying exactly that, and nothing else.
func (e *APIError) Revoked() bool {
	return e.Code == "device_unauthorized"
}

// NoCredential reports that the panel saw no credential at all, as opposed to
// one it did not like.
//
// The difference matters more than it sounds. Apache does not pass the
// Authorization header to PHP-FPM unless it is told to, so a correctly
// configured agent holding a perfectly good token produced exactly the same
// 401 as a revoked device — and the agent told the customer to reset, which
// throws the device's identity away and fixes nothing.
func (e *APIError) NoCredential() bool {
	return e.Code == "no_credential"
}

// envelope matches Response::api() and Response::apiError() exactly:
//
//	{"success":true, "data":…, "meta":{…}, "error":null}
//	{"success":false,"data":null,"meta":{},"error":{"code":…,"message":…}}
type envelope struct {
	Success bool            `json:"success"`
	Data    json.RawMessage `json:"data"`
	Meta    struct {
		// The panel tells the agent how long to wait before asking again.
		// Honouring it is what keeps ten thousand agents from synchronising
		// into a thundering herd against one PHP pool.
		PollAfter int `json:"poll_after"`
	} `json:"meta"`
	Error *struct {
		Code    string `json:"code"`
		Message string `json:"message"`
	} `json:"error"`
}

// do performs one request and unmarshals data into out. It returns the
// poll_after the panel asked for, which is zero when it did not say.
func (c *Client) do(ctx context.Context, method, path string, body any, out any) (int, error) {
	var reader io.Reader
	if body != nil {
		encoded, err := json.Marshal(body)
		if err != nil {
			return 0, fmt.Errorf("encoding request: %w", err)
		}
		reader = bytes.NewReader(encoded)
	}

	req, err := http.NewRequestWithContext(ctx, method, c.baseURL+path, reader)
	if err != nil {
		return 0, fmt.Errorf("building request: %w", err)
	}

	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", c.userAgent)
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	if c.token != "" {
		req.Header.Set("Authorization", "Bearer "+c.token)
	}

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return 0, fmt.Errorf("calling %s: %w", path, err)
	}
	defer resp.Body.Close()

	return decode(resp, out)
}

func decode(resp *http.Response, out any) (int, error) {
	// Bounded: a control-plane response that is megabytes long is a bug or an
	// attack, and either way should not be buffered whole.
	raw, err := io.ReadAll(io.LimitReader(resp.Body, 8<<20))
	if err != nil {
		return 0, fmt.Errorf("reading response: %w", err)
	}

	var env envelope
	if err := json.Unmarshal(raw, &env); err != nil {
		return 0, &APIError{
			StatusCode: resp.StatusCode,
			Message:    fmt.Sprintf("response was not JSON (%d bytes)", len(raw)),
		}
	}

	if resp.StatusCode >= 400 || !env.Success {
		apiErr := &APIError{StatusCode: resp.StatusCode}
		if env.Error != nil {
			apiErr.Code = env.Error.Code
			apiErr.Message = env.Error.Message
		}
		if apiErr.Message == "" {
			apiErr.Message = http.StatusText(resp.StatusCode)
		}

		return env.Meta.PollAfter, apiErr
	}

	if out == nil || len(env.Data) == 0 {
		return env.Meta.PollAfter, nil
	}

	if err := json.Unmarshal(env.Data, out); err != nil {
		return env.Meta.PollAfter, fmt.Errorf("decoding response body: %w", err)
	}

	return env.Meta.PollAfter, nil
}
