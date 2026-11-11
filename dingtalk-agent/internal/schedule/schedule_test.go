package schedule

import (
	"strings"
	"testing"
	"time"
)

// Wed 2026-11-11 10:00 WIB
var now = time.Date(2026, 11, 11, 10, 0, 0, 0, WIB)

func next(t *testing.T, when string) time.Time {
	t.Helper()
	s, err := Parse(when, now)
	if err != nil {
		t.Fatalf("Parse(%q): %v", when, err)
	}
	n, ok := s.Next(now)
	if !ok {
		t.Fatalf("Parse(%q) never fires", when)
	}
	return n
}

func TestParseNext(t *testing.T) {
	cases := map[string]string{
		"0 9 * * 1":        "2026-11-16 09:00", // next Monday
		"30 10 * * *":      "2026-11-11 10:30", // later today
		"0 9 * * 1-5":      "2026-11-12 09:00", // weekdays → Thursday
		"0 8 1 * *":        "2026-12-01 08:00", // 1st of month
		"0 9 * * 0":        "2026-11-15 09:00", // Sunday as 0
		"0 9 * * 7":        "2026-11-15 09:00", // Sunday as 7
		"0 9,15 * * *":     "2026-11-11 15:00", // list
		"0 */6 * * *":      "2026-11-11 12:00", // step
		"2026-11-12 08:15": "2026-11-12 08:15", // one-shot
		"in 30m":           "2026-11-11 10:30", // relative
		"in 2h":            "2026-11-11 12:00",
	}
	for when, want := range cases {
		if got := next(t, when).In(WIB).Format("2006-01-02 15:04"); got != want {
			t.Errorf("%q: next = %s, want %s", when, got, want)
		}
	}
}

func TestParseRejects(t *testing.T) {
	for when, wantErr := range map[string]string{
		"*/5 * * * *":      "30m0s apart", // spam
		"0,10 9 * * *":     "30m0s apart", // spam hidden in a list
		"* * * *":          "5-field",
		"0 25 * * *":       "outside",
		"2026-11-10 09:00": "in the past",
		"in 10s":           "at least 1 minute",
		"every monday":     "5-field",
	} {
		_, err := Parse(when, now)
		if err == nil || !strings.Contains(err.Error(), wantErr) {
			t.Errorf("Parse(%q) err = %v, want containing %q", when, err, wantErr)
		}
	}
}

func TestStoreLifecycle(t *testing.T) {
	dir := t.TempDir()
	s := NewStore(dir)

	weekly, err := s.Add(Item{ConversationID: "c1", When: "0 9 * * 1", Mode: ModeTask, Text: "summarize open incidents"}, now)
	if err != nil {
		t.Fatal(err)
	}
	once, err := s.Add(Item{ConversationID: "c1", When: "in 30m", Mode: ModeMessage, Text: "check P1"}, now)
	if err != nil {
		t.Fatal(err)
	}
	if weekly.ID != 1 || once.ID != 2 {
		t.Fatalf("ids = %d,%d want 1,2", weekly.ID, once.ID)
	}

	// Cancel is scoped to the owning conversation.
	if _, err := s.Cancel(1, "other-chat"); err == nil {
		t.Error("another chat must not cancel #1")
	}

	// Persistence survives a reload.
	if got := len(NewStore(dir).List("c1")); got != 2 {
		t.Fatalf("reloaded store has %d items, want 2", got)
	}

	// 10:30: the one-shot fires and is removed; the weekly is untouched.
	due := s.TakeDue(now.Add(30 * time.Minute))
	if len(due) != 1 || due[0].ID != 2 {
		t.Fatalf("due at 10:30 = %+v, want only #2", due)
	}
	if len(s.List("c1")) != 1 {
		t.Fatal("one-shot not removed after firing")
	}

	// Monday 09:00: the weekly fires and advances a week.
	mon := time.Date(2026, 11, 16, 9, 0, 0, 0, WIB)
	due = s.TakeDue(mon)
	if len(due) != 1 || !due[0].NextRun.Equal(mon) {
		t.Fatalf("due Monday = %+v", due)
	}
	if got := s.List("c1")[0].NextRun.In(WIB).Format("2006-01-02 15:04"); got != "2026-11-23 09:00" {
		t.Errorf("weekly advanced to %s, want 2026-11-23 09:00", got)
	}
	if len(s.TakeDue(mon)) != 0 {
		t.Error("same instant must not fire twice")
	}

	if _, err := s.Cancel(1, "c1"); err != nil || len(s.List("c1")) != 0 {
		t.Errorf("cancel by owner failed: %v", err)
	}
}

func TestStoreCapsPerConversation(t *testing.T) {
	s := NewStore("")
	for i := 0; i < MaxPerConversation; i++ {
		if _, err := s.Add(Item{ConversationID: "c", When: "in 1h", Mode: ModeMessage, Text: "x"}, now); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := s.Add(Item{ConversationID: "c", When: "in 1h", Mode: ModeMessage, Text: "x"}, now); err == nil {
		t.Error("cap not enforced")
	}
	if _, err := s.Add(Item{ConversationID: "other", When: "in 1h", Mode: ModeMessage, Text: "x"}, now); err != nil {
		t.Errorf("cap must be per conversation: %v", err)
	}
}

func TestPendingClaimOnce(t *testing.T) {
	p := NewPending()
	tok := p.Put(Request{When: "in 1h", Text: "x"})
	if _, ok := p.Claim(tok); !ok {
		t.Fatal("claim failed")
	}
	if _, ok := p.Claim(tok); ok {
		t.Error("a token must only be claimable once")
	}
}
