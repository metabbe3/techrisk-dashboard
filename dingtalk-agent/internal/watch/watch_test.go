package watch

import (
	"fmt"
	"os"
	"path/filepath"
	"testing"
)

func TestStoreAddCancelScopedAndReload(t *testing.T) {
	dir := t.TempDir()
	s := NewStore(dir)

	it, err := s.Add(Item{IncidentNo: "20261101_IN_0042", Title: "Payment timeout",
		LastStatus: "Open", ConversationID: "c1", ConversationType: "2", StaffID: "s1", Nick: "N"})
	if err != nil {
		t.Fatal(err)
	}
	if it.ID != 1 {
		t.Fatalf("want ID 1, got %d", it.ID)
	}

	// Same incident twice in the same chat is rejected.
	if _, err := s.Add(Item{IncidentNo: "20261101_IN_0042", ConversationID: "c1"}); err == nil {
		t.Error("duplicate watch in the same chat must be rejected")
	}

	// Cancel is conversation-scoped: another chat cannot cancel it.
	if _, err := s.Cancel(1, "c2"); err == nil {
		t.Error("cancel from another chat must fail")
	}
	got, err := s.Cancel(1, "c1")
	if err != nil || got.ID != 1 {
		t.Fatalf("cancel failed: %v %v", got, err)
	}
	if len(s.List("c1")) != 0 {
		t.Error("cancelled watch must be gone")
	}

	// Reload from disk.
	if _, err := s.Add(Item{IncidentNo: "20261102_IS_0007", Title: "Issue", LastStatus: "Open",
		ConversationID: "c1"}); err != nil {
		t.Fatal(err)
	}
	s2 := NewStore(dir)
	all := s2.All()
	// ID 1 is reused after the cancel — same behavior as the reminder store.
	if len(all) != 1 || all[0].IncidentNo != "20261102_IS_0007" || all[0].ID != 1 {
		t.Fatalf("reload wrong: %+v", all)
	}
	if _, err := os.Stat(filepath.Join(dir, "watches.json")); err != nil {
		t.Errorf("watches.json not persisted: %v", err)
	}
}

func TestStorePerConversationCap(t *testing.T) {
	s := NewStore("")
	for i := 0; i < MaxPerConversation; i++ {
		if _, err := s.Add(Item{IncidentNo: fmt.Sprintf("N%03d", i), ConversationID: "c"}); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := s.Add(Item{IncidentNo: "X", ConversationID: "c"}); err == nil {
		t.Error("cap not enforced")
	}
}

func TestSetStatusPersists(t *testing.T) {
	dir := t.TempDir()
	s := NewStore(dir)
	it, _ := s.Add(Item{IncidentNo: "A", ConversationID: "c", LastStatus: "Open"})
	if err := s.SetStatus(it.ID, "Completed"); err != nil {
		t.Fatal(err)
	}
	if got := NewStore(dir).All()[0].LastStatus; got != "Completed" {
		t.Fatalf("SetStatus not persisted: %q", got)
	}
}

func TestPendingPutClaim(t *testing.T) {
	p := NewPending()
	tok := p.Put(Request{No: "X"})
	if req, ok := p.Claim(tok); !ok || req.No != "X" {
		t.Fatalf("claim wrong: %+v %v", req, ok)
	}
	if _, ok := p.Claim(tok); ok {
		t.Error("claimed token must not re-apply")
	}
}
