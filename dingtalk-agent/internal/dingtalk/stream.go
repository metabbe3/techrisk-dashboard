package dingtalk

import (
	"context"
	"fmt"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"

	"github.com/open-dingtalk/dingtalk-stream-sdk-go/chatbot"

	"techrisk-dashboard/dingtalk-agent/internal/agent"
	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/guardrail"
	"techrisk-dashboard/dingtalk-agent/internal/ingest"
	"techrisk-dashboard/dingtalk-agent/internal/kb"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/memory"
	"techrisk-dashboard/dingtalk-agent/internal/schedule"
	"techrisk-dashboard/dingtalk-agent/internal/tools"
	"techrisk-dashboard/dingtalk-agent/internal/util"
	"techrisk-dashboard/dingtalk-agent/internal/watch"
)

const (
	// MaxConcurrentAgents bounds in-flight agent loops (each holds an LLM conversation).
	MaxConcurrentAgents = 4
	// historyKeepMsgs mirrors upstream: keep the last N messages per conversation.
	historyKeepMsgs = 12
	// seenCap bounds the msgId dedup map before a purge pass.
	seenCap = 4096
	// Message batching: rapid consecutive messages join into one agent turn.
	// A batch closes after batchQuietWait with no new message, or at batchMaxWait.
	batchQuietWait = 800 * time.Millisecond
	batchMaxWait   = 2500 * time.Millisecond
	inboxCap       = 64
)

// Bot bridges DingTalk stream messages to the agent loop.
type Bot struct {
	cfg      *config.Config
	loop     *agent.Loop
	replier  *chatbot.ChatbotReplier
	allowed  map[string]struct{}
	api      *openAPIClient
	llm      *llm.Client   // no-tools client for profile extraction
	store    *memory.Store // nil = memory-only (tests)
	schedAPI *tools.ScheduleAPI
	watches  *watch.Store
	know     *kb.Index // RAG index over uploaded documents; nil when KB tools off

	mu      sync.Mutex
	history map[string]historyEntry

	seenMu sync.Mutex
	seen   map[string]time.Time

	inboxMu sync.Mutex
	inboxes map[string]chan chatTask // per-conversation batching workers

	sem chan struct{}
}

// chatTask is one inbound message queued for a conversation worker. file/pic
// are non-nil for an uploaded document or picture — they run alone, not
// batched with text.
type chatTask struct {
	m    *chatbot.BotCallbackDataModel
	text string
	file *fileMessage
	pic  *pictureMessage
}

type historyEntry struct {
	messages []llm.ChatMessage
	lastSeen time.Time
}

// NewBot builds a DingTalk bot around the agent loop. When cfg.DataDir is set
// the bot auto-recalls persisted history at boot and auto-saves users + history.
func NewBot(cfg *config.Config, loop *agent.Loop) *Bot {
	allowed := make(map[string]struct{}, len(cfg.DingTalkAllowlist))
	for _, id := range cfg.DingTalkAllowlist {
		allowed[id] = struct{}{}
	}
	b := &Bot{
		cfg:      cfg,
		loop:     loop,
		replier:  chatbot.NewChatbotReplier(),
		allowed:  allowed,
		api:      newOpenAPIClient(cfg),
		llm:      llm.NewClient(cfg),
		history:  make(map[string]historyEntry),
		seen:     make(map[string]time.Time),
		inboxes:  make(map[string]chan chatTask),
		sem:      make(chan struct{}, MaxConcurrentAgents),
		schedAPI: tools.NewScheduleAPI(cfg),   // dashboard agent-schedules API (10s client)
		watches:  watch.NewStore(cfg.DataDir), // "" = memory-only
	}
	if cfg.DataDir != "" {
		b.store = memory.NewStore(cfg.DataDir)
		for id, e := range b.store.All() { // auto recall
			b.history[id] = historyEntry{messages: e.Messages, lastSeen: e.LastSeen}
		}
		if loop != nil {
			loop.SystemExtras = b.knowledgePrompt // inject remembered docs
		}
	}
	// RAG knowledge base over chat-uploaded documents (SOPs etc.). The index
	// also backs picture filing — it exists whenever KB tools are on, loop or
	// not (a picture turn needs no agent loop).
	if cfg.KBToolsEnabled {
		b.know = kb.NewIndex(cfg.DataDir, cfg.EmbeddingModel, b.llm)
		if loop != nil {
			loop.Registry().RegisterKnowledgeTools(b.know)
		}
	}
	return b
}

// knowledgePrompt renders remembered document summaries for the system prompt.
func (b *Bot) knowledgePrompt() string {
	if b.store == nil {
		return ""
	}
	var sb strings.Builder
	for _, e := range b.store.Knowledge() {
		summary := util.Cut(e.Summary, 600)
		if summary != e.Summary {
			summary += "…"
		}
		fmt.Fprintf(&sb, "- [%s] %s — %s\n", e.Time.In(schedule.WIB).Format("2006-01-02"), e.File, summary)
	}
	return sb.String()
}

// Handle implements chatbot.IChatBotMessageHandler: it acks every frame and
// processes allowed messages asynchronously.
func (b *Bot) Handle(ctx context.Context, m *chatbot.BotCallbackDataModel) ([]byte, error) {
	if !b.dedup(m.MsgId) {
		return nil, nil
	}

	if !b.isAllowed(m.SenderStaffId) {
		log.Printf("[dingtalk] denied sender staffId=%s nick=%q conversation=%s (not in allowlist)",
			m.SenderStaffId, m.SenderNick, m.ConversationId)
		return nil, nil
	}

	text := StripMention(strings.TrimSpace(m.Text.Content), b.cfg.DingTalkBotNames)

	// Hard input gate: injection / off-topic asks are refused here, in Go,
	// before any LLM call is spent.
	if refusal := guardrail.InputGate(text); refusal != "" {
		log.Printf("[dingtalk] input gate refused message from %s (%s): %q",
			m.SenderNick, m.SenderStaffId, truncateStr(text, 120))
		b.reply(ctx, m, refusal)
		return nil, nil
	}

	// File uploads: download → extract text → summarize/knowledge → delete file.
	// Only real file messages — pictures/audio also carry downloadCode but are
	// not documents. Routed through the SAME per-conversation worker as text so
	// it can never race takeHistory/saveHistory for that chat.
	if m.Msgtype == "file" {
		if fm, ok := parseFileMessage(m.Content); ok {
			log.Printf("[dingtalk] file from %s (%s) in conversation %s: %s",
				m.SenderNick, m.SenderStaffId, m.ConversationId, fm.FileName)
			b.reply(ctx, m, fmt.Sprintf("📥 Got **%s** — reading it now…", fm.FileName))
			b.enqueue(m, text, &fm, nil)
			return nil, nil
		}
	}

	// Pictures: download → vision transcription → filed into the RAG KB under
	// "Picture from <nick> — <ts>". KB-gated: with AGENT_KB_TOOLS off, pictures
	// stay ignored exactly as before this feature. Also runs alone (own turn).
	if m.Msgtype == "picture" && b.know != nil {
		if pm, ok := parsePictureMessage(m.Content); ok {
			log.Printf("[dingtalk] picture from %s (%s) in conversation %s",
				m.SenderNick, m.SenderStaffId, m.ConversationId)
			b.reply(ctx, m, "🖼️ Got your picture — reading it…")
			b.enqueue(m, text, nil, &pm)
			return nil, nil
		}
	}

	if text == "" {
		return nil, nil
	}

	log.Printf("[dingtalk] message from %s (%s) in conversation %s: %s",
		m.SenderNick, m.SenderStaffId, m.ConversationId, text)

	b.enqueue(m, text, nil, nil)

	return nil, nil // ack the frame; the reply travels over the session webhook
}

// enqueue hands a message to its conversation's batching worker, creating the
// worker on first contact. Never blocks: an overflowing inbox is refused with
// a webhook apology instead of stalling the SDK's frame reader.
func (b *Bot) enqueue(m *chatbot.BotCallbackDataModel, text string, file *fileMessage, pic *pictureMessage) {
	b.inboxMu.Lock()
	ch, ok := b.inboxes[m.ConversationId]
	if !ok {
		ch = make(chan chatTask, inboxCap)
		b.inboxes[m.ConversationId] = ch
		go b.conversationWorker(ch)
	}
	b.inboxMu.Unlock()

	select {
	case ch <- chatTask{m: m, text: text, file: file, pic: pic}:
	default:
		b.reply(context.Background(), m, "I'm backed up with queued messages — please retry in a moment.")
	}
}

// conversationWorker is the single consumer for one conversation: it batches
// rapid messages FROM ONE SENDER into one agent turn, answers, then takes the
// next. In a group, another member's message closes the batch and becomes its
// own turn — two people's questions are never merged (prod 2026-11-11).
func (b *Bot) conversationWorker(ch chan chatTask) {
	var carry *chatTask // a different sender's message that closed the last batch
	for {
		var first chatTask
		if carry != nil {
			first, carry = *carry, nil
		} else {
			first = <-ch // inboxes are never closed
		}
		batch := []chatTask{first}

		// Debounce: close the batch after a quiet gap, or a hard deadline.
		quiet := time.NewTimer(batchQuietWait)
		hardStop := time.NewTimer(batchMaxWait)
	collect:
		for {
			select {
			case t := <-ch:
				// Batching is keyed on BOTH sender ids — either differing means
				// a different person (SenderStaffId is empty for external
				// users, SenderId for test payloads). A file upload or picture
				// always runs as its own turn.
				if t.m.SenderId != first.m.SenderId || t.m.SenderStaffId != first.m.SenderStaffId || t.file != nil || first.file != nil || t.pic != nil || first.pic != nil {
					carry = &t
					break collect
				}
				batch = append(batch, t)
				quiet.Reset(batchQuietWait)
			case <-quiet.C:
				break collect
			case <-hardStop.C:
				break collect
			}
		}
		quiet.Stop()
		hardStop.Stop()

		b.runBatch(batch)
	}
}

// runBatch turns a batch (one sender) into one agent call. The LAST message's
// callback model is used for replies (freshest session webhook).
func (b *Bot) runBatch(batch []chatTask) {
	m := batch[len(batch)-1].m

	// A file upload runs alone through the same concurrency gate.
	if len(batch) == 1 && batch[0].file != nil {
		b.sem <- struct{}{}
		defer func() { <-b.sem }()
		b.processFile(context.Background(), m, *batch[0].file, batch[0].text)
		return
	}

	// A picture runs alone through the same gate: transcribe → file into the KB.
	if len(batch) == 1 && batch[0].pic != nil {
		b.sem <- struct{}{}
		defer func() { <-b.sem }()
		b.processPicture(context.Background(), m, *batch[0].pic, batch[0].text)
		return
	}

	var sb strings.Builder
	for i, t := range batch {
		if i > 0 {
			sb.WriteString("\n\n")
		}
		sb.WriteString(t.text)
	}
	text := sb.String()
	if len(batch) > 1 {
		text = fmt.Sprintf("[The user sent %d messages in quick succession — treat them as ONE combined request.]\n\n%s", len(batch), text)
	}
	if m.ConversationType == "2" { // group: shared history must say who asked what
		text = fmt.Sprintf("[From %s]\n%s", m.SenderNick, text)
	}
	if b.schedAPI != nil {
		items, err := b.schedAPI.List(context.Background(), m.ConversationId)
		switch {
		case err != nil: // degrade: a dead dashboard must not fail the turn
			log.Printf("[schedule] list failed for %s (%v) — skipping reminder context", m.ConversationId, err)
		case len(items) > 0:
			var rs strings.Builder
			for _, it := range items {
				rs.WriteString("\n- " + it.Describe())
			}
			text += "\n\n[Active reminders in this chat:" + rs.String() + "]"
		}
	}
	if ws := b.watches.List(m.ConversationId); len(ws) > 0 {
		var rs strings.Builder
		for _, it := range ws {
			rs.WriteString("\n- " + it.Describe())
		}
		text += "\n\n" + watchListHeader + rs.String() + "]"
	}

	ack := "👌 On it — working on that…"
	if len(batch) > 1 {
		ack = fmt.Sprintf("👌 Got %d messages — working on them together…", len(batch))
	}

	b.sem <- struct{}{}
	defer func() { <-b.sem }()
	// One sink per turn: concurrent turns no longer share the Notion-reads buffer.
	b.process(tools.WithNotionSink(context.Background()), m, text, ack)
}

// processFile handles a chat-uploaded document: download, extract text,
// summarize via the agent, remember the summary — then ALWAYS delete the file.
func (b *Bot) processFile(ctx context.Context, m *chatbot.BotCallbackDataModel, fm fileMessage, caption string) {
	path, err := b.downloadMessageFile(ctx, fm, filepath.Join(b.dataDir(), "tmp"))
	defer func() {
		if path != "" {
			_ = os.Remove(path) // delete after done, success or failure
		}
	}()
	if err != nil {
		log.Printf("[dingtalk] file download failed: %v", err)
		b.reply(ctx, m, fmt.Sprintf("Sorry, I couldn't retrieve **%s** (%v).", fm.FileName, err))
		return
	}

	raw, err := os.ReadFile(path)
	if err != nil {
		b.reply(ctx, m, "Sorry, I couldn't read that file.")
		return
	}
	text, err := ingest.ExtractText(fm.FileName, raw)
	if err != nil {
		b.reply(ctx, m, fmt.Sprintf("I couldn't process **%s**: %v", fm.FileName, err))
		return
	}
	if strings.TrimSpace(text) == "" {
		// Empty text also covers empty txt/docx. Scanned PDFs are out of scope
		// (owner decision) — point the user at the picture path that DOES work.
		b.reply(ctx, m, fmt.Sprintf("I couldn't read any text from **%s** — it looks like a scanned or image-only file. Send the pages as pictures (🖼️) instead and I'll read those.", fm.FileName))
		return
	}

	content := fmt.Sprintf("[The user attached the document %q. Its text follows.]\n\n%s\n\n[End of document. User instruction: %s]",
		fm.FileName, ingest.Truncate(text), orDefault(caption, "Summarize this document."))
	reply := b.process(ctx, m, content, "") // file path already acked at Handle time

	if b.store != nil && reply != "" {
		if err := b.store.AppendKnowledge(memory.KnowledgeEntry{
			File: fm.FileName, Author: m.SenderNick, Summary: truncateStr(reply, 2000),
		}); err != nil {
			log.Printf("[dingtalk] persist knowledge failed: %v", err)
		}
	}
	// RAG ingest runs in the background; embedding failures only log — the
	// summary above still answered the user.
	if b.know != nil {
		b.know.Ingest(fm.FileName, text)
	}
}

// processPicture handles a chat-sent picture: fetch it, transcribe it via the
// vision model, and file the transcription into the RAG KB under
// "Picture from <nick> — <ts>". No agent turn — replies are webhook-only.
func (b *Bot) processPicture(ctx context.Context, m *chatbot.BotCallbackDataModel, pm pictureMessage, caption string) {
	dlURL, err := b.pictureDownloadURL(ctx, pm)
	if err != nil {
		log.Printf("[dingtalk] picture url failed (conversation %s): %v", m.ConversationId, err)
		b.reply(ctx, m, "Sorry, I couldn't read that picture — I couldn't fetch it from DingTalk.")
		return
	}
	path, err := b.downloadTo(ctx, dlURL, "picture", filepath.Join(b.dataDir(), "tmp"))
	if err != nil {
		log.Printf("[dingtalk] picture download failed (conversation %s): %v", m.ConversationId, err)
		b.reply(ctx, m, "Sorry, I couldn't read that picture — I couldn't fetch it from DingTalk.")
		return
	}
	raw, err := os.ReadFile(path)
	_ = os.Remove(path)
	if err != nil {
		b.reply(ctx, m, "Sorry, I couldn't read that picture.")
		return
	}
	mime := http.DetectContentType(raw)
	if !strings.HasPrefix(mime, "image/") {
		b.reply(ctx, m, "That upload isn't a picture I can read — send it as a **file** if it's a document.")
		return
	}
	b.processPictureData(ctx, m, raw, mime, caption)
}

// processPictureData is the bytes core of processPicture: the test hands bytes
// in directly; the resolve+download half lives in processPicture.
func (b *Bot) processPictureData(ctx context.Context, m *chatbot.BotCallbackDataModel, raw []byte, mimeType, caption string) {
	if b.know == nil {
		return
	}
	transcription, err := b.llm.Vision(ctx, visionPrompt(caption), raw, mimeType)
	if err != nil || strings.TrimSpace(transcription) == "" {
		log.Printf("[dingtalk] picture transcription failed (conversation %s): %v", m.ConversationId, err)
		b.reply(ctx, m, "Sorry, I couldn't read that picture — try a clearer image or paste the text instead.")
		return
	}
	// Unique title: Save replaces by title hash, so two same-minute pictures
	// from one sender would silently overwrite each other without the seconds
	// + msgId tail.
	title := fmt.Sprintf("Picture from %s — %s (%s)", m.SenderNick, time.Now().In(schedule.WIB).Format("2006-01-02 15:04:05"), util.Cut(m.MsgId, 8))
	if err := b.know.Save(ctx, title, transcription); err != nil {
		b.reply(ctx, m, fmt.Sprintf("I read the picture but couldn't file it: %v", err))
		return
	}
	b.reply(ctx, m, fmt.Sprintf("🖼️ Read the picture and filed it in the knowledge base as **%s**.\n\n> %s", title, truncateStr(transcription, 300)))
}

// visionPrompt builds the image-transcription instruction; the user caption is
// appended when present ("what's wrong in this screenshot?" works alongside
// the plain transcription).
func visionPrompt(caption string) string {
	p := "Transcribe everything in this image: all visible text (exact wording), table contents, and a one-line description of any diagram. Output only the transcription."
	if c := strings.TrimSpace(caption); c != "" {
		p += "\n\nThe user sent it with this message: " + c
	}
	return p
}

// process runs one agent turn and returns the guarded reply text ("" on failure).
// ack, when non-empty, is webhoked as an instant receipt on the fallback path
// (when the AI card is unavailable, nothing else signals "task accepted").
func (b *Bot) process(ctx context.Context, m *chatbot.BotCallbackDataModel, text, ack string) string {
	if b.store != nil { // auto save who chats
		if err := b.store.AppendUser(memory.UserRecord{
			StaffID: m.SenderStaffId, Nick: m.SenderNick,
			ConversationID: m.ConversationId, ConversationType: m.ConversationType,
		}); err != nil {
			log.Printf("[dingtalk] persist user failed: %v", err)
		}
	}

	sysExtra := b.profilePrompt(m.SenderStaffId)

	// Preferred path: a streaming AI card (typewriter). If the card cannot be
	// created (missing card permission, API hiccup) fall back to a one-shot
	// webhook reply — the answer still arrives, just without streaming.
	title := "Risk Agent"
	card, err := newAICard(ctx, b.api, m, title)
	if err != nil {
		log.Printf("[dingtalk] AI card unavailable, falling back to webhook reply: %v", err)
		if ack != "" {
			b.reply(ctx, m, ack)
		}
		return b.processPlain(ctx, m, text, sysExtra)
	}

	filtered, err := b.processStreaming(ctx, m, text, sysExtra, card)
	if err != nil {
		log.Printf("[dingtalk] agent loop error (conversation %s): %v", m.ConversationId, err)
		// One failed card state — fail+finish used to overwrite the failure
		// with flowStatus=3, so the card LOOKED finished.
		_ = card.failWith(ctx, "Sorry, that request failed. Please try again in a moment.")
		b.learnNotion(ctx, m) // reads made before the failure drain here — else the NEXT turn's sender inherits them
		return ""
	}
	filtered = b.deliverGenerated(ctx, m, filtered)
	if err := card.finish(ctx, filtered); err != nil {
		log.Printf("[dingtalk] card finish failed (conversation %s): %v", m.ConversationId, err)
	}
	// Whole-group history is everyone's messages — learning from it would pin
	// other people's style onto one sender's profile.
	if m.ConversationType != "2" {
		b.learnLater(m)
	}
	b.learnNotion(ctx, m)
	return filtered
}

// dataDir resolves the agent's data directory (config always sets it at boot;
// tests may leave it empty → cwd-relative "data").
func (b *Bot) dataDir() string {
	if b.cfg.DataDir != "" {
		return b.cfg.DataDir
	}
	return "data"
}

// profilePrompt renders the sender's learned preferences for the system prompt.
func (b *Bot) profilePrompt(staffID string) string {
	if b.store == nil || !b.cfg.AutoLearn {
		return ""
	}
	lines := b.store.Profile(staffID)
	if len(lines) == 0 {
		return ""
	}
	var sb strings.Builder
	sb.WriteString("## About the user you are replying to (learned preferences — apply them)\n")
	for _, ln := range lines {
		fmt.Fprintf(&sb, "- %s\n", ln)
	}
	return sb.String()
}

// learnLater updates the sender's learned profile in the background after a
// completed turn — never blocks the reply path.
func (b *Bot) learnLater(m *chatbot.BotCallbackDataModel) {
	if b.store == nil || !b.cfg.AutoLearn || b.llm == nil {
		return
	}
	history := b.takeHistory(m.ConversationId)
	if len(history) == 0 {
		return
	}
	go b.learnProfile(context.Background(), m.SenderStaffId, m.SenderNick, history)
}

// learnProfile asks the LLM to merge what the recent conversation reveals about
// the user (language, format, standing instructions) into their stored profile.
// One cheap no-tools call; any failure is logged and skipped — learning must
// never break chatting.
func (b *Bot) learnProfile(ctx context.Context, staffID, nick string, history []llm.ChatMessage) {
	var cur strings.Builder
	for _, ln := range b.store.Profile(staffID) {
		fmt.Fprintf(&cur, "- %s\n", ln)
	}
	if cur.Len() == 0 {
		cur.WriteString("(none yet)")
	}

	var convo strings.Builder
	start := len(history) - 8
	if start < 0 {
		start = 0
	}
	for _, msg := range history[start:] {
		if msg.Role != "user" && msg.Role != "assistant" {
			continue
		}
		fmt.Fprintf(&convo, "%s: %s\n", msg.Role, truncateStr(msg.Content, 400))
	}
	if convo.Len() == 0 {
		return
	}

	msgs := []llm.ChatMessage{
		{Role: "system", Content: "You maintain a compact preference profile of a DingTalk user for an assistant. Merge what the conversation reveals (preferred language, reply format, seniority, recurring topics, standing instructions like 'always reply in Indonesian') into the existing profile. Output ONLY the updated profile as at most 8 short lines (max 150 chars each), no numbering, no commentary. Drop lines that no longer apply. If nothing new, output the existing lines unchanged."},
		{Role: "user", Content: "EXISTING PROFILE:\n" + cur.String() + "\nRECENT CONVERSATION with user " + nick + ":\n" + convo.String()},
	}
	reply, _, err := b.llm.ChatCompletion(ctx, msgs, nil)
	if err != nil {
		log.Printf("[dingtalk] profile learning skipped: %v", err)
		return
	}
	var lines []string
	for _, ln := range strings.Split(reply.Content, "\n") {
		ln = strings.TrimSpace(strings.TrimPrefix(strings.TrimSpace(ln), "-"))
		if ln != "" {
			lines = append(lines, ln)
		}
	}
	if err := b.store.LearnProfile(staffID, nick, lines); err != nil {
		log.Printf("[dingtalk] persist profile failed: %v", err)
	} else {
		log.Printf("[dingtalk] learned profile for %s (%d lines)", nick, len(lines))
	}
}

// learnNotion drains the Notion pages read this turn and summarizes them into
// knowledge memory in the background — the same never-blocks contract as learnLater.
func (b *Bot) learnNotion(ctx context.Context, m *chatbot.BotCallbackDataModel) {
	if b.store == nil || b.llm == nil || b.loop == nil {
		return
	}
	reads := tools.TakeNotionReads(ctx)
	if len(reads) == 0 {
		return
	}
	go b.summarizeNotionReads(context.Background(), m.SenderNick, reads)
}

// summarizeNotionReads asks the LLM for a compact per-page summary and appends
// it to knowledge.jsonl (flows into the last-8 knowledge prompt automatically).
// Errors are logged and skipped — learning must never break chatting.
func (b *Bot) summarizeNotionReads(ctx context.Context, nick string, reads []tools.NotionRead) {
	if len(reads) > 6 {
		reads = reads[:6]
	}
	var pages strings.Builder
	for _, r := range reads {
		fmt.Fprintf(&pages, "PAGE %s (%s)\n%s\n\n", r.Title, r.URL, r.Excerpt)
	}

	msgs := []llm.ChatMessage{
		{Role: "system", Content: "You maintain the assistant's document memory. Summarize the Notion pages below in at most 6 short lines total — per page: name + 1-2 key facts (decisions, risks, requirements). Output ONLY those lines."},
		{Role: "user", Content: pages.String()},
	}
	reply, _, err := b.llm.ChatCompletion(ctx, msgs, nil)
	if err != nil {
		log.Printf("[dingtalk] notion learning skipped: %v", err)
		return
	}
	file := "Notion: " + reads[0].Title
	if len(reads) > 1 {
		file = fmt.Sprintf("%s (+%d more)", file, len(reads)-1)
	}
	if err := b.store.AppendKnowledge(memory.KnowledgeEntry{
		File: file, Author: nick, Summary: truncateStr(reply.Content, 2000),
	}); err != nil {
		log.Printf("[dingtalk] persist knowledge failed: %v", err)
	}
}

// processStreaming runs the agent loop, streaming answer deltas into the card
// (throttled — one API push per flushInterval, not one per token).
func (b *Bot) processStreaming(ctx context.Context, m *chatbot.BotCallbackDataModel, text, sysExtra string, card *aiCard) (string, error) {
	writer := newCardWriter(card)
	started := time.Now()
	reply, newHistory, usage, err := b.loop.RunStreaming(ctx, text, b.takeHistory(m.ConversationId), writer.onDelta, sysExtra)
	if err != nil {
		b.reportTurn(m, text, "", usage, time.Since(started), err.Error())
		return "", err
	}

	// card.finish pushes the final frame itself — an extra flush here rewrote
	// the same content.
	return b.finalize(m, text, reply, newHistory, usage, started), nil
}

func (b *Bot) processPlain(ctx context.Context, m *chatbot.BotCallbackDataModel, text, sysExtra string) string {
	started := time.Now()
	defer func() {
		if r := recover(); r != nil {
			log.Printf("[dingtalk] panic in agent loop: %v", r)
			b.reply(ctx, m, "Sorry, something went wrong while processing that request.")
			b.learnNotion(ctx, m) // same leak as the error branch: a panic mid-turn must not strand its reads
			b.reportTurn(m, text, "", llm.Usage{}, time.Since(started), fmt.Sprintf("panic: %v", r))
		}
	}()

	reply, newHistory, usage, err := b.loop.Run(ctx, text, b.takeHistory(m.ConversationId), sysExtra)
	if err != nil {
		log.Printf("[dingtalk] agent loop error (conversation %s): %v", m.ConversationId, err)
		b.reply(ctx, m, "Sorry, that request failed. Please try again in a moment.")
		b.learnNotion(ctx, m) // reads made before the failure drain here — else the NEXT turn's sender inherits them
		b.reportTurn(m, text, "", usage, time.Since(started), err.Error())
		return ""
	}

	filtered := b.finalize(m, text, reply, newHistory, usage, started)
	filtered = b.deliverGenerated(ctx, m, filtered)
	b.reply(ctx, m, filtered)
	if m.ConversationType != "2" {
		b.learnLater(m)
	}
	b.learnNotion(ctx, m)
	return filtered
}

// finalize is the shared tail of a successful turn: persist history, guard the
// reply, bind parked reminder/watch tickets, report the turn.
func (b *Bot) finalize(m *chatbot.BotCallbackDataModel, text, reply string, newHistory []llm.ChatMessage, usage llm.Usage, started time.Time) string {
	b.saveHistory(m.ConversationId, newHistory)
	filtered := guardrail.FilterReplyCap(reply, b.cfg.MaxReplyChars)
	if filtered == "" {
		filtered = "Sorry, I could not produce an answer for that."
	}
	filtered = b.applySchedules(m, newHistory, filtered)
	filtered = b.applyWatches(m, newHistory, filtered)
	b.reportTurn(m, text, filtered, usage, time.Since(started), "")
	return filtered
}

// reportTurn fires the async dashboard turn report for a finished chat turn —
// best-effort, never blocks the reply. Called on both the success and the
// failure path so Agent Runs shows failures too.
func (b *Bot) reportTurn(m *chatbot.BotCallbackDataModel, userText, assistantText string, usage llm.Usage, elapsed time.Duration, errMsg string) {
	if !b.cfg.AgentTurnReports {
		return
	}
	tools.ReportTurnAsync(b.cfg, tools.TurnReport{
		ConversationID:   m.ConversationId,
		ConversationType: m.ConversationType,
		StaffID:          m.SenderStaffId,
		Nick:             m.SenderNick,
		UserText:         truncateStr(userText, 2000),
		AssistantText:    truncateStr(assistantText, 8000),
		Model:            b.llm.Model(),
		Usage:            usage,
		ResponseTimeMs:   elapsed.Milliseconds(),
		Error:            errMsg,
	})
}

// reGeneratedFile matches the deliverable tickets the generate tools embed in
// tool results (the LLM is instructed to copy them verbatim into its reply).
var reGeneratedFile = regexp.MustCompile(`\[generated-file: ([^\]\n]+)\]`)

// deliverGenerated ships every [generated-file: …] ticket in the reply as a
// downloadable attachment. Flexible by conversation type: a group ask tries
// the group first (file appears where the question was asked) and falls back
// to the sender's DM when the robot lacks the group-send capability; a DM ask
// delivers to the DM. Upload/DM failure downgrades the note to a saved-path
// line — it never fails the turn.
func (b *Bot) deliverGenerated(ctx context.Context, m *chatbot.BotCallbackDataModel, reply string) string {
	for _, mm := range reGeneratedFile.FindAllStringSubmatch(reply, -1) {
		rel := strings.TrimSpace(mm[1])
		note := "📎 " + filepath.Base(rel) + " — saved at `" + rel + "` (DM delivery failed, see server log)"
		if path, ok := b.safeExportPath(rel); ok {
			mediaID, err := b.api.uploadRobotMedia(ctx, path)
			if err != nil {
				log.Printf("[dingtalk] media upload failed for %s: %v", rel, err)
			} else if m.ConversationType != "2" { // p2p chat (or unknown): DM the sender
				if err := SendFileDM(ctx, b.cfg, m.SenderStaffId, mediaID, filepath.Base(path)); err != nil {
					log.Printf("[dingtalk] file DM failed for %s: %v", rel, err)
				} else {
					note = "📎 **" + filepath.Base(path) + "** — sent to your DM ✓"
				}
			} else if err := SendFileGroup(ctx, b.cfg, m.ConversationId, mediaID, filepath.Base(path)); err != nil {
				log.Printf("[dingtalk] group file send failed for %s: %v — falling back to DM", rel, err)
				if err := SendFileDM(ctx, b.cfg, m.SenderStaffId, mediaID, filepath.Base(path)); err != nil {
					log.Printf("[dingtalk] file DM failed for %s: %v", rel, err)
				} else {
					note = "📎 **" + filepath.Base(path) + "** — group send failed, sent to your DM ✓"
				}
			} else {
				note = "📎 **" + filepath.Base(path) + "** — sent to the group ✓"
			}
		} else {
			log.Printf("[dingtalk] generated-file ticket rejected (missing or outside exports): %q", rel)
		}
		reply = strings.Replace(reply, mm[0], note, 1)
	}
	return reply
}

// safeExportPath resolves a generated-file ticket to a real file under
// DataDir/exports — the ticket text comes from LLM output, so traversal
// outside the exports dir is refused.
func (b *Bot) safeExportPath(rel string) (string, bool) {
	dir := b.dataDir()
	clean := filepath.Clean("/" + rel) // absolute-rooted: kills any ".." climb
	path := filepath.Join(dir, clean)
	st, err := os.Stat(path)
	if err != nil || st.IsDir() {
		return "", false
	}
	return path, true
}

func orDefault(s, def string) string {
	if strings.TrimSpace(s) == "" {
		return def
	}
	return s
}

func truncateStr(s string, max int) string {
	t := util.Cut(s, max)
	if t != s {
		return t + "…"
	}
	return t
}

// cardWriter batches LLM deltas into at most one card update per flushInterval
// and applies secret redaction + the hard scope gate to every pushed frame.
type cardWriter struct {
	card      *aiCard
	mu        sync.Mutex
	content   string
	lastFlush time.Time
	dirty     bool
	// override latches the refusal once the hard gate fires: a violating
	// sentence can never stream to the card, and every later frame shows the
	// refusal instead.
	override string
}

const cardFlushInterval = 400 * time.Millisecond

func newCardWriter(card *aiCard) *cardWriter {
	return &cardWriter{card: card}
}

func (w *cardWriter) onDelta(delta string) {
	w.mu.Lock()
	w.content += delta
	w.dirty = true // a delta arrived — the next flush must push (the missing line that kept streaming dead)
	due := time.Since(w.lastFlush) >= cardFlushInterval
	w.mu.Unlock()
	if due {
		w.flush(context.Background(), "")
	}
}

// flush pushes content (or the accumulated content when empty) to the card.
func (w *cardWriter) flush(ctx context.Context, content string) {
	w.mu.Lock()
	if w.override != "" {
		// Gate already fired this turn: only the refusal may be shown.
		push := w.override
		w.dirty = false
		w.mu.Unlock()
		_ = w.card.stream(ctx, push)
		return
	}
	if content != "" {
		w.content = content
	}
	if !w.dirty && content == "" {
		w.mu.Unlock()
		return
	}
	push := w.content
	// Hard scope gate on the streaming frame too — a violation replaces the
	// frame and latches for the rest of the turn.
	if bad := guardrail.HardGuardReply(push); bad != "" {
		w.override = bad
		w.dirty = false
		w.mu.Unlock()
		_ = w.card.stream(ctx, bad)
		return
	}
	w.dirty = push != ""
	w.lastFlush = time.Now()
	w.mu.Unlock()

	redacted, _ := guardrail.Redact(push)
	if err := w.card.stream(ctx, redacted); err != nil {
		log.Printf("[dingtalk] card stream update failed: %v", err)
	}
}

func (b *Bot) reply(ctx context.Context, m *chatbot.BotCallbackDataModel, text string) {
	title := "Risk Agent"
	if t := util.Cut(text, 40); t != text {
		title = t
	}
	if err := b.replier.SimpleReplyMarkdown(ctx, m.SessionWebhook, []byte(title), []byte(text)); err != nil {
		// DingTalk markdown is a subset — fall back to plain text when it rejects the payload.
		log.Printf("[dingtalk] markdown reply failed (%v), falling back to plain text", err)
		if err := b.replier.SimpleReplyText(ctx, m.SessionWebhook, []byte(plainText(text))); err != nil {
			log.Printf("[dingtalk] reply failed for conversation %s: %v", m.ConversationId, err)
		}
	}
}

// isAllowed gates senders. Access control is primarily the DingTalk app's
// visibility range (owner-configured): an EMPTY allowlist lets everyone who
// can reach the bot chat with it; a non-empty allowlist narrows further.
func (b *Bot) isAllowed(staffID string) bool {
	if len(b.allowed) == 0 {
		return true // rely on the DingTalk app visibility range
	}
	_, ok := b.allowed[staffID]
	return ok
}

// dedup suppresses DingTalk stream redeliveries of the same msgId.
func (b *Bot) dedup(msgID string) bool {
	if msgID == "" {
		return true
	}
	now := time.Now()

	b.seenMu.Lock()
	defer b.seenMu.Unlock()
	if _, dup := b.seen[msgID]; dup {
		return false
	}
	b.seen[msgID] = now
	if len(b.seen) > seenCap {
		for id, at := range b.seen {
			if now.Sub(at) > 10*time.Minute {
				delete(b.seen, id)
			}
		}
	}
	return true
}

func (b *Bot) takeHistory(conversationID string) []llm.ChatMessage {
	b.mu.Lock()
	defer b.mu.Unlock()
	b.purgeExpiredLocked()
	entry, ok := b.history[conversationID]
	if !ok {
		return nil
	}
	return entry.messages
}

func (b *Bot) saveHistory(conversationID string, messages []llm.ChatMessage) {
	if len(messages) > historyKeepMsgs {
		messages = messages[len(messages)-historyKeepMsgs:]
		// A history starting on a tool result (orphaned from its tool_calls)
		// 400s the whole gateway request.
		for len(messages) > 0 && messages[0].Role == "tool" {
			messages = messages[1:]
		}
	}

	b.mu.Lock()
	b.history[conversationID] = historyEntry{messages: messages, lastSeen: time.Now()}
	b.mu.Unlock()

	if b.store != nil { // auto save across restarts
		if err := b.store.SaveHistory(conversationID, messages, b.cfg.HistoryTTL); err != nil {
			log.Printf("[dingtalk] persist history failed: %v", err)
		}
	}
}

// purgeExpiredLocked drops conversations idle beyond the TTL so long-lived groups
// cannot grow memory unbounded.
func (b *Bot) purgeExpiredLocked() {
	if b.cfg.HistoryTTL <= 0 {
		return // zero TTL: keep history forever (Load() always sets a positive TTL)
	}
	for id, entry := range b.history {
		if time.Since(entry.lastSeen) > b.cfg.HistoryTTL {
			delete(b.history, id)
		}
	}
}

// StripMention removes leading @mention tokens from a group message. A mention is
// matched against the robot's display names first ("@Risk Agent hello" → "hello");
// anything else @-prefixed loses just its first token ("@bob hi there" → "hi there").
func StripMention(text string, botNames []string) string {
	for {
		trimmed := strings.TrimSpace(text)
		if !strings.HasPrefix(trimmed, "@") {
			return trimmed
		}
		rest := trimmed[1:]

		// Longest configured-name match wins, so multi-word names strip whole.
		matched := ""
		for _, name := range botNames {
			if len(name) > len(matched) && strings.HasPrefix(strings.ToLower(rest), strings.ToLower(name)) {
				matched = name
			}
		}
		if matched != "" {
			text = rest[len(matched):]
			continue
		}

		idx := strings.IndexAny(rest, " \t\n ")
		if idx < 0 {
			return "" // mention with nothing after it
		}
		text = rest[idx+1:]
	}
}

// plainText strips common markdown for the plain-text reply fallback.
func plainText(s string) string {
	r := strings.NewReplacer("*", "", "_", "", "`", "", "#", "", "[", "", "]", "")
	return r.Replace(s)
}
