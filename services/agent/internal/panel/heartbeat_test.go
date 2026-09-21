package panel

import (
	"encoding/json"
	"testing"
)

// Defect 14: the agent sent rx_bytes and tx_bytes; the panel reads rx_delta
// and tx_delta. Every heartbeat was silently discarded for accounting, so the
// usage figures a customer would be billed from were always zero. Nothing
// errored, nothing logged, and the tunnel worked perfectly.
//
// The field names are a contract with app/Controllers/Api/AgentController.php.
// This test is the only thing that notices when one side moves.
func TestHeartbeatUsesTheFieldNamesThePanelReads(t *testing.T) {
	encoded, err := json.Marshal(Heartbeat{
		Endpoint:       "203.0.113.4:51820",
		ConnectionType: "relay",
		RXDelta:        1234,
		TXDelta:        5678,
		Revision:       9,
	})
	if err != nil {
		t.Fatal(err)
	}

	var got map[string]any
	if err := json.Unmarshal(encoded, &got); err != nil {
		t.Fatal(err)
	}

	// What the panel reads.
	for _, field := range []string{"rx_delta", "tx_delta", "connection_type", "endpoint"} {
		if _, ok := got[field]; !ok {
			t.Errorf("heartbeat has no %q field; the panel reads that name", field)
		}
	}

	// What it must not send, because those names mean nothing to the panel and
	// their presence is what hid the bug.
	for _, field := range []string{"rx_bytes", "tx_bytes"} {
		if _, ok := got[field]; ok {
			t.Errorf("heartbeat still sends %q, which the panel ignores", field)
		}
	}

	if got["rx_delta"].(float64) != 1234 || got["tx_delta"].(float64) != 5678 {
		t.Errorf("values did not survive encoding: %v", got)
	}
}

// The panel accepts only these three, and maps anything else to "offline".
// Sending a fourth value would silently show a working device as offline.
func TestConnectionTypeValuesThePanelAccepts(t *testing.T) {
	for _, valid := range []string{"direct", "relay", "offline"} {
		encoded, err := json.Marshal(Heartbeat{ConnectionType: valid})
		if err != nil {
			t.Fatal(err)
		}

		var got map[string]any
		if err := json.Unmarshal(encoded, &got); err != nil {
			t.Fatal(err)
		}

		if got["connection_type"] != valid {
			t.Errorf("connection_type = %v, want %q", got["connection_type"], valid)
		}
	}
}
