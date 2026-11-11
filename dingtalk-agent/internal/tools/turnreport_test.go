package tools

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"sync"
	"testing"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
)

// waitForDone polls cond under mu until true or the deadline hits.
func waitForDone(t *testing.T, mu *sync.Mutex, cond func() bool) {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		mu.Lock()
		done := cond()
		mu.Unlock()
		if done {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatal("timed out waiting for the turn report POST")
}

func TestReportTurnAsyncPostsTheEnvelope(t *testing.T) {
	var mu sync.Mutex
	var gotPath, gotAuth, gotMethod, gotCT string
	var gotBody map[string]any

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		gotPath = r.URL.Path
		gotAuth = r.Header.Get("Authorization")
		gotMethod = r.Method
		gotCT = r.Header.Get("Content-Type")
		gotBody = map[string]any{}
		_ = json.NewDecoder(r.Body).Decode(&gotBody)
		envelope(w, nil, nil)
	}))
	defer srv.Close()

	ReportTurnAsync(&config.Config{
		DashboardAPIBase:  srv.URL,
		DashboardAPIToken: "tok",
	}, TurnReport{
		ConversationID:   "cid-1",
		ConversationType: "1",
		StaffID:          "staff-1",
		Nick:             "Andi",
		UserText:         "how many open P1s?",
		AssistantText:    "3 open P1 incidents.",
		Model:            "glm-5.3",
		Usage:            llm.Usage{PromptTokens: 100, CompletionTokens: 30, TotalTokens: 130},
		ResponseTimeMs:   2400,
	})

	waitForDone(t, &mu, func() bool { return gotPath != "" })

	mu.Lock()
	defer mu.Unlock()
	if gotPath != "/agent-runs" {
		t.Errorf("path = %s, want /agent-runs", gotPath)
	}
	if gotMethod != http.MethodPost {
		t.Errorf("method = %s, want POST", gotMethod)
	}
	if gotAuth != "Bearer tok" {
		t.Errorf("auth = %q, want Bearer tok", gotAuth)
	}
	if gotCT != "application/json" {
		t.Errorf("content-type = %q", gotCT)
	}
	for key, want := range map[string]any{
		"conversation_id":   "cid-1",
		"conversation_type": "1",
		"staff_id":          "staff-1",
		"nick":              "Andi",
		"user_text":         "how many open P1s?",
		"assistant_text":    "3 open P1 incidents.",
		"model":             "glm-5.3",
	} {
		if gotBody[key] != want {
			t.Errorf("%s = %v, want %v", key, gotBody[key], want)
		}
	}
	if usage, ok := gotBody["usage"].(map[string]any); !ok {
		t.Errorf("usage missing from payload: %v", gotBody["usage"])
	} else if usage["total_tokens"] != float64(130) || usage["prompt_tokens"] != float64(100) {
		t.Errorf("usage = %v", usage)
	}
	if gotBody["response_time_ms"] != float64(2400) {
		t.Errorf("response_time_ms = %v", gotBody["response_time_ms"])
	}
	if _, present := gotBody["error"]; present {
		t.Errorf("error must be omitted on success, got %v", gotBody["error"])
	}
}

// Zero usage (message-mode / unreported turns) must be omitted, not a zero object.
func TestReportTurnAsyncOmitsZeroUsage(t *testing.T) {
	var mu sync.Mutex
	var gotBody map[string]any

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		gotBody = map[string]any{}
		_ = json.NewDecoder(r.Body).Decode(&gotBody)
		envelope(w, nil, nil)
	}))
	defer srv.Close()

	ReportTurnAsync(&config.Config{
		DashboardAPIBase:  srv.URL,
		DashboardAPIToken: "tok",
	}, TurnReport{ConversationID: "cid-1", UserText: "hi", AssistantText: "hello"})

	waitForDone(t, &mu, func() bool { return gotBody != nil })

	mu.Lock()
	defer mu.Unlock()
	if _, present := gotBody["usage"]; present {
		t.Errorf("usage = %v, want omitted for a zero-usage turn", gotBody["usage"])
	}
}

// A failing dashboard must never panic or block the caller — the goroutine logs and drops.
func TestReportTurnAsyncSwallowsServerErrors(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, "boom", http.StatusInternalServerError)
	}))
	defer srv.Close()

	done := make(chan struct{})
	go func() {
		ReportTurnAsync(&config.Config{
			DashboardAPIBase:  srv.URL,
			DashboardAPIToken: "tok",
		}, TurnReport{ConversationID: "cid-1", UserText: "hi", AssistantText: "hello"})
		close(done)
	}()

	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("ReportTurnAsync did not return")
	}
	// Give the inner goroutine a moment to hit the 500; no assertion beyond not hanging.
	time.Sleep(200 * time.Millisecond)
}
