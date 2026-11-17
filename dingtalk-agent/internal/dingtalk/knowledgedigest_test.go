package dingtalk

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/config"
	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/memory"
)

// promptCapture is a mutex-guarded view of the last system+user prompt the
// fake LLM saw.
type promptCapture struct {
	mu sync.Mutex
	v  string
}

func (p *promptCapture) set(s string) { p.mu.Lock(); p.v = s; p.mu.Unlock() }
func (p *promptCapture) get() string  { p.mu.Lock(); defer p.mu.Unlock(); return p.v }

// digestBot builds a bot over dir with the digest flag on and a fake LLM that
// always replies with `reply` and records its last prompt in `cap`.
func digestBot(t *testing.T, dir string, reply string, cap *promptCapture) *Bot {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var req struct {
			Messages []llm.ChatMessage `json:"messages"`
		}
		_ = json.NewDecoder(r.Body).Decode(&req)
		if len(req.Messages) > 0 {
			cap.set(req.Messages[0].Content + "\n" + req.Messages[len(req.Messages)-1].Content)
		}
		resp := llm.ChatResponse{Choices: []llm.ChatChoice{{
			Message: llm.ChatMessage{Role: "assistant", Content: reply}, FinishReason: "stop",
		}}}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	}))
	t.Cleanup(srv.Close)

	cfg := &config.Config{
		LLMBaseURL: srv.URL, LLMAPIKey: "k", LLMModel: "m", DataDir: dir,
		KnowledgeDigest: true,
	}
	return NewBot(cfg, nil, llm.NewClient(cfg))
}

// seedKnowledge writes knowledge.jsonl directly — the raw layer as prod has it
// after N uploads.
func seedKnowledge(t *testing.T, dir string, files []string, summaries []string) {
	t.Helper()
	var sb strings.Builder
	for i := range files {
		fmt.Fprintf(&sb, `{"time":"2026-01-%02dT08:00:00Z","file":%q,"author":"Nick","summary":%q}`+"\n",
			i+1, files[i], summaries[i])
	}
	if err := os.WriteFile(filepath.Join(dir, "knowledge.jsonl"), []byte(sb.String()), 0o600); err != nil {
		t.Fatal(err)
	}
}

func TestKnowledgeDigestBackfill(t *testing.T) {
	dir := t.TempDir()
	seedKnowledge(t, dir,
		[]string{"a.pdf", "b.pdf", "c.pdf"},
		[]string{"gateway rotates tokens", "blue-green migration plan", "P1 RCA: cache stampede"},
	)

	cap := &promptCapture{}
	bot := digestBot(t, dir, "- [2026-01-01] a.pdf — gateway rotates tokens\n- [2026-01-03] c.pdf — P1 RCA: cache stampede", cap)
	bot.mergeKnowledgeDigest() // serialize behind the boot goroutine (single-flight)

	data, err := os.ReadFile(filepath.Join(dir, "knowledge.md"))
	if err != nil {
		t.Fatalf("knowledge.md missing after backfill: %v", err)
	}
	for _, want := range []string{"gateway rotates tokens", "cache stampede"} {
		if !strings.Contains(string(data), want) {
			t.Errorf("digest missing %q: %s", want, data)
		}
	}

	meta, err := os.ReadFile(filepath.Join(dir, "knowledge_digest.json"))
	if err != nil {
		t.Fatalf("knowledge_digest.json missing: %v", err)
	}
	if !strings.Contains(string(meta), `"covered":3`) {
		t.Errorf("covered not advanced to 3: %s", meta)
	}

	// The merge prompt carried the raw summaries for synthesis.
	if p := cap.get(); !strings.Contains(p, "cache stampede") || !strings.Contains(p, "c.pdf") {
		t.Errorf("merge prompt missing raw entries: %.300s", p)
	}

	// A merge with nothing pending is a no-op: no LLM call at all.
	cap.set("")
	bot.mergeKnowledgeDigest()
	if p := cap.get(); p != "" {
		t.Errorf("no-op merge must not call the LLM, prompt seen: %.200s", p)
	}
}

func TestKnowledgeDigestIncremental(t *testing.T) {
	dir := t.TempDir()
	seedKnowledge(t, dir,
		[]string{"a.pdf", "b.pdf", "c.pdf", "d.pdf"},
		[]string{"fact one", "fact two", "fact three", "fact four"},
	)
	if err := os.WriteFile(filepath.Join(dir, "knowledge.md"), []byte("- [2026-01-01] a.pdf — old fact\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "knowledge_digest.json"), []byte(`{"covered":3}`), 0o600); err != nil {
		t.Fatal(err)
	}

	cap := &promptCapture{}
	bot := digestBot(t, dir, "- [2026-01-01] a.pdf — old fact\n- [2026-01-04] d.pdf — newly merged fact", cap)
	bot.mergeKnowledgeDigest()

	p := cap.get()
	if !strings.Contains(p, "fact four") {
		t.Errorf("incremental prompt must carry the uncovered entry: %.300s", p)
	}
	if strings.Contains(p, "fact three") { // already covered — must NOT re-merge
		t.Errorf("covered entries re-merged: %.300s", p)
	}

	data, _ := os.ReadFile(filepath.Join(dir, "knowledge.md"))
	if !strings.Contains(string(data), "newly merged fact") {
		t.Errorf("digest not updated: %s", data)
	}
	meta, _ := os.ReadFile(filepath.Join(dir, "knowledge_digest.json"))
	if !strings.Contains(string(meta), `"covered":4`) {
		t.Errorf("covered not advanced to 4: %s", meta)
	}
}

func TestKnowledgeDigestNeverWipesOnEmpty(t *testing.T) {
	dir := t.TempDir()
	seedKnowledge(t, dir, []string{"a.pdf"}, []string{"fact one"})
	if err := os.WriteFile(filepath.Join(dir, "knowledge.md"), []byte("- [2026-01-01] a.pdf — old fact\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "knowledge_digest.json"), []byte(`{"covered":0}`), 0o600); err != nil {
		t.Fatal(err)
	}

	cap := &promptCapture{}
	bot := digestBot(t, dir, "", cap) // the LLM returned nothing
	bot.mergeKnowledgeDigest()

	data, err := os.ReadFile(filepath.Join(dir, "knowledge.md"))
	if err != nil {
		t.Fatalf("digest must survive an empty merge: %v", err)
	}
	if !strings.Contains(string(data), "old fact") {
		t.Errorf("empty merge wiped the digest: %s", data)
	}
	meta, _ := os.ReadFile(filepath.Join(dir, "knowledge_digest.json"))
	if !strings.Contains(string(meta), `"covered":0`) {
		t.Errorf("empty merge must not advance covered: %s", meta)
	}
}

func TestKnowledgeDigestCapKeepsNewest(t *testing.T) {
	dir := t.TempDir()
	seedKnowledge(t, dir, []string{"a.pdf"}, []string{"fact one"})

	cap := &promptCapture{}
	var sb strings.Builder
	for i := 0; i < memory.MaxDigestLines+10; i++ {
		fmt.Fprintf(&sb, "- line %02d of fifty\n", i)
	}
	bot := digestBot(t, dir, sb.String(), cap)
	bot.mergeKnowledgeDigest()

	data, err := os.ReadFile(filepath.Join(dir, "knowledge.md"))
	if err != nil {
		t.Fatalf("digest missing: %v", err)
	}
	lines := strings.Split(strings.TrimSpace(string(data)), "\n")
	if len(lines) != memory.MaxDigestLines {
		t.Fatalf("digest = %d lines, want %d", len(lines), memory.MaxDigestLines)
	}
	if !strings.Contains(lines[len(lines)-1], "line 49") {
		t.Errorf("cap must keep the newest lines, last = %q", lines[len(lines)-1])
	}
}

func TestKnowledgePromptUsesDigest(t *testing.T) {
	dir := t.TempDir()
	seedKnowledge(t, dir, []string{"a.pdf"}, []string{"fact one"})

	cap := &promptCapture{}
	bot := digestBot(t, dir, "- [2026-01-01] a.pdf — compiled fact", cap)
	bot.mergeKnowledgeDigest()

	p := bot.knowledgePrompt()
	if !strings.Contains(p, "compiled fact") {
		t.Errorf("prompt must carry the digest: %.300s", p)
	}
	if !strings.Contains(p, "UNTRUSTED") {
		t.Errorf("digest must sit inside the untrusted-data marker: %.300s", p)
	}
}

func TestKnowledgePromptFallsBackToLast8(t *testing.T) {
	dir := t.TempDir()
	cfg := &config.Config{LLMBaseURL: "http://127.0.0.1:1", LLMAPIKey: "k", LLMModel: "m", DataDir: dir}
	bot := NewBot(cfg, nil, llm.NewClient(cfg))
	_ = bot.store.AppendKnowledge(memory.KnowledgeEntry{
		File: "doc.pdf", Author: "Nick", Summary: "raw summary line",
	})

	p := bot.knowledgePrompt()
	if !strings.Contains(p, "doc.pdf") || !strings.Contains(p, "raw summary line") {
		t.Errorf("fallback must render the raw last-8 entries: %.300s", p)
	}
}

func TestRememberKnowledgeAppendsAndMerges(t *testing.T) {
	dir := t.TempDir()
	cap := &promptCapture{}
	bot := digestBot(t, dir, "- [2026-01-01] doc.pdf — remembered", cap)

	bot.rememberKnowledge(memory.KnowledgeEntry{File: "doc.pdf", Author: "Nick", Summary: "fresh fact"})
	if k := bot.store.Knowledge(); len(k) != 1 || k[0].File != "doc.pdf" {
		t.Fatalf("rememberKnowledge did not append: %+v", k)
	}

	// The background merge is async — wait for it to land in the digest file.
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		if _, err := os.Stat(filepath.Join(dir, "knowledge.md")); err == nil {
			break
		}
		time.Sleep(10 * time.Millisecond)
	}
	data, err := os.ReadFile(filepath.Join(dir, "knowledge.md"))
	if err != nil {
		t.Fatalf("rememberKnowledge never triggered the digest merge: %v", err)
	}
	if !strings.Contains(string(data), "remembered") {
		t.Errorf("digest wrong: %s", data)
	}
}
