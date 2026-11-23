package tools

import (
	"context"
	"fmt"
	"net/url"
	"strconv"
	"strings"

	"techrisk-dashboard/dingtalk-agent/internal/guardrail"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
)

var validInvestigationStatuses = map[string]bool{
	"open":      true,
	"pending":   true,
	"expired":   true,
	"submitted": true,
	"revoked":   true,
	"all":       true,
}

func getInvestigationStatusDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "get_investigation_status",
			Description: "Check Incident Investigation Form intake status across incidents — answers " +
				"\"which incidents still have pending investigation forms?\", \"which forms expired?\", " +
				"\"has team X submitted their investigation form?\". Default lists OPEN (pending, non-expired) " +
				"requests. Response has meta.total (use limit=1 for count questions) and data rows with " +
				"incident_no, team, state (pending/expired/submitted/revoked), expires_at, has_draft, " +
				"recipient_count, opened_count, reminder_count, and action_items_count. Pair with " +
				"schedule_reminder for a weekly chase digest.",
			Parameters: schema(
				map[string]any{
					"status":      map[string]any{"type": "string", "description": "Filter by state: open (default, pending & not expired), pending, expired, submitted, revoked, or all"},
					"incident_no": map[string]any{"type": "string", "description": "Scope to one incident number, e.g. 20261116_IN_1234"},
					"team":        map[string]any{"type": "string", "description": "Filter by team name (partial match)"},
					"limit":       map[string]any{"type": "integer", "description": "Rows to return, 1-100 (default 20). Use 1 for count-only via meta.total"},
					"offset":      map[string]any{"type": "integer", "description": "Rows to skip (paging)"},
				},
				[]string{},
			),
		},
	}
}

func (c *dashboardClient) handleGetInvestigationStatus(ctx context.Context, args struct {
	Status     string `json:"status"`
	IncidentNo string `json:"incident_no"`
	Team       string `json:"team"`
	Limit      *int   `json:"limit"`
	Offset     int    `json:"offset"`
}) (string, error) {
	status := strings.ToLower(strings.TrimSpace(args.Status))
	if status != "" && !validInvestigationStatuses[status] {
		return "", fmt.Errorf("status must be one of: open, pending, expired, submitted, revoked, all (got %q)", args.Status)
	}

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
	if status != "" {
		q.Set("status", status)
	}
	if strings.TrimSpace(args.IncidentNo) != "" {
		q.Set("incident_no", strings.TrimSpace(args.IncidentNo))
	}
	if strings.TrimSpace(args.Team) != "" {
		q.Set("team", strings.TrimSpace(args.Team))
	}

	env, err := c.get(ctx, "/investigation-forms?"+q.Encode())
	if err != nil {
		return "", err
	}

	return guardrail.FrameDocumentContent("investigation forms", passthroughEnvelope(env)), nil
}
