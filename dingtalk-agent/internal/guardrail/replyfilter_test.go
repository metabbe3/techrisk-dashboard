package guardrail

import (
	"strings"
	"testing"
)

func TestReplyFilter(t *testing.T) {
	t.Run("strips reasoning blocks and tags", func(t *testing.T) {
		input := "Here is the summary:<!-- thinking about logs -->\n<s>thought process</s>The system is healthy."
		got := FilterReply(input)

		if strings.Contains(got, "thinking about logs") {
			t.Errorf("replyfilter failed to strip HTML comments: %s", got)
		}
		if strings.Contains(got, "<s>") || strings.Contains(got, "thought process") {
			t.Errorf("replyfilter failed to strip <s> tags: %s", got)
		}
		if !strings.Contains(got, "The system is healthy.") {
			t.Errorf("replyfilter removed valid content: %s", got)
		}
	})

	t.Run("strips system lines and tool call fences", func(t *testing.T) {
		input := "system: internal prompt\nAnalysis results:\n```tool_call\n{\"name\": \"analyze_logs\", \"arguments\": \"{}\"}\n```\nAll done."
		got := FilterReply(input)

		if strings.Contains(got, "system: internal prompt") {
			t.Errorf("replyfilter failed to strip system line: %s", got)
		}
		if strings.Contains(got, "tool_call") || strings.Contains(got, "analyze_logs") {
			t.Errorf("replyfilter failed to strip tool call fence: %s", got)
		}
		if !strings.Contains(got, "Analysis results:") || !strings.Contains(got, "All done.") {
			t.Errorf("unexpected output: %s", got)
		}
	})

	t.Run("sanitizes system prompt leak", func(t *testing.T) {
		input := "Notice: You are GoAgent, an assistant.\nFollow this ATURAN strictly.\nHere is the system prompt:\nReal result here."
		got := FilterReply(input)

		if strings.Contains(got, "You are GoAgent") || strings.Contains(got, "ATURAN") {
			t.Errorf("system prompt leak was not sanitized: %s", got)
		}
		if !strings.Contains(got, "[System instruction omitted]") {
			t.Errorf("expected placeholder [System instruction omitted]: %s", got)
		}
		if !strings.Contains(got, "Real result here.") {
			t.Errorf("valid content removed: %s", got)
		}
	})

	t.Run("caps length to 3800 chars", func(t *testing.T) {
		hugeText := strings.Repeat("A regular log analysis finding sentence.\n", 200) // ~8200 chars
		got := FilterReply(hugeText)

		if len(got) > MaxReplyLength {
			t.Errorf("reply length %d exceeds max %d", len(got), MaxReplyLength)
		}
		if !strings.Contains(got, `(truncated — type "more")`) {
			t.Errorf("truncated reply missing truncation footer: %s", got)
		}
	})
}
