package agent

import (
	"context"
	"fmt"
	"log"
	"runtime/debug"
	"sync"

	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/guardrail"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/schedule"
	"techrisk-dashboard/dingtalk-agent/internal/tools"

	"time"
	"unicode/utf8"
)

const (
	MaxToolResultBytes = 8000
	MaxContextChars    = 100000 // ponytail: raised for multi-incident analysis; PruneContextBudget still guards
	MaxConcurrentTools = 4
)

type ctxKey string

const (
	TaskIDKey    ctxKey = "task_id"
	MissionIDKey ctxKey = "mission_id"
)

// FromContext extracts task_id and mission_id from context.
func FromContext(ctx context.Context) (taskID, missionID string) {
	if ctx == nil {
		return "", ""
	}
	if v, ok := ctx.Value(TaskIDKey).(string); ok {
		taskID = v
	}
	if v, ok := ctx.Value(MissionIDKey).(string); ok {
		missionID = v
	}
	return taskID, missionID
}

// LogPrefix returns a prefix string like "[task <id>] " if task_id or mission_id is in ctx.
func LogPrefix(ctx context.Context) string {
	taskID, missionID := FromContext(ctx)
	if taskID != "" {
		return fmt.Sprintf("[task %s] ", taskID)
	}
	if missionID != "" {
		return fmt.Sprintf("[mission %s] ", missionID)
	}
	return ""
}

// Loop manages the iterative conversation cycle between user, LLM, and tools.
type Loop struct {
	llmClient     *llm.Client
	registry      *tools.Registry
	maxIterations int
	role          string
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
		role:          "solo",
	}
}

// SetRole sets the mesh role ("solo", "leader", "worker").
func (l *Loop) SetRole(role string) {
	l.role = role
}

// Role returns the configured mesh role.
func (l *Loop) Role() string {
	return l.role
}

// Registry exposes the tool registry (e.g. the bot drains Notion reads from it).
func (l *Loop) Registry() *tools.Registry { return l.registry }

type toolResult struct {
	toolCallID string
	name       string
	content    string
}

// Run executes the agent cycle with a user message and returns the final assistant reply
// and the summed token usage of every LLM round it took. sysExtra (optional) is appended
// verbatim to the system prompt for this run only — the per-sender learned profile
// travels through it.
func (l *Loop) Run(ctx context.Context, userText string, history []llm.ChatMessage, sysExtra string) (string, []llm.ChatMessage, llm.Usage, error) {
	return l.runWithEscalation(ctx, userText, history, nil, sysExtra)
}

// RunStreaming is Run with text deltas of the assistant's answers forwarded to
// onDelta as they arrive. Tool-call reasoning rounds produce no deltas; only the
// visible answer streams.
func (l *Loop) RunStreaming(ctx context.Context, userText string, history []llm.ChatMessage, onDelta func(string), sysExtra string) (string, []llm.ChatMessage, llm.Usage, error) {
	return l.runWithEscalation(ctx, userText, history, onDelta, sysExtra)
}

func (l *Loop) run(ctx context.Context, userText string, history []llm.ChatMessage, onDelta func(string), sysExtra string, heavy bool) (string, []llm.ChatMessage, llm.Usage, error) {
	// The system prompt is rebuilt fresh EVERY turn and stripped from history —
	// a persisted system message would pin a stale clock/sender profile/knowledge
	// set into every later turn until the trim drops it.
	messages := []llm.ChatMessage{{Role: "system", Content: l.buildSystemPrompt(sysExtra)}}
	for _, m := range history {
		if m.Role == "system" {
			continue
		}
		messages = append(messages, m)
	}
	// Frame the user message: injected instructions read as data, not commands.
	messages = append(messages, llm.ChatMessage{
		Role:    "user",
		Content: guardrail.FrameUserInput(userText),
	})

	toolDefs := l.registry.GetDefinitions()
	tail := llm.ChatMessage{Role: "system", Content: ConstitutionTail}

	// Usage is summed across every LLM round of this run — the loop is shared
	// across concurrent turns, so the accumulator lives in run scope.
	var totalUsage llm.Usage

	for iter := 0; iter < l.maxIterations; iter++ {
		// Prune old tool results if context budget is exceeded (>60,000 chars)
		PruneContextBudget(messages, MaxContextChars)
		log.Printf("[agent] %siteration %d/%d", LogPrefix(ctx), iter+1, l.maxIterations)

		// The constitution rides AFTER everything else on every call — the
		// nearest instruction to the generation point, so nothing can step
		// over it. It is not persisted into history.
		callMsgs := append(append([]llm.ChatMessage{}, messages...), tail)

		var assistantMsg *llm.ChatMessage
		var roundUsage llm.Usage
		var err error
		if heavy {
			// Escalation retry: same tools/context on the smarter model.
			assistantMsg, roundUsage, err = l.llmClient.ChatCompletionHeavy(ctx, callMsgs, toolDefs)
		} else if onDelta != nil {
			assistantMsg, roundUsage, err = l.llmClient.ChatCompletionStream(ctx, callMsgs, toolDefs, onDelta)
		} else {
			assistantMsg, roundUsage, err = l.llmClient.ChatCompletion(ctx, callMsgs, toolDefs)
		}
		totalUsage = totalUsage.Add(roundUsage)
		if err != nil {
			log.Printf("[agent] %sllm error on iteration %d: %v", LogPrefix(ctx), iter+1, err)
			return "", messages, totalUsage, fmt.Errorf("agent llm error: %w", err)
		}

		messages = append(messages, *assistantMsg)

		// If no tool calls requested, we have the final answer
		if len(assistantMsg.ToolCalls) == 0 {
			return assistantMsg.Content, messages[1:], totalUsage, nil
		}

		messages = append(messages, l.execTools(ctx, assistantMsg.ToolCalls)...)
		if ctx.Err() != nil {
			return "", messages[1:], totalUsage, ctx.Err()
		}
	}

	// Iteration budget exhausted is an ERROR, not a final answer (audit
	// 2026-11-12): returning it as a normal reply made callers persist the
	// placeholder into history and report the turn as a success.
	return "", messages[1:], totalUsage, fmt.Errorf("agent hit the %d-iteration tool budget without a final answer — narrow the query", l.maxIterations)
}

// runWithEscalation runs the loop; when the chat model burns its tool
// iteration budget without a final answer AND a heavy model is configured,
// the whole turn is retried ONCE on the heavy model with a slimmer task
// framing ("extract everything, then answer") — the smarter model tends to
// converge where the fast one loops (audit 2026-11-12). The heavy retry is
// capped to half the normal iteration budget so a bad night cannot burn
// unlimited tokens.
func (l *Loop) runWithEscalation(ctx context.Context, userText string, history []llm.ChatMessage, onDelta func(string), sysExtra string) (string, []llm.ChatMessage, llm.Usage, error) {
	reply, msgs, usage, err := l.run(ctx, userText, history, onDelta, sysExtra, false)
	if err == nil || l.llmClient.HeavyModel() == "" || ctx.Err() != nil {
		return reply, msgs, usage, err
	}
	log.Printf("[agent] %sescalating to heavy model %q after error: %v", LogPrefix(ctx), l.llmClient.HeavyModel(), err)
	// No shared-state mutation: the retry runs on a shallow copy with half
	// the iteration budget (audit round 4 — mutating l.maxIterations raced
	// concurrent turns sharing this loop).
	retry := *l
	retry.maxIterations = max(l.maxIterations/2, 2)
	reply2, msgs2, usage2, err2 := retry.run(ctx, userText, history, onDelta, sysExtra+"\n[Retry on the primary model failed to converge within its tool budget. Be DECISIVE: gather at most the few facts you still need, then commit to a final answer.]", true)
	usage.PromptTokens += usage2.PromptTokens
	usage.CompletionTokens += usage2.CompletionTokens
	if err2 != nil {
		return reply, msgs, usage, err // original error is the true story
	}
	return reply2, msgs2, usage, nil
}

// execTools runs one round of tool calls (max 4 concurrent) and returns the
// tool messages in original call order.
func (l *Loop) execTools(ctx context.Context, toolCalls []llm.ToolCall) []llm.ChatMessage {
	results := make([]toolResult, len(toolCalls))
	var resultsMu sync.Mutex
	sem := make(chan struct{}, MaxConcurrentTools)
	var wg sync.WaitGroup

	for i, tc := range toolCalls {
		wg.Add(1)
		go func(idx int, call llm.ToolCall) {
			defer wg.Done()
			// A panic in ANY child goroutine kills the whole process — the
			// parent's recover cannot cross goroutine boundaries (audit
			// round 4). Contain it here and surface it as a tool error.
			defer func() {
				if r := recover(); r != nil {
					log.Printf("[agent] %sPANIC in tool %s: %v\n%s", LogPrefix(ctx), call.Function.Name, r, debug.Stack())
					resultsMu.Lock()
					results[idx] = toolResult{
						toolCallID: call.ID,
						name:       call.Function.Name,
						content:    fmt.Sprintf("Error: internal tool failure (%s): %v", call.Function.Name, r),
					}
					resultsMu.Unlock()
				}
			}()
			sem <- struct{}{}
			defer func() { <-sem }()

			log.Printf("[agent] %sexecuting tool %s (id: %s) [worker]", LogPrefix(ctx), call.Function.Name, call.ID)
			output, err := l.registry.Execute(ctx, call.Function.Name, call.Function.Arguments)
			if err != nil {
				output = fmt.Sprintf("Error executing tool: %v", err)
			}

			// Tool result truncation (per-tool cap when registered — e.g.
			// notion_read_page at 48k — else the 8000-byte default + footer)
			truncated := TruncateToolOutput(output, l.registry.ResultCap(call.Function.Name))

			// Secret redaction before adding to LLM context
			redacted, _ := guardrail.Redact(truncated)

			resultsMu.Lock()
			results[idx] = toolResult{
				toolCallID: call.ID,
				name:       call.Function.Name,
				content:    redacted,
			}
			resultsMu.Unlock()
		}(i, tc)
	}

	done := make(chan struct{})
	go func() {
		wg.Wait()
		close(done)
	}()

	select {
	case <-done:
	case <-ctx.Done():
		// remaining goroutines will finish on their own; results not yet gathered are considered timeout failures
	}

	out := make([]llm.ChatMessage, 0, len(results))
	for idx, tc := range toolCalls {
		resultsMu.Lock()
		res := results[idx]
		resultsMu.Unlock()
		if res.toolCallID == "" {
			res = toolResult{
				toolCallID: tc.ID,
				name:       tc.Function.Name,
				content:    fmt.Sprintf("Error executing %s: context cancelled or timed out: %v", tc.Function.Name, ctx.Err()),
			}
		}
		out = append(out, llm.ChatMessage{
			Role:       "tool",
			ToolCallID: res.toolCallID,
			Name:       res.name,
			Content:    res.content,
		})
	}
	return out
}

// buildSystemPrompt assembles the system message fresh for this turn: base
// prompt + one section per registered tool family + dynamic knowledge extras
// + the current WIB clock.
func (l *Loop) buildSystemPrompt(sysExtra string) string {
	sys := SystemPrompt

	switch l.role {
	case "leader":
		sys += "\n\n" + LeaderPrompt
	case "worker":
		sys += "\n\n" + WorkerPrompt
	}

	if l.registry.Has("web_search") {
		sys += "\n\n" + WebResearchPrompt
	}
	if l.registry.Has("notion_search") {
		sys += "\n\n" + NotionPrompt
	}
	if l.registry.Has("generate_pdf_report") {
		sys += "\n\n" + DeliverablesPrompt
	}
	if l.registry.Has("schedule_reminder") {
		sys += "\n\n" + SchedulePrompt
	}
	if l.registry.Has("watch_incident") {
		sys += "\n\n" + WatchPrompt
	}
	if l.registry.Has("search_knowledge") {
		sys += "\n\n" + KnowledgePrompt
	}
	if l.SystemExtras != nil {
		if extra := l.SystemExtras(); extra != "" {
			sys += "\n\n## Knowledge (documents users shared earlier)\n" + extra
		}
	}
	if sysExtra != "" {
		sys += "\n\n" + sysExtra
	}
	// "since yesterday" and friends are unreliable without the clock —
	// scheduled task-runs especially (they fire at any hour on any day).
	// Hour granularity: a per-minute clock made the system prompt string
	// unique every turn, defeating provider prompt caching (audit round 4).
	sys += "\n\nCurrent date/time (hour precision): " + time.Now().In(schedule.WIB).Format("Mon 2006-01-02 15h MST")
	return sys
}

// TruncateToolOutput caps output to maxBytes with a truncation notice.
func TruncateToolOutput(output string, maxBytes int) string {
	if maxBytes <= 0 {
		maxBytes = MaxToolResultBytes
	}
	if len(output) > maxBytes {
		truncated := len(output) - maxBytes
		// Back off to a rune boundary — a split rune is invalid UTF-8 in LLM context.
		for maxBytes > 0 && !utf8.RuneStart(output[maxBytes]) {
			maxBytes--
		}
		return output[:maxBytes] + fmt.Sprintf("\n...[truncated %d bytes]", truncated)
	}
	return output
}

// PruneContextBudget omits the oldest tool results when total message characters exceed maxChars.
func PruneContextBudget(messages []llm.ChatMessage, maxChars int) {
	if maxChars <= 0 {
		maxChars = MaxContextChars
	}
	total := 0
	for _, m := range messages {
		total += len(m.Content)
	}
	for total > maxChars {
		// Find the oldest tool result not yet omitted
		found := false
		for i := range messages {
			if messages[i].Role == "tool" && messages[i].Content != "[tool result omitted]" {
				total -= len(messages[i].Content)
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
