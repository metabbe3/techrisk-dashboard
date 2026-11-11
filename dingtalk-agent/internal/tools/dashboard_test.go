package tools

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"techrisk-dashboard/dingtalk-agent/internal/config"
)

func newTestRegistry(t *testing.T, handler http.HandlerFunc) (*Registry, *httptest.Server) {
	t.Helper()
	server := httptest.NewServer(handler)
	t.Cleanup(server.Close)

	cfg := &config.Config{
		DashboardAPIBase:  server.URL,
		DashboardAPIToken: "test-token",
	}
	return NewRegistry(cfg), server
}

func envelope(w http.ResponseWriter, data any, meta any) {
	w.Header().Set("Content-Type", "application/json")
	payload := map[string]any{
		"code":    200,
		"status":  "Success",
		"message": "ok",
		"data":    data,
	}
	if meta != nil {
		payload["meta"] = meta
	}
	_ = json.NewEncoder(w).Encode(payload)
}

func TestSearchIncidentsMapsParamsToQuery(t *testing.T) {
	var gotQuery string
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		gotQuery = r.URL.RawQuery
		var auth = r.Header.Get("Authorization")
		if auth != "Bearer test-token" {
			t.Errorf("missing bearer token, got %q", auth)
		}
		if r.Method != http.MethodGet {
			t.Errorf("expected GET, got %s", r.Method)
		}
		envelope(w, []any{}, map[string]any{"total": 7})
	})

	out, err := reg.Execute("search_incidents", `{
		"severity": "P1",
		"status": "Completed",
		"classification": "Incident",
		"fund_status": "Confirmed loss",
		"type": "Tech",
		"tags": "payment,database",
		"search": "outage",
		"start_date": "2026-10-01",
		"end_date": "2026-10-31",
		"limit": 1
	}`)
	if err != nil {
		t.Fatalf("search failed: %v", err)
	}

	for _, want := range []string{
		"severity=P1", "incident_status=Completed", "classification=Incident",
		"fund_status=Confirmed+loss",
		"type=Tech", "tags=payment%2Cdatabase", "search=outage",
		"start_date=2026-10-01", "end_date=2026-10-31", "limit=1",
	} {
		if !strings.Contains(gotQuery, want) {
			t.Errorf("query missing %s (got: %s)", want, gotQuery)
		}
	}
	if !strings.Contains(out, `"total":7`) {
		t.Errorf("meta.total not surfaced in output: %s", out)
	}
}

func TestSearchIncidentsPairsStartDateWithToday(t *testing.T) {
	var gotQuery string
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		gotQuery = r.URL.RawQuery
		envelope(w, []any{}, map[string]any{"total": 0})
	})

	// A lone start_date must be paired — the API ignores a single bound (all-time counts otherwise).
	if _, err := reg.Execute("search_incidents", `{"start_date":"2026-01-01","limit":1}`); err != nil {
		t.Fatalf("search failed: %v", err)
	}
	if !strings.Contains(gotQuery, "start_date=2026-01-01") || !strings.Contains(gotQuery, "end_date=") {
		t.Errorf("start_date was not paired with an end_date: %s", gotQuery)
	}
}

func TestSearchIncidentsClampsLimitAndIgnoresShortSearch(t *testing.T) {
	var gotQuery string
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		gotQuery = r.URL.RawQuery
		envelope(w, []any{}, map[string]any{"total": 0})
	})

	if _, err := reg.Execute("search_incidents", `{"limit": 5000, "search": "a"}`); err != nil {
		t.Fatalf("search failed: %v", err)
	}
	if !strings.Contains(gotQuery, "limit=100") {
		t.Errorf("limit not clamped to 100: %s", gotQuery)
	}
	if strings.Contains(gotQuery, "search=") {
		t.Errorf("1-char search should be dropped (server 422s on min:2): %s", gotQuery)
	}
}

func TestSearchIncidentsRejectsBadDate(t *testing.T) {
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		t.Fatal("server should not be called for an invalid date")
	})

	// Registry.Execute renders tool errors as result strings so the LLM can self-correct.
	for _, args := range []string{
		`{"start_date":"01-10-2026","limit":1}`,
		`{"start_date":"2026-13-45","limit":1}`,
		`{"start_date":"2026-10-05","end_date":"2026-10-01","limit":1}`,
	} {
		out, err := reg.Execute("search_incidents", args)
		if err != nil {
			t.Fatalf("Execute should not error (error becomes tool content), got %v", err)
		}
		if !strings.Contains(out, "Error executing search_incidents") {
			t.Errorf("args %s: expected error content, got %q", args, out)
		}
	}
}

func TestGetIncidentByNoAndID(t *testing.T) {
	var gotPath string
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		envelope(w, map[string]any{"no": "20250115_IN_1234", "title": "Payment Gateway Timeout"}, nil)
	})

	if _, err := reg.Execute("get_incident", `{"no":"20250115_IN_1234"}`); err != nil {
		t.Fatalf("get by no failed: %v", err)
	}
	if gotPath != "/incidents-by-no/20250115_IN_1234" {
		t.Errorf("unexpected path: %s", gotPath)
	}

	if _, err := reg.Execute("get_incident", `{"id": 42}`); err != nil {
		t.Fatalf("get by id failed: %v", err)
	}
	if gotPath != "/incidents/42" {
		t.Errorf("unexpected path: %s", gotPath)
	}
}

func TestGetIncidentRejectsUnsafeRef(t *testing.T) {
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		t.Fatal("server should not be called for an unsafe reference")
	})

	for _, bad := range []string{`{"no":"../../etc/passwd"}`, `{"no":"2026 IN 01"}`, `{"no":""}`, `{"no":"abc%20def"}`} {
		out, err := reg.Execute("get_incident", bad)
		if err != nil {
			t.Fatalf("Execute should not error (error becomes tool content), got %v", err)
		}
		if !strings.Contains(out, "Error executing get_incident") {
			t.Errorf("args %s: expected rejection content, got %q", bad, out)
		}
	}
}

func TestGetIncidentMarkdownDecodesBase64(t *testing.T) {
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/incidents-by-no/20250115_IN_1234/markdown" {
			t.Errorf("unexpected path: %s", r.URL.Path)
		}
		envelope(w, base64.StdEncoding.EncodeToString([]byte("# Payment Gateway Timeout\n\n**Root cause:** pool exhausted")), nil)
	})

	out, err := reg.Execute("get_incident_markdown", `{"no":"20250115_IN_1234"}`)
	if err != nil {
		t.Fatalf("markdown failed: %v", err)
	}
	if !strings.Contains(out, "# Payment Gateway Timeout") || strings.Contains(out, "base64") {
		t.Errorf("markdown not decoded: %.80s", out)
	}
}

func TestDashboardErrorSurfacing(t *testing.T) {
	cases := []struct {
		status  int
		wantErr string
	}{
		{http.StatusUnauthorized, "401"},
		// 403 must carry the SERVER's message through — the generic wrap alone
		// sent the agent's LLM chasing Notion integration permissions when the
		// real cause was a token endpoint-ability gap (prod 2026-11-11).
		{http.StatusForbidden, "403): boom"},
		{http.StatusTooManyRequests, "429"},
		{http.StatusNotFound, "404"},
		{http.StatusUnprocessableEntity, "422"},
		{http.StatusInternalServerError, "500"},
	}
	for _, tc := range cases {
		reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(tc.status)
			fmt.Fprintf(w, `{"code":%d,"status":"Error","message":"boom"}`, tc.status)
		})
		out, err := reg.Execute("search_incidents", `{"limit":1}`)
		if err != nil {
			t.Fatalf("status %d: unexpected Execute error: %v", tc.status, err)
		}
		if !strings.Contains(out, tc.wantErr) {
			t.Errorf("status %d: want error content containing %s, got %q", tc.status, tc.wantErr, out)
		}
	}
}
