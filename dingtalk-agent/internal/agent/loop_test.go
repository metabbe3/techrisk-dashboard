package agent

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/tools"
)

func TestAgentLoopWithToolCall(t *testing.T) {
	step := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		step++
		var chatReq llm.ChatRequest
		_ = json.NewDecoder(r.Body).Decode(&chatReq)

		var resp llm.ChatResponse
		if step == 1 {
			// First call: model requests tool 'stub_lookup'
			resp = llm.ChatResponse{
				Choices: []llm.ChatChoice{
					{
						Message: llm.ChatMessage{
							Role: "assistant",
							ToolCalls: []llm.ToolCall{
								{
									ID:   "call_step1",
									Type: "function",
									Function: llm.FunctionCall{
										Name:      "stub_lookup",
										Arguments: `{"path":"."}`,
									},
								},
							},
						},
						FinishReason: "tool_calls",
					},
				},
			}
		} else {
			// Second call: model responds with final answer after receiving tool output
			resp = llm.ChatResponse{
				Choices: []llm.ChatChoice{
					{
						Message: llm.ChatMessage{
							Role:    "assistant",
							Content: "Found the files successfully.",
						},
						FinishReason: "stop",
					},
				},
			}
		}

		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	cfg := &config.Config{
		LLMBaseURL: server.URL,
		LLMAPIKey:  "mock-key",
		LLMModel:   "mock-model",
	}

	llmClient := llm.NewClient(cfg)
	reg := tools.NewRegistry(nil)
	reg.Register(
		llm.ToolDefinition{Type: "function", Function: llm.ToolFunctionDef{Name: "stub_lookup", Description: "test stub"}},
		func(ctx context.Context, argsJSON string) (string, error) { return "Directory listing: 3 files", nil },
	)
	agentLoop := NewLoop(llmClient, reg, 5)

	finalText, messages, _, _, err := agentLoop.Run(context.Background(), "list current directory", nil, "")
	if err != nil {
		t.Fatalf("agent loop failed: %v", err)
	}

	if finalText != "Found the files successfully." {
		t.Errorf("unexpected final text: %s", finalText)
	}

	// Verify messages: user, assistant (tool_call), tool (result), assistant
	// (final). The system prompt is NOT in the returned history — it is rebuilt
	// fresh every turn (#11), so history never pins a stale clock or profile.
	if len(messages) < 4 {
		t.Errorf("expected at least 4 messages in conversation history, got %d", len(messages))
	}
	if messages[0].Role != "user" {
		t.Errorf("history must start at the user turn (no system), got %q", messages[0].Role)
	}
	for _, m := range messages {
		if m.Role == "system" {
			t.Errorf("system prompt must never persist into history")
		}
	}

	hasToolResult := false
	for _, m := range messages {
		if m.Role == "tool" && m.ToolCallID == "call_step1" && strings.Contains(m.Content, "Directory listing") {
			hasToolResult = true
			break
		}
	}
	if !hasToolResult {
		t.Errorf("conversation history missing tool result")
	}
}

func TestParallelToolCallsDeterministicOrder(t *testing.T) {
	step := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		step++
		var resp llm.ChatResponse
		if step == 1 {
			// First response: request 2 tools simultaneously
			resp = llm.ChatResponse{
				Choices: []llm.ChatChoice{
					{
						Message: llm.ChatMessage{
							Role: "assistant",
							ToolCalls: []llm.ToolCall{
								{
									ID:   "call_first",
									Type: "function",
									Function: llm.FunctionCall{
										Name:      "stub_one",
										Arguments: `{"path":"."}`,
									},
								},
								{
									ID:   "call_second",
									Type: "function",
									Function: llm.FunctionCall{
										Name:      "stub_two",
										Arguments: `{"cmd":"echo 'second tool result'"}`,
									},
								},
							},
						},
						FinishReason: "tool_calls",
					},
				},
			}
		} else {
			// Second response: final answer
			resp = llm.ChatResponse{
				Choices: []llm.ChatChoice{
					{
						Message: llm.ChatMessage{
							Role:    "assistant",
							Content: "Completed parallel tasks.",
						},
						FinishReason: "stop",
					},
				},
			}
		}

		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	cfg := &config.Config{
		LLMBaseURL: server.URL,
		LLMAPIKey:  "mock-key",
		LLMModel:   "mock-model",
	}

	llmClient := llm.NewClient(cfg)
	reg := tools.NewRegistry(nil)
	reg.Register(
		llm.ToolDefinition{Type: "function", Function: llm.ToolFunctionDef{Name: "stub_one", Description: "test stub"}},
		func(ctx context.Context, argsJSON string) (string, error) { return "first tool result", nil },
	)
	reg.Register(
		llm.ToolDefinition{Type: "function", Function: llm.ToolFunctionDef{Name: "stub_two", Description: "test stub"}},
		func(ctx context.Context, argsJSON string) (string, error) { return "second tool result", nil },
	)
	agentLoop := NewLoop(llmClient, reg, 5)

	finalText, messages, _, _, err := agentLoop.Run(context.Background(), "run parallel diagnostics", nil, "")
	if err != nil {
		t.Fatalf("agent loop failed: %v", err)
	}

	if finalText != "Completed parallel tasks." {
		t.Errorf("unexpected final text: %s", finalText)
	}

	// Verify that tool results appear in exact deterministic order: call_first, then call_second
	var toolResults []llm.ChatMessage
	for _, m := range messages {
		if m.Role == "tool" {
			toolResults = append(toolResults, m)
		}
	}

	if len(toolResults) != 2 {
		t.Fatalf("expected 2 tool results, got %d", len(toolResults))
	}

	if toolResults[0].ToolCallID != "call_first" {
		t.Errorf("expected first tool result to be call_first, got: %s", toolResults[0].ToolCallID)
	}
	if toolResults[1].ToolCallID != "call_second" {
		t.Errorf("expected second tool result to be call_second, got: %s", toolResults[1].ToolCallID)
	}
}

func TestToolResultTruncation(t *testing.T) {
	longOutput := strings.Repeat("X", 10000)
	truncated := TruncateToolOutput(longOutput, 8000)

	if !strings.HasPrefix(truncated, strings.Repeat("X", 8000)) {
		t.Errorf("expected prefix of 8000 characters")
	}
	if !strings.Contains(truncated, "...[truncated 2000 bytes]") {
		t.Errorf("expected truncation footer, got: %s", truncated[7990:])
	}
	if len(truncated) > 8100 {
		t.Errorf("truncated string too long: %d", len(truncated))
	}
}

func TestContextBudgetPruning(t *testing.T) {
	// Total budget > 60,000 chars
	oldToolContent := strings.Repeat("LOG LINE\n", 6000)    // ~54,000 chars
	newToolContent := strings.Repeat("ANOTHER LOG\n", 2000) // ~24,000 chars

	messages := []llm.ChatMessage{
		{Role: "system", Content: "System prompt"},
		{Role: "user", Content: "Analyze first log"},
		{Role: "tool", ToolCallID: "call_1", Content: oldToolContent},
		{Role: "assistant", Content: "Done with first"},
		{Role: "user", Content: "Analyze second log"},
		{Role: "tool", ToolCallID: "call_2", Content: newToolContent},
	}

	PruneContextBudget(messages, 60000)

	// Oldest tool result should be omitted
	if messages[2].Content != "[tool result omitted]" {
		t.Errorf("expected oldest tool result to be omitted, got length %d", len(messages[2].Content))
	}

	// User and assistant messages must be kept intact
	if messages[0].Content != "System prompt" || messages[1].Content != "Analyze first log" || messages[3].Content != "Done with first" {
		t.Errorf("user or assistant message content was corrupted during pruning")
	}
}

// TestSysExtraAppendedToSystemPrompt verifies the per-run extra (learned user
// profile) reaches the LLM inside the system message, the user message is
// framed as data, and the constitution tail rides as the LAST message.
func TestSysExtraAppendedToSystemPrompt(t *testing.T) {
	var firstSystem, lastMsgRole, lastMsgContent, userMsg string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var req struct {
			Messages []llm.ChatMessage `json:"messages"`
		}
		_ = json.NewDecoder(r.Body).Decode(&req)
		for _, m := range req.Messages {
			if m.Role == "system" && firstSystem == "" {
				firstSystem = m.Content
			}
			if m.Role == "user" {
				userMsg = m.Content
			}
			lastMsgRole, lastMsgContent = m.Role, m.Content
		}
		resp := llm.ChatResponse{Choices: []llm.ChatChoice{{
			Message: llm.ChatMessage{Role: "assistant", Content: "ok"}, FinishReason: "stop",
		}}}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	cfg := &config.Config{LLMBaseURL: server.URL, LLMAPIKey: "k", LLMModel: "m"}
	agentLoop := NewLoop(llm.NewClient(cfg), tools.NewRegistry(nil), 2)

	if _, _, _, _, err := agentLoop.Run(context.Background(), "hi", nil,
		"## About the user you are replying to\n- prefers Indonesian\n"); err != nil {
		t.Fatalf("run failed: %v", err)
	}
	if !strings.Contains(firstSystem, "prefers Indonesian") {
		t.Errorf("sysExtra missing from system prompt: %.200s", firstSystem)
	}
	if !strings.Contains(firstSystem, "Domain Rules") {
		t.Errorf("base system prompt lost: %.200s", firstSystem)
	}
	if !strings.Contains(userMsg, "[user message —") || !strings.Contains(userMsg, "hi") {
		t.Errorf("user message not framed: %q", userMsg)
	}
	if lastMsgRole != "system" || !strings.Contains(lastMsgContent, "Risk Agent") {
		t.Errorf("constitution tail must be the last message, got %s: %.120s", lastMsgRole, lastMsgContent)
	}
}

// Usage must be summed across every LLM round of a run (run-local accumulator —
// the loop is shared across concurrent turns, no shared state).
func TestRunSumsUsageAcrossIterations(t *testing.T) {
	step := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		step++
		var resp llm.ChatResponse
		if step == 1 {
			resp = llm.ChatResponse{
				Usage: &llm.Usage{PromptTokens: 100, CompletionTokens: 10, TotalTokens: 110},
				Choices: []llm.ChatChoice{{
					Message: llm.ChatMessage{
						Role: "assistant",
						ToolCalls: []llm.ToolCall{{
							ID:       "call_1",
							Type:     "function",
							Function: llm.FunctionCall{Name: "stub_lookup", Arguments: `{"path":"."}`},
						}},
					},
					FinishReason: "tool_calls",
				}},
			}
		} else {
			resp = llm.ChatResponse{
				Usage: &llm.Usage{PromptTokens: 200, CompletionTokens: 20, TotalTokens: 220},
				Choices: []llm.ChatChoice{{
					Message:      llm.ChatMessage{Role: "assistant", Content: "done"},
					FinishReason: "stop",
				}},
			}
		}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	reg := tools.NewRegistry(nil)
	reg.Register(
		llm.ToolDefinition{Type: "function", Function: llm.ToolFunctionDef{Name: "stub_lookup", Description: "test stub"}},
		func(ctx context.Context, argsJSON string) (string, error) { return "result", nil },
	)
	agentLoop := NewLoop(
		llm.NewClient(&config.Config{LLMBaseURL: server.URL, LLMAPIKey: "k", LLMModel: "m"}),
		reg, 5,
	)

	_, _, usage, _, err := agentLoop.Run(context.Background(), "hi", nil, "")
	if err != nil {
		t.Fatalf("run failed: %v", err)
	}
	if usage != (llm.Usage{PromptTokens: 300, CompletionTokens: 30, TotalTokens: 330}) {
		t.Errorf("usage = %+v, want the sum of both rounds", usage)
	}
}

// The clock must ride the system prompt — "since yesterday" and the daily
// digest's updated_since windows are unreliable without it.
func TestCurrentDateTimeInSystemPrompt(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var req struct {
			Messages []llm.ChatMessage `json:"messages"`
		}
		_ = json.NewDecoder(r.Body).Decode(&req)
		var sys string
		for _, m := range req.Messages {
			if m.Role == "system" && sys == "" {
				sys = m.Content
			}
		}
		resp := llm.ChatResponse{Choices: []llm.ChatChoice{{
			Message: llm.ChatMessage{Role: "assistant", Content: "ok"}, FinishReason: "stop",
		}}}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)

		if !strings.Contains(sys, "Current date/time (hour precision): ") {
			t.Errorf("current date/time missing from system prompt: %.200s", sys)
		}
		// WIB fixed zone — hour-granularity format must round-trip
		// (minute precision defeated provider prompt caching; audit round 4).
		idx := strings.Index(sys, "Current date/time (hour precision): ")
		line := strings.TrimSpace(sys[idx+len("Current date/time (hour precision): "):])
		if _, err := time.Parse("Mon 2006-01-02 15h MST", line); err != nil {
			t.Errorf("date line not parseable: %q", line)
		}
	}))
	defer server.Close()

	cfg := &config.Config{LLMBaseURL: server.URL, LLMAPIKey: "k", LLMModel: "m"}
	agentLoop := NewLoop(llm.NewClient(cfg), tools.NewRegistry(nil), 2)
	if _, _, _, _, err := agentLoop.Run(context.Background(), "hi", nil, ""); err != nil {
		t.Fatalf("run failed: %v", err)
	}
}

// The KB is writable from chat (save/list/delete beside search) — the model
// must be TAUGHT that, and the constitution must not forbid it: its scope line
// used to say the only KB interaction is search_knowledge, "Nothing else",
// which would make the model refuse the very tools that are registered.
func TestKnowledgePromptTeachesAllKBTools(t *testing.T) {
	for _, tool := range []string{"search_knowledge", "save_knowledge", "list_knowledge", "delete_knowledge"} {
		if !strings.Contains(KnowledgePrompt, tool) {
			t.Errorf("KnowledgePrompt does not mention %s — the model will never use it", tool)
		}
		if !strings.Contains(ConstitutionTail, tool) {
			t.Errorf("ConstitutionTail scope does not name %s — the constitution forbids what the tool does", tool)
		}
	}
}

// The external RCA is a partner-facing document — the prompt must teach both
// the confirm-first flow and the neutral-wording rules, and the constitution
// must allow the tool (it is the third allowed document type, beside PDF/deck).
func TestExternalRCAPromptAndConstitution(t *testing.T) {
	for _, want := range []string{"generate_external_rca", "generate_pdf_report", "generate_deck"} {
		if !strings.Contains(ConstitutionTail, want) {
			t.Errorf("ConstitutionTail does not name %s — the constitution forbids what the tool does", want)
		}
	}
	for _, want := range []string{"generate_external_rca", "get_incident", "confirm", "neutral",
		"inferred from the timeline", "hedged", "30 minutes apart"} {
		if !strings.Contains(ExternalRCAPrompt, want) {
			t.Errorf("ExternalRCAPrompt does not teach %q", want)
		}
	}
}

func TestLoopToolContextTimeout(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		resp := llm.ChatResponse{
			ID: "chatcmpl-slow",
			Choices: []llm.ChatChoice{
				{
					Index: 0,
					Message: llm.ChatMessage{
						Role: "assistant",
						ToolCalls: []llm.ToolCall{
							{
								ID:   "call_slow_1",
								Type: "function",
								Function: llm.FunctionCall{
									Name:      "slow_tool",
									Arguments: "{}",
								},
							},
						},
					},
					FinishReason: "tool_calls",
				},
			},
		}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	cfg := &config.Config{
		LLMBaseURL: server.URL,
		LLMAPIKey:  "key",
		LLMModel:   "model",
	}
	llmClient := llm.NewClient(cfg)
	reg := tools.NewRegistry(cfg)

	reg.Register(llm.ToolDefinition{
		Type:     "function",
		Function: llm.ToolFunctionDef{Name: "slow_tool"},
	}, func(ctx context.Context, _ string) (string, error) {
		select {
		case <-time.After(10 * time.Second):
			return "done", nil
		case <-ctx.Done():
			return "", ctx.Err()
		}
	})

	agentLoop := NewLoop(llmClient, reg, 5)
	ctx, cancel := context.WithTimeout(context.Background(), 100*time.Millisecond)
	defer cancel()

	start := time.Now()
	_, _, _, _, err := agentLoop.Run(ctx, "run slow", nil, "")
	elapsed := time.Since(start)

	if err == nil {
		t.Fatalf("expected error due to context timeout, got nil")
	}
	if elapsed >= 3*time.Second {
		t.Fatalf("loop should complete in < 3s when ctx times out, took %v", elapsed)
	}
}

// Trigger phrases route the WHOLE turn (every round) to the heavy model —
// proactive routing beside the failure escalation. Plain text stays on the
// chat model.
func TestHeavyKeywordRoutesToHeavyModel(t *testing.T) {
	var models []string
	step := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var chatReq llm.ChatRequest
		_ = json.NewDecoder(r.Body).Decode(&chatReq)
		models = append(models, chatReq.Model)
		step++
		var resp llm.ChatResponse
		if step%2 == 1 {
			resp = llm.ChatResponse{Choices: []llm.ChatChoice{{
				Message: llm.ChatMessage{Role: "assistant", ToolCalls: []llm.ToolCall{{
					ID: "call_1", Type: "function",
					Function: llm.FunctionCall{Name: "stub_lookup", Arguments: `{"path":"."}`},
				}}},
				FinishReason: "tool_calls",
			}}}
		} else {
			resp = llm.ChatResponse{Choices: []llm.ChatChoice{{
				Message: llm.ChatMessage{Role: "assistant", Content: "done"}, FinishReason: "stop",
			}}}
		}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	reg := tools.NewRegistry(nil)
	reg.Register(
		llm.ToolDefinition{Type: "function", Function: llm.ToolFunctionDef{Name: "stub_lookup", Description: "test stub"}},
		func(ctx context.Context, argsJSON string) (string, error) { return "result", nil },
	)
	agentLoop := NewLoop(
		llm.NewClient(&config.Config{LLMBaseURL: server.URL, LLMAPIKey: "k", LLMModel: "flash", HeavyModel: "heavy"}),
		reg, 5,
	)
	agentLoop.SetHeavyKeywords([]string{"ultrathink"})

	if _, _, _, _, err := agentLoop.Run(context.Background(), "ultrathink: analyze the spike", nil, ""); err != nil {
		t.Fatalf("triggered run failed: %v", err)
	}
	for i, m := range models {
		if m != "heavy" {
			t.Errorf("triggered turn round %d used %q, want heavy", i+1, m)
		}
	}
	if len(models) != 2 {
		t.Fatalf("triggered turn made %d calls, want 2 (tool round + final)", len(models))
	}
	models = nil

	if _, _, _, _, err := agentLoop.Run(context.Background(), "plain question", nil, ""); err != nil {
		t.Fatalf("plain run failed: %v", err)
	}
	for i, m := range models {
		if m != "flash" {
			t.Errorf("plain turn round %d used %q, want flash", i+1, m)
		}
	}
}

// heavyRequested: case-insensitive substring; no keywords = never heavy.
// Keywords WITHOUT a configured heavy model must not route (ChatCompletionHeavy
// would error every turn) — the guard lives in runWithEscalation.
func TestHeavyRequestedGuard(t *testing.T) {
	l := &Loop{}
	l.SetHeavyKeywords([]string{"ultrathink", "deep research"})
	if !l.heavyRequested("please ULTRATHINK this") {
		t.Error("case-insensitive trigger not matched")
	}
	if !l.heavyRequested("run a deep research on incidents") {
		t.Error("multi-word trigger not matched")
	}
	if l.heavyRequested("plain question") {
		t.Error("non-matching text routed heavy")
	}
	empty := &Loop{}
	if empty.heavyRequested("ultrathink") {
		t.Error("no keywords must never route heavy")
	}
	// HeavyTurn also requires a configured heavy model — without one the
	// trigger must not claim heavy (the ack would lie about the routing).
	withHeavy := &Loop{llmClient: llm.NewClient(&config.Config{LLMBaseURL: "http://127.0.0.1:1", LLMAPIKey: "k", LLMModel: "flash", HeavyModel: "heavy"})}
	withHeavy.SetHeavyKeywords([]string{"ultrathink"})
	if !withHeavy.HeavyTurn("ULTRATHINK this") {
		t.Error("trigger + heavy model must route")
	}
	if withHeavy.HeavyTurn("plain question") {
		t.Error("non-matching text must not route")
	}
	noHeavy := &Loop{llmClient: llm.NewClient(&config.Config{LLMBaseURL: "http://127.0.0.1:1", LLMAPIKey: "k", LLMModel: "flash"})}
	noHeavy.SetHeavyKeywords([]string{"ultrathink"})
	if noHeavy.HeavyTurn("ultrathink") {
		t.Error("trigger without a heavy model must NOT route — the ack would lie")
	}
}

func TestLogContextCorrelation(t *testing.T) {
	// 1. Empty context
	taskID, missionID := FromContext(context.Background())
	if taskID != "" || missionID != "" {
		t.Errorf("expected empty taskID and missionID, got: %q, %q", taskID, missionID)
	}
	if p := LogPrefix(context.Background()); p != "" {
		t.Errorf("expected empty log prefix, got: %q", p)
	}

	// 2. Context with task_id only
	ctx := context.WithValue(context.Background(), TaskIDKey, "task-999")
	taskID, missionID = FromContext(ctx)
	if taskID != "task-999" || missionID != "" {
		t.Errorf("expected task-999 and empty missionID, got: %q, %q", taskID, missionID)
	}
	if p := LogPrefix(ctx); p != "[task task-999] " {
		t.Errorf("unexpected prefix: %q", p)
	}

	// 3. Context with both task_id and mission_id
	ctx = context.WithValue(ctx, MissionIDKey, "mission-111")
	taskID, missionID = FromContext(ctx)
	if taskID != "task-999" || missionID != "mission-111" {
		t.Errorf("expected task-999 and mission-111, got: %q, %q", taskID, missionID)
	}
	if p := LogPrefix(ctx); p != "[task task-999] " {
		t.Errorf("unexpected prefix: %q", p)
	}

	// 4. Context with mission_id only
	ctxMissionOnly := context.WithValue(context.Background(), MissionIDKey, "mission-222")
	taskID, missionID = FromContext(ctxMissionOnly)
	if taskID != "" || missionID != "mission-222" {
		t.Errorf("expected empty taskID and mission-222, got: %q, %q", taskID, missionID)
	}
	if p := LogPrefix(ctxMissionOnly); p != "[mission mission-222] " {
		t.Errorf("unexpected prefix: %q", p)
	}
}

// A secret cut by the byte-truncator loses its head/tail below the redaction
// regexes' minimum lengths — redact BEFORE truncating, not after.
func TestToolResultRedactedBeforeTruncation(t *testing.T) {
	// Pad so the secret straddles the truncation boundary.
	// Pad so a partial GitHub PAT straddles the truncation boundary: after a
	// byte cut only "ghp_AAA" survives — too short for the PAT regex, so
	// redact-after-truncate leaves it in context; redact-before catches it.
	padding := strings.Repeat("A", 7994)
	output := padding + " ghp_AAAABBBBCCCCDDDDeee tail"

	step := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		step++
		var resp llm.ChatResponse
		if step == 1 {
			resp = llm.ChatResponse{Choices: []llm.ChatChoice{{
				Message: llm.ChatMessage{Role: "assistant", ToolCalls: []llm.ToolCall{{
					ID: "call_1", Type: "function",
					Function: llm.FunctionCall{Name: "leaky", Arguments: `{}`},
				}}},
				FinishReason: "tool_calls",
			}}}
		} else {
			resp = llm.ChatResponse{Choices: []llm.ChatChoice{{
				Message: llm.ChatMessage{Role: "assistant", Content: "done"}, FinishReason: "stop",
			}}}
		}
		_ = json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	cfg := &config.Config{LLMBaseURL: server.URL, LLMAPIKey: "k", LLMModel: "m"}
	reg := tools.NewRegistry(nil)
	reg.Register(
		llm.ToolDefinition{Type: "function", Function: llm.ToolFunctionDef{Name: "leaky", Description: "leaks"}},
		func(ctx context.Context, argsJSON string) (string, error) { return output, nil },
	)
	loop := NewLoop(llm.NewClient(cfg), reg, 5)

	_, messages, _, _, err := loop.Run(context.Background(), "run leaky", nil, "")
	if err != nil {
		t.Fatalf("run: %v", err)
	}
	found := false
	for _, m := range messages {
		if m.Role == "tool" {
			found = true
			if strings.Contains(m.Content, "ghp_") {
				t.Errorf("partial PAT survived truncation+redaction: %q", m.Content[max(0, len(m.Content)-80):])
			}
		}
	}
	if !found {
		t.Error("no tool result in history")
	}
}

// A 429/5xx/network blip must NOT burn a 30-round heavy-model retry — only
// iteration-budget exhaustion escalates.
func TestEscalationOnlyOnBudgetExhaustion(t *testing.T) {
	var cheapCalls, heavyCalls int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var req struct {
			Model string `json:"model"`
		}
		_ = json.NewDecoder(r.Body).Decode(&req)
		if req.Model == "heavy" {
			atomic.AddInt32(&heavyCalls, 1)
		} else {
			atomic.AddInt32(&cheapCalls, 1)
		}
		http.Error(w, `{"error":{"message":"rate limited"}}`, http.StatusTooManyRequests)
	}))
	defer srv.Close()

	cfg := &config.Config{LLMBaseURL: srv.URL, LLMAPIKey: "k", LLMModel: "cheap", HeavyModel: "heavy"}
	reg := tools.NewRegistry(nil)
	loop := NewLoop(llm.NewClient(cfg), reg, 5)

	_, _, _, _, err := loop.runWithEscalation(context.Background(), "hi", nil, nil, "")
	if err == nil || !strings.Contains(err.Error(), "rate limited") {
		t.Fatalf("expected the original 429 error, got: %v", err)
	}
	// Count by MODEL: the cheap call legitimately retries the 429 ladder
	// (client.go), so total hits exceed 1 — but escalation to the heavy
	// model must never fire for a non-budget error.
	if got := atomic.LoadInt32(&heavyCalls); got != 0 {
		t.Errorf("429 escalated to the heavy model: %d heavy calls, want 0", got)
	}
	if got := atomic.LoadInt32(&cheapCalls); got == 0 {
		t.Error("cheap model never called")
	}
}

// D6: with NO tool results to omit, PruneContextBudget used to give up and
// ship an over-budget request — it now falls back to blanking the oldest
// USER message (never the last one; users carry no tool_calls).
func TestPruneContextBudgetFallsBackToUserMessages(t *testing.T) {
	long := strings.Repeat("x", 600)
	msgs := []llm.ChatMessage{
		{Role: "system", Content: strings.Repeat("s", 600)},
		{Role: "user", Content: long},
		{Role: "assistant", Content: long},
		{Role: "user", Content: "current question"},
	}
	PruneContextBudget(msgs, 1500)
	if msgs[1].Content != "" {
		t.Errorf("oldest user message not blanked: %d chars", len(msgs[1].Content))
	}
	if msgs[3].Content != "current question" {
		t.Errorf("the live question was touched: %q", msgs[3].Content)
	}
	if msgs[0].Content == "" || msgs[2].Content == "" {
		t.Error("system/assistant messages must survive the user-fallback")
	}
}

// B1: tools report their START to a ctx-keyed progress hook — the AI card
// shows "checking X…" instead of a 30s black hole during multi-tool turns.
func TestToolProgressHookFires(t *testing.T) {
	step := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		step++
		var resp llm.ChatResponse
		if step == 1 {
			resp = llm.ChatResponse{
				Choices: []llm.ChatChoice{{
					Message: llm.ChatMessage{
						Role: "assistant",
						ToolCalls: []llm.ToolCall{{
							ID:       "call_1",
							Type:     "function",
							Function: llm.FunctionCall{Name: "probe_tool", Arguments: "{}"},
						}},
					},
					FinishReason: "tool_calls",
				}},
			}
		} else {
			resp = llm.ChatResponse{Choices: []llm.ChatChoice{{
				Message: llm.ChatMessage{Role: "assistant", Content: "done"}, FinishReason: "stop",
			}}}
		}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	reg := tools.NewRegistry(nil)
	reg.Register(llm.ToolDefinition{Type: "function", Function: llm.ToolFunctionDef{Name: "probe_tool"}},
		func(ctx context.Context, _ string) (string, error) { return "ok", nil })

	agentLoop := NewLoop(
		llm.NewClient(&config.Config{LLMBaseURL: server.URL, LLMAPIKey: "k", LLMModel: "m"}),
		reg, 4,
	)
	var names []string
	var mu sync.Mutex
	ctx := tools.WithToolProgress(context.Background(), func(name string) {
		mu.Lock()
		names = append(names, name)
		mu.Unlock()
	})
	_, _, _, _, err := agentLoop.Run(ctx, "run it", nil, "")
	if err != nil {
		t.Fatalf("run: %v", err)
	}
	mu.Lock()
	defer mu.Unlock()
	if len(names) == 0 || names[0] != "probe_tool" {
		t.Errorf("progress hook not fired with the tool name: %v", names)
	}
}

// The skills bridge: the prompt must teach both tools (a tool the prompt
// doesn't mention is never chosen) and the constitution must ALLOW them —
// methodology content is otherwise off-scope under "Nothing else".
func TestSkillsPromptAndConstitution(t *testing.T) {
	for _, tool := range []string{"list_skills", "load_skill"} {
		if !strings.Contains(SkillsPrompt, tool) {
			t.Errorf("SkillsPrompt does not mention %s — the model will never use it", tool)
		}
		if !strings.Contains(ConstitutionTail, tool) {
			t.Errorf("ConstitutionTail scope does not name %s — the constitution forbids what the tool does", tool)
		}
	}
	if !strings.Contains(SkillsPrompt, "role-") {
		t.Error("SkillsPrompt does not teach persona entries (role-<key>)")
	}
}

// PruneContextBudget blanks the OLDEST tool result first — which is exactly
// the skill the turn loaded at its start. load_skill results survive the
// first pass; they go only when no other tool result remains.
func TestPruneContextBudgetSparesLoadedSkills(t *testing.T) {
	long := strings.Repeat("x", 600)
	msgs := []llm.ChatMessage{
		{Role: "system", Content: strings.Repeat("s", 600)},
		{Role: "user", Content: "how do we run an RCA?"},
		{Role: "assistant", Content: "", ToolCalls: []llm.ToolCall{{ID: "c1", Function: llm.FunctionCall{Name: "load_skill"}}}},
		{Role: "tool", Name: "load_skill", ToolCallID: "c1", Content: long},
		{Role: "assistant", Content: "", ToolCalls: []llm.ToolCall{{ID: "c2", Function: llm.FunctionCall{Name: "search_incidents"}}}},
		{Role: "tool", Name: "search_incidents", ToolCallID: "c2", Content: long},
		{Role: "assistant", Content: "answer"},
		{Role: "user", Content: "current question"},
	}
	PruneContextBudget(msgs, 1500)
	if msgs[5].Content != "[tool result omitted]" {
		t.Errorf("the non-skill result must blank first, got %d chars", len(msgs[5].Content))
	}
	if msgs[3].Content != long {
		t.Errorf("the loaded skill must survive while a non-skill result remains, got %d chars", len(msgs[3].Content))
	}
}

func TestPruneContextBudgetBlanksLoadedSkillAsLastResort(t *testing.T) {
	long := strings.Repeat("x", 600)
	msgs := []llm.ChatMessage{
		{Role: "system", Content: strings.Repeat("s", 600)},
		{Role: "user", Content: "how do we run an RCA?"},
		{Role: "tool", Name: "load_skill", Content: long},
		{Role: "user", Content: "current question"},
	}
	PruneContextBudget(msgs, 1000)
	if msgs[2].Content != "[tool result omitted]" {
		t.Errorf("with no other tool result left, the loaded skill must blank, got %d chars", len(msgs[2].Content))
	}
	if msgs[3].Content != "current question" {
		t.Errorf("the live question was touched: %q", msgs[3].Content)
	}
}
