package memory

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/llm"
)

func writeFile(path, content string) error {
	return os.WriteFile(path, []byte(content), 0o600)
}

func TestUserRegistryRoundtrip(t *testing.T) {
	dir := t.TempDir()
	s := NewStore(dir)
	rec := UserRecord{StaffID: "s1", Nick: "Nick", ConversationID: "c1", ConversationType: "1"}
	if err := s.AppendUser(rec); err != nil {
		t.Fatalf("AppendUser: %v", err)
	}
	if err := s.AppendUser(UserRecord{StaffID: "s2", Nick: "Tomy"}); err != nil {
		t.Fatalf("AppendUser: %v", err)
	}

	// __USERS_ANCHOR__

	// S9: the registry dedupes by staff id — one line per person, not one
	// per message (the file used to grow a line every chat turn).
	before, err := os.ReadFile(filepath.Join(dir, "users.jsonl"))
	if err != nil {
		t.Fatalf("read users.jsonl: %v", err)
	}
	if err := s.AppendUser(UserRecord{StaffID: "s1", Nick: "Nick again", ConversationID: "c1", ConversationType: "1"}); err != nil {
		t.Fatalf("re-append: %v", err)
	}
	after, _ := os.ReadFile(filepath.Join(dir, "users.jsonl"))
	if string(before) != string(after) {
		t.Errorf("duplicate staff id appended: %s", after)
	}
	lines := strings.Count(string(after), "\n")
	if lines != 2 {
		t.Errorf("users.jsonl has %d lines, want 2", lines)
	}
}

func TestHistoryPersistAndRecall(t *testing.T) {
	dir := t.TempDir()
	msgs := []llm.ChatMessage{{Role: "user", Content: "berapa P1?"}, {Role: "assistant", Content: "3"}}

	s1 := NewStore(dir)
	if err := s1.SaveHistory("c1", msgs, time.Hour); err != nil {
		t.Fatalf("SaveHistory: %v", err)
	}

	// Fresh store from the same dir = restart simulation (auto recall).
	s2 := NewStore(dir)
	if got := s2.All()["c1"].Messages; len(got) != 2 || got[0].Content != "berapa P1?" {
		t.Errorf("recalled = %+v", got)
	}
	if all := s2.All(); len(all) != 1 {
		t.Errorf("All() = %d conversations, want 1", len(all))
	}
}

func TestHistoryTTLPurgeOnSave(t *testing.T) {
	s := NewStore(t.TempDir())
	if err := s.SaveHistory("c1", []llm.ChatMessage{{Role: "user"}}, 5*time.Millisecond); err != nil {
		t.Fatalf("SaveHistory: %v", err)
	}
	time.Sleep(10 * time.Millisecond) // c1 crosses the TTL
	if err := s.SaveHistory("c2", []llm.ChatMessage{{Role: "user"}}, 5*time.Millisecond); err != nil {
		t.Fatalf("SaveHistory: %v", err)
	}
	if got := len(s.All()); got != 1 { // c1 expired, fresh c2 survives
		t.Errorf("after TTL purge All() = %d, want 1", got)
	}
}

func TestKnowledgeRoundtrip(t *testing.T) {
	dir := t.TempDir()
	s := NewStore(dir)
	for i := 0; i < MaxKnowledgeEntries+3; i++ {
		if err := s.AppendKnowledge(KnowledgeEntry{File: "doc.md", Author: "Nick", Summary: "summary"}); err != nil {
			t.Fatalf("AppendKnowledge: %v", err)
		}
	}

	// Fresh store = restart: only the last MaxKnowledgeEntries survive.
	got := NewStore(dir).Knowledge()
	if len(got) != MaxKnowledgeEntries {
		t.Fatalf("Knowledge() = %d entries, want %d", len(got), MaxKnowledgeEntries)
	}
	if got[0].Time.IsZero() {
		t.Error("timestamp must be auto-filled")
	}
}

func TestCorruptHistoryFileIgnored(t *testing.T) {
	dir := t.TempDir()
	if err := writeFile(dir+string(os.PathSeparator)+"history.json", "not json"); err != nil {
		t.Fatal(err)
	}
	s := NewStore(dir)
	if got := s.All(); len(got) != 0 {
		t.Errorf("corrupt file must start fresh, got %+v", got)
	}
}

func TestProfileLearnRecallAndCaps(t *testing.T) {
	dir := t.TempDir()
	s := NewStore(dir)

	if lines := s.Profile("staff1"); lines != nil {
		t.Fatalf("unknown user must have no profile, got %v", lines)
	}

	long := strings.Repeat("x", 300)
	many := []string{}
	for i := 0; i < 20; i++ {
		many = append(many, fmt.Sprintf("line %d %s", i, long))
	}
	if err := s.LearnProfile("staff1", "Nick", many); err != nil {
		t.Fatalf("LearnProfile: %v", err)
	}
	lines := s.Profile("staff1")
	if len(lines) != MaxProfileLines {
		t.Errorf("lines = %d, want cap %d", len(lines), MaxProfileLines)
	}
	for _, ln := range lines {
		if len(ln) > MaxProfileLineLen {
			t.Errorf("line exceeds cap: %d chars", len(ln))
		}
	}

	// Empty extraction must never wipe an existing profile.
	if err := s.LearnProfile("staff1", "Nick", nil); err != nil {
		t.Fatalf("LearnProfile empty: %v", err)
	}
	if got := len(s.Profile("staff1")); got == 0 {
		t.Error("empty extraction wiped the profile")
	}

	// Persists across store instances (restart).
	if got := NewStore(dir).Profile("staff1"); len(got) == 0 {
		t.Error("profile lost across NewStore (no persistence)")
	}
	// And is per-user.
	if got := NewStore(dir).Profile("staff2"); got != nil {
		t.Errorf("another user must not see staff1's profile, got %v", got)
	}
}
