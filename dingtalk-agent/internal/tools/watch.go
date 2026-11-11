package tools

import (
	"encoding/json"
	"fmt"
	"regexp"
	"strings"

	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/watch"
)

// WatchTicket marks a parked watch request in a tool result — same binding
// contract as ScheduleTicket: the bot scans its OWN turn's tool results and
// binds each to the real sender/conversation.
var WatchTicket = regexp.MustCompile(`\[watch-ticket: ([0-9a-f]{16})\]`)

func (r *Registry) registerWatchTools() {
	r.watchPending = watch.NewPending()
	r.Register(watchIncidentDef(), r.handleWatchIncident)
	r.Register(unwatchIncidentDef(), r.handleUnwatchIncident)
}

// WatchPending exposes the parked requests to the bot (nil when disabled).
func (r *Registry) WatchPending() *watch.Pending { return r.watchPending }

// Dashboard exposes the dashboard API client to the bot (watch poller sweep).
func (r *Registry) Dashboard() *dashboardClient { return r.dash }

func watchIncidentDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name: "watch_incident",
			Description: "Watch an incident in THIS chat: when its status changes (e.g. Open → In progress → Completed), " +
				"the bot reports the change here with full incident context, checking about once a minute. " +
				"Use when someone asks to be notified/alerted about a specific incident's progress.",
			Parameters: schema(
				map[string]any{
					"no": map[string]any{"type": "string", "description": "Incident number, e.g. 20250115_IN_1234"},
				},
				[]string{"no"},
			),
		},
	}
}

func unwatchIncidentDef() llm.ToolDefinition {
	return llm.ToolDefinition{
		Type: "function",
		Function: llm.ToolFunctionDef{
			Name:        "unwatch_incident",
			Description: "Stop watching an incident in THIS chat by the watch ID (active watches are listed in the user's message context).",
			Parameters: schema(
				map[string]any{
					"id": map[string]any{"type": "integer", "description": "Watch ID, e.g. 2"},
				},
				[]string{"id"},
			),
		},
	}
}

type watchArgs struct {
	No string `json:"no"`
}

func (r *Registry) handleWatchIncident(argsJSON string) (string, error) {
	var a watchArgs
	if err := json.Unmarshal([]byte(argsJSON), &a); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}
	a.No = strings.TrimSpace(a.No)
	if a.No == "" || len(a.No) > 64 || strings.ContainsAny(a.No, " /?#") {
		return "", fmt.Errorf("no must be an incident number like 20250115_IN_1234")
	}
	token := r.watchPending.Put(watch.Request{No: a.No})
	return fmt.Sprintf("[watch-ticket: %s] Accepted — the bot verifies the incident, saves the watch to this chat after your reply, "+
		"and appends the confirmed watch ID. Tell the user what is now watched; do not invent an ID.", token), nil
}

func (r *Registry) handleUnwatchIncident(argsJSON string) (string, error) {
	var a struct {
		ID int `json:"id"`
	}
	if err := json.Unmarshal([]byte(argsJSON), &a); err != nil || a.ID < 1 {
		return "", fmt.Errorf("id must be a positive watch ID")
	}
	token := r.watchPending.Put(watch.Request{CancelID: a.ID})
	return fmt.Sprintf("[watch-ticket: %s] Cancel of watch #%d accepted — the bot applies it after your reply "+
		"(only watches belonging to this chat can be cancelled).", token, a.ID), nil
}
