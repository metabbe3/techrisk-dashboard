package dingtalk

// P3 polish batch (audit round 5): help fast-path, KB-off picture notice,
// dialog-shaped history. Tests written first; each names the behavior it pins.

import (
	"context"
	"strings"
	"testing"

	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
)

// "help" must answer from a canned text with ZERO LLM calls — a help ask
// used to burn a full agent turn (B6).
func TestHelpFastPathNoLLM(t *testing.T) {
	f := newFakeReminderAPI(t)
	bot := newReminderBot(t, f, "")

	for _, ask := range []string{"help", "bantuan", "/help", "menu"} {
		_, _ = bot.Handle(context.Background(), groupMsg(f, "hp-"+ask, "s1", "Nicholas", ask))
		f.waitFor(t, "help reply", func() bool { return len(f.replies) >= 1 })
		f.mu.Lock()
		got := f.replies[len(f.replies)-1]
		f.replies = nil
		f.mu.Unlock()
		if !strings.Contains(got, "What I can do") {
			t.Errorf("help ask %q got no help text: %q", ask, got)
		}
	}
	if n := len(f.models); n != 0 {
		t.Errorf("help fast-path spent %d LLM calls", n)
	}
}

// A picture message with the KB disabled used to vanish silently (B7) — the
// sender now gets one honest line instead of silence.
func TestPictureWithoutKBRepliesNotice(t *testing.T) {
	f := newFakeReminderAPI(t)
	bot := newReminderBot(t, f, "") // KBToolsEnabled unset → b.know == nil

	m := groupMsg(f, "pic1", "s1", "Nicholas", "")
	m.Msgtype = "picture"
	m.Content = map[string]any{"pictureDownloadCode": "pc1"} // Content is any; the parser re-marshals it
	_, _ = bot.Handle(context.Background(), m)

	f.waitFor(t, "picture notice", func() bool { return len(f.replies) >= 1 })
	f.mu.Lock()
	defer f.mu.Unlock()
	if got := f.replies[0]; !strings.Contains(got, "disabled") {
		t.Errorf("KB-off picture got %q, want the disabled notice", got)
	}
}

// History keeps DIALOG turns only: intermediate tool rounds (assistant w/
// tool_calls + tool results) are dropped at save time — 12 raw-message slots
// used to be eaten by ONE multi-tool turn, wiping the conversation context
// that follow-up questions need (B2).
func TestHistoryKeepsDialogTurnsNotToolDumps(t *testing.T) {
	bot := NewBot(&config.Config{}, nil, nil)
	hist := []llm.ChatMessage{
		{Role: "user", Content: "q1"},
		{Role: "assistant", Content: "a1"},
		{Role: "assistant", ToolCalls: []llm.ToolCall{{ID: "t1"}}},
		{Role: "tool", Content: "tool result body"},
		{Role: "assistant", Content: "a2"},
		{Role: "user", Content: "q3"},
		{Role: "assistant", Content: "a3"},
	}
	bot.saveHistory("c1", hist)
	got := bot.takeHistory("c1")
	if len(got) == 0 {
		t.Fatal("history empty after save")
	}
	for _, m := range got {
		if m.Role == "tool" {
			t.Errorf("tool result persisted into history: %q", m.Content)
		}
		if m.Role == "assistant" && len(m.ToolCalls) > 0 {
			t.Errorf("assistant tool_calls round persisted into history: %q", m.Content)
		}
	}
	if got[0].Content != "q1" || got[len(got)-1].Content != "a3" {
		t.Errorf("dialog turns lost in cleaning: %+v", got)
	}
}
