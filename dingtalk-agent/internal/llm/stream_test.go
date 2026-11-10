package llm

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"

	"techrisk-dashboard/dingtalk-agent/internal/config"
)

func streamClient(t *testing.T, handler http.HandlerFunc) *Client {
	t.Helper()
	srv := httptest.NewServer(handler)
	t.Cleanup(srv.Close)
	return NewClient(&config.Config{LLMBaseURL: srv.URL, LLMAPIKey: "k", LLMModel: "m"})
}

func sse(frames ...string) string {
	out := ""
	for _, f := range frames {
		out += "data: " + f + "\n\n"
	}
	return out + "data: [DONE]\n\n"
}

func TestChatCompletionStreamContent(t *testing.T) {
	client := streamClient(t, func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/event-stream")
		fmt.Fprint(w, sse(
			`{"choices":[{"delta":{"content":"Hello"}}]}`,
			`{"choices":[{"delta":{"content":" world"}}]}`,
			`: keep-alive`,
			`{"choices":[{"delta":{},"finish_reason":"stop"}]}`,
		))
	})

	var got string
	msg, err := client.ChatCompletionStream(context.Background(), []ChatMessage{{Role: "user", Content: "hi"}}, nil, func(d string) { got += d })
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if msg.Content != "Hello world" {
		t.Errorf("content = %q, want %q", msg.Content, "Hello world")
	}
	if got != "Hello world" {
		t.Errorf("onDelta got %q, want %q", got, "Hello world")
	}
}

func TestChatCompletionStreamToolCalls(t *testing.T) {
	client := streamClient(t, func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/event-stream")
		fmt.Fprint(w, sse(
			`{"choices":[{"delta":{"tool_calls":[{"index":1,"id":"call-b","function":{"name":"get_incident"}}]}}]}`,
			`{"choices":[{"delta":{"tool_calls":[{"index":0,"id":"call-a","function":{"name":"search_incidents"}}]}}]}`,
			`{"choices":[{"delta":{"tool_calls":[{"index":1,"function":{"arguments":"{\"no\""}}]}}]}`,
			`{"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"{}"}}]}}]}`,
			`{"choices":[{"delta":{"tool_calls":[{"index":1,"function":{"arguments":":\"X1\"}"}}]}}]}`,
		))
	})

	msg, err := client.ChatCompletionStream(context.Background(), []ChatMessage{{Role: "user", Content: "hi"}}, nil, nil)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(msg.ToolCalls) != 2 {
		t.Fatalf("tool calls = %d, want 2", len(msg.ToolCalls))
	}
	// Index order: call-a (search_incidents) first even though call-b arrived first.
	if msg.ToolCalls[0].ID != "call-a" || msg.ToolCalls[0].Function.Name != "search_incidents" || msg.ToolCalls[0].Function.Arguments != "{}" {
		t.Errorf("toolCalls[0] = %+v", msg.ToolCalls[0])
	}
	if msg.ToolCalls[1].ID != "call-b" || msg.ToolCalls[1].Function.Arguments != `{"no":"X1"}` {
		t.Errorf("toolCalls[1] = %+v", msg.ToolCalls[1])
	}
}

func TestChatCompletionStreamErrorFrame(t *testing.T) {
	client := streamClient(t, func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/event-stream")
		fmt.Fprint(w, sse(`{"error":{"message":"rate limited"}}`))
	})
	if _, err := client.ChatCompletionStream(context.Background(), []ChatMessage{{Role: "user", Content: "hi"}}, nil, nil); err == nil {
		t.Fatal("expected error from stream error frame")
	}
}

func TestChatCompletionStreamHTTPError(t *testing.T) {
	client := streamClient(t, func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, `{"error":{"message":"bad key"}}`, http.StatusUnauthorized)
	})
	if _, err := client.ChatCompletionStream(context.Background(), []ChatMessage{{Role: "user", Content: "hi"}}, nil, nil); err == nil {
		t.Fatal("expected error from HTTP 401")
	}
}
