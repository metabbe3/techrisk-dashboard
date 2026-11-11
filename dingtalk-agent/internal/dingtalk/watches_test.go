package dingtalk

import (
	"context"
	"encoding/json"
	"fmt"
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
	"techrisk-dashboard/dingtalk-agent/internal/watch"
)

// fakeWatchAPI plays the dashboard API, the LLM, and the DingTalk OpenAPI.
// The dashboard serves one incident (by-no) and a configurable sweep list.
type fakeWatchAPI struct {
	srv      *httptest.Server
	mu       sync.Mutex
	sweep    []map[string]any // rows returned by the updated_since sweep
	chats    []string         // user content of each agent turn
	pushes   []map[string]any // batchSend + groupMessages bodies
	pushPath []string

	dashboardStatus int
}

func newFakeWatchAPI(t *testing.T) *fakeWatchAPI {
	f := &fakeWatchAPI{}
	f.srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		switch {
		case strings.Contains(r.URL.Path, "chat/completions"):
			var req struct {
				Messages []llm.ChatMessage `json:"messages"`
			}
			_ = json.NewDecoder(r.Body).Decode(&req)
			last := req.Messages[len(req.Messages)-2]
			f.mu.Lock()
			f.chats = append(f.chats, last.Content)
			f.mu.Unlock()
			_ = json.NewEncoder(w).Encode(llm.ChatResponse{Choices: []llm.ChatChoice{{
				Message: llm.ChatMessage{Role: "assistant", Content: "watch report: project QRIS payment gateway"}, FinishReason: "stop",
			}}})
		case r.URL.Path == "/v1.0/oauth2/accessToken":
			_, _ = w.Write([]byte(`{"accessToken":"tok","expireIn":7200}`))
		case r.URL.Path == "/incidents-by-no/20261101_IN_0042":
			_, _ = w.Write([]byte(`{"code":200,"data":{"no":"20261101_IN_0042","title":"Payment gateway timeout","incident_status":"Open"}}`))
		case strings.HasPrefix(r.URL.Path, "/incidents"):
			f.mu.Lock()
			rows := f.sweep
			f.mu.Unlock()
			_ = json.NewEncoder(w).Encode(map[string]any{"code": 200, "data": rows, "meta": map[string]any{"total": len(rows)}})
		case r.URL.Path == "/v1.0/robot/oToMessages/batchSend", r.URL.Path == "/v1.0/robot/groupMessages/send":
			var body map[string]any
			_ = json.NewDecoder(r.Body).Decode(&body)
			f.mu.Lock()
			f.pushes = append(f.pushes, body)
			f.pushPath = append(f.pushPath, r.URL.Path)
			f.mu.Unlock()
			_, _ = w.Write([]byte(`{}`))
		default:
			t.Errorf("unexpected request %s", r.URL.Path)
		}
	}))
	t.Cleanup(f.srv.Close)
	return f
}

func (f *fakeWatchAPI) waitFor(t *testing.T, what string, cond func() bool) {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		f.mu.Lock()
		ok := cond()
		f.mu.Unlock()
		if ok {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatalf("timed out waiting for %s", what)
}

func newWatchBot(t *testing.T, f *fakeWatchAPI, dataDir string) *Bot {
	cfg := &config.Config{
		LLMBaseURL: f.srv.URL, LLMAPIKey: "k", LLMModel: "m",
		DashboardAPIBase: f.srv.URL, DashboardAPIToken: "t",
		DingTalkOpenAPIHost: f.srv.URL, DingTalkClientID: "robot", DataDir: dataDir,
	}
	reg := tools.NewRegistry(cfg)
	return NewBot(cfg, agent.NewLoop(llm.NewClient(cfg), reg, 4))
}

func dmMsg(staff, text string) *chatbot.BotCallbackDataModel {
	return &chatbot.BotCallbackDataModel{
		MsgId: "w1", SenderStaffId: staff, SenderNick: "Nicholas",
		ConversationId: "cid-dm", ConversationType: "1",
		SessionWebhook: "http://localhost/unused",
		Text:           chatbot.BotCallbackDataTextModel{Content: text},
	}
}

// Tool call → ticket → bindWatch resolves the incident and stores the watch
// with its current status; the reply carries the confirmation note.
func TestWatchTicketBindsToRealConversation(t *testing.T) {
	f := newFakeWatchAPI(t)
	bot := newWatchBot(t, f, t.TempDir())

	// Park a watch request the way the tool handler does.
	token := bot.loop.Registry().WatchPending().Put(watch.Request{No: "20261101_IN_0042"})
	history := []llm.ChatMessage{{Role: "tool", Content: fmt.Sprintf("[watch-ticket: %s] Accepted", token)}}

	out := bot.applyWatches(dmMsg("s1", "watch it"), history, "On it.")
	if !strings.Contains(out, "👁 Watching 20261101_IN_0042") || !strings.Contains(out, "Open") {
		t.Errorf("bind note wrong: %s", out)
	}
	if strings.Contains(out, "watch-ticket") {
		t.Errorf("ticket must be stripped from the reply: %s", out)
	}
	ws := bot.watches.List("cid-dm")
	if len(ws) != 1 || ws[0].Title != "Payment gateway timeout" || ws[0].LastStatus != "Open" {
		t.Fatalf("watch not stored: %+v", ws)
	}

	// Same incident again in the same chat: rejected, not duplicated.
	token2 := bot.loop.Registry().WatchPending().Put(watch.Request{No: "20261101_IN_0042"})
	history2 := []llm.ChatMessage{{Role: "tool", Content: fmt.Sprintf("[watch-ticket: %s] Accepted", token2)}}
	out = bot.applyWatches(dmMsg("s1", "watch it"), history2, "ok")
	if !strings.Contains(out, "already watched") || len(bot.watches.List("cid-dm")) != 1 {
		t.Errorf("duplicate watch not rejected: %s", out)
	}
}

// The sweep detects a status change, runs a task-mode agent turn, and DMs
// the report to the creator; unchanged rows fire nothing.
func TestSweepFiresOnChangeAndSkipsUnchanged(t *testing.T) {
	f := newFakeWatchAPI(t)
	bot := newWatchBot(t, f, t.TempDir())
	ctx := context.Background()

	it, err := bot.watches.Add(watch.Item{
		IncidentNo: "20261101_IN_0042", Title: "Payment gateway timeout",
		LastStatus: "Open", ConversationID: "cid-dm", ConversationType: "1",
		StaffID: "s1", Nick: "Nicholas",
	})
	if err != nil {
		t.Fatal(err)
	}

	// Unchanged status → no fire.
	f.mu.Lock()
	f.sweep = []map[string]any{{"incident_no": "20261101_IN_0042", "incident_status": "Open"}}
	f.mu.Unlock()
	bot.sweepWatches(ctx, time.Now().Add(-time.Hour))
	if got := bot.watches.All()[0].LastStatus; got != "Open" {
		t.Fatalf("unchanged row must not fire: %s", got)
	}

	// Status change → agent turn + DM delivery + persisted new status.
	f.mu.Lock()
	f.sweep = []map[string]any{{"incident_no": "20261101_IN_0042", "incident_status": "In progress"}}
	f.mu.Unlock()
	bot.sweepWatches(ctx, time.Now().Add(-time.Hour))
	f.waitFor(t, "watch report DM", func() bool { return len(f.pushes) > 0 })

	f.mu.Lock()
	defer f.mu.Unlock()
	if f.pushPath[0] != "/v1.0/robot/oToMessages/batchSend" {
		t.Fatalf("watch report went to %s", f.pushPath[0])
	}
	p, _ := f.pushes[0]["msgParam"].(string)
	if !strings.Contains(p, "20261101_IN_0042") || !strings.Contains(p, "Open") || !strings.Contains(p, "In progress") {
		t.Errorf("watch report body wrong: %s", p)
	}
	if len(f.chats) == 0 || !strings.Contains(f.chats[0], "Watch #"+fmt.Sprint(it.ID)) {
		t.Errorf("agent turn not run with watch context: %v", f.chats)
	}
	if bot.watches.All()[0].LastStatus != "In progress" {
		t.Error("new status not persisted")
	}
}
