package agent

import (
	"strings"
	"testing"

	"techrisk-dashboard/dingtalk-agent/internal/llm"
)

// BenchmarkPruneContextBudget prunes a turn near the Go loop's 100k budget:
// mixed user/tool/history messages, several rounds over.
func BenchmarkPruneContextBudget(b *testing.B) {
	mk := []llm.ChatMessage{
		{Role: "system", Content: strings.Repeat("system prompt body ", 400)},
	}
	for i := 0; i < 25; i++ {
		mk = append(mk,
			llm.ChatMessage{Role: "user", Content: strings.Repeat("question body ", 300)},
			llm.ChatMessage{Role: "assistant", Content: strings.Repeat("tool call json ", 200)},
			llm.ChatMessage{Role: "tool", Name: "search_incidents", Content: strings.Repeat("tool result row ", 400)},
			llm.ChatMessage{Role: "tool", Name: "load_skill", Content: strings.Repeat("methodology step ", 400)},
		)
	}
	mk = append(mk, llm.ChatMessage{Role: "user", Content: "the live question"})
	for b.Loop() {
		msgs := make([]llm.ChatMessage, len(mk))
		copy(msgs, mk)
		PruneContextBudget(msgs, 100000)
	}
}
