package tools

import (
	"encoding/json"
	"fmt"
	"regexp"
	"strings"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/schedule"
)

// ScheduleTicket marks a parked reminder request in a tool result. The bot
// scans its OWN turn's tool results for these and binds each to the real
// sender/conversation — the LLM never supplies a conversation id.
var ScheduleTicket = regexp.MustCompile(`\[schedule-ticket: ([0-9a-f]{16})\]`)

func (r *Registry) registerScheduleTools() {
	r.pending = schedule.NewPending()
	r.Register(scheduleReminderDef(), r.handleScheduleReminder)
	r.Register(cancelReminderDef(), r.handleCancelReminder)
}

// SchedulePending exposes the parked requests to the bot (nil when disabled).
func (r *Registry) SchedulePending() *schedule.Pending { return r.pending }

func scheduleReminderDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "schedule_reminder",
			Description: "Create a reminder in THIS chat (group or DM). Times are WIB (UTC+7). " +
				"when: 5-field cron 'min hour dom month dow' (e.g. '0 9 * * 1' = Mondays 09:00, '0 9 * * 1-5' = weekdays 09:00), " +
				"a one-shot 'YYYY-MM-DD HH:MM', or 'in 30m' / 'in 2h'. Recurring reminders must be ≥30 min apart. " +
				"mode: 'message' posts text as-is; 'task' runs text as a request through you at fire time and posts the answer " +
				"(e.g. 'list open P1/P2 incidents and overdue action improvements').",
			Parameters: schema(
				map[string]any{
					"when": map[string]any{"type": "string", "description": "Cron, 'YYYY-MM-DD HH:MM' (WIB), or 'in 30m'"},
					"mode": map[string]any{"type": "string", "description": "message (default) or task"},
					"text": map[string]any{"type": "string", "description": "Reminder text (message) or the request to run (task), max 1000 chars"},
				},
				[]string{"when", "text"},
			),
		},
	}
}

type scheduleArgs struct {
	When string `json:"when"`
	Mode string `json:"mode"`
	Text string `json:"text"`
}

func (r *Registry) handleScheduleReminder(argsJSON string) (string, error) {
	var a scheduleArgs
	if err := json.Unmarshal([]byte(argsJSON), &a); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}
	a.Text = strings.TrimSpace(a.Text)
	switch a.Mode = strings.ToLower(strings.TrimSpace(a.Mode)); a.Mode {
	case "":
		a.Mode = schedule.ModeMessage
	case schedule.ModeMessage, schedule.ModeTask:
	default:
		return "", fmt.Errorf("mode must be 'message' or 'task'")
	}
	if a.Text == "" || len(a.Text) > schedule.MaxTextChars {
		return "", fmt.Errorf("text is required and at most %d chars", schedule.MaxTextChars)
	}
	now := time.Now()
	spec, err := schedule.Parse(a.When, now)
	if err != nil {
		return "", err // immediate feedback so the LLM can fix the spec
	}
	first, _ := spec.Next(now)
	token := r.pending.Put(schedule.Request{When: strings.TrimSpace(a.When), Mode: a.Mode, Text: a.Text})
	return fmt.Sprintf("[schedule-ticket: %s] Accepted — first run %s WIB. The bot saves it to this chat after your reply "+
		"and appends the confirmed reminder ID. Tell the user what was scheduled; do not invent an ID.",
		token, first.In(schedule.WIB).Format("Mon 2006-01-02 15:04")), nil
}

func cancelReminderDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name:        "cancel_reminder",
			Description: "Cancel a reminder in THIS chat by its ID (the active reminders are listed in the user's message context).",
			Parameters: schema(
				map[string]any{
					"id": map[string]any{"type": "integer", "description": "Reminder ID, e.g. 3"},
				},
				[]string{"id"},
			),
		},
	}
}

func (r *Registry) handleCancelReminder(argsJSON string) (string, error) {
	var a struct {
		ID int `json:"id"`
	}
	if err := json.Unmarshal([]byte(argsJSON), &a); err != nil || a.ID < 1 {
		return "", fmt.Errorf("id must be a positive reminder ID")
	}
	token := r.pending.Put(schedule.Request{CancelID: a.ID})
	return fmt.Sprintf("[schedule-ticket: %s] Cancel of #%d accepted — the bot applies it after your reply "+
		"(only reminders belonging to this chat can be cancelled).", token, a.ID), nil
}
