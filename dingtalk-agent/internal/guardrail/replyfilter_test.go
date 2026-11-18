package guardrail

import (
	"strings"
	"testing"
	"unicode/utf8"
)

func TestReplyFilter(t *testing.T) {
	t.Run("strips reasoning blocks and tags", func(t *testing.T) {
		input := "Here is the summary:<!-- thinking about logs -->\n<s>thought process</s>The system is healthy."
		got := FilterReplyCap(input, MaxReplyLength)

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
		got := FilterReplyCap(input, MaxReplyLength)

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
		got := FilterReplyCap(input, MaxReplyLength)

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
		got := FilterReplyCap(hugeText, MaxReplyLength)

		if len(got) > MaxReplyLength {
			t.Errorf("reply length %d exceeds max %d", len(got), MaxReplyLength)
		}
		if !strings.Contains(got, `(truncated — type "more")`) {
			t.Errorf("truncated reply missing truncation footer: %s", got)
		}
	})
}

// Regression: the old cap path sliced filtered[:maxLen] and THEN scanned for
// the rune start, reading filtered[maxLen] — an index-out-of-range panic
// whenever maxLen <= len(TruncationFooter). FilterReplyData had the same shape.
func TestReplyFilterTinyCapDoesNotPanic(t *testing.T) {
	for _, maxLen := range []int{1, 5, len(TruncationFooter) - 1, len(TruncationFooter), 100} {
		got := FilterReplyCap("some reply that is definitely longer than any cap here", maxLen)
		if got == "" {
			t.Errorf("maxLen=%d: empty reply", maxLen)
		}
		got = FilterReplyData("some reply that is definitely longer than any cap here", maxLen)
		if got == "" {
			t.Errorf("maxLen=%d: FilterReplyData empty", maxLen)
		}
	}
	// A reply shorter than the cap must pass through untouched.
	if got := FilterReplyData("short", 100); got != "short" {
		t.Errorf("short reply mutated: %q", got)
	}
	// Multi-byte runes are never split at the cut.
	cjk := strings.Repeat("风", 100) // 300 bytes
	if got := cutRuneSafe(cjk, 299); len(got) != 297 || (len(got) > 0 && !utf8.ValidString(got)) {
		t.Errorf("rune-split at cut: %d bytes", len(got))
	}
}
