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
	Model    string           `json:"model"`
	Messages []ChatMessage    `json:"messages"`
	Tools    []ToolDefinition `json:"tools,omitempty"`
	Stream   bool             `json:"stream,omitempty"`
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
	Error   *APIError    `json:"error,omitempty"`
}

// APIError represents an error returned by the OpenAI API.
type APIError struct {
	Message string `json:"message"`
	Type    string `json:"type"`
	Code    any    `json:"code"`
}

// Client is an OpenAI-compatible HTTP client for chat completions.
type Client struct {
	baseURL    string
	apiKey     string
	model      string
	httpClient *http.Client
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
		baseURL: baseURL,
		apiKey:  cfg.LLMAPIKey,
		model:   model,
		httpClient: &http.Client{
			Timeout: 120 * time.Second,
		},
	}
}

// ChatCompletion sends a request to the LLM provider and returns the assistant's reply.
func (c *Client) ChatCompletion(ctx context.Context, messages []ChatMessage, tools []ToolDefinition) (*ChatMessage, error) {
	reqBody := ChatRequest{
		Model:    c.model,
		Messages: messages,
		Tools:    tools,
	}

	data, err := json.Marshal(reqBody)
	if err != nil {
		return nil, fmt.Errorf("failed to marshal chat request: %w", err)
	}

	url := c.baseURL + "/chat/completions"
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, url, bytes.NewReader(data))
	if err != nil {
		return nil, fmt.Errorf("failed to create http request: %w", err)
	}

	req.Header.Set("Content-Type", "application/json")
	if c.apiKey != "" {
		req.Header.Set("Authorization", "Bearer "+c.apiKey)
	}

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("http request failed: %w", err)
	}
	defer resp.Body.Close()

	bodyBytes, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("failed to read response body: %w", err)
	}

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		var apiErr ChatResponse
		if err := json.Unmarshal(bodyBytes, &apiErr); err == nil && apiErr.Error != nil {
			return nil, fmt.Errorf("llm api error (status %d): %s", resp.StatusCode, apiErr.Error.Message)
		}
		return nil, fmt.Errorf("llm api error (status %d): %s", resp.StatusCode, string(bodyBytes))
	}

	var chatResp ChatResponse
	if err := json.Unmarshal(bodyBytes, &chatResp); err != nil {
		return nil, fmt.Errorf("failed to decode chat response: %w", err)
	}

	if len(chatResp.Choices) == 0 {
		return nil, fmt.Errorf("llm returned no choices in response")
	}

	return &chatResp.Choices[0].Message, nil
}

// ChatCompletionStream sends a streaming request and returns the assembled assistant
// message. Text deltas are forwarded to onDelta as they arrive (may be nil); tool
// calls are accumulated across chunks exactly like the OpenAI wire format delivers them.
func (c *Client) ChatCompletionStream(ctx context.Context, messages []ChatMessage, tools []ToolDefinition, onDelta func(string)) (*ChatMessage, error) {
	if onDelta == nil {
		onDelta = func(string) {}
	}

	reqBody := ChatRequest{
		Model:    c.model,
		Messages: messages,
		Tools:    tools,
		Stream:   true,
	}

	data, err := json.Marshal(reqBody)
	if err != nil {
		return nil, fmt.Errorf("failed to marshal chat request: %w", err)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+"/chat/completions", bytes.NewReader(data))
	if err != nil {
		return nil, fmt.Errorf("failed to create http request: %w", err)
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "text/event-stream")
	if c.apiKey != "" {
		req.Header.Set("Authorization", "Bearer "+c.apiKey)
	}

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("http request failed: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		bodyBytes, _ := io.ReadAll(io.LimitReader(resp.Body, 64<<10))
		var apiErr ChatResponse
		if err := json.Unmarshal(bodyBytes, &apiErr); err == nil && apiErr.Error != nil {
			return nil, fmt.Errorf("llm api error (status %d): %s", resp.StatusCode, apiErr.Error.Message)
		}
		return nil, fmt.Errorf("llm api error (status %d): %s", resp.StatusCode, string(bodyBytes))
	}

	var assembled ChatMessage
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
			return nil, fmt.Errorf("llm stream error: %s", chunk.Error.Message)
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
		return nil, fmt.Errorf("llm stream read failed: %w", err)
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

	return &assembled, nil
}
