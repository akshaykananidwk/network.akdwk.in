package panel

import (
	"context"
	"errors"
	"net/http"
	"runtime"
)

// EnrollRequest is what the agent sends to POST /api/v1/enroll.
//
// Note what is absent: there is no private key field, and no way to add one.
// The panel is told the public half and facts about the machine, nothing else.
type EnrollRequest struct {
	JoinCode      string `json:"join_code"`
	PublicKey     string `json:"public_key"`
	Hostname      string `json:"hostname,omitempty"`
	OS            string `json:"os,omitempty"`
	OSVersion     string `json:"os_version,omitempty"`
	Arch          string `json:"arch,omitempty"`
	AgentVersion  string `json:"agent_version,omitempty"`
	HWFingerprint string `json:"hw_fingerprint,omitempty"`
}

// EnrollResponse is the panel's answer. R4 means Status is "pending" unless
// the network opted into auto-approval, and no token is included either way.
type EnrollResponse struct {
	DeviceUID  string `json:"device_uid"`
	Status     string `json:"status"`
	NetworkUID string `json:"network_uid"`
	PollAfter  int    `json:"poll_after"`
}

// Enroll registers this device against a join code.
func (c *Client) Enroll(ctx context.Context, req EnrollRequest) (*EnrollResponse, error) {
	if req.Arch == "" {
		req.Arch = runtime.GOARCH
	}
	if req.OS == "" {
		req.OS = runtime.GOOS
	}

	var out EnrollResponse
	if _, err := c.do(ctx, http.MethodPost, "/api/v1/enroll", req, &out); err != nil {
		return nil, err
	}

	return &out, nil
}

// ClaimResponse is the answer to a poll for approval.
type ClaimResponse struct {
	Status      string `json:"status"`
	Authorized  bool   `json:"authorized"`
	DeviceToken string `json:"device_token"`
	VirtualIP   string `json:"virtual_ip"`
	// PollAfter comes from the envelope's meta, not the data body.
	PollAfter int `json:"-"`
}

// ErrNotEnrolled means the panel has no record of this public key. Re-enrolling
// is the only way forward, so it is distinguished from a transient failure.
var ErrNotEnrolled = errors.New("the panel has no enrolment for this device key")

// Claim asks whether an admin has approved this device yet, and collects the
// device token if so.
//
// The public key is the credential: only the machine holding the matching
// private key could have produced the enrolment this is claiming.
func (c *Client) Claim(ctx context.Context, deviceUID, publicKey string) (*ClaimResponse, error) {
	body := map[string]string{
		"device_uid": deviceUID,
		"public_key": publicKey,
	}

	var out ClaimResponse
	pollAfter, err := c.do(ctx, http.MethodPost, "/api/v1/agent/claim", body, &out)
	if err != nil {
		var apiErr *APIError
		if errors.As(err, &apiErr) && apiErr.Code == "not_enrolled" {
			return nil, ErrNotEnrolled
		}

		return nil, err
	}

	out.PollAfter = pollAfter

	return &out, nil
}
