package llm

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"techrisk-dashboard/dingtalk-agent/internal/config"
)

func TestDecodeResponseFixtureWithToolCalls(t *testing.T) {
	fixture := `{
		"id": "chatcmpl-test-123",
		"object": "chat.completion",
		"created": 1677652288,
		"model": "gpt-4o-mini",
		"choices": [{
			"index": 0,
			"message": {
				"role": "assistant",
				"content": null,
				"tool_calls": [{
					"id": "call_abc123",
					"type": "function",
					"function": {
						"name": "analyze_logs",
						"arguments": "{\"path\":\"/var/log/app.log\",\"top\":5}"
					}
				}]
			},
			"finish_reason": "tool_calls"
		}]
	}`

	var resp ChatResponse
	if err := json.Unmarshal([]byte(fixture), &resp); err != nil {
		t.Fatalf("failed to unmarshal fixture: %v", err)
	}

	if len(resp.Choices) != 1 {
		t.Fatalf("expected 1 choice, got %d", len(resp.Choices))
	}

	choice := resp.Choices[0]
	if choice.FinishReason != "tool_calls" {
		t.Errorf("expected finish_reason 'tool_calls', got '%s'", choice.FinishReason)
	}

	if len(choice.Message.ToolCalls) != 1 {
		t.Fatalf("expected 1 tool call, got %d", len(choice.Message.ToolCalls))
	}

	tc := choice.Message.ToolCalls[0]
	if tc.ID != "call_abc123" {
		t.Errorf("expected id 'call_abc123', got '%s'", tc.ID)
	}
	if tc.Function.Name != "analyze_logs" {
		t.Errorf("expected function name 'analyze_logs', got '%s'", tc.Function.Name)
	}

	var args map[string]any
	if err := json.Unmarshal([]byte(tc.Function.Arguments), &args); err != nil {
		t.Fatalf("failed to parse arguments JSON: %v", err)
	}
	if args["path"] != "/var/log/app.log" {
		t.Errorf("expected path '/var/log/app.log', got '%v'", args["path"])
	}
}

func TestChatCompletionMockServer(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/chat/completions" {
			t.Errorf("unexpected path: %s", r.URL.Path)
			http.NotFound(w, r)
			return
		}
		if r.Header.Get("Authorization") != "Bearer test-key" {
			t.Errorf("expected Bearer test-key, got '%s'", r.Header.Get("Authorization"))
		}

		resp := ChatResponse{
			ID: "chatcmpl-mock",
			Choices: []ChatChoice{
				{
					Index: 0,
					Message: ChatMessage{
						Role:    "assistant",
						Content: "Hello from mock LLM!",
					},
					FinishReason: "stop",
				},
			},
		}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	cfg := &config.Config{
		LLMBaseURL: server.URL,
		LLMAPIKey:  "test-key",
		LLMModel:   "test-model",
	}

	client := NewClient(cfg)
	msg, err := client.ChatCompletion(context.Background(), []ChatMessage{
		{Role: "user", Content: "Hi"},
	}, nil)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if msg.Content != "Hello from mock LLM!" {
		t.Errorf("unexpected message content: %s", msg.Content)
	}
}
