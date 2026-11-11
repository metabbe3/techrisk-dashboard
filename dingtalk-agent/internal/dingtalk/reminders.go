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

const (
	schedulerTick = 30 * time.Second
	// lateSkip: a RECURRING reminder this overdue (bot was down) is skipped to
	// its next run instead of firing stale. One-shots always fire, marked late.
	lateSkip = time.Hour
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

func (b *Bot) bindSchedule(m *chatbot.BotCallbackDataModel, req schedule.Request) string {
	if req.CancelID > 0 {
		it, err := b.sched.Cancel(req.CancelID, m.ConversationId)
		if err != nil {
			return "⚠️ " + err.Error()
		}
		log.Printf("[schedule] #%d cancelled by %s in %s", it.ID, m.SenderNick, m.ConversationId)
		return fmt.Sprintf("🗑️ Reminder #%d cancelled.", it.ID)
	}
	// The task text runs through the agent later with no human in the loop —
	// it must pass the same input gate a live message does.
	if refusal := guardrail.InputGate(req.Text); refusal != "" {
		return "⚠️ Reminder not saved: " + refusal
	}
	it, err := b.sched.Add(schedule.Item{
		ConversationID: m.ConversationId, ConversationType: m.ConversationType,
		StaffID: m.SenderStaffId, Nick: m.SenderNick,
		When: req.When, Mode: req.Mode, Text: req.Text,
	}, time.Now())
	if err != nil {
		return "⚠️ Reminder not saved: " + err.Error()
	}
	log.Printf("[schedule] saved %s by %s in %s", it.Describe(), m.SenderNick, m.ConversationId)
	return "⏰ Saved reminder " + it.Describe()
}

// RunScheduler fires due reminders until ctx ends. Start it once from main.
func (b *Bot) RunScheduler(ctx context.Context) {
	t := time.NewTicker(schedulerTick)
	defer t.Stop()
	for {
		b.fireDue(ctx, time.Now())
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
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

func (b *Bot) fireDue(ctx context.Context, now time.Time) {
	for _, it := range b.sched.TakeDue(now) {
		late := now.Sub(it.NextRun)
		if schedule.IsRecurring(it.When) && late > lateSkip {
			log.Printf("[schedule] #%d skipped — %v late (bot was down); next run kept", it.ID, late.Round(time.Minute))
			continue
		}
		go b.fire(ctx, it, late > lateSkip)
	}
}

// fire delivers one reminder: a message posts its text; a task runs the text
// through the agent (fresh context, guarded output) and posts the answer.
func (b *Bot) fire(ctx context.Context, it schedule.Item, late bool) {
	header := fmt.Sprintf("⏰ **Reminder #%d** (set by %s)", it.ID, it.Nick)
	if late {
		header += " — delayed, the bot was offline at the scheduled time"
	}
	body := it.Text

	if it.Mode == schedule.ModeTask {
		b.sem <- struct{}{}
		reply, _, err := b.loop.Run(ctx,
			fmt.Sprintf("[Scheduled check #%d set by %s — run it now and report the result. Do not create or cancel reminders.]\n\n%s", it.ID, it.Nick, it.Text),
			nil, "")
		b.loop.Registry().TakeNotionReads() // a scheduled run must not leak reads into the next chat turn
		<-b.sem
		if err != nil {
			log.Printf("[schedule] #%d task failed: %v", it.ID, err)
			body = "The scheduled check failed this time: " + it.Text
		} else if body = guardrail.FilterReplyCap(reply, b.cfg.MaxReplyChars); body == "" {
			body = "The scheduled check produced no answer: " + it.Text
		}
		header = strings.Replace(header, "Reminder", "Scheduled check", 1)
	}

	text := header + "\n\n" + body
	if err := b.deliverMarkdown(ctx, it.ConversationID, it.ConversationType, it.StaffID, "Risk Agent reminder", text); err != nil {
		log.Printf("[schedule] #%d delivery failed: %v", it.ID, err)
		return
	}
	log.Printf("[schedule] #%d fired (%s) to %s", it.ID, it.Mode, it.ConversationID)
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
