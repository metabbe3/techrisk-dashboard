package llm

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
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
		var body map[string]any
		_ = json.NewDecoder(r.Body).Decode(&body)
		if got, ok := body["max_tokens"].(float64); !ok || int(got) != 16384 {
			t.Errorf("max_tokens = %v, want 16384", body["max_tokens"])
		}
		if _, present := body["temperature"]; present {
			t.Error("temperature must never be sent (gateway models reject it)")
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
		LLMBaseURL:   server.URL,
		LLMAPIKey:    "test-key",
		LLMModel:     "test-model",
		LLMMaxTokens: 16384,
	}

	client := NewClient(cfg)
	msg, _, err := client.ChatCompletion(context.Background(), []ChatMessage{
		{Role: "user", Content: "Hi"},
	}, nil)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if msg.Content != "Hello from mock LLM!" {
		t.Errorf("unexpected message content: %s", msg.Content)
	}
}

// Non-stream usage parsing: present → the value; absent → zero.
func TestChatCompletionUsage(t *testing.T) {
	var lastResp *ChatResponse
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(lastResp)
	}))
	defer server.Close()

	client := NewClient(&config.Config{LLMBaseURL: server.URL, LLMAPIKey: "k", LLMModel: "m"})

	lastResp = &ChatResponse{
		Usage: &Usage{PromptTokens: 7, CompletionTokens: 3, TotalTokens: 10},
		Choices: []ChatChoice{{
			Message: ChatMessage{Role: "assistant", Content: "a"}, FinishReason: "stop",
		}},
	}
	if _, usage, err := client.ChatCompletion(context.Background(), []ChatMessage{{Role: "user", Content: "hi"}}, nil); err != nil {
		t.Fatalf("unexpected error: %v", err)
	} else if usage != (Usage{PromptTokens: 7, CompletionTokens: 3, TotalTokens: 10}) {
		t.Errorf("usage = %+v", usage)
	}

	lastResp = &ChatResponse{
		Choices: []ChatChoice{{
			Message: ChatMessage{Role: "assistant", Content: "b"}, FinishReason: "stop",
		}},
	}
	if _, usage, err := client.ChatCompletion(context.Background(), []ChatMessage{{Role: "user", Content: "hi"}}, nil); err != nil {
		t.Fatalf("unexpected error: %v", err)
	} else if usage != (Usage{}) {
		t.Errorf("usage = %+v, want zero when the response carries none", usage)
	}
}

func TestUsageAdd(t *testing.T) {
	got := Usage{PromptTokens: 1, CompletionTokens: 2, TotalTokens: 3}.
		Add(Usage{PromptTokens: 10, CompletionTokens: 20, TotalTokens: 30})
	if got != (Usage{PromptTokens: 11, CompletionTokens: 22, TotalTokens: 33}) {
		t.Errorf("Add = %+v", got)
	}
}

// Embed sends array input as a JSON array and returns vectors sorted by index.
func TestEmbedArrayInputSortedByIndex(t *testing.T) {
	var gotModel string
	var gotInput any
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/v1/embeddings" {
			t.Errorf("unexpected path: %s", r.URL.Path)
			http.NotFound(w, r)
			return
		}
		var body map[string]any
		_ = json.NewDecoder(r.Body).Decode(&body)
		gotModel, _ = body["model"].(string)
		gotInput = body["input"]
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{"data": []map[string]any{
			{"index": 1, "embedding": []float32{0, 1}},
			{"index": 0, "embedding": []float32{1, 0}},
		}})
	}))
	defer server.Close()

	client := NewClient(&config.Config{LLMBaseURL: server.URL, LLMAPIKey: "k", LLMModel: "chat", EmbeddingModel: "embed-model"})
	vecs, err := client.Embed(context.Background(), []string{"a", "b"})
	if err != nil {
		t.Fatal(err)
	}
	if gotModel != "embed-model" {
		t.Errorf("model = %q, want embed-model", gotModel)
	}
	if arr, ok := gotInput.([]any); !ok || len(arr) != 2 {
		t.Errorf("input = %#v, want a 2-item array", gotInput)
	}
	if len(vecs) != 2 || vecs[0][0] != 1 || vecs[1][1] != 1 {
		t.Errorf("vectors not sorted by index: %v", vecs)
	}
}

// A gateway that rejects array input falls back to one request per chunk and
// stays in single mode afterwards; a single chunk was always sent as a string.
func TestEmbedFallsBackToPerChunk(t *testing.T) {
	var arrayCalls, singleCalls int
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var body map[string]any
		_ = json.NewDecoder(r.Body).Decode(&body)
		w.Header().Set("Content-Type", "application/json")
		if _, ok := body["input"].([]any); ok {
			arrayCalls++
			http.Error(w, `{"error":{"message":"array not supported"}}`, http.StatusForbidden)
			return
		}
		singleCalls++
		_ = json.NewEncoder(w).Encode(map[string]any{"data": []map[string]any{
			{"index": 0, "embedding": []float32{1, 2, 3}},
		}})
	}))
	defer server.Close()

	client := NewClient(&config.Config{LLMBaseURL: server.URL, LLMAPIKey: "k", LLMModel: "chat"})
	vecs, err := client.Embed(context.Background(), []string{"a", "b"})
	if err != nil {
		t.Fatal(err)
	}
	if len(vecs) != 2 || vecs[1][2] != 3 {
		t.Fatalf("fallback vectors wrong: %v", vecs)
	}
	if arrayCalls != 1 || singleCalls != 2 {
		t.Fatalf("calls: array=%d single=%d, want 1/2", arrayCalls, singleCalls)
	}

	// Later calls stay in single mode even for multiple inputs.
	if _, err := client.Embed(context.Background(), []string{"c", "d", "e"}); err != nil {
		t.Fatal(err)
	}
	if arrayCalls != 1 || singleCalls != 5 {
		t.Fatalf("single mode not sticky: array=%d single=%d", arrayCalls, singleCalls)
	}
}

// Empty input short-circuits; a hard gateway error propagates.
func TestEmbedEmptyAndError(t *testing.T) {
	client := NewClient(&config.Config{LLMModel: "chat"})
	if vecs, err := client.Embed(context.Background(), nil); err != nil || vecs != nil {
		t.Fatalf("empty input = %v, %v; want nil, nil", vecs, err)
	}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, "boom", http.StatusInternalServerError)
	}))
	defer server.Close()
	client = NewClient(&config.Config{LLMBaseURL: server.URL, LLMAPIKey: "k", LLMModel: "chat"})
	if _, err := client.Embed(context.Background(), []string{"a"}); err == nil {
		t.Fatal("expected error on gateway failure")
	}
}

// Vision posts an OpenAI content-parts request (text + image_url data URL)
// under the vision model and returns the transcription.
func TestVision(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/chat/completions" {
			t.Errorf("unexpected path: %s", r.URL.Path)
			http.NotFound(w, r)
			return
		}
		if r.Header.Get("Authorization") != "Bearer test-key" {
			t.Errorf("expected Bearer test-key")
		}
		var body map[string]any
		_ = json.NewDecoder(r.Body).Decode(&body)
		if body["model"] != "vision-model" {
			t.Errorf("model = %v, want vision-model", body["model"])
		}
		msgs, _ := body["messages"].([]any)
		if len(msgs) != 1 {
			t.Fatalf("want 1 message, got %d", len(msgs))
		}
		content, _ := msgs[0].(map[string]any)["content"].([]any)
		if len(content) != 2 {
			t.Fatalf("want 2 content parts, got %d", len(content))
		}
		p0, _ := content[0].(map[string]any)
		p1, _ := content[1].(map[string]any)
		if p0["type"] != "text" || p0["text"] != "what is this" {
			t.Errorf("text part wrong: %v", p0)
		}
		if p1["type"] != "image_url" {
			t.Errorf("image part type wrong: %v", p1)
		}
		iu, _ := p1["image_url"].(map[string]any)
		u, _ := iu["url"].(string)
		if !strings.HasPrefix(u, "data:image/png;base64,") {
			t.Errorf("image url = %.40s..., want data:image/png;base64, prefix", u)
		}

		resp := ChatResponse{
			ID: "chatcmpl-vis",
			Choices: []ChatChoice{{
				Index:        0,
				Message:      ChatMessage{Role: "assistant", Content: "a red button"},
				FinishReason: "stop",
			}},
		}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	cfg := &config.Config{LLMBaseURL: server.URL, LLMAPIKey: "test-key", LLMModel: "chat-model", VisionModel: "vision-model"}
	c := NewClient(cfg)
	out, err := c.Vision(context.Background(), "what is this", []byte("fakepng"), "image/png")
	if err != nil {
		t.Fatal(err)
	}
	if out != "a red button" {
		t.Fatalf("Vision = %q, want %q", out, "a red button")
	}
}

// No VisionModel configured → the vision call rides the chat model.
func TestVisionModelDefaultsToChatModel(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var body map[string]any
		_ = json.NewDecoder(r.Body).Decode(&body)
		if body["model"] != "chat-model" {
			t.Errorf("model = %v, want chat-model", body["model"])
		}
		resp := ChatResponse{Choices: []ChatChoice{{Message: ChatMessage{Content: "ok"}}}}
		_ = json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	c := NewClient(&config.Config{LLMBaseURL: server.URL, LLMModel: "chat-model"})
	if _, err := c.Vision(context.Background(), "p", []byte("img"), "image/png"); err != nil {
		t.Fatal(err)
	}
}

// A gateway rejection surfaces its error message.
func TestVisionErrorSurfaces(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusBadRequest)
		_, _ = w.Write([]byte(`{"error":{"message":"model does not support image parts"}}`))
	}))
	defer server.Close()

	c := NewClient(&config.Config{LLMBaseURL: server.URL, LLMModel: "m", VisionModel: "m"})
	_, err := c.Vision(context.Background(), "p", []byte("img"), "image/png")
	if err == nil || !strings.Contains(err.Error(), "model does not support image parts") {
		t.Fatalf("err = %v, want gateway message", err)
	}
}
