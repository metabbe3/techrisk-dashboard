package dingtalk

import (
	"context"
	"encoding/json"
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

	deadline := time.Now().Add(3 * time.Second)
	for {
		mu.Lock()
		got := len(replyBodies)
		mu.Unlock()
		if got > 0 || time.Now().After(deadline) {
			break
		}
		time.Sleep(10 * time.Millisecond)
	}

	mu.Lock()
	defer mu.Unlock()
	if len(replyBodies) == 0 {
		t.Fatal("no reply was delivered to the session webhook")
	}
	if !strings.Contains(replyBodies[0], "P1 incidents") {
		t.Errorf("reply body does not carry the agent answer: %s", replyBodies[0])
	}

	// Conversation history must now exist for the next turn.
	if hist := bot.takeHistory("cid-e2e"); len(hist) == 0 {
		t.Error("history not saved after a successful turn")
	}
}
