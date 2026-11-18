package llm

import (
	"bufio"
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"sort"
	"strings"
	"sync/atomic"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/util"
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
	heavyModel   string // smarter escalation model; empty = none
	visionModel  string // image transcription; defaults to model
	maxTokens    int
	httpClient   *http.Client
	embedModel   string
	embedArrayOK atomic.Bool // array input accepted by /embeddings (probed lazily)
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
	visionModel := cfg.VisionModel
	if visionModel == "" {
		visionModel = model // chat model must be multimodal
	}
	heavyModel := cfg.HeavyModel // empty = escalation disabled

	c := &Client{
		baseURL:     baseURL,
		apiKey:      cfg.LLMAPIKey,
		model:       model,
		heavyModel:  heavyModel,
		visionModel: visionModel,
		maxTokens:   cfg.LLMMaxTokens,
		embedModel:  cfg.EmbeddingModel,

		// No overall Client.Timeout: long streaming answers (big reports
		// through the tool loop) legitimately exceed any fixed ceiling and
		// Timeout kills the connection mid-stream. ResponseHeaderTimeout
		// still fails fast on a dead gateway; the callers' contexts bound
		// the rest.
		httpClient: &http.Client{
			Transport: &http.Transport{ResponseHeaderTimeout: 120 * time.Second},
		},
	}
	c.embedArrayOK.Store(true) // optimistic — flips off on a definitive 4xx/count mismatch

	return c
}

// UsagePtr returns a pointer for JSON omitempty, or nil when zero.
func UsagePtr(u Usage) *Usage {
	if u == (Usage{}) {
		return nil
	}
	return &u
}

// Model returns the model every completion from this client uses (resolved
// default included) — e.g. for turn reports.
func (c *Client) Model() string { return c.model }

// HeavyModel returns the escalation model ("" when unset).
func (c *Client) HeavyModel() string { return c.heavyModel }

// Ping verifies connectivity to the LLM endpoint by requesting a minimal completion.
func (c *Client) Ping(ctx context.Context) error {
	pingCtx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()

	reqBody := ChatRequest{
		Model: c.model,
		Messages: []ChatMessage{
			{Role: "user", Content: "ping"},
		},
		MaxTokens: 1,
	}
	_, _, err := c.doCompletion(pingCtx, reqBody)
	if err != nil && (strings.Contains(err.Error(), "max_tokens") || strings.Contains(err.Error(), "400")) {
		reqBody.MaxTokens = 0
		_, _, err = c.doCompletion(pingCtx, reqBody)
	}
	return err
}

// ChatCompletionHeavy retries the same completion on the heavy (smarter)
// model. Returns an error when no heavy model is configured.
func (c *Client) ChatCompletionHeavy(ctx context.Context, messages []ChatMessage, tools []ToolDefinition) (*ChatMessage, Usage, error) {
	if c.heavyModel == "" {
		return nil, Usage{}, fmt.Errorf("no heavy model configured")
	}
	reqBody := ChatRequest{
		Model:     c.heavyModel,
		Messages:  messages,
		Tools:     tools,
		MaxTokens: c.maxTokens,
	}
	return c.doCompletion(ctx, reqBody)
}

// postJSON builds and sends one JSON POST with the client's auth headers.
// The response body is NOT read here — the streaming caller needs it raw.
// One implementation for every request path (doCompletion, embedCall,
// Vision, ChatCompletionStream) that used to marshal/build/header/Do by hand.
func (c *Client) postJSON(ctx context.Context, path string, payload any, accept string) (*http.Response, error) {
	data, err := json.Marshal(payload)
	if err != nil {
		return nil, fmt.Errorf("failed to marshal request: %w", err)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+path, bytes.NewReader(data))
	if err != nil {
		return nil, fmt.Errorf("failed to create http request: %w", err)
	}

	req.Header.Set("Content-Type", "application/json")
	if accept != "" {
		req.Header.Set("Accept", accept)
	}
	if c.apiKey != "" {
		req.Header.Set("Authorization", "Bearer "+c.apiKey)
	}

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("http request failed: %w", err)
	}
	return resp, nil
}

// apiError builds the error for a non-2xx response: the gateway's structured
// error message when parseable, else the rune-safe capped body — a chatty
// gateway error must not dump unbounded bytes into one log line (the old
// doCompletion/Vision branches read the whole body; the stream path capped at
// 64KB; embedCall truncated by bytes, splitting CJK).
func (c *Client) apiError(status int, body []byte) error {
	var apiErr ChatResponse
	if err := json.Unmarshal(body, &apiErr); err == nil && apiErr.Error != nil {
		return fmt.Errorf("llm api error (status %d): %s", status, apiErr.Error.Message)
	}
	return fmt.Errorf("llm api error (status %d): %s", status, util.Cut(string(body), 200))
}

func (c *Client) doCompletion(ctx context.Context, reqBody ChatRequest) (*ChatMessage, Usage, error) {
	resp, err := c.postJSON(ctx, "/chat/completions", reqBody, "")
	if err != nil {
		return nil, Usage{}, err
	}
	defer resp.Body.Close()

	bodyBytes, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, Usage{}, fmt.Errorf("failed to read response body: %w", err)
	}

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, Usage{}, c.apiError(resp.StatusCode, bodyBytes)
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
	if !c.embedArrayOK.Load() {
		return c.embedOneByOne(ctx, inputs)
	}
	out, err := c.embedBatch(ctx, inputs)
	if err == nil {
		return out, nil
	}
	// Downgrade on a definitive rejection only (4xx = the gateway did not
	// understand array input; count mismatch = it processed it wrong). A
	// transport error or 5xx is transient — a single blip used to disable
	// batch embeds for the process lifetime. Written from concurrent
	// goroutines → atomic.Bool.
	if isEmbedRejection(err) {
		c.embedArrayOK.Store(false)
	}
	return c.embedOneByOne(ctx, inputs)
}

// A 4xx status or a wrong vector count is proof array input is not honoured;
// anything else (timeout, 5xx, parse flake) is not.
func isEmbedRejection(err error) bool {
	if err == nil {
		return false
	}
	msg := err.Error()
	return strings.Contains(msg, "embeddings: 4") || strings.Contains(msg, "want %d vectors") || strings.Contains(msg, "embeddings: empty data")
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
	resp, err := c.postJSON(ctx, "/v1/embeddings", map[string]any{"model": c.embedModel, "input": input}, "")
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, err
	}
	// NOT apiError — the "embeddings: <status>" shape is load-bearing:
	// isEmbedRejection greps it to decide the batch→single downgrade.
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("embeddings: %s: %s", resp.Status, util.Cut(string(body), 200))
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

// ChatCompletion sends a request to the LLM provider and returns the assistant's
// reply plus the token usage of the call.
func (c *Client) ChatCompletion(ctx context.Context, messages []ChatMessage, tools []ToolDefinition) (*ChatMessage, Usage, error) {
	reqBody := ChatRequest{
		Model:     c.model,
		Messages:  messages,
		Tools:     tools,
		MaxTokens: c.maxTokens,
	}
	return c.doCompletion(ctx, reqBody)
}

// ChatCompletionUncapped is ChatCompletion WITHOUT the chat max_tokens budget:
// MaxTokens stays the zero value, so `omitempty` drops the field from the wire
// and the provider uses its default. For background synthesis jobs (knowledge
// digest merge) — a thinking model handed the chat budget can spend all of it
// on reasoning and return empty content.
func (c *Client) ChatCompletionUncapped(ctx context.Context, messages []ChatMessage, tools []ToolDefinition) (*ChatMessage, Usage, error) {
	reqBody := ChatRequest{
		Model:    c.model,
		Messages: messages,
		Tools:    tools,
	}
	return c.doCompletion(ctx, reqBody)
}

// visionPart is one content part of a multimodal message (OpenAI wire format).
type visionPart struct {
	Type     string          `json:"type"` // "text" | "image_url"
	Text     string          `json:"text,omitempty"`
	ImageURL *visionImageURL `json:"image_url,omitempty"`
}

type visionImageURL struct {
	URL string `json:"url"`
}

// Vision transcribes/describes one image via a multimodal chat completion
// (content-parts wire format, one-shot: no tools, no streaming). The caller
// caps the image size; empty image bytes are the caller's bug, not ours.
func (c *Client) Vision(ctx context.Context, prompt string, image []byte, mimeType string) (string, error) {
	reqBody := map[string]any{
		"model": c.visionModel,
		"messages": []any{map[string]any{
			"role": "user",
			"content": []visionPart{
				{Type: "text", Text: prompt},
				{Type: "image_url", ImageURL: &visionImageURL{URL: "data:" + mimeType + ";base64," + base64.StdEncoding.EncodeToString(image)}},
			},
		}},
	}
	if c.maxTokens > 0 { // 0 = omit — real parity with ChatRequest's omitempty (LLM_MAX_TOKENS=0 escape hatch)
		reqBody["max_tokens"] = c.maxTokens
	}

	resp, err := c.postJSON(ctx, "/chat/completions", reqBody, "")
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return "", fmt.Errorf("failed to read response body: %w", err)
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return "", c.apiError(resp.StatusCode, body)
	}
	var chatResp ChatResponse
	if err := json.Unmarshal(body, &chatResp); err != nil {
		return "", fmt.Errorf("failed to decode chat response: %w", err)
	}
	if len(chatResp.Choices) == 0 {
		return "", fmt.Errorf("llm returned no choices in response")
	}
	return chatResp.Choices[0].Message.Content, nil
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

	resp, err := c.postJSON(ctx, "/chat/completions", reqBody, "text/event-stream")
	if err != nil {
		return nil, Usage{}, err
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		bodyBytes, _ := io.ReadAll(io.LimitReader(resp.Body, 64<<10))
		return nil, Usage{}, c.apiError(resp.StatusCode, bodyBytes)
	}

	var assembled ChatMessage
	var usage Usage
	toolCalls := map[int]*ToolCall{}
	// Stream-end validation: an EOF before [DONE] used to return partial
	// text as SUCCESS, and finish_reason=length (truncated tool-call JSON)
	// used to execute anyway.
	var sawDone bool
	finishReason := ""

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
		if payload == "[DONE]" {
			sawDone = true
			continue
		}
		if payload == "" {
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
		if chunk.Choices[0].FinishReason != "" {
			finishReason = chunk.Choices[0].FinishReason
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
	if !sawDone {
		return nil, Usage{}, fmt.Errorf("llm stream ended before [DONE] — answer truncated")
	}
	if finishReason == "length" {
		return nil, Usage{}, fmt.Errorf("llm hit the token limit mid-answer (finish_reason=length) — narrow the request")
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
