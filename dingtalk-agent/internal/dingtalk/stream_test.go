package dingtalk

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/open-dingtalk/dingtalk-stream-sdk-go/chatbot"

	"techrisk-dashboard/dingtalk-agent/internal/agent"
	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/tools"
)

func TestStripMention(t *testing.T) {
	names := []string{"Risk Agent"}
	cases := map[string]struct{ in, want string }{
		"plain":           {"how many P1s this month?", "how many P1s this month?"},
		"leading mention": {"@Risk Agent how many P1s?", "how many P1s?"},
		"mention only":    {"@RiskAgent", ""},
		"multi mention":   {"@Risk Agent @Risk Agent details", "details"},
		"whitespace":      {"   \n  hello   ", "hello"},
		"at inside text":  {"email me at bob@corp.com please", "email me at bob@corp.com please"},
		"unknown mention": {"@bob hi there", "hi there"},
	}
	for name, tc := range cases {
		if got := StripMention(tc.in, names); got != tc.want {
			t.Errorf("%s: StripMention(%q) = %q, want %q", name, tc.in, got, tc.want)
		}
	}
}

func TestAllowlist(t *testing.T) {
	// Empty allowlist = everyone (gated upstream by the DingTalk app visibility range).
	cfg := &config.Config{}
	bot := NewBot(cfg, nil)
	if !bot.isAllowed("anyone") {
		t.Error("empty allowlist must allow everyone")
	}

	cfg.DingTalkAllowlist = []string{"staff123"}
	bot = NewBot(cfg, nil)
	if !bot.isAllowed("staff123") {
		t.Error("allowlisted staff should pass")
	}
	if bot.isAllowed("staff456") {
		t.Error("non-allowlisted staff should be denied when a list is set")
	}
}

func TestDedupSuppressesRedelivery(t *testing.T) {
	bot := NewBot(&config.Config{}, nil)
	if !bot.dedup("m1") || !bot.dedup("m2") {
		t.Fatal("first sightings must pass")
	}
	if bot.dedup("m1") {
		t.Error("redelivered msgId must be suppressed")
	}
	if !bot.dedup("") {
		t.Error("empty msgId cannot be deduped; let it through")
	}
}

func TestHistoryKeepLastNAndTTL(t *testing.T) {
	cfg := &config.Config{HistoryTTL: 20 * time.Millisecond}
	bot := NewBot(cfg, nil)

	var msgs []llm.ChatMessage
	for i := 0; i < 30; i++ {
		msgs = append(msgs, llm.ChatMessage{Role: "assistant", Content: strings.Repeat("x", i)})
	}
	bot.saveHistory("cid", msgs)
	if got := len(bot.takeHistory("cid")); got != historyKeepMsgs {
		t.Errorf("history should be capped at %d, got %d", historyKeepMsgs, got)
	}

	time.Sleep(30 * time.Millisecond)
	if got := bot.takeHistory("cid"); got != nil {
		t.Errorf("expired conversation must be purged, got %d messages", len(got))
	}
}

func TestHandleIgnoresDeniedAndEmpty(t *testing.T) {
	// Webhook sink: any reply attempt fails the test for denied/empty inputs.
	sink := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Error("no reply should be sent")
	}))
	defer sink.Close()

	cfg := &config.Config{DingTalkAllowlist: []string{"staff1"}}
	bot := NewBot(cfg, nil)

	denied := &chatbot.BotCallbackDataModel{
		MsgId: "m-deny", SenderStaffId: "stranger",
		Text: chatbot.BotCallbackDataTextModel{Content: "hello"},
	}
	if _, err := bot.Handle(context.Background(), denied); err != nil {
		t.Fatalf("handle error: %v", err)
	}

	empty := &chatbot.BotCallbackDataModel{
		MsgId: "m-empty", SenderStaffId: "staff1",
		Text: chatbot.BotCallbackDataTextModel{Content: "  @RiskAgent  "},
	}
	if _, err := bot.Handle(context.Background(), empty); err != nil {
		t.Fatalf("handle error: %v", err)
	}

	dup := &chatbot.BotCallbackDataModel{
		MsgId: "m-deny", SenderStaffId: "staff1",
		Text: chatbot.BotCallbackDataTextModel{Content: "hello"},
	}
	if _, err := bot.Handle(context.Background(), dup); err != nil {
		t.Fatalf("handle error: %v", err)
	}
}

// TestHandleEndToEnd wires a scripted LLM and a fake DingTalk session webhook,
// then verifies the agent's answer is delivered as a markdown reply.
func TestHandleEndToEnd(t *testing.T) {
	var mu sync.Mutex
	var replyBodies []string
	webhook := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body := make([]byte, r.ContentLength)
		_, _ = r.Body.Read(body)
		mu.Lock()
		replyBodies = append(replyBodies, string(body))
		mu.Unlock()
		w.WriteHeader(http.StatusOK)
	}))
	defer webhook.Close()

	llmSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		resp := llm.ChatResponse{
			Choices: []llm.ChatChoice{{
				Message:      llm.ChatMessage{Role: "assistant", Content: "There were **3** P1 incidents."},
				FinishReason: "stop",
			}},
		}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	}))
	defer llmSrv.Close()

	cfg := &config.Config{
		DingTalkAllowlist: []string{"staff1"},
		LLMBaseURL:        llmSrv.URL,
		LLMAPIKey:         "k",
		LLMModel:          "m",
		// Hermetic: the AI-card attempt must fail fast against a local server
		// (not the real api.dingtalk.com) so the webhook fallback path runs.
		DingTalkOpenAPIHost: llmSrv.URL,
	}
	loop := agent.NewLoop(llm.NewClient(cfg), tools.NewRegistry(nil), 2)
	bot := NewBot(cfg, loop)

	msg := &chatbot.BotCallbackDataModel{
		MsgId:          "m-e2e",
		SenderStaffId:  "staff1",
		SenderNick:     "Tester",
		ConversationId: "cid-e2e",
		SessionWebhook: webhook.URL,
		Text:           chatbot.BotCallbackDataTextModel{Content: "@RiskAgent how many P1 incidents this month?"},
	}
	if _, err := bot.Handle(context.Background(), msg); err != nil {
		t.Fatalf("handle error: %v", err)
	}

	deadline := time.Now().Add(5 * time.Second)
	gotAnswer, gotAck := false, false
	for !gotAnswer && time.Now().Before(deadline) {
		mu.Lock()
		for _, b := range replyBodies {
			if strings.Contains(b, "P1 incidents") {
				gotAnswer = true
			}
			if strings.Contains(b, "On it") {
				gotAck = true
			}
		}
		mu.Unlock()
		if !gotAnswer {
			time.Sleep(10 * time.Millisecond)
		}
	}

	mu.Lock()
	defer mu.Unlock()
	if len(replyBodies) == 0 {
		t.Fatal("no reply was delivered to the session webhook")
	}
	if !gotAck {
		t.Errorf("no receipt ack was delivered: %v", replyBodies)
	}
	if !gotAnswer {
		t.Errorf("no body carries the agent answer: %v", replyBodies)
	}

	// Conversation history must now exist for the next turn.
	if hist := bot.takeHistory("cid-e2e"); len(hist) == 0 {
		t.Error("history not saved after a successful turn")
	}
}

// TestBatchingJoinsRapidMessages verifies that messages sent in quick
// succession become ONE combined LLM call, not two racing answers.
func TestBatchingJoinsRapidMessages(t *testing.T) {
	var mu sync.Mutex
	var llmCalls []string // user content of each LLM request
	var replyBodies []string

	webhook := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		mu.Lock()
		replyBodies = append(replyBodies, string(body))
		mu.Unlock()
		w.WriteHeader(http.StatusOK)
	}))
	defer webhook.Close()

	llmSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// The AI-card attempt also lands on this host; count only real LLM calls.
		if !strings.Contains(r.URL.Path, "chat/completions") {
			w.WriteHeader(http.StatusBadRequest)
			return
		}
		var req struct {
			Messages []llm.ChatMessage `json:"messages"`
		}
		_ = json.NewDecoder(r.Body).Decode(&req)
		var userText string
		for _, m := range req.Messages {
			if m.Role == "user" {
				userText = m.Content
			}
		}
		mu.Lock()
		llmCalls = append(llmCalls, userText)
		mu.Unlock()

		resp := llm.ChatResponse{
			Choices: []llm.ChatChoice{{
				Message:      llm.ChatMessage{Role: "assistant", Content: "Combined answer."},
				FinishReason: "stop",
			}},
		}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	}))
	defer llmSrv.Close()

	cfg := &config.Config{
		LLMBaseURL: llmSrv.URL, LLMAPIKey: "k", LLMModel: "m",
		DingTalkOpenAPIHost: llmSrv.URL, // card fails fast → webhook fallback path
	}
	loop := agent.NewLoop(llm.NewClient(cfg), tools.NewRegistry(nil), 2)
	bot := NewBot(cfg, loop)

	send := func(id, text string) {
		_, err := bot.Handle(context.Background(), &chatbot.BotCallbackDataModel{
			MsgId: id, SenderStaffId: "staff1", SenderNick: "Tester",
			ConversationId: "cid-batch", SessionWebhook: webhook.URL,
			Text: chatbot.BotCallbackDataTextModel{Content: text},
		})
		if err != nil {
			t.Fatalf("handle %s: %v", id, err)
		}
	}
	send("b1", "analyze October incidents")
	send("b2", "include issues too")

	deadline := time.Now().Add(5 * time.Second)
	for {
		mu.Lock()
		done := len(llmCalls) > 0 && len(replyBodies) >= 2 // ack + answer
		mu.Unlock()
		if done || time.Now().After(deadline) {
			break
		}
		time.Sleep(10 * time.Millisecond)
	}

	mu.Lock()
	defer mu.Unlock()
	if len(llmCalls) != 1 {
		t.Fatalf("rapid messages must produce ONE llm call, got %d: %q", len(llmCalls), llmCalls)
	}
	if !strings.Contains(llmCalls[0], "analyze October incidents") || !strings.Contains(llmCalls[0], "include issues too") {
		t.Errorf("combined call is missing a message: %q", llmCalls[0])
	}
	if !strings.Contains(llmCalls[0], "ONE combined request") {
		t.Errorf("multi-message hint missing: %q", llmCalls[0])
	}
}

// TestAutoLearnPersistsProfile verifies the full loop: a completed turn
// triggers the background extraction call, the profile lands in the store,
// and profilePrompt serves it back for the next turn.
func TestAutoLearnPersistsProfile(t *testing.T) {
	dir := t.TempDir()

	var mu sync.Mutex
	var learnCalls int
	llmSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !strings.Contains(r.URL.Path, "chat/completions") {
			w.WriteHeader(http.StatusBadRequest)
			return
		}
		var req struct {
			Messages []llm.ChatMessage `json:"messages"`
		}
		_ = json.NewDecoder(r.Body).Decode(&req)

		mu.Lock()
		// Any request whose system prompt is the extraction prompt counts as learning.
		if len(req.Messages) > 0 && strings.Contains(req.Messages[0].Content, "preference profile") {
			learnCalls++
			mu.Unlock()
			resp := llm.ChatResponse{Choices: []llm.ChatChoice{{
				Message: llm.ChatMessage{Role: "assistant", Content: "- Prefers replies in Indonesian\n- Wants short bullet points"}, FinishReason: "stop",
			}}}
			w.Header().Set("Content-Type", "application/json")
			_ = json.NewEncoder(w).Encode(resp)
			return
		}
		mu.Unlock()

		resp := llm.ChatResponse{Choices: []llm.ChatChoice{{
			Message: llm.ChatMessage{Role: "assistant", Content: "Answer delivered."}, FinishReason: "stop",
		}}}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	}))
	defer llmSrv.Close()

	webhook := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
	}))
	defer webhook.Close()

	cfg := &config.Config{
		LLMBaseURL: llmSrv.URL, LLMAPIKey: "k", LLMModel: "m",
		DingTalkOpenAPIHost: llmSrv.URL, // card fails fast → plain path
		DataDir:             dir, AutoLearn: true,
	}
	bot := NewBot(cfg, agent.NewLoop(llm.NewClient(cfg), tools.NewRegistry(nil), 2))

	if _, err := bot.Handle(context.Background(), &chatbot.BotCallbackDataModel{
		MsgId: "learn1", SenderStaffId: "staff1", SenderNick: "Tester",
		ConversationId: "cid-learn", SessionWebhook: webhook.URL,
		Text: chatbot.BotCallbackDataTextModel{Content: "always reply to me in Indonesian, short bullets"},
	}); err != nil {
		t.Fatalf("handle: %v", err)
	}

	deadline := time.Now().Add(5 * time.Second)
	for {
		mu.Lock()
		done := learnCalls >= 1 && len(bot.store.Profile("staff1")) > 0
		mu.Unlock()
		if done || time.Now().After(deadline) {
			break
		}
		time.Sleep(10 * time.Millisecond)
	}

	if learnCalls == 0 {
		t.Fatal("extraction call never happened")
	}
	profile := bot.store.Profile("staff1")
	if len(profile) != 2 || !strings.Contains(profile[0], "Indonesian") {
		t.Fatalf("learned profile wrong: %v", profile)
	}
	if extra := bot.profilePrompt("staff1"); !strings.Contains(extra, "Indonesian") {
		t.Errorf("profilePrompt must serve the learned lines: %q", extra)
	}
	if extra := bot.profilePrompt("staff2"); extra != "" {
		t.Errorf("other users must not see staff1's profile: %q", extra)
	}

	// Kill switch: AutoLearn off → no injection, no extraction.
	cfg.AutoLearn = false
	if extra := bot.profilePrompt("staff1"); extra != "" {
		t.Errorf("kill switch must stop injection: %q", extra)
	}
}
