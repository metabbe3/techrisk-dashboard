package dingtalk

import (
	"context"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"strings"
	"time"

	"github.com/open-dingtalk/dingtalk-stream-sdk-go/chatbot"

	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/guardrail"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/schedule"
	"techrisk-dashboard/dingtalk-agent/internal/tools"
)

// applySchedules binds the reminder requests parked by THIS turn's tool calls
// to the real sender and conversation, then swaps any ticket the LLM echoed
// for the confirmed outcome. The conversation id comes from the DingTalk
// callback, never from the LLM — a prompt cannot aim a reminder at another chat.
func (b *Bot) applySchedules(m *chatbot.BotCallbackDataModel, history []llm.ChatMessage, reply string) string {
	reply = strings.TrimSpace(tools.ScheduleTicket.ReplaceAllString(reply, ""))
	if b.loop == nil || b.loop.Registry().SchedulePending() == nil {
		return reply
	}
	pending := b.loop.Registry().SchedulePending()

	var notes []string
	for _, msg := range history {
		if msg.Role != "tool" {
			continue
		}
		for _, mm := range tools.ScheduleTicket.FindAllStringSubmatch(msg.Content, -1) {
			req, ok := pending.Claim(mm[1]) // earlier turns' tokens are already claimed
			if !ok {
				continue
			}
			notes = append(notes, b.bindSchedule(m, req))
		}
	}
	if len(notes) == 0 {
		return reply
	}
	return reply + "\n\n" + strings.Join(notes, "\n")
}

// bindSchedule validates the "when" grammar Go-side (immediate feedback in the
// chat), then saves/cancels through the dashboard's agent-schedules API. The
// dashboard owns storage and dispatch; cron passes through verbatim, one-shots
// arrive as a resolved run_at.
func (b *Bot) bindSchedule(m *chatbot.BotCallbackDataModel, req schedule.Request) string {
	if b.schedAPI == nil {
		return "⚠️ Reminder not saved: the dashboard API is not configured."
	}
	if req.CancelID > 0 {
		if err := b.schedAPI.Cancel(context.Background(), m.ConversationId, req.CancelID); err != nil {
			return "⚠️ " + err.Error()
		}
		log.Printf("[schedule] #%d cancelled by %s in %s", req.CancelID, m.SenderNick, m.ConversationId)
		return fmt.Sprintf("🗑️ Reminder #%d cancelled.", req.CancelID)
	}
	// The task text runs through the agent later with no human in the loop —
	// it must pass the same input gate a live message does.
	if refusal := guardrail.InputGate(req.Text); refusal != "" {
		return "⚠️ Reminder not saved: " + refusal
	}
	now := time.Now()
	spec, err := schedule.Parse(req.When, now)
	if err != nil {
		return "⚠️ Reminder not saved: " + err.Error()
	}
	cron := ""
	var runAt *time.Time
	if spec.Recurring() {
		cron = req.When
	} else {
		at, ok := spec.Next(now)
		if !ok {
			return "⚠️ Reminder not saved: that time never fires."
		}
		runAt = &at
	}
	it, err := b.schedAPI.Create(context.Background(), m.ConversationId, m.ConversationType,
		m.SenderStaffId, m.SenderNick, req.Mode, cron, runAt, req.Text)
	if err != nil {
		return "⚠️ Reminder not saved: " + err.Error()
	}
	log.Printf("[schedule] saved %s by %s in %s", it.Describe(), m.SenderNick, m.ConversationId)
	return "⏰ Saved reminder " + it.Describe()
}

// SendMarkdownGroup pushes a robot markdown message INTO a group conversation
// via POST /v1.0/robot/groupMessages/send. The bot must be in the group;
// conversationID is the openConversationId (callbacks carry it as ConversationId).
func SendMarkdownGroup(ctx context.Context, cfg *config.Config, conversationID, title, markdown string) error {
	msgParam, err := json.Marshal(map[string]string{"title": title, "text": markdown})
	if err != nil {
		return err
	}
	return newOpenAPIClient(cfg).do(ctx, http.MethodPost, "/v1.0/robot/groupMessages/send", map[string]any{
		"robotCode":          cfg.DingTalkClientID,
		"openConversationId": conversationID,
		"msgKey":             "sampleMarkdown",
		"msgParam":           string(msgParam),
	})
}

// deliverMarkdown routes a proactive markdown push by conversation type:
// groups go via SendMarkdownGroup, and when that endpoint rejects the robot
// (missing group-send capability — live-probed "robot 不存在") the message
// falls back to the creator's DM rather than being lost. Fallback is on ANY
// error, never on matching the error text.
func (b *Bot) deliverMarkdown(ctx context.Context, convoID, convoType, staffID, title, text string) error {
	if convoType != "2" {
		return SendMarkdownDM(ctx, b.cfg, []string{staffID}, title, text)
	}
	if err := SendMarkdownGroup(ctx, b.cfg, convoID, title, text); err != nil {
		log.Printf("[dingtalk] group send failed (%v) — falling back to DM", err)
		return SendMarkdownDM(ctx, b.cfg, []string{staffID}, title, text+"\n\n_(group delivery failed — sent here instead)_")
	}
	return nil
}
