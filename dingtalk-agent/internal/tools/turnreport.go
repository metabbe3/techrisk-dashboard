package tools

import (
	"context"
	"log"
	"net/http"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
)

// TurnReport is one chat turn's record for the dashboard. Posted asynchronously
// to /api/v1/agent-runs (recorded under the synthetic "DingTalk Bot (chat turns)"
// agent), so chat usage and failures are visible on the Agent Runs tab.
type TurnReport struct {
	ConversationID   string
	ConversationType string
	StaffID          string
	Nick             string
	UserText         string
	AssistantText    string
	Model            string
	Usage            llm.Usage
	ResponseTimeMs   int64
	Error            string
}

// payload matches app/Http/Requests/Api/V1/Agent/ReportAgentTurnRequest.
type turnReportPayload struct {
	ConversationID   string     `json:"conversation_id"`
	ConversationType string     `json:"conversation_type,omitempty"`
	StaffID          string     `json:"staff_id,omitempty"`
	Nick             string     `json:"nick,omitempty"`
	UserText         string     `json:"user_text"`
	AssistantText    string     `json:"assistant_text,omitempty"`
	Model            string     `json:"model,omitempty"`
	Usage            *llm.Usage `json:"usage,omitempty"`
	ResponseTimeMs   int64      `json:"response_time_ms,omitempty"`
	Error            string     `json:"error,omitempty"`
}

// ReportTurnAsync posts the turn report in a goroutine; any failure is logged
// and dropped. Never blocks the reply.
func ReportTurnAsync(cfg *config.Config, rep TurnReport) {
	go func() {
		// 10s client: a slow dashboard must never pile up reporting goroutines.
		c := &dashboardClient{
			base:  cfg.DashboardAPIBase,
			token: cfg.DashboardAPIToken,
			http:  &http.Client{Timeout: 10 * time.Second},
		}
		if _, err := c.post(context.Background(), "/agent-runs", turnReportPayload{
			ConversationID:   rep.ConversationID,
			ConversationType: rep.ConversationType,
			StaffID:          rep.StaffID,
			Nick:             rep.Nick,
			UserText:         rep.UserText,
			AssistantText:    rep.AssistantText,
			Model:            rep.Model,
			Usage:            usagePtr(rep.Usage),
			ResponseTimeMs:   rep.ResponseTimeMs,
			Error:            rep.Error,
		}); err != nil {
			log.Printf("[turn-report] POST /agent-runs failed: %v", err)
		}
	}()
}

// usagePtr returns a pointer for the JSON omitempty, or nil when zero.
func usagePtr(u llm.Usage) *llm.Usage {
	if u == (llm.Usage{}) {
		return nil
	}
	return &u
}
