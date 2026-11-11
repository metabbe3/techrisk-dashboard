package dingtalk

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"

	"techrisk-dashboard/dingtalk-agent/internal/agent"
	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/tools"
)

// fakeInternalAPI plays the LLM and the DingTalk OpenAPI for internal-run tests.
type fakeInternalAPI struct {
	srv      *httptest.Server
	mu       sync.Mutex
	llmReqs  []llm.ChatRequest
	pushes   []map[string]any
	pushPath []string
	failLLM  bool
}

func newFakeInternalAPI(t *testing.T) *fakeInternalAPI {
	f := &fakeInternalAPI{}
	f.srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		switch {
		case strings.Contains(r.URL.Path, "chat/completions"):
			var req llm.ChatRequest
			_ = json.NewDecoder(r.Body).Decode(&req)
			f.mu.Lock()
			f.llmReqs = append(f.llmReqs, req)
			fail := f.failLLM
			f.mu.Unlock()
			if fail {
				http.Error(w, `llm down`, http.StatusInternalServerError)
				return
			}
			_ = json.NewEncoder(w).Encode(llm.ChatResponse{
				Usage: &llm.Usage{PromptTokens: 40, CompletionTokens: 8, TotalTokens: 48},
				Choices: []llm.ChatChoice{{
					Message:      llm.ChatMessage{Role: "assistant", Content: "All clear — 0 open P1 incidents."},
					FinishReason: "stop",
				}},
			})
		case r.URL.Path == "/v1.0/oauth2/accessToken":
			_, _ = w.Write([]byte(`{"accessToken":"tok","expireIn":7200}`))
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

func newInternalBot(t *testing.T, f *fakeInternalAPI) *Bot {
	t.Helper()
	cfg := &config.Config{
		LLMBaseURL: f.srv.URL, LLMAPIKey: "k", LLMModel: "glm-internal",
		DingTalkOpenAPIHost: f.srv.URL, DingTalkClientID: "robot",
		InternalToken: "shared-secret",
	}
	return NewBot(cfg, agent.NewLoop(llm.NewClient(cfg), tools.NewRegistry(&config.Config{}), 4))
}

func postInternal(t *testing.T, url, token string, body any) (int, map[string]any) {
	t.Helper()
	data, _ := json.Marshal(body)
	req, _ := http.NewRequest(http.MethodPost, url+"/internal/run", bytes.NewReader(data))
	if token != "" {
		req.Header.Set("X-Agent-Token", token)
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatalf("post /internal/run: %v", err)
	}
	defer resp.Body.Close()
	var out map[string]any
	_ = json.NewDecoder(resp.Body).Decode(&out)
	return resp.StatusCode, out
}

func TestInternalRunRejectsMissingOrWrongToken(t *testing.T) {
	f := newFakeInternalAPI(t)
	bot := newInternalBot(t, f)
	srv := httptest.NewServer(NewInternalMux(bot))
	defer srv.Close()

	for _, tok := range []string{"", "wrong-secret"} {
		code, out := postInternal(t, srv.URL, tok, internalRunRequest{Mode: "task", Text: "hi"})
		if code != http.StatusUnauthorized {
			t.Fatalf("token %q: status = %d, want 401 (out=%v)", tok, code, out)
		}
	}
	if len(f.llmReqs) != 0 {
		t.Errorf("unauthorized request must not reach the LLM, got %d calls", len(f.llmReqs))
	}
}

func TestInternalRunWrongMethod(t *testing.T) {
	f := newFakeInternalAPI(t)
	bot := newInternalBot(t, f)
	srv := httptest.NewServer(NewInternalMux(bot))
	defer srv.Close()

	req, _ := http.NewRequest(http.MethodGet, srv.URL+"/internal/run", nil)
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatalf("GET /internal/run: %v", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusMethodNotAllowed {
		t.Fatalf("GET status = %d, want 405", resp.StatusCode)
	}
}

func TestInternalRunBadJSON(t *testing.T) {
	f := newFakeInternalAPI(t)
	bot := newInternalBot(t, f)
	srv := httptest.NewServer(NewInternalMux(bot))
	defer srv.Close()

	req, _ := http.NewRequest(http.MethodPost, srv.URL+"/internal/run", bytes.NewReader([]byte("not json")))
	req.Header.Set("X-Agent-Token", "shared-secret")
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatalf("POST /internal/run: %v", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusBadRequest {
		t.Fatalf("bad json status = %d, want 400", resp.StatusCode)
	}
}

// Static engine: text delivered as-is, no LLM call spent.
func TestInternalRunMessageModeDeliversWithoutLLM(t *testing.T) {
	f := newFakeInternalAPI(t)
	bot := newInternalBot(t, f)
	srv := httptest.NewServer(NewInternalMux(bot))
	defer srv.Close()

	code, out := postInternal(t, srv.URL, "shared-secret", internalRunRequest{
		AgentName: "P1 digest", Mode: "message",
		Text:             "Morning check: no new P1s.",
		ConversationType: "1", ConversationID: "cid-dm", StaffID: "staff-9",
	})
	if code != http.StatusOK || out["ok"] != true {
		t.Fatalf("status=%d out=%v", code, out)
	}
	if out["output"] != "Morning check: no new P1s." {
		t.Errorf("output = %v", out["output"])
	}
	if out["delivered"] != true {
		t.Errorf("delivered = %v, want true", out["delivered"])
	}
	if len(f.llmReqs) != 0 {
		t.Errorf("message mode must not call the LLM, got %d calls", len(f.llmReqs))
	}

	f.mu.Lock()
	defer f.mu.Unlock()
	if len(f.pushes) != 1 {
		t.Fatalf("pushes = %d, want 1 DM push", len(f.pushes))
	}
	raw, _ := json.Marshal(f.pushes[0])
	if !strings.Contains(string(raw), "Morning check: no new P1s.") {
		t.Errorf("push body missing the text: %s", raw)
	}
	if f.pushPath[0] != "/v1.0/robot/oToMessages/batchSend" {
		t.Errorf("push path = %s, want the DM batchSend", f.pushPath[0])
	}
}

// Unbound runs (no dingtalk conversation) skip delivery and still succeed.
func TestInternalRunMessageModeUnbound(t *testing.T) {
	f := newFakeInternalAPI(t)
	bot := newInternalBot(t, f)
	srv := httptest.NewServer(NewInternalMux(bot))
	defer srv.Close()

	code, out := postInternal(t, srv.URL, "shared-secret", internalRunRequest{
		Mode: "message", Text: "report text",
	})
	if code != http.StatusOK || out["ok"] != true {
		t.Fatalf("status=%d out=%v", code, out)
	}
	if _, present := out["delivered"]; present {
		t.Errorf("delivered = %v, want omitted for an unbound run", out["delivered"])
	}
	if len(f.pushes) != 0 {
		t.Errorf("unbound run must not push, got %d", len(f.pushes))
	}
}

// Go engine: the dashboard's system prompt rides as the system message, the
// reply is guarded, usage + model reported, and the answer is delivered.
func TestInternalRunTaskModeRunsLoopAndReportsUsage(t *testing.T) {
	f := newFakeInternalAPI(t)
	bot := newInternalBot(t, f)
	srv := httptest.NewServer(NewInternalMux(bot))
	defer srv.Close()

	code, out := postInternal(t, srv.URL, "shared-secret", internalRunRequest{
		AgentName: "Open P1 watch", Mode: "task",
		System:           "You are the open-incident watcher.",
		Text:             "List open P1 incidents now.",
		ConversationType: "1", ConversationID: "cid-dm", StaffID: "staff-9",
	})
	if code != http.StatusOK || out["ok"] != true {
		t.Fatalf("status=%d out=%v", code, out)
	}
	if out["output"] != "All clear — 0 open P1 incidents." {
		t.Errorf("output = %v", out["output"])
	}
	if out["model"] != "glm-internal" {
		t.Errorf("model = %v, want glm-internal", out["model"])
	}
	usage, _ := out["usage"].(map[string]any)
	if usage == nil || usage["total_tokens"] != float64(48) {
		t.Errorf("usage = %v, want total_tokens 48", out["usage"])
	}
	if out["delivered"] != true {
		t.Errorf("delivered = %v, want true", out["delivered"])
	}

	f.mu.Lock()
	defer f.mu.Unlock()
	if len(f.llmReqs) != 1 {
		t.Fatalf("llm calls = %d, want 1", len(f.llmReqs))
	}
	msgs := f.llmReqs[0].Messages
	if msgs[0].Role != "system" || !strings.Contains(msgs[0].Content, "open-incident watcher") {
		t.Errorf("first message must be the dashboard system prompt, got %+v", msgs[0])
	}
	sawUser := false
	for _, m := range msgs {
		if m.Role == "user" && strings.Contains(m.Content, "List open P1 incidents") {
			sawUser = true
		}
	}
	if !sawUser {
		t.Errorf("task text missing from the LLM messages: %+v", msgs)
	}
	if len(f.pushes) != 1 {
		t.Fatalf("pushes = %d, want 1 DM push", len(f.pushes))
	}
}

// A failing loop still answers 200 with ok:false and the error text — the
// dashboard records a Failed run row, never a transport error.
func TestInternalRunTaskErrorReturnsNotOK(t *testing.T) {
	f := newFakeInternalAPI(t)
	f.mu.Lock()
	f.failLLM = true
	f.mu.Unlock()
	bot := newInternalBot(t, f)
	srv := httptest.NewServer(NewInternalMux(bot))
	defer srv.Close()

	code, out := postInternal(t, srv.URL, "shared-secret", internalRunRequest{
		Mode: "task", System: "sys", Text: "do the thing",
		ConversationType: "1", ConversationID: "cid-dm", StaffID: "staff-9",
	})
	if code != http.StatusOK {
		t.Fatalf("status = %d, want 200 even on failure", code)
	}
	if out["ok"] != false {
		t.Fatalf("ok = %v, want false", out["ok"])
	}
	if !strings.Contains(out["error"].(string), "agent llm error") {
		t.Errorf("error = %v, want the loop error text", out["error"])
	}
	if len(f.pushes) != 0 {
		t.Errorf("failed run must not push, got %d", len(f.pushes))
	}
}

func TestInternalRunUnknownMode(t *testing.T) {
	f := newFakeInternalAPI(t)
	bot := newInternalBot(t, f)
	srv := httptest.NewServer(NewInternalMux(bot))
	defer srv.Close()

	code, out := postInternal(t, srv.URL, "shared-secret", internalRunRequest{Mode: "bogus", Text: "x"})
	if code != http.StatusOK || out["ok"] != false {
		t.Fatalf("status=%d out=%v", code, out)
	}
	if !strings.Contains(out["error"].(string), "unknown mode") {
		t.Errorf("error = %v", out["error"])
	}
}
