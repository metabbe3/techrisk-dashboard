package tools

import (
	"net/http"
	"net/url"
	"strings"
	"testing"
)

// Track C: thin reads over existing Laravel surfaces — cross-incident action
// items, governance KPIs, reference vocabularies. Zero new metric math: the
// Go side builds query params and passes rows through (one-logic rule).
func TestSearchActionItemsMapsFilters(t *testing.T) {
	var gotQuery url.Values
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		gotQuery = r.URL.Query()
		envelope(w, []map[string]any{
			{"id": 7, "incident_no": "20261116_IN_7846", "title": "Rotate credentials", "status": "on_progress", "due_date": "2026-11-10", "detail": "Rotate the pool"},
		}, map[string]any{"total": 1, "limit": 20, "offset": 0, "returned": 1, "has_more": false})
	})

	out, _ := execute(reg, "search_action_items", map[string]any{
		"overdue": true, "pic": "kevin@dana.id", "limit": 5, "offset": 2,
	})

	want := map[string]string{"overdue": "1", "pic": "kevin@dana.id", "limit": "5", "offset": "2"}
	for k, v := range want {
		if got := gotQuery.Get(k); got != v {
			t.Errorf("query %s = %q, want %q (full: %v)", k, got, v, gotQuery)
		}
	}
	if !strings.Contains(out, "20261116_IN_7846") || !strings.Contains(out, "Rotate credentials") {
		t.Errorf("rows not passed through:\n%s", out)
	}
	if !strings.Contains(out, `"total":1`) {
		t.Errorf("meta.total must survive for count questions:\n%s", out)
	}
}

func TestSearchActionItemsDefaultLimitClamped(t *testing.T) {
	var gotQuery url.Values
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		gotQuery = r.URL.Query()
		envelope(w, []map[string]any{}, map[string]any{"total": 0})
	})

	_, _ = execute(reg, "search_action_items", map[string]any{})
	if got := gotQuery.Get("limit"); got != "20" {
		t.Errorf("default limit = %q, want 20", got)
	}

	_, _ = execute(reg, "search_action_items", map[string]any{"limit": 500})
	if got := gotQuery.Get("limit"); got != "100" {
		t.Errorf("over-ceiling limit = %q, want 100", got)
	}
}

func TestGetActionItemFetchesById(t *testing.T) {
	var gotPath string
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		envelope(w, map[string]any{"id": 42, "title": "Rotate credentials", "status": "pending", "due_date": "2026-11-20"}, nil)
	})

	out, _ := execute(reg, "get_action_item", map[string]any{"id": 42})
	if gotPath != "/action-improvements/42" {
		t.Errorf("fetched from %q", gotPath)
	}
	if !strings.Contains(out, "Rotate credentials") {
		t.Errorf("record not returned:\n%s", out)
	}
}

func TestGetGovernanceKpisPassthrough(t *testing.T) {
	var gotPath, gotQuery string
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		gotQuery = r.URL.RawQuery
		envelope(w, map[string]any{"overdue_action_items": 3, "rca_within_5d": map[string]any{"pct": 80.0, "coverage": 4, "total": 5}, "period_label": "2026"}, nil)
	})

	out, _ := execute(reg, "get_governance_kpis", map[string]any{})
	if gotPath != "/governance" {
		t.Errorf("fetched from %q", gotPath)
	}
	for _, want := range []string{"overdue_action_items", "rca_within_5d"} {
		if !strings.Contains(out, want) {
			t.Errorf("summary key %q missing:\n%s", want, out)
		}
	}

	_, _ = execute(reg, "get_governance_kpis", map[string]any{"series": true, "year": 2025})
	if gotQuery != "series=1&year=2025" {
		t.Errorf("series/year query = %q", gotQuery)
	}
}

func TestListReferenceKinds(t *testing.T) {
	cases := map[string]string{
		"labels":         "/labels",
		"incident-types": "/incident-types",
		"categories":     "/categories",
	}
	for kind, wantPath := range cases {
		var gotPath string
		reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
			gotPath = r.URL.Path
			envelope(w, []string{"Payment failure", "Timeout"}, nil)
		})
		out, _ := execute(reg, "list_reference", map[string]any{"kind": kind})
		if gotPath != wantPath {
			t.Errorf("kind %s fetched from %q, want %s", kind, gotPath, wantPath)
		}
		if !strings.Contains(out, "Payment failure") {
			t.Errorf("kind %s: values not returned:\n%s", kind, out)
		}
	}
}

func TestListReferenceUnknownKindFailsLocally(t *testing.T) {
	hits := 0
	reg, _ := newTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		hits++
		envelope(w, []string{}, nil)
	})
	out, _ := execute(reg, "list_reference", map[string]any{"kind": "users"})
	if !strings.Contains(out, "Error executing") {
		t.Errorf("unknown kind must list the valid vocabulary, got: %q", out)
	}
	if hits != 0 {
		t.Errorf("unknown kind must fail before any HTTP round trip, got %d requests", hits)
	}
}
