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
	"techrisk-dashboard/dingtalk-agent/internal/util"
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
	// maxSweepPages bounds one sweep's page-through (5000 rows) before the
	// window tail is dropped (logged) so the watermark can advance.
	maxSweepPages = 50
)

const watchListHeader = "[Active watches in this chat:"

// applyWatches binds the watch requests parked by THIS turn's tool calls to
// the real sender/conversation, verifies each incident via the dashboard API,
// and saves the watch. Same contract as applySchedules — the LLM never
// supplies a conversation id. (Ticket tokens are stripped from the reply too —
// the schedule twin always did this, watches forgot.)
func (b *Bot) applyWatches(m *chatbot.BotCallbackDataModel, history []llm.ChatMessage, reply string) string {
	if b.loop == nil || b.loop.Registry().WatchPending() == nil {
		return reply
	}
	return claimTickets(history, reply, tools.WatchTicket, b.loop.Registry().WatchPending(),
		func(req watch.Request) string { return b.bindWatch(m, req) })
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
// for every watched incident whose status changed. It reports whether the sweep
// COMPLETED (the poller advances its watermark only then — an outage, a late
// tick, or a truncated page must never lose changes for good).
// ponytail: sweep misses sub-window A→B→A round-trips (60s window) — acceptable.
func (b *Bot) sweepWatches(ctx context.Context, watermark time.Time) bool {
	ws := b.watches.All()
	if len(ws) == 0 || b.loop == nil {
		return true
	}

	dash := b.loop.Registry().Dashboard()
	// Two chats watching one incident BOTH fire — a single map keyed by
	// incident number let the later watch overwrite the earlier one.
	byNo := map[string][]watch.Item{}
	for _, it := range ws {
		byNo[it.IncidentNo] = append(byNo[it.IncidentNo], it)
	}

	// Page ascending by updated_at until a short page: a full first page used
	// to return "incomplete" forever — the same 100 persisted rows re-fetched
	// every tick, every watch past row 100 stalled for good.
	for offset := 0; ; offset += sweepLimit {
		env, err := dash.Search(ctx, "/incidents?updated_since="+url.QueryEscape(watermark.UTC().Format(time.RFC3339))+"&sort=updated_at&order=asc&limit="+strconv.Itoa(sweepLimit)+"&offset="+strconv.Itoa(offset))
		if err != nil {
			log.Printf("[watch] sweep failed: %v", err)
			return false
		}
		var rows []sweepRow
		if err := json.Unmarshal(env.Data, &rows); err != nil {
			log.Printf("[watch] sweep decode failed: %v", err)
			return false
		}

		b.fireSweepRows(ctx, byNo, rows)

		if len(rows) < sweepLimit {
			return true
		}
		if offset >= maxSweepPages*sweepLimit {
			// ponytail: >5000 changed rows in one window drops the tail (logged) —
			// statuses are persisted, so lastOK must still advance or the sweep stalls.
			log.Printf("[watch] sweep hit %d-page cap; tail of window skipped", maxSweepPages)
			return true
		}
	}
}

// fireSweepRows fires a task-mode agent turn for every watched incident whose
// status changed in this sweep page.
func (b *Bot) fireSweepRows(ctx context.Context, byNo map[string][]watch.Item, rows []sweepRow) {
	for _, row := range rows {
		for _, it := range byNo[row.No] {
			if row.Status == it.LastStatus || row.Status == "" {
				continue
			}
			old := it.LastStatus
			if err := b.watches.SetStatus(it.ID, row.Status); err != nil { // persist BEFORE firing: never refire
				log.Printf("[watch] #%d status persist failed: %v", it.ID, err)
				continue
			}
			// Acquire the agent slot BEFORE spawning (watch fires used to
			// release b.sem without ever acquiring it: the release either
			// hung forever on an empty sem or STOLE a live turn's slot —
			// audit round 4). A cancelled poller ctx gives up instead of
			// queueing one more turn behind Drain (audit round 5).
			if !b.acquire(ctx) {
				return
			}
			it, old, status := it, old, row.Status
			util.Go("watch-fire", func() {
				// A watch that just fired reports on a DETACHED ctx: the
				// status is already persisted, and a deploy's SIGTERM used
				// to cancel the turn mid-answer — notification lost. The
				// timeout bounds a hung loop.
				fireCtx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 360*time.Second)
				defer cancel()
				b.fireWatch(fireCtx, it, old, status)
			})
		}
	}
}

// fireWatch runs a task-mode agent turn (fresh context, guarded output) and
// delivers the change report — same flow as a task-mode scheduled reminder.
func (b *Bot) fireWatch(ctx context.Context, it watch.Item, old, new string) {
	defer func() { <-b.sem }() // no defer used to mean a panic leaked the slot forever
	// Same Subset as the task path (internalapi.go): reminder/watch tickets are
	// claimed by the chat turn handler only — a fire that creates one parks it
	// forever — and fireWatch's delivery is markdown-only, so generate_* tickets
	// would reach the chat raw instead of uploading. Family prompts drop
	// automatically with the tools.
	runLoop := b.loop.With(b.loop.Registry().Subset(func(name string) bool {
		return name != "schedule_reminder" && name != "watch_incident" && !strings.HasPrefix(name, "generate_")
	}))
	turnCtx := tools.WithNotionSink(ctx)
	reply, _, _, _, err := runLoop.Run(turnCtx,
		fmt.Sprintf("[Watch #%d — incident %s status changed %s → %s — report which project and all relevant information about this incident now. Do not create or cancel reminders or watches.]",
			it.ID, it.IncidentNo, old, new),
		nil, "")
	tools.TakeNotionReads(turnCtx) // a watch turn must not leak reads into the next chat turn

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
	// lastOK is what the next sweep scans from — advanced ONLY after a complete
	// sweep. The old fixed now-65s window lost changes across any outage or
	// late tick.
	lastOK := time.Now().Add(-watchTick - watchWatermarkSlack)
	for {
		if b.sweepWatches(ctx, lastOK) {
			lastOK = time.Now().Add(-watchWatermarkSlack)
		}
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}
