package tools

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"strconv"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/schedule"
)

// ScheduleAPI is the dashboard's agent-schedules API client. Chat reminders
// live as AiAgent rows — the dashboard owns storage and dispatch; this client
// is the Go side's create/list/cancel surface.
type ScheduleAPI struct {
	c *dashboardClient
}

// NewScheduleAPI builds the client (10s timeout: a dead dashboard must not
// hang the chat reply path).
func NewScheduleAPI(cfg *config.Config) *ScheduleAPI {
	return &ScheduleAPI{c: &dashboardClient{
		base:  cfg.DashboardAPIBase,
		token: cfg.DashboardAPIToken,
		http:  &http.Client{Timeout: 10 * time.Second},
	}}
}

// ScheduleItem is one active reminder as the dashboard reports it.
type ScheduleItem struct {
	No      int     `json:"no"`
	Mode    string  `json:"mode"`
	Text    string  `json:"text"`
	Cron    *string `json:"cron"`
	RunAt   *string `json:"run_at"`
	NextRun string  `json:"next_run_at"`
}

// Describe renders "#1 cron '0 9 * * 1' (next Mon 2026-11-16 09:00 WIB) task: …"
// — the same shape the retired local store used, now over dashboard rows.
func (it ScheduleItem) Describe() string {
	kind := "once"
	if it.Cron != nil {
		kind = "cron '" + *it.Cron + "'"
	}
	next := it.NextRun
	if t, err := time.Parse(time.RFC3339, it.NextRun); err == nil {
		next = t.In(schedule.WIB).Format("Mon 2006-01-02 15:04")
	}
	text := it.Text
	if len(text) > 80 {
		text = text[:80] + "…"
	}
	return fmt.Sprintf("#%d %s (next %s WIB) %s: %s", it.No, kind, next, it.Mode, text)
}

// Create saves a reminder bound to the real chat. cron non-empty = recurring;
// otherwise runAt is the resolved one-shot instant (any timezone — the
// dashboard normalizes to WIB).
func (a *ScheduleAPI) Create(ctx context.Context, conversationID, conversationType, staffID, nick, mode, cron string, runAt *time.Time, text string) (ScheduleItem, error) {
	payload := map[string]any{
		"conversation_id":   conversationID,
		"conversation_type": conversationType,
		"staff_id":          staffID,
		"nick":              nick,
		"mode":              mode,
		"text":              text,
	}
	if cron != "" {
		payload["cron"] = cron
	}
	if runAt != nil {
		payload["run_at"] = runAt.Format(time.RFC3339)
	}
	env, err := a.c.post(ctx, "/agent-schedules", payload)
	if err != nil {
		return ScheduleItem{}, err
	}
	var item ScheduleItem
	if err := json.Unmarshal(env.Data, &item); err != nil {
		return ScheduleItem{}, fmt.Errorf("agent-schedules returned invalid JSON: %w", err)
	}
	return item, nil
}

// List returns the chat's active reminders.
func (a *ScheduleAPI) List(ctx context.Context, conversationID string) ([]ScheduleItem, error) {
	env, err := a.c.get(ctx, "/agent-schedules?conversation_id="+url.QueryEscape(conversationID))
	if err != nil {
		return nil, err
	}
	var items []ScheduleItem
	if err := json.Unmarshal(env.Data, &items); err != nil {
		return nil, fmt.Errorf("agent-schedules returned invalid JSON: %w", err)
	}
	return items, nil
}

// Cancel cancels reminder #no in that chat (dashboard-side soft cancel — the
// row is kept, disabled).
func (a *ScheduleAPI) Cancel(ctx context.Context, conversationID string, no int) error {
	_, err := a.c.del(ctx, "/agent-schedules/"+strconv.Itoa(no), map[string]any{"conversation_id": conversationID})
	return err
}
