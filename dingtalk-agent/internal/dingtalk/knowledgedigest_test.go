package dingtalk

import (
	"bytes"
	"encoding/json"
	"fmt"
	"log"
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
// fake LLM saw, plus whether that request carried a max_tokens budget.
type promptCapture struct {
	mu        sync.Mutex
	v         string
	maxTokens *float64 // nil = the wire request had NO max_tokens field
}

func (p *promptCapture) set(s string) { p.mu.Lock(); p.v = s; p.mu.Unlock() }
func (p *promptCapture) get() string  { p.mu.Lock(); defer p.mu.Unlock(); return p.v }

func (p *promptCapture) setMaxTokens(n *float64) { p.mu.Lock(); p.maxTokens = n; p.mu.Unlock() }
func (p *promptCapture) maxTokensSeen() *float64 {
	p.mu.Lock()
	defer p.mu.Unlock()
	return p.maxTokens
}

// logCapture is a mutex-guarded log sink: the boot backfill goroutine and the
// test's own merge call both log through it, so reads stay race-free.
type logCapture struct {
	mu sync.Mutex
	b  bytes.Buffer
}

func (l *logCapture) Write(p []byte) (int, error) {
	l.mu.Lock()
	defer l.mu.Unlock()
	return l.b.Write(p)
}

func (l *logCapture) String() string {
	l.mu.Lock()
	defer l.mu.Unlock()
	return l.b.String()
}

// digestBot builds a bot over dir with the digest flag on and a fake LLM that
// always replies with `reply` and records its last prompt in `cap`.
func digestBot(t *testing.T, dir string, reply string, cap *promptCapture) *Bot {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var req struct {
			Messages  []llm.ChatMessage `json:"messages"`
			MaxTokens *float64          `json:"max_tokens"`
		}
		_ = json.NewDecoder(r.Body).Decode(&req)
		cap.setMaxTokens(req.MaxTokens)
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

// captureLogs swaps the package log sink for lc until the test ends.
func captureLogs(lc *logCapture) func() {
	old := log.Writer()
	log.SetOutput(lc)
	return func() { log.SetOutput(old) }
}

// seedKnowledge writes knowledge.jsonl directly — the raw layer as prod has it
// after N uploads.
func seedKnowledge(t *testing.T, dir string, files []string, summaries []string) {
	t.Helper()
	var sb strings.Builder
	day := time.Date(2026, 1, 1, 8, 0, 0, 0, time.UTC)
	for i := range files {
		fmt.Fprintf(&sb, `{"time":%q,"file":%q,"author":"Nick","summary":%q}`+"\n",
			day.AddDate(0, 0, i).Format(time.RFC3339), files[i], summaries[i])
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

// TestDigestMergeSurvivesTheInMemoryTrim (review Critical #1): AppendKnowledge
// trims the in-memory window to 200 while mergeKnowledgeDigest slices that
// window with the ABSOLUTE covered cursor — once knowledge.jsonl passes 200
// entries the incremental merge silently freezes (covered >= len(window))
// until a restart heals it. The digest must keep merging at every file size.
func TestDigestMergeSurvivesTheInMemoryTrim(t *testing.T) {
	dir := t.TempDir()
	files := make([]string, 250)
	summaries := make([]string, 250)
	for i := range files {
		files[i] = fmt.Sprintf("doc%03d.pdf", i)
		summaries[i] = fmt.Sprintf("summary %03d", i)
	}
	seedKnowledge(t, dir, files, summaries)

	// Echo fake: the "digest" reply is the last user message verbatim, so the
	// saved digest carries exactly what the merge batch merged — the
	// assertion sees the truth, not the fake's imagination.
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var req struct {
			Messages []llm.ChatMessage `json:"messages"`
		}
		_ = json.NewDecoder(r.Body).Decode(&req)
		resp := llm.ChatResponse{Choices: []llm.ChatChoice{{
			Message:      llm.ChatMessage{Role: "assistant", Content: req.Messages[len(req.Messages)-1].Content},
			FinishReason: "stop",
		}}}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	}))
	t.Cleanup(srv.Close)

	cfg := &config.Config{LLMBaseURL: srv.URL, LLMAPIKey: "k", LLMModel: "m", DataDir: dir, KnowledgeDigest: true}
	bot := NewBot(cfg, nil, llm.NewClient(cfg))
	bot.mergeKnowledgeDigest() // serialize behind the boot backfill
	if bot.store.DigestCovered() != 250 {
		t.Fatalf("backfill covered = %d, want 250", bot.store.DigestCovered())
	}

	fresh := memory.KnowledgeEntry{
		Time: time.Date(2026, 2, 1, 8, 0, 0, 0, time.UTC),
		File: "doc251.pdf", Author: "Nick", Summary: "FRESH pattern just learned",
	}
	if err := bot.store.AppendKnowledge(fresh); err != nil {
		t.Fatal(err)
	}
	bot.mergeKnowledgeDigest()

	if !strings.Contains(bot.store.KnowledgeDigest(), "doc251.pdf") {
		t.Error("entry 251 never reached the digest — incremental merge froze past the in-memory trim")
	}
}

// TestPromptShowsNewRawEntriesWhenMergeFails (review Important #3): with a
// digest present, knowledgePrompt served ONLY the digest — an LLM merge
// failure left newly-learned documents invisible until the next successful
// merge. The raw tail beyond the covered cursor must ride along under the
// digest.
func TestPromptShowsNewRawEntriesWhenMergeFails(t *testing.T) {
	dir := t.TempDir()
	seedKnowledge(t, dir, []string{"a.pdf"}, []string{"old gateway pattern"})

	cap := &promptCapture{}
	bot := digestBot(t, dir, "- [2026-01-01] a.pdf — old gateway pattern", cap)
	bot.mergeKnowledgeDigest()
	if bot.store.KnowledgeDigest() == "" {
		t.Fatal("digest missing after first merge")
	}

	// Every subsequent merge "fails" (empty reply — SaveKnowledgeDigest
	// refuses empty and keeps the old digest + cursor).
	failing := digestBot(t, dir, "", cap)
	fresh := memory.KnowledgeEntry{
		Time: time.Date(2026, 2, 2, 8, 0, 0, 0, time.UTC),
		File: "b.pdf", Author: "Nick", Summary: "fresh stampede pattern",
	}
	if err := failing.store.AppendKnowledge(fresh); err != nil {
		t.Fatal(err)
	}
	failing.mergeKnowledgeDigest()

	if failing.store.DigestCovered() != 1 {
		t.Fatalf("covered advanced on a failed merge: %d", failing.store.DigestCovered())
	}
	prompt := failing.knowledgePrompt()
	if !strings.Contains(prompt, "old gateway pattern") {
		t.Error("digest line missing from prompt")
	}
	if !strings.Contains(prompt, "fresh stampede pattern") {
		t.Errorf("new raw entry hidden by the stale digest:\n%.300s", prompt)
	}
}

// TestDigestSaveRefusalLogsDiagnostics (prod 2026-11-18: "empty digest refused"
// fired twice with zero explanation). An empty model reply must log WHY it was
// refused: the batch context, the raw length, and — when the model returned
// nothing at all — the model name + completion tokens, so the next reader can
// tell a thinking-model budget burn from a provider outage.
func TestDigestSaveRefusalLogsDiagnostics(t *testing.T) {
	dir := t.TempDir()
	seedKnowledge(t, dir, []string{"a.pdf"}, []string{"fact one"})

	lc := &logCapture{}
	defer captureLogs(lc)()
	cap := &promptCapture{}
	bot := digestBot(t, dir, "", cap) // the LLM returned NOTHING
	bot.mergeKnowledgeDigest()

	logs := lc.String()
	for _, want := range []string{
		"empty digest refused (raw reply 0 chars)",
		"[0/1 known docs]",
		"EMPTY content",
		"model m",
		"completion_tokens=0",
	} {
		if !strings.Contains(logs, want) {
			t.Errorf("refusal log missing %q:\n%s", want, logs)
		}
	}
	if bot.store.DigestCovered() != 0 {
		t.Errorf("refused merge advanced the cursor: %d", bot.store.DigestCovered())
	}
}

// TestDigestSaveRefusalLogsPreviewForUnusableReply: a non-empty reply that
// normalizes to zero lines must log its sanitized preview, so a junk reply is
// visible in the log without re-running the merge.
func TestDigestSaveRefusalLogsPreviewForUnusableReply(t *testing.T) {
	tests := []struct {
		name    string
		reply   string
		wantRaw int
	}{
		{"symbol-only", "***", 3},
		{"whitespace-only", "  \n\t ", 5},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			dir := t.TempDir()
			seedKnowledge(t, dir, []string{"a.pdf"}, []string{"fact one"})

			lc := &logCapture{}
			defer captureLogs(lc)()
			cap := &promptCapture{}
			bot := digestBot(t, dir, tc.reply, cap)
			bot.mergeKnowledgeDigest()

			logs := lc.String()
			if !strings.Contains(logs, fmt.Sprintf("raw reply %d chars", tc.wantRaw)) {
				t.Errorf("log missing raw length %d:\n%s", tc.wantRaw, logs)
			}
			if !strings.Contains(logs, "unusable reply") {
				t.Errorf("log missing unusable-reply detail:\n%s", logs)
			}
			if bot.store.DigestCovered() != 0 {
				t.Errorf("refused merge advanced the cursor: %d", bot.store.DigestCovered())
			}
		})
	}
}

// TestDigestMergeOmitsMaxTokensOnTheWire (owner 2026-11-18: "i think the max
// token is to small ? doesnt need max token ?"): the digest merge is a
// synthesis job, not a chat reply — it must NOT inherit the chat max_tokens
// budget (LLM_MAX_TOKENS), which a thinking model can burn entirely on
// reasoning, producing the empty replies the guard keeps refusing.
func TestDigestMergeOmitsMaxTokensOnTheWire(t *testing.T) {
	dir := t.TempDir()
	seedKnowledge(t, dir, []string{"a.pdf"}, []string{"fact one"})

	cap := &promptCapture{}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var req struct {
			MaxTokens *float64 `json:"max_tokens"`
		}
		_ = json.NewDecoder(r.Body).Decode(&req)
		cap.setMaxTokens(req.MaxTokens)
		resp := llm.ChatResponse{Choices: []llm.ChatChoice{{
			Message: llm.ChatMessage{Role: "assistant", Content: "- [2026-01-01] a.pdf — merged fact"}, FinishReason: "stop",
		}}}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	}))
	t.Cleanup(srv.Close)

	cfg := &config.Config{
		LLMBaseURL: srv.URL, LLMAPIKey: "k", LLMModel: "m", DataDir: dir,
		KnowledgeDigest: true, LLMMaxTokens: 100, // prod carries a chat budget
	}
	bot := NewBot(cfg, nil, llm.NewClient(cfg))
	bot.mergeKnowledgeDigest()

	if seen := cap.maxTokensSeen(); seen != nil {
		t.Errorf("digest merge sent max_tokens=%v on the wire — merge must not inherit the chat budget", *seen)
	}
	if bot.store.DigestCovered() != 1 {
		t.Fatalf("merge did not advance the cursor: %d", bot.store.DigestCovered())
	}
}
