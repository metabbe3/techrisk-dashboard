package tools

import (
	"context"
	"encoding/json"
	"fmt"
	"net/url"
	"strconv"

	"techrisk-dashboard/dingtalk-agent/internal/llm"
)

// Track C thin reads: cross-incident action items, governance KPIs, and
// reference vocabularies. All metric math lives server-side (one-logic rule) —
// the Go side builds query params and passes rows through.

func searchActionItemsDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "search_action_items",
			Description: "Search corrective/preventive action items ACROSS incidents — answers \"what's overdue\", " +
				"\"what's due this week\", \"what's on X's plate\". Default lists OPEN work (pending + on_progress). " +
				"Response has meta.total (THE source for count questions: request limit=1 and read meta.total) and " +
				"data rows (each carries incident_no — link findings back to it). Overdue = open work past its due " +
				"date (the governance definition; a null due date means TBD and is never chased).",
			Parameters: schema(
				map[string]any{
					"status":           map[string]any{"type": "string", "description": "Exact status filter: draft, pending, on_progress, done, cancelled, or tbd. Omit for open work (pending + on_progress)"},
					"overdue":          map[string]any{"type": "boolean", "description": "true = open work past its due date only"},
					"pending_approval": map[string]any{"type": "boolean", "description": "true = only action items with worker-submitted proof awaiting approval"},
					"pic":              map[string]any{"type": "string", "description": "PIC email to filter by (actions where this person is responsible)"},
					"incident_no": map[string]any{"type": "string", "description": "Scope to one incident number, e.g. 20250115_IN_1234"},
					"limit":       map[string]any{"type": "integer", "description": "Rows to return, 1-100 (default 20). Use 1 when you only need meta.total"},
					"offset":      map[string]any{"type": "integer", "description": "Rows to skip (paging)"},
				},
				[]string{},
			),
		},
	}
}

func getActionItemDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name:        "get_action_item",
			Description: "One action item's full record by id (ids come from search_action_items rows).",
			Parameters: schema(
				map[string]any{
					"id": map[string]any{"type": "integer", "description": "Action item id from search_action_items"},
				},
				[]string{"id"},
			),
		},
	}
}

func getGovernanceKpisDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "get_governance_kpis",
			Description: "The team's governance/PDR KPIs, exactly as the dashboard's Governance page computes them: " +
				"register completeness %, RCA within 5 days (pct/coverage/total), overdue action items, recurrence " +
				"this quarter, fund-loss vs non-fund detection-lag medians and usable-timing %, unknown-channel %. " +
				"Quote them as-is — never re-math on top.",
			Parameters: schema(
				map[string]any{
					"year":   map[string]any{"type": "integer", "description": "Calendar year to scope to (default: current year)"},
					"series": map[string]any{"type": "boolean", "description": "true = also return lag_by_quarter (labels/fl/nfl arrays for the quarterly detection-lag medians)"},
				},
				[]string{},
			),
		},
	}
}

func listReferenceDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "list_reference",
			Description: "The dashboard's filter vocabularies — call BEFORE guessing values for search_incidents " +
				"filters (type/severity vocab) or asking about categories. kinds: labels (incident labels), " +
				"incident-types (incident type names), categories (root-cause categories with their type).",
			Parameters: schema(
				map[string]any{
					"kind": map[string]any{"type": "string", "description": "One of: labels, incident-types, categories"},
				},
				[]string{"kind"},
			),
		},
	}
}

func (c *dashboardClient) handleSearchActionItems(ctx context.Context, args struct {
	Status          string `json:"status"`
	Overdue         bool   `json:"overdue"`
	PendingApproval bool   `json:"pending_approval"`
	Pic             string `json:"pic"`
	IncidentNo      string `json:"incident_no"`
	Limit           *int   `json:"limit"`
	Offset          int    `json:"offset"`
}) (string, error) {
	limit := searchLimitDefault
	if args.Limit != nil {
		limit = *args.Limit
	}
	if limit < 1 {
		limit = 1
	}
	if limit > searchLimitCeiling {
		limit = searchLimitCeiling
	}
	if args.Offset < 0 {
		args.Offset = 0
	}

	q := url.Values{}
	q.Set("limit", strconv.Itoa(limit))
	q.Set("offset", strconv.Itoa(args.Offset))
	if args.Status != "" {
		// One status per request is enough for an agent; the API validates the
		// vocabulary and 422s anything hand-typed that isn't real. Omitting
		// status lets the server apply ActionImprovement::openStatuses()
		// (pending + on_progress).
		q.Set("status", args.Status)
	}
	if args.Overdue {
		q.Set("overdue", "1")
	}
	if args.PendingApproval {
		q.Set("pending_approval", "1")
	}
	if args.Pic != "" {
		q.Set("pic", args.Pic)
	}
	if args.IncidentNo != "" {
		q.Set("incident_no", args.IncidentNo)
	}

	env, err := c.get(ctx, "/action-improvements?"+q.Encode())
	if err != nil {
		return "", err
	}
	return passthroughEnvelope(env), nil
}

func (c *dashboardClient) handleGetActionItem(ctx context.Context, args struct {
	ID int64 `json:"id"`
}) (string, error) {
	if args.ID <= 0 {
		return "", fmt.Errorf("id must be a positive action-item id (from search_action_items)")
	}
	env, err := c.get(ctx, "/action-improvements/"+strconv.FormatInt(args.ID, 10))
	if err != nil {
		return "", err
	}
	return passthroughEnvelope(env), nil
}

func (c *dashboardClient) handleGetGovernanceKpis(ctx context.Context, args struct {
	Year   *int `json:"year"`
	Series bool `json:"series"`
}) (string, error) {
	q := url.Values{}
	if args.Year != nil && *args.Year > 0 {
		q.Set("year", strconv.Itoa(*args.Year))
	}
	if args.Series {
		q.Set("series", "1")
	}
	env, err := c.get(ctx, "/governance?"+q.Encode())
	if err != nil {
		return "", err
	}
	return passthroughEnvelope(env), nil
}

var referenceKinds = map[string]string{
	"labels":         "/labels",
	"incident-types": "/incident-types",
	"categories":     "/categories",
}

func (c *dashboardClient) handleListReference(ctx context.Context, args struct {
	Kind string `json:"kind"`
}) (string, error) {
	path, ok := referenceKinds[args.Kind]
	if !ok {
		return "", fmt.Errorf("kind must be one of: labels, incident-types, categories")
	}
	env, err := c.get(ctx, path)
	if err != nil {
		return "", err
	}
	return passthroughEnvelope(env), nil
}

// passthroughEnvelope returns meta + data as compact JSON. The Laravel
// resources already emit exactly the fields the model may see (pic_email is
// gated server-side by the token's read:pii ability) — re-projecting here
// would just risk a key-name drift like the 2026 search_incidents one.
func passthroughEnvelope(env *apiEnvelope) string {
	out := map[string]any{"data": json.RawMessage(env.Data)}
	if env.Meta != nil {
		out["meta"] = env.Meta
	}
	encoded, err := json.Marshal(out)
	if err != nil {
		return string(env.Data)
	}
	return string(encoded)
}
