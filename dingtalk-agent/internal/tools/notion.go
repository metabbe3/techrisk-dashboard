// notion.go — read-only Notion workspace doc tools (PROJ-019 extension).
// All four tools proxy the Laravel v1 API's /notion endpoints, which reuse the
// dashboard's Notion OAuth; this agent never sees a Notion token. Schemas stay
// FLAT via schema() — nested types 400 the whole upstream request (prod 2026-11-10).
package tools

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"sync"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
)

// NotionRead is one page the agent read this turn — drained by the bot after
// the turn and summarized into knowledge memory.
type NotionRead struct {
	Title   string
	URL     string
	Excerpt string // first 3000 chars of the page content
}

const notionExcerptChars = 3000

// notionCatalog persists named database ids (<DataDir>/notion_catalog.json) so
// taught names survive restarts. Upsert-only: env seeds and add() never delete.
type notionCatalog struct {
	path string // "" = memory-only
	mu   sync.Mutex
	dbs  map[string]notionDB
}

type notionDB struct {
	ID      string    `json:"id"`
	AddedAt time.Time `json:"added_at"`
}

type notionCatalogFile struct {
	Databases map[string]notionDB `json:"databases"`
}

var notionNamePattern = regexp.MustCompile(`^[A-Za-z0-9_-]{1,32}$`)

func loadNotionCatalog(dataDir string) *notionCatalog {
	c := &notionCatalog{dbs: map[string]notionDB{}}
	if dataDir == "" {
		return c
	}
	c.path = filepath.Join(dataDir, "notion_catalog.json")
	if data, err := os.ReadFile(c.path); err == nil { // missing file = fresh
		var f notionCatalogFile
		if json.Unmarshal(data, &f) == nil && f.Databases != nil {
			c.dbs = f.Databases
		}
	}
	return c
}

// seedFromEnv upserts env-configured names; catalog-only names survive. Saved
// only when something changed.
func (c *notionCatalog) seedFromEnv(env map[string]string) {
	if len(env) == 0 {
		return
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	changed := false
	for name, id := range env {
		if id == "" || !notionNamePattern.MatchString(name) {
			continue
		}
		if cur, ok := c.dbs[name]; ok && cur.ID == id {
			continue
		}
		c.dbs[name] = notionDB{ID: id, AddedAt: time.Now()}
		changed = true
	}
	if changed {
		_ = c.saveLocked() // best effort — the env re-seeds on next boot anyway
	}
}

var (
	// UUID with optional dashes — Notion ids appear both dashed and as bare 32-hex.
	notionIDPattern = regexp.MustCompile(`^[0-9a-fA-F]{8}-?[0-9a-fA-F]{4}-?[0-9a-fA-F]{4}-?[0-9a-fA-F]{4}-?[0-9a-fA-F]{12}$`)
	notionLetter    = regexp.MustCompile(`[A-Za-z]`)
)

// looksLikeName reports whether ref reads as a catalog NAME rather than an id:
// it has letters but is not a (Notion) id and carries no scheme/path (URL, collection://).
func looksLikeName(ref string) bool {
	return !strings.ContainsAny(ref, "/:") &&
		notionLetter.MatchString(ref) && !notionIDPattern.MatchString(ref)
}

// resolve maps a catalog NAME to its id; raw ids/URLs/collection:// refs pass
// through untouched. An unknown name-like ref returns ok=false so the caller
// can list the catalog (the LLM self-corrects) without calling the server.
func (c *notionCatalog) resolve(ref string) (string, bool) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if db, ok := c.dbs[ref]; ok {
		return db.ID, true
	}
	if looksLikeName(ref) {
		return "", false
	}
	return ref, true
}

// add validates and upserts a name → id mapping, persisting the catalog.
func (c *notionCatalog) add(name, id string) error {
	if !notionNamePattern.MatchString(name) {
		return fmt.Errorf("name must be 1-32 chars of [A-Za-z0-9_-], got %q", name)
	}
	if id == "" {
		return fmt.Errorf("id is required (a Notion database id or URL)")
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	c.dbs[name] = notionDB{ID: id, AddedAt: time.Now()}
	return c.saveLocked()
}

func (c *notionCatalog) saveLocked() error {
	if c.path == "" {
		return nil // memory-only (tests without DataDir)
	}
	data, err := json.Marshal(notionCatalogFile{Databases: c.dbs})
	if err != nil {
		return err
	}
	tmp := c.path + ".tmp"
	if err := os.WriteFile(tmp, data, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, c.path)
}

// listing renders "PRD=<id>, TA=<id>" sorted by name (stable tool descriptions).
func (c *notionCatalog) listing() string {
	c.mu.Lock()
	defer c.mu.Unlock()
	names := make([]string, 0, len(c.dbs))
	for name := range c.dbs {
		names = append(names, name)
	}
	sort.Strings(names)
	parts := make([]string, 0, len(names))
	for _, name := range names {
		parts = append(parts, name+"="+c.dbs[name].ID)
	}
	if len(parts) == 0 {
		return "(none yet — teach one with notion_add_database)"
	}
	return strings.Join(parts, ", ")
}

// notionTools carries the Notion tool state: its own API client (90s timeout —
// the Notion MCP backend is slow; the shared dashboard client keeps 30s), the
// persistent catalog, and the read tracker drained after each turn.
type notionTools struct {
	client   *dashboardClient
	catalog  *notionCatalog
	registry *Registry

	mu    sync.Mutex
	reads []NotionRead
}

func (n *notionTools) recordRead(r NotionRead) {
	n.mu.Lock()
	n.reads = append(n.reads, r)
	n.mu.Unlock()
}

// takeNotionReads drains the reads recorded since the last call.
func (n *notionTools) takeNotionReads() []NotionRead {
	n.mu.Lock()
	defer n.mu.Unlock()
	reads := n.reads
	n.reads = nil
	return reads
}

func (r *Registry) registerNotionTools(cfg *config.Config) {
	catalog := loadNotionCatalog(cfg.DataDir)
	catalog.seedFromEnv(cfg.NotionDatabases)
	n := &notionTools{
		client: &dashboardClient{
			base:  cfg.DashboardAPIBase,
			token: cfg.DashboardAPIToken,
			http:  &http.Client{Timeout: 90 * time.Second},
		},
		catalog:  catalog,
		registry: r,
	}
	r.notion = n
	r.Register(notionSearchDef(), n.handleNotionSearch)
	r.Register(n.notionQueryDatabaseDef(), n.handleNotionQueryDatabase)
	r.RegisterCapped(notionReadPageDef(), n.handleNotionReadPage, 48000) // a PRD is ~48k; default 8k would gut it
	r.Register(notionAddDatabaseDef(), n.handleNotionAddDatabase)
}

// TakeNotionReads drains the Notion pages read this turn (nil when the notion
// tools are disabled) — the bot summarizes them into knowledge memory.
func (r *Registry) TakeNotionReads() []NotionRead {
	if r.notion == nil {
		return nil
	}
	return r.notion.takeNotionReads()
}

func notionSearchDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "notion_search",
			Description: "Search pages in the connected Notion workspace by keyword; returns page titles and URLs. " +
				"Use it to find technical documents (PRD, TA, design docs, postmortems), then read a result with notion_read_page.",
			Parameters: schema(
				map[string]any{
					"query": map[string]any{"type": "string", "description": "Search keywords"},
				},
				[]string{"query"},
			),
		},
	}
}

type notionSearchArgs struct {
	Query string `json:"query"`
}

func (n *notionTools) handleNotionSearch(argsJSON string) (string, error) {
	var args notionSearchArgs
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}
	q := strings.TrimSpace(args.Query)
	if q == "" {
		return "", fmt.Errorf("query is required")
	}

	env, err := n.client.post(context.Background(), "/notion/search", map[string]string{"query": q})
	if err != nil {
		return "", err
	}
	var data struct {
		Text      string `json:"text"`
		Truncated bool   `json:"truncated"`
	}
	if err := json.Unmarshal(env.Data, &data); err != nil {
		return "", fmt.Errorf("unexpected notion search payload: %w", err)
	}
	if strings.TrimSpace(data.Text) == "" {
		return fmt.Sprintf("No Notion pages found for %q.", q), nil
	}
	return data.Text, nil
}

// notionQueryDatabaseDef is rebuilt from the live catalog on every
// (re)registration so its description always lists the current names.
func (n *notionTools) notionQueryDatabaseDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "notion_query_database",
			Description: "Run a read-only SQL query against a Notion database. Known databases: " + n.catalog.listing() + ". " +
				"Run SELECT * ... LIMIT 1 first when unsure of a database's column names. " +
				"Notion caps rows at 100 per query — page with WHERE createdTime >= '<last row createdTime>'. " +
				"The url column holds each row's page — read it with notion_read_page.",
			Parameters: schema(
				map[string]any{
					"database": map[string]any{"type": "string", "description": "Catalog name (e.g. PRD) or a raw database id/URL"},
					"query":    map[string]any{"type": "string", "description": "SQL query, e.g. SELECT * FROM \"collection://...\" LIMIT 10"},
				},
				[]string{"database", "query"},
			),
		},
	}
}

type notionQueryArgs struct {
	Database string `json:"database"`
	Query    string `json:"query"`
}

func (n *notionTools) handleNotionQueryDatabase(argsJSON string) (string, error) {
	var args notionQueryArgs
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}
	ref := strings.TrimSpace(args.Database)
	if ref == "" || strings.TrimSpace(args.Query) == "" {
		return "", fmt.Errorf("database and query are required")
	}

	resolved, ok := n.catalog.resolve(ref)
	if !ok {
		// Name-like but unknown: list the catalog so the LLM self-corrects
		// without a wasted server round-trip.
		return "", fmt.Errorf("unknown database name %q. Known databases: %s", ref, n.catalog.listing())
	}

	env, err := n.client.post(context.Background(), "/notion/query", map[string]string{"database": resolved, "query": args.Query})
	if err != nil {
		return "", err
	}
	return string(env.Data), nil // rows as JSON
}

func notionReadPageDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "notion_read_page",
			Description: "Fetch one Notion page's full content as Markdown by page id or URL. " +
				"Read pages ONE AT A TIME. The result cap is larger than other tools (48k) — long documents survive.",
			Parameters: schema(
				map[string]any{
					"id_or_url": map[string]any{"type": "string", "description": "Page id or URL (e.g. from notion_search or a query's url column)"},
				},
				[]string{"id_or_url"},
			),
		},
	}
}

type notionReadPageArgs struct {
	IDOrURL string `json:"id_or_url"`
}

func (n *notionTools) handleNotionReadPage(argsJSON string) (string, error) {
	var args notionReadPageArgs
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}
	ref := strings.TrimSpace(args.IDOrURL)
	if ref == "" {
		return "", fmt.Errorf("id_or_url is required")
	}

	env, err := n.client.post(context.Background(), "/notion/fetch", map[string]string{"id": ref})
	if err != nil {
		return "", err
	}
	var data struct {
		Title   string  `json:"title"` // optional — the proxy returns content only today
		Content *string `json:"content"`
	}
	if err := json.Unmarshal(env.Data, &data); err != nil {
		return "", fmt.Errorf("unexpected notion fetch payload: %w", err)
	}
	if data.Content == nil || strings.TrimSpace(*data.Content) == "" {
		return "(empty page — Notion returned no content)", nil
	}
	content := *data.Content

	title := data.Title
	if title == "" {
		title = firstHeadingOrRef(content, ref)
	}
	excerpt := content
	if len(excerpt) > notionExcerptChars {
		excerpt = excerpt[:notionExcerptChars]
	}
	n.recordRead(NotionRead{Title: title, URL: ref, Excerpt: excerpt})
	return content, nil
}

// firstHeadingOrRef derives a display title from the page's first markdown
// heading, falling back to the raw reference.
func firstHeadingOrRef(content, ref string) string {
	for _, line := range strings.SplitN(content, "\n", 40) {
		if line = strings.TrimSpace(line); strings.HasPrefix(line, "#") {
			return strings.TrimSpace(strings.TrimLeft(line, "# "))
		}
	}
	return ref
}

func notionAddDatabaseDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "notion_add_database",
			Description: "Teach the agent a named Notion database so notion_query_database can use it by name. " +
				"Use only when the user explicitly asks to add/name a database.",
			Parameters: schema(
				map[string]any{
					"name": map[string]any{"type": "string", "description": "Short name, 1-32 chars of letters/digits/_/- (e.g. PRD)"},
					"id":   map[string]any{"type": "string", "description": "Notion database id or URL (32-hex id, full URL, or collection://...)"},
				},
				[]string{"name", "id"},
			),
		},
	}
}

type notionAddDatabaseArgs struct {
	Name string `json:"name"`
	ID   string `json:"id"`
}

func (n *notionTools) handleNotionAddDatabase(argsJSON string) (string, error) {
	var args notionAddDatabaseArgs
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}
	if err := n.catalog.add(strings.TrimSpace(args.Name), strings.TrimSpace(args.ID)); err != nil {
		return "", err
	}
	// Re-register the query def so its description lists the new name on the
	// next GetDefinitions() (defs are re-collected every LLM call).
	n.registry.Register(n.notionQueryDatabaseDef(), n.handleNotionQueryDatabase)
	return "Catalog now: " + n.catalog.listing(), nil
}
