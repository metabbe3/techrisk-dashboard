package agent

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
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
		func(argsJSON string) (string, error) { return "Directory listing: 3 files", nil },
	)
	agentLoop := NewLoop(llmClient, reg, 5)

	finalText, messages, _, err := agentLoop.Run(context.Background(), "list current directory", nil, "")
	if err != nil {
		t.Fatalf("agent loop failed: %v", err)
	}

	if finalText != "Found the files successfully." {
		t.Errorf("unexpected final text: %s", finalText)
	}

	// Verify messages: system, user, assistant (tool_call), tool (result), assistant (final)
	if len(messages) < 5 {
		t.Errorf("expected at least 5 messages in conversation history, got %d", len(messages))
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
		func(argsJSON string) (string, error) { return "first tool result", nil },
	)
	reg.Register(
		llm.ToolDefinition{Type: "function", Function: llm.ToolFunctionDef{Name: "stub_two", Description: "test stub"}},
		func(argsJSON string) (string, error) { return "second tool result", nil },
	)
	agentLoop := NewLoop(llmClient, reg, 5)

	finalText, messages, _, err := agentLoop.Run(context.Background(), "run parallel diagnostics", nil, "")
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

	if _, _, _, err := agentLoop.Run(context.Background(), "hi", nil,
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

		if !strings.Contains(sys, "Current date/time: ") {
			t.Errorf("current date/time missing from system prompt: %.200s", sys)
		}
		// WIB fixed zone — format 'Mon 2006-01-02 15:04 MST' must round-trip.
		idx := strings.Index(sys, "Current date/time: ")
		line := strings.TrimSpace(sys[idx+len("Current date/time: "):])
		if _, err := time.Parse("Mon 2006-01-02 15:04 MST", line); err != nil {
			t.Errorf("date line not parseable: %q", line)
		}
	}))
	defer server.Close()

	cfg := &config.Config{LLMBaseURL: server.URL, LLMAPIKey: "k", LLMModel: "m"}
	agentLoop := NewLoop(llm.NewClient(cfg), tools.NewRegistry(nil), 2)
	if _, _, _, err := agentLoop.Run(context.Background(), "hi", nil, ""); err != nil {
		t.Fatalf("run failed: %v", err)
	}
}
