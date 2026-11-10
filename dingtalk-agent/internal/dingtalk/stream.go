package dingtalk

import (
	"context"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"github.com/open-dingtalk/dingtalk-stream-sdk-go/chatbot"

	"techrisk-dashboard/dingtalk-agent/internal/agent"
	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/guardrail"
	"techrisk-dashboard/dingtalk-agent/internal/ingest"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/memory"
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
	cfg     *config.Config
	loop    *agent.Loop
	replier *chatbot.ChatbotReplier
	allowed map[string]struct{}
	api     *openAPIClient
	llm     *llm.Client   // no-tools client for profile extraction
	store   *memory.Store // nil = memory-only (tests)

	mu      sync.Mutex
	history map[string]historyEntry

	seenMu sync.Mutex
	seen   map[string]time.Time

	inboxMu sync.Mutex
	inboxes map[string]chan chatTask // per-conversation batching workers

	sem chan struct{}
}

// chatTask is one inbound text message queued for a conversation worker.
type chatTask struct {
	m    *chatbot.BotCallbackDataModel
	text string
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
		cfg:     cfg,
		loop:    loop,
		replier: chatbot.NewChatbotReplier(),
		allowed: allowed,
		api:     newOpenAPIClient(cfg),
		llm:     llm.NewClient(cfg),
		history: make(map[string]historyEntry),
		seen:    make(map[string]time.Time),
		inboxes: make(map[string]chan chatTask),
		sem:     make(chan struct{}, MaxConcurrentAgents),
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
	return b
}

// knowledgePrompt renders remembered document summaries for the system prompt.
func (b *Bot) knowledgePrompt() string {
	if b.store == nil {
		return ""
	}
	var sb strings.Builder
	for _, e := range b.store.Knowledge() {
		summary := e.Summary
		if len(summary) > 600 {
			summary = summary[:600] + "…"
		}
		fmt.Fprintf(&sb, "- [%s] %s — %s\n", e.Time.Format("2006-01-02"), e.File, summary)
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

	// File uploads: download → extract text → summarize/knowledge → delete file.
	if fm, ok := parseFileMessage(m.Content); ok {
		log.Printf("[dingtalk] file from %s (%s) in conversation %s: %s",
			m.SenderNick, m.SenderStaffId, m.ConversationId, fm.FileName)
		b.reply(ctx, m, fmt.Sprintf("📥 Got **%s** — reading it now…", fm.FileName))
		b.sem <- struct{}{}
		go func() {
			defer func() { <-b.sem }()
			b.processFile(context.Background(), m, fm, text)
		}()
		return nil, nil
	}

	if text == "" {
		return nil, nil
	}

	log.Printf("[dingtalk] message from %s (%s) in conversation %s: %s",
		m.SenderNick, m.SenderStaffId, m.ConversationId, text)

	b.enqueue(m, text)

	return nil, nil // ack the frame; the reply travels over the session webhook
}

// enqueue hands a message to its conversation's batching worker, creating the
// worker on first contact. Never blocks: an overflowing inbox is refused with
// a webhook apology instead of stalling the SDK's frame reader.
func (b *Bot) enqueue(m *chatbot.BotCallbackDataModel, text string) {
	b.inboxMu.Lock()
	ch, ok := b.inboxes[m.ConversationId]
	if !ok {
		ch = make(chan chatTask, inboxCap)
		b.inboxes[m.ConversationId] = ch
		go b.conversationWorker(ch)
	}
	b.inboxMu.Unlock()

	select {
	case ch <- chatTask{m: m, text: text}:
	default:
		b.reply(context.Background(), m, "I'm backed up with queued messages — please retry in a moment.")
	}
}

// conversationWorker is the single consumer for one conversation: it batches
// rapid messages into one agent turn, answers, then picks up anything that
// arrived while the LLM was running as the next (follow-up) turn.
func (b *Bot) conversationWorker(ch chan chatTask) {
	followUp := false // true when this batch arrived while a previous run was answering
	for first := range ch {
		batch := []chatTask{first}

		// Debounce: close the batch after a quiet gap, or a hard deadline.
		quiet := time.NewTimer(batchQuietWait)
		hardStop := time.NewTimer(batchMaxWait)
	collect:
		for {
			select {
			case t, ok := <-ch:
				if !ok {
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

		b.runBatch(batch, followUp)
		followUp = len(ch) > 0 // messages queued during the run become a follow-up turn
	}
}

// runBatch turns a batch into one agent call. The LAST message's callback model
// is used for replies (freshest session webhook).
func (b *Bot) runBatch(batch []chatTask, followUp bool) {
	m := batch[len(batch)-1].m

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

	ack := "👌 On it — working on that…"
	if followUp {
		ack = "👌 Got it — added to the current task, continuing…"
	} else if len(batch) > 1 {
		ack = fmt.Sprintf("👌 Got %d messages — working on them together…", len(batch))
	}

	b.sem <- struct{}{}
	defer func() { <-b.sem }()
	b.process(context.Background(), m, text, ack)
}

// processFile handles a chat-uploaded document: download, extract text,
// summarize via the agent, remember the summary — then ALWAYS delete the file.
func (b *Bot) processFile(ctx context.Context, m *chatbot.BotCallbackDataModel, fm fileMessage, caption string) {
	dir := "data" // ponytail: relative to cwd, matches default DataDir
	if b.cfg.DataDir != "" {
		dir = b.cfg.DataDir
	}
	path, err := b.downloadMessageFile(ctx, fm, filepath.Join(dir, "tmp"))
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
		_ = card.fail(ctx)
		_ = card.finish(ctx, "Sorry, that request failed. Please try again in a moment.")
		return ""
	}
	if err := card.finish(ctx, filtered); err != nil {
		log.Printf("[dingtalk] card finish failed (conversation %s): %v", m.ConversationId, err)
	}
	b.learnLater(m)
	return filtered
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
	reply, err := b.llm.ChatCompletion(ctx, msgs, nil)
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

// processStreaming runs the agent loop, streaming answer deltas into the card
// (throttled — one API push per flushInterval, not one per token).
func (b *Bot) processStreaming(ctx context.Context, m *chatbot.BotCallbackDataModel, text, sysExtra string, card *aiCard) (string, error) {
	writer := newCardWriter(card)
	reply, newHistory, err := b.loop.RunStreaming(ctx, text, b.takeHistory(m.ConversationId), writer.onDelta, sysExtra)
	if err != nil {
		return "", err
	}

	b.saveHistory(m.ConversationId, newHistory)

	filtered := guardrail.FilterReplyCap(reply, b.cfg.MaxReplyChars)
	if filtered == "" {
		filtered = "Sorry, I could not produce an answer for that."
	}
	writer.flush(ctx, filtered) // ensure the visible card matches the guarded final text
	return filtered, nil
}

func (b *Bot) processPlain(ctx context.Context, m *chatbot.BotCallbackDataModel, text, sysExtra string) string {
	defer func() {
		if r := recover(); r != nil {
			log.Printf("[dingtalk] panic in agent loop: %v", r)
			b.reply(ctx, m, "Sorry, something went wrong while processing that request.")
		}
	}()

	reply, newHistory, err := b.loop.Run(ctx, text, b.takeHistory(m.ConversationId), sysExtra)
	if err != nil {
		log.Printf("[dingtalk] agent loop error (conversation %s): %v", m.ConversationId, err)
		b.reply(ctx, m, "Sorry, that request failed. Please try again in a moment.")
		return ""
	}

	b.saveHistory(m.ConversationId, newHistory)

	filtered := guardrail.FilterReplyCap(reply, b.cfg.MaxReplyChars)
	if filtered == "" {
		filtered = "Sorry, I could not produce an answer for that."
	}
	b.reply(ctx, m, filtered)
	b.learnLater(m)
	return filtered
}

func orDefault(s, def string) string {
	if strings.TrimSpace(s) == "" {
		return def
	}
	return s
}

func truncateStr(s string, max int) string {
	if len(s) <= max {
		return s
	}
	return s[:max] + "…"
}

// cardWriter batches LLM deltas into at most one card update per flushInterval
// and applies secret redaction to every pushed frame.
type cardWriter struct {
	card          *aiCard
	mu            sync.Mutex
	content       string
	lastFlush     time.Time
	dirty         bool
	flushInterval time.Duration
}

const cardFlushInterval = 400 * time.Millisecond

func newCardWriter(card *aiCard) *cardWriter {
	return &cardWriter{card: card, flushInterval: cardFlushInterval}
}

func (w *cardWriter) onDelta(delta string) {
	w.mu.Lock()
	w.content += delta
	due := time.Since(w.lastFlush) >= w.flushInterval
	w.mu.Unlock()
	if due {
		w.flush(context.Background(), "")
	}
}

// flush pushes content (or the accumulated content when empty) to the card.
func (w *cardWriter) flush(ctx context.Context, content string) {
	w.mu.Lock()
	if content != "" {
		w.content = content
	}
	if !w.dirty && content == "" {
		w.mu.Unlock()
		return
	}
	push := w.content
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
	if len(text) > 40 {
		title = text[:40]
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
