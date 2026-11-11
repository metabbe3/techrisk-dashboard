package llm

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"sort"
	"strings"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/config"
)

// FunctionCall represents the function called by the assistant.
type FunctionCall struct {
	Name      string `json:"name"`
	Arguments string `json:"arguments"`
}

// ToolCall represents a tool call requested by the model.
type ToolCall struct {
	ID       string       `json:"id"`
	Type     string       `json:"type"` // always "function"
	Function FunctionCall `json:"function"`
}

// ChatMessage represents a single message in the chat conversation.
type ChatMessage struct {
	Role       string     `json:"role"`                   // "system", "user", "assistant", "tool"
	Content    string     `json:"content,omitempty"`      // text content
	ToolCalls  []ToolCall `json:"tool_calls,omitempty"`   // if assistant requests tool calls
	ToolCallID string     `json:"tool_call_id,omitempty"` // if role is "tool"
	Name       string     `json:"name,omitempty"`         // optional function name for tool
}

// ToolDefinition represents the OpenAI tool definition schema.
type ToolDefinition struct {
	Type     string          `json:"type"` // "function"
	Function ToolFunctionDef `json:"function"`
}

// ToolFunctionDef represents the metadata and parameters of a tool function.
type ToolFunctionDef struct {
	Name        string `json:"name"`
	Description string `json:"description"`
	Parameters  any    `json:"parameters"` // JSON Schema map or struct
}

// ChatRequest represents the payload sent to /chat/completions.
type ChatRequest struct {
	Model     string           `json:"model"`
	Messages  []ChatMessage    `json:"messages"`
	Tools     []ToolDefinition `json:"tools,omitempty"`
	Stream    bool             `json:"stream,omitempty"`
	MaxTokens int              `json:"max_tokens,omitempty"` // 0 = omit; temperature is NEVER sent (gateway models reject it)
}

// chatStreamChunk is one SSE frame of a streaming completion.
type chatStreamChunk struct {
	Choices []struct {
		Delta struct {
			Content   string           `json:"content"`
			ToolCalls []streamToolCall `json:"tool_calls"`
		} `json:"delta"`
		FinishReason string `json:"finish_reason"`
	} `json:"choices"`
	Usage *Usage    `json:"usage,omitempty"`
	Error *APIError `json:"error,omitempty"`
}

// streamToolCall mirrors the streaming wire format: the first chunk of a call
// carries index + id + name, later chunks append argument fragments at that index.
type streamToolCall struct {
	Index    int          `json:"index"`
	ID       string       `json:"id"`
	Function FunctionCall `json:"function"`
}

// ChatChoice represents an individual choice in the completion response.
type ChatChoice struct {
	Index        int         `json:"index"`
	Message      ChatMessage `json:"message"`
	FinishReason string      `json:"finish_reason"`
}

// ChatResponse represents the response payload from /chat/completions.
type ChatResponse struct {
	ID      string       `json:"id"`
	Choices []ChatChoice `json:"choices"`
	Usage   *Usage       `json:"usage,omitempty"`
	Error   *APIError    `json:"error,omitempty"`
}

// Usage is the token accounting of one completion. Absent on the wire = zero.
type Usage struct {
	PromptTokens     int `json:"prompt_tokens"`
	CompletionTokens int `json:"completion_tokens"`
	TotalTokens      int `json:"total_tokens"`
}

// Add returns the element-wise sum of two usage values.
func (u Usage) Add(other Usage) Usage {
	return Usage{
		PromptTokens:     u.PromptTokens + other.PromptTokens,
		CompletionTokens: u.CompletionTokens + other.CompletionTokens,
		TotalTokens:      u.TotalTokens + other.TotalTokens,
	}
}

// resolved returns the pointed-to value, or the zero Usage when nil.
func (u *Usage) resolved() Usage {
	if u == nil {
		return Usage{}
	}
	return *u
}

// APIError represents an error returned by the OpenAI API.
type APIError struct {
	Message string `json:"message"`
	Type    string `json:"type"`
	Code    any    `json:"code"`
}

// Client is an OpenAI-compatible HTTP client for chat completions.
type Client struct {
	baseURL      string
	apiKey       string
	model        string
	maxTokens    int
	httpClient   *http.Client
	embedModel   string
	embedArrayOK bool // array input accepted by /embeddings (probed lazily)
}

// NewClient initializes a new LLM client.
func NewClient(cfg *config.Config) *Client {
	baseURL := cfg.LLMBaseURL
	if baseURL == "" {
		baseURL = config.DefaultLLMBaseURL
	}
	baseURL = strings.TrimRight(baseURL, "/")

	model := cfg.LLMModel
	if model == "" {
		model = config.DefaultLLMModel
	}

	return &Client{
		baseURL:   baseURL,
		apiKey:    cfg.LLMAPIKey,
		model:     model,
		maxTokens: cfg.LLMMaxTokens,
		httpClient: &http.Client{
			Timeout: 120 * time.Second,
		},
		embedModel:   cfg.EmbeddingModel,
		embedArrayOK: true, // optimistic — flips off on the first batch failure
	}
}

// Model returns the model every completion from this client uses (resolved
// default included) — e.g. for turn reports.
func (c *Client) Model() string { return c.model }

// embeddingResult is one row of the /embeddings response.
type embeddingResult struct {
	Index int       `json:"index"`
	Embed []float32 `json:"embedding"`
}

// Embed calls POST /embeddings for a batch of inputs and returns vectors in
// input order. The gateway's support for array input is unverified — try the
// batch first; on ANY failure fall back to one request per input and stick
// with single mode for later calls.
func (c *Client) Embed(ctx context.Context, inputs []string) ([][]float32, error) {
	if len(inputs) == 0 {
		return nil, nil
	}
	if !c.embedArrayOK {
		return c.embedOneByOne(ctx, inputs)
	}
	out, err := c.embedBatch(ctx, inputs)
	if err == nil {
		return out, nil
	}
	c.embedArrayOK = false // gateway rejected the array — remember
	return c.embedOneByOne(ctx, inputs)
}

func (c *Client) embedBatch(ctx context.Context, inputs []string) ([][]float32, error) {
	out, err := c.embedCall(ctx, inputs)
	if err != nil {
		return nil, err
	}
	if len(out) != len(inputs) {
		return nil, fmt.Errorf("embeddings: want %d vectors, got %d", len(inputs), len(out))
	}
	return out, nil
}

func (c *Client) embedOneByOne(ctx context.Context, inputs []string) ([][]float32, error) {
	out := make([][]float32, 0, len(inputs))
	for _, in := range inputs {
		vec, err := c.embedCall(ctx, []string{in})
		if err != nil {
			return nil, err
		}
		out = append(out, vec[0])
	}
	return out, nil
}

// embedCall performs one POST /embeddings with the given input list and
// returns vectors sorted by the API's index field.
func (c *Client) embedCall(ctx context.Context, inputs []string) ([][]float32, error) {
	var input any = inputs[0]
	if len(inputs) > 1 {
		input = inputs
	}
	payload, err := json.Marshal(map[string]any{"model": c.embedModel, "input": input})
	if err != nil {
		return nil, err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+"/v1/embeddings", bytes.NewReader(payload))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	if c.apiKey != "" {
		req.Header.Set("Authorization", "Bearer "+c.apiKey)
	}
	resp, err := c.httpClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, err
	}
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("embeddings: %s: %s", resp.Status, truncate(body, 200))
	}
	var parsed struct {
		Data []embeddingResult `json:"data"`
	}
	if err := json.Unmarshal(body, &parsed); err != nil {
		return nil, err
	}
	if len(parsed.Data) == 0 {
		return nil, fmt.Errorf("embeddings: empty data")
	}
	sort.Slice(parsed.Data, func(i, j int) bool { return parsed.Data[i].Index < parsed.Data[j].Index })
	out := make([][]float32, len(parsed.Data))
	for i, d := range parsed.Data {
		out[i] = d.Embed
	}
	return out, nil
}

func truncate(b []byte, n int) string {
	if len(b) <= n {
		return string(b)
	}
	return string(b[:n])
}

// ChatCompletion sends a request to the LLM provider and returns the assistant's
// reply plus the token usage of the call.
func (c *Client) ChatCompletion(ctx context.Context, messages []ChatMessage, tools []ToolDefinition) (*ChatMessage, Usage, error) {
	reqBody := ChatRequest{
		Model:     c.model,
		Messages:  messages,
		Tools:     tools,
		MaxTokens: c.maxTokens,
	}

	data, err := json.Marshal(reqBody)
	if err != nil {
		return nil, Usage{}, fmt.Errorf("failed to marshal chat request: %w", err)
	}

	url := c.baseURL + "/chat/completions"
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, url, bytes.NewReader(data))
	if err != nil {
		return nil, Usage{}, fmt.Errorf("failed to create http request: %w", err)
	}

	req.Header.Set("Content-Type", "application/json")
	if c.apiKey != "" {
		req.Header.Set("Authorization", "Bearer "+c.apiKey)
	}

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return nil, Usage{}, fmt.Errorf("http request failed: %w", err)
	}
	defer resp.Body.Close()

	bodyBytes, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, Usage{}, fmt.Errorf("failed to read response body: %w", err)
	}

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		var apiErr ChatResponse
		if err := json.Unmarshal(bodyBytes, &apiErr); err == nil && apiErr.Error != nil {
			return nil, Usage{}, fmt.Errorf("llm api error (status %d): %s", resp.StatusCode, apiErr.Error.Message)
		}
		return nil, Usage{}, fmt.Errorf("llm api error (status %d): %s", resp.StatusCode, string(bodyBytes))
	}

	var chatResp ChatResponse
	if err := json.Unmarshal(bodyBytes, &chatResp); err != nil {
		return nil, Usage{}, fmt.Errorf("failed to decode chat response: %w", err)
	}

	if len(chatResp.Choices) == 0 {
		return nil, Usage{}, fmt.Errorf("llm returned no choices in response")
	}

	return &chatResp.Choices[0].Message, chatResp.Usage.resolved(), nil
}

// ChatCompletionStream sends a streaming request and returns the assembled assistant
// message. Text deltas are forwarded to onDelta as they arrive (may be nil); tool
// calls are accumulated across chunks exactly like the OpenAI wire format delivers them.
// Usage arrives on the final SSE frame — the last non-nil frame wins.
func (c *Client) ChatCompletionStream(ctx context.Context, messages []ChatMessage, tools []ToolDefinition, onDelta func(string)) (*ChatMessage, Usage, error) {
	if onDelta == nil {
		onDelta = func(string) {}
	}

	reqBody := ChatRequest{
		Model:     c.model,
		Messages:  messages,
		Tools:     tools,
		Stream:    true,
		MaxTokens: c.maxTokens,
	}

	data, err := json.Marshal(reqBody)
	if err != nil {
		return nil, Usage{}, fmt.Errorf("failed to marshal chat request: %w", err)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+"/chat/completions", bytes.NewReader(data))
	if err != nil {
		return nil, Usage{}, fmt.Errorf("failed to create http request: %w", err)
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "text/event-stream")
	if c.apiKey != "" {
		req.Header.Set("Authorization", "Bearer "+c.apiKey)
	}

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return nil, Usage{}, fmt.Errorf("http request failed: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		bodyBytes, _ := io.ReadAll(io.LimitReader(resp.Body, 64<<10))
		var apiErr ChatResponse
		if err := json.Unmarshal(bodyBytes, &apiErr); err == nil && apiErr.Error != nil {
			return nil, Usage{}, fmt.Errorf("llm api error (status %d): %s", resp.StatusCode, apiErr.Error.Message)
		}
		return nil, Usage{}, fmt.Errorf("llm api error (status %d): %s", resp.StatusCode, string(bodyBytes))
	}

	var assembled ChatMessage
	var usage Usage
	toolCalls := map[int]*ToolCall{}

	scanner := bufio.NewScanner(resp.Body)
	scanner.Buffer(make([]byte, 0, 64<<10), 256<<10)
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, ":") {
			continue
		}
		if !strings.HasPrefix(line, "data:") {
			continue
		}
		payload := strings.TrimSpace(strings.TrimPrefix(line, "data:"))
		if payload == "" || payload == "[DONE]" {
			continue
		}

		var chunk chatStreamChunk
		if err := json.Unmarshal([]byte(payload), &chunk); err != nil {
			continue // tolerate keep-alives and unknown frames
		}
		if chunk.Error != nil {
			return nil, Usage{}, fmt.Errorf("llm stream error: %s", chunk.Error.Message)
		}
		if chunk.Usage != nil {
			usage = *chunk.Usage
		}
		if len(chunk.Choices) == 0 {
			continue
		}

		delta := chunk.Choices[0].Delta
		if delta.Content != "" {
			assembled.Content += delta.Content
			onDelta(delta.Content)
		}
		for _, tc := range delta.ToolCalls {
			acc, exists := toolCalls[tc.Index]
			if !exists {
				acc = &ToolCall{ID: tc.ID, Type: "function"}
				toolCalls[tc.Index] = acc
			}
			if tc.ID != "" {
				acc.ID = tc.ID
			}
			if tc.Function.Name != "" {
				acc.Function.Name = tc.Function.Name
			}
			acc.Function.Arguments += tc.Function.Arguments
		}
	}
	if err := scanner.Err(); err != nil {
		return nil, Usage{}, fmt.Errorf("llm stream read failed: %w", err)
	}

	if len(toolCalls) > 0 {
		indices := make([]int, 0, len(toolCalls))
		for idx := range toolCalls {
			indices = append(indices, idx)
		}
		sort.Ints(indices)
		for _, idx := range indices {
			assembled.ToolCalls = append(assembled.ToolCalls, *toolCalls[idx])
		}
	}

	return &assembled, usage, nil
}
