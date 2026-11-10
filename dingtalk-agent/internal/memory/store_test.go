package memory

import (
	"os"
	"testing"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/llm"
)

func writeFile(path, content string) error {
	return os.WriteFile(path, []byte(content), 0o600)
}

func TestUserRegistryRoundtrip(t *testing.T) {
	s := NewStore(t.TempDir())
	rec := UserRecord{StaffID: "s1", Nick: "Nick", ConversationID: "c1", ConversationType: "1"}
	if err := s.AppendUser(rec); err != nil {
		t.Fatalf("AppendUser: %v", err)
	}
	if err := s.AppendUser(UserRecord{StaffID: "s2", Nick: "Tomy"}); err != nil {
		t.Fatalf("AppendUser: %v", err)
	}

	users, err := s.Users()
	if err != nil {
		t.Fatalf("Users: %v", err)
	}
	if len(users) != 2 || users[0].StaffID != "s1" || users[1].Nick != "Tomy" {
		t.Errorf("users = %+v", users)
	}
	if users[0].Time.IsZero() {
		t.Error("timestamp must be auto-filled")
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
	if got := s2.TakeHistory("c1"); len(got) != 2 || got[0].Content != "berapa P1?" {
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
	if got := s.TakeHistory("any"); got != nil {
		t.Errorf("corrupt file must start fresh, got %+v", got)
	}
}
