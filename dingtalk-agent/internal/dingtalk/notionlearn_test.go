package dingtalk

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/open-dingtalk/dingtalk-stream-sdk-go/chatbot"

	"techrisk-dashboard/dingtalk-agent/internal/agent"
	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/memory"
	"techrisk-dashboard/dingtalk-agent/internal/tools"
)

// TestSummarizeNotionReadsPersistsKnowledge: a fixed LLM summary of the pages
// read this turn lands in knowledge.jsonl under "Notion: <first title>".
func TestSummarizeNotionReadsPersistsKnowledge(t *testing.T) {
	dir := t.TempDir()

	var gotPrompt string
	llmSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var req struct {
			Messages []llm.ChatMessage `json:"messages"`
		}
		_ = json.NewDecoder(r.Body).Decode(&req)
		if len(req.Messages) > 0 {
			gotPrompt = req.Messages[0].Content + "\n" + req.Messages[len(req.Messages)-1].Content
		}
		resp := llm.ChatResponse{Choices: []llm.ChatChoice{{
			Message: llm.ChatMessage{Role: "assistant", Content: "- Payments PRD: gateway must rotate refresh tokens every 24h"}, FinishReason: "stop",
		}}}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	}))
	defer llmSrv.Close()

	cfg := &config.Config{LLMBaseURL: llmSrv.URL, LLMAPIKey: "k", LLMModel: "m", DataDir: dir}
	bot := NewBot(cfg, nil)

	reads := []tools.NotionRead{{
		Title:   "Payments PRD",
		URL:     "https://notion.so/pg1",
		Excerpt: "The payment gateway must rotate refresh tokens every 24 hours.",
	}}
	bot.summarizeNotionReads(context.Background(), "Tester", reads) // synchronous in the test

	if !strings.Contains(gotPrompt, "document memory") || !strings.Contains(gotPrompt, "Payments PRD") {
		t.Errorf("summarizer prompt wrong: %.200s", gotPrompt)
	}

	data, err := os.ReadFile(filepath.Join(dir, "knowledge.jsonl"))
	if err != nil {
		t.Fatalf("knowledge.jsonl missing: %v", err)
	}
	if !strings.Contains(string(data), "Notion: Payments PRD") {
		t.Errorf("knowledge entry wrong: %s", data)
	}
	if !strings.Contains(string(data), "rotate refresh tokens") || !strings.Contains(string(data), "Tester") {
		t.Errorf("summary/author not persisted: %s", data)
	}

	if k := bot.store.Knowledge(); len(k) != 1 || !strings.Contains(k[0].File, "Notion: Payments PRD") {
		t.Errorf("store.Knowledge() wrong: %+v", k)
	}

	// Multiple reads: title gains "(+N more)".
	bot.summarizeNotionReads(context.Background(), "Tester", append(reads,
		tools.NotionRead{Title: "DB TA", URL: "https://notion.so/pg2", Excerpt: "blue-green migration"}))
	if k := bot.store.Knowledge(); len(k) != 2 || !strings.Contains(k[1].File, "(+1 more)") {
		t.Errorf("multi-read entry wrong: %+v", k)
	}
}

// TestLearnNotionDrainsAndSkips: learnNotion hands recorded reads to the
// summarizer goroutine (store gains an entry) and is a no-op without reads.
func TestLearnNotionDrainsAndSkips(t *testing.T) {
	dir := t.TempDir()
	llmSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		resp := llm.ChatResponse{Choices: []llm.ChatChoice{{
			Message: llm.ChatMessage{Role: "assistant", Content: "- PRD: rotates tokens"}, FinishReason: "stop",
		}}}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	}))
	defer llmSrv.Close()

	dashSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewEncoder(w).Encode(map[string]any{
			"code": 200, "status": "Success", "message": "ok",
			"data": map[string]any{"content": "# Payments PRD\nrotate every 24h", "truncated": false},
		})
	}))
	defer dashSrv.Close()

	llmCfg := &config.Config{LLMBaseURL: llmSrv.URL, LLMAPIKey: "k", LLMModel: "m", DataDir: dir}
	reg := tools.NewRegistry(&config.Config{
		NotionToolsEnabled: true, DataDir: dir,
		DashboardAPIBase: dashSrv.URL, DashboardAPIToken: "t",
	})
	bot := NewBot(llmCfg, agent.NewLoop(llm.NewClient(llmCfg), reg, 2))

	m := &chatbot.BotCallbackDataModel{SenderStaffId: "staff1", SenderNick: "Tester", ConversationId: "cid-n"}

	// No reads recorded → nothing happens, no panic, no knowledge entry.
	turnCtx := tools.WithNotionSink(context.Background())
	bot.learnNotion(turnCtx, m)
	if k := bot.store.Knowledge(); len(k) != 0 {
		t.Fatalf("learnNotion without reads must not append knowledge, got %+v", k)
	}

	// A read recorded through the tool lands as knowledge after learnNotion.
	if _, err := reg.Execute(turnCtx, "notion_read_page", `{"id_or_url":"pg1"}`); err != nil {
		t.Fatalf("read_page: %v", err)
	}

	bot.learnNotion(turnCtx, m)

	var entries int
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		if entries = len(bot.store.Knowledge()); entries > 0 {
			break
		}
		time.Sleep(10 * time.Millisecond)
	}
	if entries == 0 {
		t.Fatal("learnNotion never appended knowledge for recorded reads")
	}
	if k := bot.store.Knowledge()[0]; !strings.Contains(k.File, "Notion: Payments PRD") {
		t.Errorf("entry wrong: %+v", k)
	}
}

// TestProcessPlainErrorPathDrainsNotionReads: a turn that read a Notion page and
// THEN died (the 2026-11-10 prod outage shape: gateway 400s tool-carrying
// requests) must still summarize its reads under THAT sender — without the
// error-branch drain the reads leak into the next successful turn's knowledge
// entry and get mislabeled with whoever chatted next.
func TestProcessPlainErrorPathDrainsNotionReads(t *testing.T) {
	dir := t.TempDir()

	// Tool-carrying requests (the agent loop) 400 like the prod gateway; the
	// summarizer's no-tools call succeeds.
	llmSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var req struct {
			Tools []llm.ToolDefinition `json:"tools"`
		}
		_ = json.NewDecoder(r.Body).Decode(&req)
		if len(req.Tools) > 0 {
			http.Error(w, `{"error":{"message":"tools[0].function.parameters is invalid or missing"}}`, http.StatusBadRequest)
			return
		}
		resp := llm.ChatResponse{Choices: []llm.ChatChoice{{
			Message: llm.ChatMessage{Role: "assistant", Content: "- Payments PRD: rotate tokens every 24h"}, FinishReason: "stop",
		}}}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	}))
	defer llmSrv.Close()

	hookSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {}))
	defer hookSrv.Close()

	dashSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewEncoder(w).Encode(map[string]any{
			"code": 200, "status": "Success", "message": "ok",
			"data": map[string]any{"content": "# Payments PRD\nrotate every 24h", "truncated": false},
		})
	}))
	defer dashSrv.Close()

	llmCfg := &config.Config{LLMBaseURL: llmSrv.URL, LLMAPIKey: "k", LLMModel: "m", DataDir: dir}
	reg := tools.NewRegistry(&config.Config{
		NotionToolsEnabled: true, DataDir: dir,
		DashboardAPIBase: dashSrv.URL, DashboardAPIToken: "t",
	})
	bot := NewBot(llmCfg, agent.NewLoop(llm.NewClient(llmCfg), reg, 2))

	// The turn reads a page, then its LLM call fails — seed exactly that state.
	turnCtx := tools.WithNotionSink(context.Background())
	if _, err := reg.Execute(turnCtx, "notion_read_page", `{"id_or_url":"pg1"}`); err != nil {
		t.Fatalf("read_page: %v", err)
	}

	m := &chatbot.BotCallbackDataModel{
		SenderStaffId: "staff1", SenderNick: "Tester",
		ConversationId: "cid-err", SessionWebhook: hookSrv.URL,
	}
	if got := bot.processPlain(turnCtx, m, "summarize the payments PRD", ""); got != "" {
		t.Fatalf("processPlain on a failed turn must return empty, got %q", got)
	}

	// The failed turn's read must land under ITS sender, not stay stranded.
	var entry *memory.KnowledgeEntry
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		if k := bot.store.Knowledge(); len(k) > 0 {
			entry = &k[0]
			break
		}
		time.Sleep(10 * time.Millisecond)
	}
	if entry == nil {
		t.Fatal("failed turn's notion reads were never summarized — leaked out of the turn")
	}
	if !strings.Contains(entry.File, "Notion: Payments PRD") || entry.Author != "Tester" {
		t.Errorf("entry wrong (attribution): %+v", entry)
	}
	if left := tools.TakeNotionReads(turnCtx); len(left) != 0 {
		t.Errorf("tracker must be drained after the failed turn, left %+v", left)
	}
}
