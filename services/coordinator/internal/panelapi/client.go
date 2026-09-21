// Package panelapi is the coordinator's client for the control plane.
//
// The coordinator holds no database and no copy of the ACL. Every decision
// about who may talk to whom comes from the panel, so there is one place that
// answer is computed and a revocation cannot be stale in a second copy.
package panelapi

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"
	"time"
)

// Client calls the panel's coordinator endpoints.
type Client struct {
	baseURL string
	secret  []byte
	http    *http.Client
}

// New builds a Client. The secret is the one both halves were installed with.
func New(baseURL, sharedSecret string) (*Client, error) {
	trimmed := strings.TrimRight(baseURL, "/")
	if trimmed == "" {
		return nil, fmt.Errorf("no panel URL configured")
	}
	if sharedSecret == "" {
		return nil, fmt.Errorf("no shared secret configured; the panel will refuse every request")
	}

	return &Client{
		baseURL: trimmed,
		secret:  []byte(sharedSecret),
		http:    &http.Client{Timeout: 15 * time.Second},
	}, nil
}

// VerifyResult is the panel's answer about one device.
type VerifyResult struct {
	Authorized bool   `json:"authorized"`
	Reason     string `json:"reason"`
	DeviceUID  string `json:"device_uid"`
	NetworkID  int    `json:"network_id"`
	TenantID   int    `json:"tenant_id"`
	Region     string `json:"region"`
	VirtualIP  string `json:"virtual_ip"`
	Peers      []struct {
		DeviceUID string `json:"device_uid"`
		PublicKey string `json:"public_key"`
	} `json:"peers"`
}

// VerifyDevice asks whether a device may be on the network, and who it may be
// introduced to.
func (c *Client) VerifyDevice(ctx context.Context, deviceUID, token, publicKey string) (*VerifyResult, error) {
	body := map[string]string{
		"device_uid": deviceUID,
		"token":      token,
		"public_key": publicKey,
	}

	var out VerifyResult
	if err := c.post(ctx, "/api/v1/coordinator/verify", body, &out); err != nil {
		return nil, err
	}

	return &out, nil
}

// EndpointReport is one device's observed addresses.
type EndpointReport struct {
	DeviceUID   string `json:"device_uid"`
	Endpoint    string `json:"endpoint,omitempty"`
	LANEndpoint string `json:"lan_endpoint,omitempty"`
}

// ReportEndpoints tells the panel where devices were seen, so the device list
// and the agent config both show a current address.
func (c *Client) ReportEndpoints(ctx context.Context, reports []EndpointReport) error {
	if len(reports) == 0 {
		return nil
	}

	return c.post(ctx, "/api/v1/coordinator/endpoints", map[string]any{"devices": reports}, nil)
}

// post signs and sends one request.
//
// The signature covers the timestamp and the body together, so neither can be
// changed without invalidating it, and a captured request is useless once the
// panel's clock has moved past its window.
// ReportRelayUsage sends per-tenant relayed byte deltas to the panel.
//
// This is the billing figure, and it comes from our own hardware. The agents'
// heartbeats still carry their own view of the same traffic; the panel keeps
// both and flags a disagreement, because two numbers that should match and do
// not is information, and quietly preferring one of them is not.
func (c *Client) ReportRelayUsage(ctx context.Context, relay string, deltas map[uint64]uint64) error {
	tenants := make([]relayUsageTenant, 0, len(deltas))
	for tenant, bytes := range deltas {
		tenants = append(tenants, relayUsageTenant{TenantID: tenant, Bytes: bytes})
	}

	return c.post(ctx, "/api/v1/coordinator/relay-usage", map[string]any{
		"relay":   relay,
		"tenants": tenants,
	}, nil)
}

type relayUsageTenant struct {
	TenantID uint64 `json:"tenant_id"`
	Bytes    uint64 `json:"bytes"`
}

func (c *Client) post(ctx context.Context, path string, body any, out any) error {
	encoded, err := json.Marshal(body)
	if err != nil {
		return fmt.Errorf("encoding request: %w", err)
	}

	timestamp := strconv.FormatInt(time.Now().Unix(), 10)

	mac := hmac.New(sha256.New, c.secret)
	mac.Write([]byte(timestamp))
	mac.Write([]byte("\n"))
	mac.Write(encoded)
	signature := hex.EncodeToString(mac.Sum(nil))

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+path, bytes.NewReader(encoded))
	if err != nil {
		return err
	}

	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("X-Coordinator-Timestamp", timestamp)
	req.Header.Set("X-Coordinator-Signature", signature)

	resp, err := c.http.Do(req)
	if err != nil {
		return fmt.Errorf("calling %s: %w", path, err)
	}
	defer resp.Body.Close()

	raw, err := io.ReadAll(io.LimitReader(resp.Body, 4<<20))
	if err != nil {
		return err
	}

	var env struct {
		Success bool            `json:"success"`
		Data    json.RawMessage `json:"data"`
		Error   *struct {
			Code    string `json:"code"`
			Message string `json:"message"`
		} `json:"error"`
	}
	if err := json.Unmarshal(raw, &env); err != nil {
		return fmt.Errorf("panel returned %d with a non-JSON body", resp.StatusCode)
	}

	if resp.StatusCode >= 400 || !env.Success {
		message := http.StatusText(resp.StatusCode)
		if env.Error != nil {
			message = env.Error.Message
		}

		return fmt.Errorf("panel returned %d: %s", resp.StatusCode, message)
	}

	if out == nil || len(env.Data) == 0 {
		return nil
	}

	return json.Unmarshal(env.Data, out)
}
