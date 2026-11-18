package memory

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/llm"
)

func writeFile(path, content string) error {
	return os.WriteFile(path, []byte(content), 0o600)
}

func TestUserRegistryRoundtrip(t *testing.T) {
	dir := t.TempDir()
	s := NewStore(dir, 0)
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

	s1 := NewStore(dir, 0)
	if err := s1.SaveHistory("c1", msgs, time.Hour); err != nil {
		t.Fatalf("SaveHistory: %v", err)
	}

	// Fresh store from the same dir = restart simulation (auto recall).
	s2 := NewStore(dir, 0)
	if got := s2.All()["c1"].Messages; len(got) != 2 || got[0].Content != "berapa P1?" {
		t.Errorf("recalled = %+v", got)
	}
	if all := s2.All(); len(all) != 1 {
		t.Errorf("All() = %d conversations, want 1", len(all))
	}
}

func TestHistoryTTLPurgeOnSave(t *testing.T) {
	s := NewStore(t.TempDir(), 0)
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
	s := NewStore(dir, 0)
	for i := 0; i < MaxKnowledgeEntries+3; i++ {
		if err := s.AppendKnowledge(KnowledgeEntry{File: "doc.md", Author: "Nick", Summary: "summary"}); err != nil {
			t.Fatalf("AppendKnowledge: %v", err)
		}
	}

	// Fresh store = restart: only the last MaxKnowledgeEntries survive.
	got := NewStore(dir, 0).Knowledge()
	if len(got) != MaxKnowledgeEntries {
		t.Fatalf("Knowledge() = %d entries, want %d", len(got), MaxKnowledgeEntries)
	}
	if got[0].Time.IsZero() {
		t.Error("timestamp must be auto-filled")
	}

	// AllKnowledge is the digest backfill's view: EVERY parsed entry, not
	// the last-8 prompt slice.
	if all := NewStore(dir, 0).AllKnowledge(); len(all) != MaxKnowledgeEntries+3 {
		t.Fatalf("AllKnowledge() = %d entries, want %d", len(all), MaxKnowledgeEntries+3)
	}
}

func TestKnowledgeDigestPersistAndCap(t *testing.T) {
	dir := t.TempDir()
	s := NewStore(dir, 0)
	if err := s.AppendKnowledge(KnowledgeEntry{File: "a.pdf", Author: "Nick", Summary: "alpha"}); err != nil {
		t.Fatalf("AppendKnowledge: %v", err)
	}
	if err := s.AppendKnowledge(KnowledgeEntry{File: "b.pdf", Author: "Nick", Summary: "beta"}); err != nil {
		t.Fatalf("AppendKnowledge: %v", err)
	}

	if got := s.KnowledgeDigest(); got != "" {
		t.Fatalf("fresh store must have no digest, got %q", got)
	}
	if s.DigestCovered() != 0 {
		t.Fatalf("fresh store covered = %d, want 0", s.DigestCovered())
	}

	// 45 lines in: the store keeps the NEWEST MaxDigestLines (the oldest
	// content is what a digest may drop — newest facts are the live ones).
	lines := make([]string, 0, MaxDigestLines+5)
	for i := 0; i < MaxDigestLines+5; i++ {
		lines = append(lines, fmt.Sprintf("- line %02d of fifty", i))
	}
	if err := s.SaveKnowledgeDigest(strings.Join(lines, "\n"), 2); err != nil {
		t.Fatalf("SaveKnowledgeDigest: %v", err)
	}

	got := strings.Split(s.KnowledgeDigest(), "\n")
	if len(got) != MaxDigestLines {
		t.Fatalf("digest = %d lines, want %d", len(got), MaxDigestLines)
	}
	if got[0] != fmt.Sprintf("- line %02d of fifty", len(lines)-MaxDigestLines) {
		t.Errorf("cap must keep the newest lines, first kept line = %q", got[0])
	}

	// Digest + covered count survive a restart (auto recall, like history).
	s2 := NewStore(dir, 0)
	if s2.DigestCovered() != 2 {
		t.Errorf("covered did not persist, got %d", s2.DigestCovered())
	}
	if !strings.Contains(s2.KnowledgeDigest(), "line 44 of fifty") {
		t.Errorf("digest did not persist: %.200s", s2.KnowledgeDigest())
	}

	// A merge gone empty must never wipe the digest (profiles.json rule):
	// refused, and the covered count stays where it was.
	if err := s.SaveKnowledgeDigest("   \n  ", 9); err == nil {
		t.Fatal("empty digest must be refused")
	}
	if s.DigestCovered() != 2 {
		t.Errorf("refused save must not advance covered, got %d", s.DigestCovered())
	}
	if !strings.Contains(s.KnowledgeDigest(), "line 44 of fifty") {
		t.Error("refused save must not touch the stored digest")
	}
}

func TestCorruptHistoryFileIgnored(t *testing.T) {
	dir := t.TempDir()
	if err := writeFile(dir+string(os.PathSeparator)+"history.json", "not json"); err != nil {
		t.Fatal(err)
	}
	s := NewStore(dir, 0)
	if got := s.All(); len(got) != 0 {
		t.Errorf("corrupt file must start fresh, got %+v", got)
	}
}

func TestProfileLearnRecallAndCaps(t *testing.T) {
	dir := t.TempDir()
	s := NewStore(dir, 0)

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
	if got := NewStore(dir, 0).Profile("staff1"); len(got) == 0 {
		t.Error("profile lost across NewStore (no persistence)")
	}
	// And is per-user.
	if got := NewStore(dir, 0).Profile("staff2"); got != nil {
		t.Errorf("another user must not see staff1's profile, got %v", got)
	}
}

// TestDigestConcurrentAccess (review Important #2): the digest getters serve
// conversation goroutines while SaveKnowledgeDigest writes from the merge
// goroutine — both sides must hold the store mutex (run under -race).
func TestDigestConcurrentAccess(t *testing.T) {
	s := NewStore(t.TempDir(), 0)
	var wg sync.WaitGroup
	wg.Add(2)
	go func() {
		defer wg.Done()
		for i := 0; i < 300; i++ {
			_ = s.SaveKnowledgeDigest(fmt.Sprintf("- line %d", i), i)
		}
	}()
	go func() {
		defer wg.Done()
		for i := 0; i < 300; i++ {
			_ = s.KnowledgeDigest()
			_ = s.DigestCovered()
		}
	}()
	wg.Wait()
}

// SetHistoryTTL used to arm the load-time filter AFTER NewStore had already
// loaded — dead code, expired conversations hydrated anyway. The TTL now
// rides NewStore itself.
func TestNewStoreDropsExpiredHistoryOnLoad(t *testing.T) {
	dir := t.TempDir()
	expired := HistoryEntry{Messages: []llm.ChatMessage{{Role: "user", Content: "stale"}}, LastSeen: time.Now().Add(-2 * time.Hour)}
	fresh := HistoryEntry{Messages: []llm.ChatMessage{{Role: "user", Content: "live"}}, LastSeen: time.Now()}
	b, err := json.Marshal(historyFile{Conversations: map[string]HistoryEntry{"gone": expired, "keep": fresh}})
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "history.json"), b, 0o600); err != nil {
		t.Fatal(err)
	}

	s := NewStore(dir, time.Hour)
	all := s.All()
	if _, ok := all["gone"]; ok {
		t.Error("expired conversation must not hydrate on load")
	}
	if _, ok := all["keep"]; !ok {
		t.Error("fresh conversation must survive load")
	}
}
