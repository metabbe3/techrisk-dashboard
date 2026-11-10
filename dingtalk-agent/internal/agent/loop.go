package agent

import (
	"context"
	"fmt"
	"log"
	"sync"

	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/guardrail"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/tools"
)

const (
	MaxToolResultBytes = 8000
	MaxContextChars    = 100000 // ponytail: raised for multi-incident analysis; PruneContextBudget still guards
	MaxConcurrentTools = 4
)

// Loop manages the iterative conversation cycle between user, LLM, and tools.
type Loop struct {
	llmClient     *llm.Client
	registry      *tools.Registry
	maxIterations int
	// SystemExtras, when set, appends dynamic context (e.g. remembered
	// document knowledge) to the system prompt on every turn.
	SystemExtras func() string
}

// NewLoop creates a new agent loop runner.
func NewLoop(llmClient *llm.Client, registry *tools.Registry, maxIterations int) *Loop {
	if maxIterations <= 0 {
		maxIterations = config.DefaultMaxIterations
	}
	return &Loop{
		llmClient:     llmClient,
		registry:      registry,
		maxIterations: maxIterations,
	}
}

// Registry exposes the tool registry (e.g. the bot drains Notion reads from it).
func (l *Loop) Registry() *tools.Registry { return l.registry }

type toolResult struct {
	index      int
	toolCallID string
	name       string
	content    string
}

// Run executes the agent cycle with a user message and returns the final assistant reply.
// sysExtra (optional) is appended verbatim to the system prompt for this run only —
// the per-sender learned profile travels through it.
func (l *Loop) Run(ctx context.Context, userText string, history []llm.ChatMessage, sysExtra string) (string, []llm.ChatMessage, error) {
	return l.run(ctx, userText, history, nil, sysExtra)
}

// RunStreaming is Run with text deltas of the assistant's answers forwarded to
// onDelta as they arrive. Tool-call reasoning rounds produce no deltas; only the
// visible answer streams.
func (l *Loop) RunStreaming(ctx context.Context, userText string, history []llm.ChatMessage, onDelta func(string), sysExtra string) (string, []llm.ChatMessage, error) {
	return l.run(ctx, userText, history, onDelta, sysExtra)
}

func (l *Loop) run(ctx context.Context, userText string, history []llm.ChatMessage, onDelta func(string), sysExtra string) (string, []llm.ChatMessage, error) {
	var messages []llm.ChatMessage

	// Ensure system prompt is first
	hasSystem := false
	for _, m := range history {
		if m.Role == "system" {
			hasSystem = true
			break
		}
	}
	if !hasSystem {
		sys := SystemPrompt
		if l.registry.Has("web_search") {
			sys += "\n\n" + WebResearchPrompt
		}
		if l.registry.Has("notion_search") {
			sys += "\n\n" + NotionPrompt
		}
		if l.registry.Has("generate_pdf_report") {
			sys += "\n\n" + DeliverablesPrompt
		}
		if l.SystemExtras != nil {
			if extra := l.SystemExtras(); extra != "" {
				sys += "\n\n## Knowledge (documents users shared earlier)\n" + extra
			}
		}
		if sysExtra != "" {
			sys += "\n\n" + sysExtra
		}
		messages = append(messages, llm.ChatMessage{
			Role:    "system",
			Content: sys,
		})
	}
	messages = append(messages, history...)
	// Frame the user message: injected instructions read as data, not commands.
	messages = append(messages, llm.ChatMessage{
		Role:    "user",
		Content: guardrail.FrameUserInput(userText),
	})

	toolDefs := l.registry.GetDefinitions()
	tail := llm.ChatMessage{Role: "system", Content: ConstitutionTail}

	for iter := 0; iter < l.maxIterations; iter++ {
		// Prune old tool results if context budget is exceeded (>60,000 chars)
		PruneContextBudget(messages, MaxContextChars)

		// The constitution rides AFTER everything else on every call — the
		// nearest instruction to the generation point, so nothing can step
		// over it. It is not persisted into history.
		callMsgs := append(append([]llm.ChatMessage{}, messages...), tail)

		var assistantMsg *llm.ChatMessage
		var err error
		if onDelta != nil {
			assistantMsg, err = l.llmClient.ChatCompletionStream(ctx, callMsgs, toolDefs, onDelta)
		} else {
			assistantMsg, err = l.llmClient.ChatCompletion(ctx, callMsgs, toolDefs)
		}
		if err != nil {
			return "", messages, fmt.Errorf("agent llm error: %w", err)
		}

		messages = append(messages, *assistantMsg)

		// If no tool calls requested, we have the final answer
		if len(assistantMsg.ToolCalls) == 0 {
			return assistantMsg.Content, messages, nil
		}

		// Parallel execution of tool calls (max 4 concurrent), preserving deterministic order
		toolCalls := assistantMsg.ToolCalls
		results := make([]toolResult, len(toolCalls))
		sem := make(chan struct{}, MaxConcurrentTools)
		var wg sync.WaitGroup

		for i, tc := range toolCalls {
			wg.Add(1)
			go func(idx int, call llm.ToolCall) {
				defer wg.Done()
				sem <- struct{}{}
				defer func() { <-sem }()

				log.Printf("[agent] executing tool %s (id: %s) [worker]", call.Function.Name, call.ID)
				output, err := l.registry.Execute(call.Function.Name, call.Function.Arguments)
				if err != nil {
					output = fmt.Sprintf("Error executing tool: %v", err)
				}

				// Tool result truncation (per-tool cap when registered — e.g.
				// notion_read_page at 48k — else the 8000-byte default + footer)
				truncated := TruncateToolOutput(output, l.registry.ResultCap(call.Function.Name))

				// Secret redaction before adding to LLM context
				redacted, _ := guardrail.Redact(truncated)

				results[idx] = toolResult{
					index:      idx,
					toolCallID: call.ID,
					name:       call.Function.Name,
					content:    redacted,
				}
			}(i, tc)
		}

		wg.Wait()

		// Append tool results in original slice index order
		for _, res := range results {
			messages = append(messages, llm.ChatMessage{
				Role:       "tool",
				ToolCallID: res.toolCallID,
				Name:       res.name,
				Content:    res.content,
			})
		}
	}

	return "Reached maximum tool execution iterations without final conclusion. Please narrow your query.", messages, nil
}

// TruncateToolOutput caps output to maxBytes with a truncation notice.
func TruncateToolOutput(output string, maxBytes int) string {
	if maxBytes <= 0 {
		maxBytes = MaxToolResultBytes
	}
	if len(output) > maxBytes {
		truncated := len(output) - maxBytes
		return output[:maxBytes] + fmt.Sprintf("\n...[truncated %d bytes]", truncated)
	}
	return output
}

// PruneContextBudget omits the oldest tool results when total message characters exceed maxChars.
func PruneContextBudget(messages []llm.ChatMessage, maxChars int) {
	if maxChars <= 0 {
		maxChars = MaxContextChars
	}
	for {
		total := 0
		for _, m := range messages {
			total += len(m.Content)
		}
		if total <= maxChars {
			break
		}

		// Find the oldest tool result not yet omitted
		found := false
		for i := range messages {
			if messages[i].Role == "tool" && messages[i].Content != "[tool result omitted]" {
				messages[i].Content = "[tool result omitted]"
				found = true
				break
			}
		}
		if !found {
			// No more tool results can be omitted
			break
		}
	}
}
