package panel

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
)

// UpdateOffer is what the panel says about a newer agent.
type UpdateOffer struct {
	Available bool   `json:"update_available"`
	Version   string `json:"version"`
	URL       string `json:"url"`
	SHA256    string `json:"sha256"`
	Signature string `json:"signature"`
	Size      int64  `json:"size"`
	Notes     string `json:"notes"`
}

// CheckUpdate asks the panel whether this device should be running something
// newer.
//
// The answer is per-device: the panel decides the rollout cohort from the
// device uid, so a bad release reaches ten machines before it reaches a
// thousand.
func (c *Client) CheckUpdate(ctx context.Context, platform, arch string) (*UpdateOffer, error) {
	query := url.Values{}
	query.Set("platform", platform)
	query.Set("arch", arch)

	var out UpdateOffer
	if _, err := c.do(ctx, http.MethodGet, "/api/v1/agent/version?"+query.Encode(), nil, &out); err != nil {
		return nil, err
	}

	return &out, nil
}

// DownloadTo streams a release into w, returning how many bytes it wrote.
//
// The URL comes from the panel, so it is checked before it is used: it must be
// on the panel this device is enrolled with. An agent that followed an
// arbitrary URL out of a JSON field would be one HTTP response away from
// fetching its next binary from anywhere.
func (c *Client) DownloadTo(ctx context.Context, rawURL string, w io.Writer) (int64, error) {
	parsed, err := url.Parse(rawURL)
	if err != nil {
		return 0, fmt.Errorf("the panel gave an unusable download address: %w", err)
	}

	base, err := url.Parse(c.baseURL)
	if err != nil {
		return 0, fmt.Errorf("this agent's panel URL is unusable: %w", err)
	}

	if !strings.EqualFold(parsed.Host, base.Host) || parsed.Scheme != base.Scheme {
		return 0, fmt.Errorf(
			"refusing to download an agent from %s: this device is enrolled with %s",
			parsed.Host, base.Host)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, parsed.String(), nil)
	if err != nil {
		return 0, err
	}
	req.Header.Set("User-Agent", c.userAgent)
	if c.token != "" {
		req.Header.Set("Authorization", "Bearer "+c.token)
	}

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return 0, fmt.Errorf("downloading the agent: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return 0, fmt.Errorf("the panel answered %d for the agent download", resp.StatusCode)
	}

	// Bounded. A release is a few megabytes; anything claiming to be hundreds
	// is a reason to stop rather than to fill the disk.
	const maxRelease = 128 << 20

	written, err := io.Copy(w, io.LimitReader(resp.Body, maxRelease))
	if err != nil {
		return written, fmt.Errorf("writing the agent: %w", err)
	}

	return written, nil
}
