package dingtalk

import (
	"context"
	"encoding/json"
	"fmt"
	"log"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/open-dingtalk/dingtalk-stream-sdk-go/chatbot"

	"techrisk-dashboard/dingtalk-agent/internal/guardrail"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/tools"
	"techrisk-dashboard/dingtalk-agent/internal/watch"
)

const (
	// watchTick is the sweep cadence. The sweep is ONE updated_since request
	// per tick regardless of watch count (1 req/min total).
	watchTick = 60 * time.Second
	// watchWatermarkSlack re-scans the tail of the previous window so a row
	// updated just after the last sweep isn't missed; firing is deduped by
	// the stored LastStatus, so re-scanning the tail is safe.
	watchWatermarkSlack = 5 * time.Second
	// sweepLimit rows per sweep — 100 edits/min would be a dire day.
	sweepLimit = 100
)

const watchListHeader = "[Active watches in this chat:"

// applyWatches binds the watch requests parked by THIS turn's tool calls to
// the real sender/conversation, verifies each incident via the dashboard API,
// and saves the watch. Same contract as applySchedules — the LLM never
// supplies a conversation id.
func (b *Bot) applyWatches(m *chatbot.BotCallbackDataModel, history []llm.ChatMessage, reply string) string {
	if b.loop == nil || b.loop.Registry().WatchPending() == nil {
		return reply
	}
	pending := b.loop.Registry().WatchPending()

	var notes []string
	for _, msg := range history {
		if msg.Role != "tool" {
			continue
		}
		for _, mm := range tools.WatchTicket.FindAllStringSubmatch(msg.Content, -1) {
			req, ok := pending.Claim(mm[1]) // earlier turns' tokens are already claimed
			if !ok {
				continue
			}
			notes = append(notes, b.bindWatch(m, req))
		}
	}
	if len(notes) == 0 {
		return reply
	}
	return reply + "\n\n" + strings.Join(notes, "\n")
}

// bindWatch resolves the incident (title + current status) via the dashboard
// API and saves the watch; cancels are conversation-scoped. Returns the
// user-visible note.
func (b *Bot) bindWatch(m *chatbot.BotCallbackDataModel, req watch.Request) string {
	if req.CancelID > 0 {
		it, err := b.watches.Cancel(req.CancelID, m.ConversationId)
		if err != nil {
			return "⚠️ " + err.Error()
		}
		log.Printf("[watch] #%d cancelled by %s in %s", it.ID, m.SenderNick, m.ConversationId)
		return fmt.Sprintf("🗑️ Watch #%d on %s cancelled.", it.ID, it.IncidentNo)
	}
	dat, err := b.loop.Registry().Dashboard().GetIncidentByNo(context.Background(), req.No)
	if err != nil {
		return "⚠️ Could not load incident " + req.No + ": " + err.Error()
	}
	var inc struct {
		Title  string `json:"title"`
		Status string `json:"incident_status"`
	}
	if err := json.Unmarshal(dat, &inc); err != nil || inc.Title == "" {
		return "⚠️ Could not read incident " + req.No + "."
	}
	it, err := b.watches.Add(watch.Item{
		IncidentNo: req.No, Title: inc.Title, LastStatus: inc.Status,
		ConversationID: m.ConversationId, ConversationType: m.ConversationType,
		StaffID: m.SenderStaffId, Nick: m.SenderNick,
	})
	if err != nil {
		return "⚠️ " + err.Error()
	}
	log.Printf("[watch] saved %s by %s in %s", it.Describe(), m.SenderNick, m.ConversationId)
	return fmt.Sprintf("👁 Watching %s — status changes will be reported here (current status: %s).", it.IncidentNo, it.LastStatus)
}

// sweepRow is one row of the updated_since sweep response.
type sweepRow struct {
	No     string `json:"incident_no"`
	Status string `json:"incident_status"`
}

// sweepWatches runs ONE updated_since query and fires a task-mode agent turn
// for every watched incident whose status changed.
// ponytail: sweep misses sub-window A→B→A round-trips (60s window) — acceptable.
func (b *Bot) sweepWatches(ctx context.Context) {
	ws := b.watches.All()
	if len(ws) == 0 || b.loop == nil {
		return
	}
	watermark := time.Now().Add(-watchTick - watchWatermarkSlack)

	dash := b.loop.Registry().Dashboard()
	env, err := dash.Search(ctx, "/incidents?updated_since="+url.QueryEscape(watermark.UTC().Format(time.RFC3339))+"&limit="+strconv.Itoa(sweepLimit))
	if err != nil {
		log.Printf("[watch] sweep failed: %v", err)
		return
	}
	var rows []sweepRow
	if json.Unmarshal(env.Data, &rows) != nil {
		return
	}

	byNo := map[string]watch.Item{}
	for _, it := range ws {
		byNo[it.IncidentNo] = it
	}
	for _, row := range rows {
		it, ok := byNo[row.No]
		if !ok || row.Status == it.LastStatus || row.Status == "" {
			continue
		}
		old := it.LastStatus
		if err := b.watches.SetStatus(it.ID, row.Status); err != nil { // persist BEFORE firing: never refire
			log.Printf("[watch] #%d status persist failed: %v", it.ID, err)
			continue
		}
		go b.fireWatch(ctx, it, old, row.Status)
	}
}

// fireWatch runs a task-mode agent turn (fresh context, guarded output) and
// delivers the change report — same flow as a task-mode scheduled reminder.
func (b *Bot) fireWatch(ctx context.Context, it watch.Item, old, new string) {
	b.sem <- struct{}{}
	reply, _, _, err := b.loop.Run(ctx,
		fmt.Sprintf("[Watch #%d — incident %s status changed %s → %s — report which project and all relevant information about this incident now. Do not create or cancel reminders or watches.]",
			it.ID, it.IncidentNo, old, new),
		nil, "")
	b.loop.Registry().TakeNotionReads() // a watch turn must not leak reads into the next chat turn
	<-b.sem

	body := guardrail.FilterReplyCap(reply, b.cfg.MaxReplyChars)
	if err != nil || body == "" {
		log.Printf("[watch] #%d report failed: %v", it.ID, err)
		body = fmt.Sprintf("Status changed %s → %s — the report failed, ask me for details.", old, new)
	}
	header := fmt.Sprintf("👁 **Watch #%d** — %s status changed **%s → %s**", it.ID, it.IncidentNo, old, new)
	if err := b.deliverMarkdown(ctx, it.ConversationID, it.ConversationType, it.StaffID, "Risk Agent watch", header+"\n\n"+body); err != nil {
		log.Printf("[watch] #%d delivery failed: %v", it.ID, err)
		return
	}
	log.Printf("[watch] #%d fired: %s %s → %s", it.ID, it.IncidentNo, old, new)
}

// RunWatchPoller fires watch reports until ctx ends. Start it once from main.
func (b *Bot) RunWatchPoller(ctx context.Context) {
	t := time.NewTicker(watchTick)
	defer t.Stop()
	for {
		b.sweepWatches(ctx)
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}
