package tools

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"techrisk-dashboard/dingtalk-agent/internal/config"
)

func newNotionTestRegistry(t *testing.T, handler http.HandlerFunc) (*Registry, string) {
	t.Helper()
	server := httptest.NewServer(handler)
	t.Cleanup(server.Close)

	dir := t.TempDir()
	cfg := &config.Config{
		DashboardAPIBase:   server.URL,
		DashboardAPIToken:  "test-token",
		DataDir:            dir,
		NotionToolsEnabled: true,
		NotionDatabases:    map[string]string{"PRD": "dbid1"},
	}
	return NewRegistry(cfg), dir
}

func TestNotionSearchPosts(t *testing.T) {
	reg, _ := newNotionTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			t.Errorf("expected POST, got %s", r.Method)
		}
		if r.URL.Path != "/notion/search" {
			t.Errorf("unexpected path: %s", r.URL.Path)
		}
		if auth := r.Header.Get("Authorization"); auth != "Bearer test-token" {
			t.Errorf("missing bearer token, got %q", auth)
		}
		var body map[string]string
		_ = json.NewDecoder(r.Body).Decode(&body)
		if body["query"] != "payments PRD" {
			t.Errorf("unexpected body: %v", body)
		}
		envelope(w, map[string]any{"text": "1. Payments PRD — https://notion.so/pg1", "truncated": false}, nil)
	})

	out, err := reg.Execute("notion_search", `{"query":"payments PRD"}`)
	if err != nil {
		t.Fatalf("search failed: %v", err)
	}
	if !strings.Contains(out, "Payments PRD") {
		t.Errorf("search text not surfaced: %s", out)
	}
}

func TestNotionQueryResolvesCatalogName(t *testing.T) {
	var body map[string]string
	reg, _ := newNotionTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/notion/query" {
			t.Errorf("unexpected path: %s", r.URL.Path)
		}
		_ = json.NewDecoder(r.Body).Decode(&body)
		envelope(w, []any{map[string]any{"Name": "Payments PRD", "url": "https://notion.so/pg1"}}, nil)
	})

	out, err := reg.Execute("notion_query_database", `{"database":"PRD","query":"SELECT * FROM \"collection://x\" LIMIT 1"}`)
	if err != nil {
		t.Fatalf("query failed: %v", err)
	}
	if body["database"] != "dbid1" { // catalog NAME resolved to the id server-side
		t.Errorf("server received database=%q, want dbid1", body["database"])
	}
	if !strings.Contains(out, "Payments PRD") {
		t.Errorf("rows not surfaced: %s", out)
	}

	// The query tool's description must list the catalog.
	for _, def := range reg.GetDefinitions() {
		if def.Function.Name == "notion_query_database" {
			if !strings.Contains(def.Function.Description, "PRD=dbid1") {
				t.Errorf("description missing catalog listing: %s", def.Function.Description)
			}
		}
	}
}

func TestNotionQueryUnknownNameListsCatalogWithoutServerCall(t *testing.T) {
	reg, _ := newNotionTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		t.Error("server must not be called for an unknown database name")
	})

	out, err := reg.Execute("notion_query_database", `{"database":"MISSING","query":"SELECT 1"}`)
	if err != nil {
		t.Fatalf("Execute should not error (error becomes tool content), got %v", err)
	}
	if !strings.Contains(out, "Error executing notion_query_database") || !strings.Contains(out, "PRD=dbid1") {
		t.Errorf("unknown name must error WITH the catalog listing: %q", out)
	}

	// A raw id/URL/collection:// passes through untouched (no catalog error).
	var got string
	reg2, _ := newNotionTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		var body map[string]string
		_ = json.NewDecoder(r.Body).Decode(&body)
		got = body["database"]
		envelope(w, []any{}, nil)
	})
	for _, ref := range []string{
		"1234567890abcdef1234567890abcdef",
		"https://notion.so/db/12345678-1234-1234-1234-123456789012",
		"collection://12345678-1234-1234-1234-123456789012",
		"12345678-1234-1234-1234-123456789012",
	} {
		if _, err := reg2.Execute("notion_query_database", `{"database":"`+ref+`","query":"SELECT 1"}`); err != nil {
			t.Fatalf("query with %s failed: %v", ref, err)
		}
		if got != ref {
			t.Errorf("ref %s passed through as %q", ref, got)
		}
	}
}

// A raw database id the user shares is saved on its first SUCCESSFUL query
// (prod 2026-11-11: the agent claimed "sudah saya catat" but only chat history
// held it). Failed queries save nothing; an already-cataloged id is not duplicated.
func TestNotionQueryAutoSavesRawIDOnSuccess(t *testing.T) {
	fail := true
	reg, dir := newNotionTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		if fail {
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(http.StatusForbidden)
			_, _ = w.Write([]byte(`{"code":403,"status":"Error","message":"nope"}`))
			return
		}
		envelope(w, []any{}, nil)
	})
	const raw = "2c72556e-44dd-81cf-8977-000bc43d6f86"
	query := `{"database":"` + raw + `","query":"SELECT 1"}`

	_, _ = reg.Execute("notion_query_database", query)
	if data, _ := os.ReadFile(filepath.Join(dir, "notion_catalog.json")); strings.Contains(string(data), raw) {
		t.Fatalf("failed query must not save to the catalog: %s", data)
	}

	fail = false
	for i := 0; i < 2; i++ { // twice: second run must not duplicate
		if _, err := reg.Execute("notion_query_database", query); err != nil {
			t.Fatalf("query failed: %v", err)
		}
	}
	data, err := os.ReadFile(filepath.Join(dir, "notion_catalog.json"))
	if err != nil {
		t.Fatalf("catalog not persisted: %v", err)
	}
	if !strings.Contains(string(data), `"db-2c72556e":{"id":"`+raw+`"`) {
		t.Errorf("raw id not auto-saved as db-2c72556e: %s", data)
	}
	if strings.Count(string(data), raw) != 1 {
		t.Errorf("id saved more than once: %s", data)
	}

	// Naming it replaces the db-… entry rather than duplicating it.
	if _, err := reg.Execute("notion_add_database", `{"name":"Investigations","id":"`+raw+`"}`); err != nil {
		t.Fatal(err)
	}
	data, _ = os.ReadFile(filepath.Join(dir, "notion_catalog.json"))
	if strings.Contains(string(data), "db-2c72556e") || !strings.Contains(string(data), `"Investigations"`) {
		t.Errorf("rename must replace the auto entry: %s", data)
	}

	// A raw id already under a catalog name is not re-saved under db-<hex>.
	reg2, dir2 := newNotionTestRegistry(t, func(w http.ResponseWriter, r *http.Request) { envelope(w, []any{}, nil) })
	if _, err := reg2.Execute("notion_query_database", `{"database":"dbid1","query":"SELECT 1"}`); err != nil {
		t.Fatal(err)
	}
	data2, _ := os.ReadFile(filepath.Join(dir2, "notion_catalog.json"))
	if strings.Contains(string(data2), `"db-`) {
		t.Errorf("cataloged id must not be duplicated: %s", data2)
	}
}

func TestNotionAddDatabasePersistsAndReRegisters(t *testing.T) {
	reg, dir := newNotionTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		envelope(w, []any{}, nil)
	})

	out, err := reg.Execute("notion_add_database", `{"name":"TA","id":"dbid2"}`)
	if err != nil {
		t.Fatalf("add failed: %v", err)
	}
	if !strings.Contains(out, "TA=dbid2") {
		t.Errorf("add must return the catalog listing: %s", out)
	}

	// Catalog file persisted.
	data, err := os.ReadFile(filepath.Join(dir, "notion_catalog.json"))
	if err != nil {
		t.Fatalf("notion_catalog.json missing: %v", err)
	}
	var f notionCatalogFile
	if err := json.Unmarshal(data, &f); err != nil {
		t.Fatalf("catalog not valid JSON: %v", err)
	}
	if f.Databases["PRD"].ID != "dbid1" || f.Databases["TA"].ID != "dbid2" {
		t.Errorf("catalog wrong after add: %v", f.Databases)
	}

	// Re-registered query def description contains the new name.
	found := false
	for _, def := range reg.GetDefinitions() {
		if def.Function.Name == "notion_query_database" && strings.Contains(def.Function.Description, "TA=dbid2") {
			found = true
		}
	}
	if !found {
		t.Error("notion_query_database def not re-registered with the new catalog")
	}

	// The new name resolves through a FRESH registry on the same DataDir (persistence).
	var resolved string
	freshSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var body map[string]string
		_ = json.NewDecoder(r.Body).Decode(&body)
		resolved = body["database"]
		envelope(w, []any{}, nil)
	}))
	defer freshSrv.Close()
	reg2 := NewRegistry(&config.Config{
		NotionToolsEnabled: true,
		DataDir:            dir,
		DashboardAPIBase:   freshSrv.URL,
		DashboardAPIToken:  "test-token",
	})
	if _, err := reg2.Execute("notion_query_database", `{"database":"TA","query":"SELECT 1"}`); err != nil {
		t.Fatalf("query via fresh registry failed: %v", err)
	}
	if resolved != "dbid2" {
		t.Errorf("fresh registry resolved TA to %q, want dbid2 (catalog not persisted)", resolved)
	}

	// Malformed names are rejected.
	if out, _ := reg.Execute("notion_add_database", `{"name":"bad name!","id":"x"}`); !strings.Contains(out, "Error") {
		t.Errorf("malformed name must error: %s", out)
	}
}

func TestNotionReadPageRecordsAndDrains(t *testing.T) {
	content := "# Payments PRD v2\n\nRefresh tokens must rotate every 24h."
	reg, _ := newNotionTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/notion/fetch" {
			t.Errorf("unexpected path: %s", r.URL.Path)
		}
		var body map[string]string
		_ = json.NewDecoder(r.Body).Decode(&body)
		if body["id"] != "pg1" {
			t.Errorf("unexpected id: %v", body)
		}
		envelope(w, map[string]any{"content": content, "truncated": false}, nil)
	})

	out, err := reg.Execute("notion_read_page", `{"id_or_url":"pg1"}`)
	if err != nil {
		t.Fatalf("read_page failed: %v", err)
	}
	if !strings.Contains(out, "Refresh tokens") {
		t.Errorf("content not surfaced: %s", out)
	}

	reads := reg.TakeNotionReads()
	if len(reads) != 1 {
		t.Fatalf("TakeNotionReads = %d reads, want 1", len(reads))
	}
	if reads[0].Title != "Payments PRD v2" || reads[0].URL != "pg1" || !strings.Contains(reads[0].Excerpt, "Refresh tokens") {
		t.Errorf("read recorded wrong: %+v", reads[0])
	}
	if second := reg.TakeNotionReads(); second != nil {
		t.Errorf("second drain must be empty, got %d", len(second))
	}

	// Empty page: success note, no read recorded.
	reg2, _ := newNotionTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		envelope(w, map[string]any{"content": nil}, nil)
	})
	if out, _ := reg2.Execute("notion_read_page", `{"id_or_url":"pg2"}`); !strings.Contains(out, "empty page") {
		t.Errorf("empty page must note it: %s", out)
	}
	if reads := reg2.TakeNotionReads(); len(reads) != 0 {
		t.Errorf("empty page must not record a read, got %d", len(reads))
	}
}

func TestNotionKillSwitch(t *testing.T) {
	cfg := &config.Config{NotionToolsEnabled: false, DataDir: t.TempDir()}
	reg := NewRegistry(cfg)
	if reg.Has("notion_search") || reg.Has("notion_query_database") || reg.Has("notion_read_page") || reg.Has("notion_add_database") {
		t.Error("Notion tools must be unregistered when AGENT_NOTION_TOOLS is off")
	}
	if reads := reg.TakeNotionReads(); reads != nil {
		t.Errorf("TakeNotionReads must be nil when disabled, got %d", len(reads))
	}
}

func TestNotionResultCaps(t *testing.T) {
	reg, _ := newNotionTestRegistry(t, func(w http.ResponseWriter, r *http.Request) {
		envelope(w, []any{}, nil)
	})
	if got := reg.ResultCap("notion_read_page"); got != 48000 {
		t.Errorf("ResultCap(notion_read_page) = %d, want 48000", got)
	}
	if got := reg.ResultCap("search_incidents"); got != 0 {
		t.Errorf("ResultCap(search_incidents) = %d, want 0 (default)", got)
	}
	if got := reg.ResultCap("notion_search"); got != 0 {
		t.Errorf("ResultCap(notion_search) = %d, want 0 (default)", got)
	}
}
