package tools

import (
	"context"
	"fmt"
	"net/url"
	"strconv"

	"techrisk-dashboard/dingtalk-agent/internal/guardrail"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
)

// weeklyReportDefaultWeeks bounds the default chat payload — a full year of
// per-week incident rows can approach the 48k cap; the model can ask for the
// whole year explicitly with weeks=0.
const weeklyReportDefaultWeeks = 8

func getWeeklyReportDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "get_weekly_report",
			Description: "Weekly report from the dashboard: per-week incident counts (open/closed/total, weeks run " +
				"Friday-to-Thursday) with a compact incident row list per week, plus a summary block. Counts follow the " +
				"dashboard's count eligibility (Issues, severity G/Non Incident, and excluded fund statuses never count). " +
				"Defaults to the last 8 elapsed weeks of the current year; pass weeks=0 for the full year. Pair with " +
				"schedule_reminder for a Monday weekly digest.",
			Parameters: schema(
				map[string]any{
					"year":  map[string]any{"type": "integer", "description": "Report year (default: current year)"},
					"weeks": map[string]any{"type": "integer", "description": "Return only the last N elapsed weeks (default 8; 0 = full year)"},
				},
				nil,
			),
		},
	}
}

func (c *dashboardClient) handleGetWeeklyReport(ctx context.Context, args struct {
	Year  *int `json:"year"`
	Weeks *int `json:"weeks"`
}) (string, error) {
	// W9 fix: validate like the server does (year 2000-2100, weeks 1-53)
	// instead of silently dropping invalid args — weeks=-5 used to widen
	// into a full-year payload. weeks=0 stays the explicit full-year ask.
	if args.Year != nil && (*args.Year < 2000 || *args.Year > 2100) {
		return "", fmt.Errorf("year must be between 2000 and 2100, got %d", *args.Year)
	}
	if args.Weeks != nil && (*args.Weeks < 0 || *args.Weeks > 53) {
		return "", fmt.Errorf("weeks must be 0 (full year) or 1-53, got %d", *args.Weeks)
	}

	q := url.Values{}
	if args.Year != nil && *args.Year > 0 {
		q.Set("year", strconv.Itoa(*args.Year))
	}

	weeks := weeklyReportDefaultWeeks
	if args.Weeks != nil {
		weeks = *args.Weeks
	}
	if weeks > 0 {
		q.Set("weeks", strconv.Itoa(weeks))
	}

	env, err := c.get(ctx, "/weekly-report?"+q.Encode())
	if err != nil {
		return "", err
	}

	// W9 fix: the envelope embeds incident titles/summaries — untrusted
	// data, framed like every other document-carrying tool.
	return guardrail.FrameDocumentContent("weekly report", passthroughEnvelope(env)), nil
}
