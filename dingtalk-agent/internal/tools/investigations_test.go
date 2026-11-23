package tools

import (
	"net/http"
	"net/url"
	"strings"
	"testing"
)

func TestGetInvestigationStatusMapsFiltersAndFramesOutput(t *testing.T) {
	var gotPath string
	var gotQuery url.Values
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		gotQuery = r.URL.Query()
		envelope(w, []map[string]any{
			{
				"id":                11,
				"incident_no":       "20261120_IN_0042",
				"incident_title":    "Payment latency spike",
				"incident_severity": "P1",
				"team":              "Payments",
				"state":             "pending",
				"has_draft":         true,
				"recipient_count":   2,
				"opened_count":      1,
			},
		}, map[string]any{"total": 1, "limit": 10, "offset": 0, "returned": 1, "has_more": false})
	})

	out, err := execute(reg, "get_investigation_status", map[string]any{
		"status":      "expired",
		"incident_no": "20261120_IN_0042",
		"team":        "Payments",
		"limit":       10,
		"offset":      2,
	})
	if err != nil {
		t.Fatalf("unexpected tool error: %v (%s)", err, out)
	}
	if gotPath != "/investigation-forms" {
		t.Errorf("path = %q, want /investigation-forms", gotPath)
	}
	want := map[string]string{
		"status":      "expired",
		"incident_no": "20261120_IN_0042",
		"team":        "Payments",
		"limit":       "10",
		"offset":      "2",
	}
	for k, v := range want {
		if got := gotQuery.Get(k); got != v {
			t.Errorf("query %s = %q, want %q", k, got, v)
		}
	}
	if !strings.Contains(out, "20261120_IN_0042") || !strings.Contains(out, "Payments") {
		t.Errorf("expected payload in output:\n%s", out)
	}
	if !strings.Contains(out, "untrusted") && !strings.Contains(out, "investigation forms") {
		t.Errorf("expected guardrail framing in output:\n%s", out)
	}
}

func TestGetInvestigationStatusRejectsInvalidStatus(t *testing.T) {
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		t.Errorf("server should not be called on invalid status")
	})

	out, _ := execute(reg, "get_investigation_status", map[string]any{"status": "bogus"})
	if !strings.Contains(out, "Error executing get_investigation_status") || !strings.Contains(out, "status must be one of") {
		t.Errorf("expected invalid-status tool error, got: %s", out)
	}
}

func TestGetInvestigationStatusDefaultsLimit(t *testing.T) {
	var gotQuery url.Values
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		gotQuery = r.URL.Query()
		envelope(w, []map[string]any{}, map[string]any{"total": 0})
	})

	_, err := execute(reg, "get_investigation_status", map[string]any{})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if got := gotQuery.Get("limit"); got != "20" {
		t.Errorf("default limit = %q, want 20", got)
	}
	if got := gotQuery.Get("status"); got != "" {
		t.Errorf("omitted status should let server default to open, got %q", got)
	}
}
