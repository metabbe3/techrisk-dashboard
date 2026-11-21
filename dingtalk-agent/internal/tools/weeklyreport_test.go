package tools

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"techrisk-dashboard/dingtalk-agent/internal/config"
)

// TestGetWeeklyReportDefaultsToLast8Weeks pins the chat-payload default: no
// args → no year param (server defaults to the current year), weeks=8, and
// the week rows pass through untouched.
func TestGetWeeklyReportDefaultsToLast8Weeks(t *testing.T) {
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/weekly-report" {
			t.Errorf("unexpected path: %s", r.URL.Path)
		}
		if got := r.URL.Query().Get("weeks"); got != "8" {
			t.Errorf("weeks default: got %q, want 8", got)
		}
		if got := r.URL.Query().Get("year"); got != "" {
			t.Errorf("year must default server-side, got %q", got)
		}
		envelope(w, map[string]any{
			"summary": map[string]any{"open": 1, "closed": 2, "total": 3, "year": 2026},
			"weeks": []any{
				map[string]any{"week": "W45", "date_range": "Nov 6 - Nov 12", "incident_open": 1, "incident_closed": 2, "total": 3},
			},
		}, nil)
	})

	out, err := reg.Execute(context.Background(), "get_weekly_report", `{}`)
	if err != nil {
		t.Fatalf("get_weekly_report failed: %v", err)
	}
	if !strings.Contains(out, `"week":"W45"`) || !strings.Contains(out, `"total":3`) {
		t.Errorf("week rows not passed through: %.200s", out)
	}
}

// TestGetWeeklyReportQueryPlumbing pins year/weeks encoding: explicit weeks
// wins over the client default, and weeks=0 (full year) omits the param.
func TestGetWeeklyReportQueryPlumbing(t *testing.T) {
	cases := []struct {
		name    string
		args    string
		want    map[string]string
		wantNil []string
	}{
		{
			name: "explicit year and weeks",
			args: `{"year":2025,"weeks":12}`,
			want: map[string]string{"year": "2025", "weeks": "12"},
		},
		{
			name:    "weeks=0 means full year",
			args:    `{"year":2025,"weeks":0}`,
			want:    map[string]string{"year": "2025"},
			wantNil: []string{"weeks"},
		},
		{
			name: "weeks only",
			args: `{"weeks":2}`,
			want: map[string]string{"weeks": "2"},
		},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
				q := r.URL.Query()
				for k, want := range tc.want {
					if got := q.Get(k); got != want {
						t.Errorf("query %s: got %q, want %q", k, got, want)
					}
				}
				for _, k := range tc.wantNil {
					if q.Has(k) {
						t.Errorf("query %s must be absent", k)
					}
				}
				envelope(w, map[string]any{"weeks": []any{}}, nil)
			})
			out, err := reg.Execute(context.Background(), "get_weekly_report", tc.args)
			if err != nil {
				t.Fatalf("execute failed: %v", err)
			}
			if strings.Contains(out, "Error") {
				t.Errorf("unexpected error content: %.120s", out)
			}
		})
	}
}

// TestGetWeeklyReportRealBasePath reproduces prod: DashboardAPIBase already
// ends in "/api/v1", so the tool's path literal must be "/weekly-report".
func TestGetWeeklyReportRealBasePath(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/weekly-report" {
			t.Errorf("unexpected path: %s (want /api/v1/weekly-report)", r.URL.Path)
		}
		envelope(w, map[string]any{"summary": map[string]any{"total": 0}, "weeks": []any{}}, nil)
	}))
	t.Cleanup(server.Close)

	cfg := &config.Config{
		DashboardAPIBase:  server.URL + "/api/v1",
		DashboardAPIToken: "test-token",
	}
	reg := NewRegistry(cfg)

	if _, err := reg.Execute(context.Background(), "get_weekly_report", `{}`); err != nil {
		t.Fatalf("get_weekly_report failed: %v", err)
	}
}

// TestGetWeeklyReportForbiddenSurfacesTokenAdvice pins the 403 contract: a
// token without the weekly-report ability must tell the model WHERE to fix it.
// Handler errors surface as error CONTENT (registry executeGuarded), not Go errors.
func TestGetWeeklyReportForbiddenSurfacesTokenAdvice(t *testing.T) {
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusForbidden)
		_, _ = w.Write([]byte(`{"code":403,"status":"Error","message":"This token does not have permission to access this endpoint.","data":null}`))
	})

	out, err := reg.Execute(context.Background(), "get_weekly_report", `{}`)
	if err != nil {
		t.Fatalf("Execute must not fail, errors are content: %v", err)
	}
	if !strings.Contains(out, "403") || !strings.Contains(out, "endpoint ability") {
		t.Errorf("403 error should point at the token ability, got: %.200s", out)
	}
}

// TestGetWeeklyReportRejectsInvalidArgs pins the W9 client-side validation:
// out-of-range year/weeks return an error tool result instead of silently
// widening (weeks=-5 used to become a full-year payload).
func TestGetWeeklyReportRejectsInvalidArgs(t *testing.T) {
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		t.Errorf("server must not be called for invalid args: %s", r.URL.String())
		envelope(w, map[string]any{"weeks": []any{}}, nil)
	})

	cases := []string{
		`{"year":1999}`,
		`{"year":2101}`,
		`{"weeks":-5}`,
		`{"weeks":54}`,
	}
	for _, args := range cases {
		out, err := reg.Execute(context.Background(), "get_weekly_report", args)
		if err != nil {
			t.Fatalf("Execute must not fail, errors are content: %v", err)
		}
		if !strings.Contains(out, "Error") {
			t.Errorf("invalid args %s must error, got: %.120s", args, out)
		}
	}

	// Valid bounds still pass: year 2000/2100 and weeks 0/53.
	ok, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		envelope(w, map[string]any{"weeks": []any{}}, nil)
	})
	for _, args := range []string{`{"year":2000}`, `{"year":2100}`, `{"weeks":53}`, `{"weeks":0}`} {
		if _, err := ok.Execute(context.Background(), "get_weekly_report", args); err != nil {
			t.Errorf("valid args %s must not fail: %v", args, err)
		}
	}
}

// TestGetWeeklyReportPayloadIsJSON guards the passthrough contract: meta +
// data ride verbatim INSIDE the guardrail document frame (FrameDocumentContent
// prefixes an untrusted-data header and dots every line — W9).
func TestGetWeeklyReportPayloadIsJSON(t *testing.T) {
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		envelope(w, map[string]any{
			"summary": map[string]any{"open": 0, "closed": 1, "total": 1, "year": 2025},
			"weeks": []any{
				map[string]any{"week": "W3", "incident_open": 0, "incident_closed": 1, "total": 1,
					"incidents": []any{map[string]any{"incident_no": "2025_IN_P2_001", "incident_title": "x"}}},
			},
		}, map[string]any{"total": 1})
	})

	out, err := reg.Execute(context.Background(), "get_weekly_report", `{"year":2025}`)
	if err != nil {
		t.Fatalf("execute failed: %v", err)
	}
	if !strings.Contains(out, "[document content from weekly report") ||
		!strings.Contains(out, "[end of document content]") {
		t.Errorf("output missing the guardrail document frame: %.200s", out)
	}
	// The JSON payload survives inside the frame, with meta intact.
	var decoded map[string]any
	start := strings.Index(out, "{\"data\":")
	if start < 0 {
		t.Fatalf("no JSON payload inside the frame: %.200s", out)
	}
	end := strings.LastIndex(out, "}")
	if err := json.Unmarshal([]byte(out[start:end+1]), &decoded); err != nil {
		t.Fatalf("embedded payload is not JSON: %v\n%.200s", err, out)
	}
	if decoded["meta"] == nil {
		t.Errorf("meta lost in passthrough: %.120s", out)
	}
}
