package kb

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"sync"
	"testing"
	"time"
)

// fakeEmb maps keywords to fixed vectors: query-like text lands near "alpha",
// so ranking is deterministic without a real embedding model.
type fakeEmb struct {
	mu    sync.Mutex
	calls int
	err   error
}

func (f *fakeEmb) Embed(_ context.Context, inputs []string) ([][]float32, error) {
	f.mu.Lock()
	f.calls++
	f.mu.Unlock()
	if f.err != nil {
		return nil, f.err
	}
	out := make([][]float32, len(inputs))
	for i, in := range inputs {
		switch {
		case strings.Contains(in, "query"):
			out[i] = []float32{0.9, 0.1} // closest to alpha
		case strings.Contains(in, "alpha"):
			out[i] = []float32{1, 0}
		case strings.Contains(in, "beta"):
			out[i] = []float32{0, 1}
		default:
			out[i] = []float32{0.5, 0.5}
		}
	}
	return out, nil
}

func (f *fakeEmb) callCount() int {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.calls
}

// chunkText respects the size/overlap window and the per-doc cap.
func TestChunkTextWindowAndCap(t *testing.T) {
	oldSize, oldOverlap, oldCap := chunkSize, chunkOverlap, maxChunksPerDoc
	defer func() { chunkSize, chunkOverlap, maxChunksPerDoc = oldSize, oldOverlap, oldCap }()
	chunkSize, chunkOverlap = 10, 3

	got := chunkText("doc.pdf", strings.Repeat("x", 16)) // 16 runes, step 7
	if len(got) != 2 || got[0] != "xxxxxxxxxx" || got[1] != "xxxxxxxxx" {
		t.Fatalf("chunks wrong: %q", got)
	}
	if chunkText("doc.pdf", "  ") != nil {
		t.Error("blank text must yield no chunks")
	}

	chunkSize = 10
	maxChunksPerDoc = 2
	if got := chunkText("doc.pdf", strings.Repeat("x", 100)); len(got) != 2 {
		t.Fatalf("per-doc cap not enforced: %d chunks", len(got))
	}
}

// Ingest → Search ranks the alpha chunk above beta; re-ingesting the same doc
// replaces it instead of duplicating.
func TestSearchRanksAndReingestReplaces(t *testing.T) {
	emb := &fakeEmb{}
	ix := NewIndex(t.TempDir(), "m1", emb)

	if err := ix.ingestNow(context.Background(), "Alpha SOP.pdf", "alpha restart procedure"); err != nil {
		t.Fatal(err)
	}
	if err := ix.ingestNow(context.Background(), "Beta SOP.pdf", "beta shutdown procedure"); err != nil {
		t.Fatal(err)
	}

	hits, err := ix.Search(context.Background(), "query about alpha restart", 6)
	if err != nil {
		t.Fatal(err)
	}
	if len(hits) != 2 || hits[0].Doc != "Alpha SOP.pdf" {
		t.Fatalf("ranking wrong: %+v", hits)
	}

	// Same doc name again: replaced, not appended.
	if err := ix.ingestNow(context.Background(), "Alpha SOP.pdf", "alpha v2 text"); err != nil {
		t.Fatal(err)
	}
	hits, err = ix.Search(context.Background(), "query about alpha", 6)
	if err != nil {
		t.Fatal(err)
	}
	if len(hits) != 2 {
		t.Fatalf("re-ingest duplicated the doc: %+v", hits)
	}
	if hits[0].Text != "alpha v2 text" {
		t.Fatalf("re-ingest did not replace: %q", hits[0].Text)
	}
}

// Persisted chunks reload from disk into a working index.
func TestReload(t *testing.T) {
	dir := t.TempDir()
	ix := NewIndex(dir, "m1", &fakeEmb{})
	if err := ix.ingestNow(context.Background(), "SOP.pdf", "alpha procedure text"); err != nil {
		t.Fatal(err)
	}

	ix2 := NewIndex(dir, "m1", &fakeEmb{})
	hits, err := ix2.Search(context.Background(), "query alpha", 6)
	if err != nil {
		t.Fatal(err)
	}
	if len(hits) != 1 || hits[0].Doc != "SOP.pdf" || hits[0].Model != "m1" {
		t.Fatalf("reload lost the index: %+v", hits)
	}
}

// Embed failures degrade to summary-only: Ingest errors, Search returns nil.
func TestEmbedFailureDegrades(t *testing.T) {
	ix := NewIndex("", "m1", &fakeEmb{err: errors.New("gateway down")})
	if err := ix.ingestNow(context.Background(), "SOP.pdf", "alpha text"); err == nil {
		t.Fatal("expected ingest error")
	}
	if hits, _ := ix.Search(context.Background(), "anything", 6); hits != nil {
		t.Fatalf("expected no hits, got %+v", hits)
	}
}

// The global cap evicts the oldest document whole.
func TestGlobalCapEvictsOldestDoc(t *testing.T) {
	oldPerDoc, oldTotal := maxChunksPerDoc, maxChunksTotal
	defer func() { maxChunksPerDoc, maxChunksTotal = oldPerDoc, oldTotal }()
	maxChunksPerDoc, maxChunksTotal = 2, 3

	ix := NewIndex("", "m1", &fakeEmb{})
	if err := ix.ingestNow(context.Background(), "old.pdf", strings.Repeat("alpha x", 400)); err != nil {
		t.Fatal(err) // 2 chunks
	}
	if err := ix.ingestNow(context.Background(), "new.pdf", strings.Repeat("beta y", 400)); err != nil {
		t.Fatal(err) // 2 chunks → 4 > 3 → evict old.pdf
	}
	hits, err := ix.Search(context.Background(), "query alpha", 6)
	if err != nil {
		t.Fatal(err)
	}
	if len(hits) != 2 || hits[0].Doc != "new.pdf" {
		t.Fatalf("oldest doc not evicted: %+v", hits)
	}
}

// Save is synchronous: the doc is searchable as Save returns; ListDocs
// aggregates per doc (newest first); DeleteDoc removes a whole doc and the
// deletion persists across reload.
func TestSaveListDeleteDocs(t *testing.T) {
	dir := t.TempDir()
	ix := NewIndex(dir, "m1", &fakeEmb{})

	if err := ix.Save(context.Background(), "Alpha SOP.pdf", "alpha restart procedure"); err != nil {
		t.Fatal(err)
	}
	// Synchronous means searchable NOW — no sleep, no poll.
	hits, err := ix.Search(context.Background(), "query about alpha restart", 6)
	if err != nil {
		t.Fatal(err)
	}
	if len(hits) != 1 || hits[0].Doc != "Alpha SOP.pdf" {
		t.Fatalf("Save was not synchronous: %+v", hits)
	}

	if err := ix.Save(context.Background(), "Beta SOP.pdf", "beta shutdown procedure"); err != nil {
		t.Fatal(err)
	}
	if err := ix.Save(context.Background(), "blank.pdf", "   "); err == nil {
		t.Error("blank text must error")
	}

	docs := ix.ListDocs()
	if len(docs) != 2 || docs[0].Doc != "Beta SOP.pdf" || docs[1].Doc != "Alpha SOP.pdf" {
		t.Fatalf("ListDocs wrong (want Beta newest-first): %+v", docs)
	}
	if docs[0].Chunks != 1 || docs[0].Chars != len("beta shutdown procedure") || docs[0].TS == 0 {
		t.Fatalf("DocStat wrong: %+v", docs[0])
	}

	n, ok := ix.DeleteDoc("Alpha SOP.pdf")
	if !ok || n != 1 {
		t.Fatalf("DeleteDoc = (%d, %v), want (1, true)", n, ok)
	}
	if n, ok := ix.DeleteDoc("Alpha SOP.pdf"); ok || n != 0 {
		t.Fatalf("second DeleteDoc = (%d, %v), want (0, false)", n, ok)
	}
	if docs = ix.ListDocs(); len(docs) != 1 || docs[0].Doc != "Beta SOP.pdf" {
		t.Fatalf("delete left wrong docs: %+v", docs)
	}

	// The deletion persists: a fresh index over the same dir misses the doc.
	ix2 := NewIndex(dir, "m1", &fakeEmb{})
	if docs = ix2.ListDocs(); len(docs) != 1 || docs[0].Doc != "Beta SOP.pdf" {
		t.Fatalf("deletion did not persist: %+v", docs)
	}
}

// DeleteDoc concurrent with async Ingest: no data race, no panic; final state
// stays internally consistent (run under -race). Same-doc delete vs in-flight
// ingest is documented last-writer-wins and not asserted.
func TestDeleteDocConcurrentWithIngest(t *testing.T) {
	emb := &fakeEmb{}
	ix := NewIndex(t.TempDir(), "m1", emb)

	var wg sync.WaitGroup
	for i := 0; i < 8; i++ {
		doc := fmt.Sprintf("doc%d.pdf", i%4)
		wg.Add(3)
		go func(doc string) { defer wg.Done(); ix.Ingest(doc, "alpha text about "+doc) }(doc)
		go func(doc string) { defer wg.Done(); ix.DeleteDoc(doc) }(doc)
		go func() { defer wg.Done(); ix.ListDocs() }()
	}
	wg.Wait()

	// Quiesce: fakeEmb embeds are instant, so a stable call count for 150ms
	// means no ingest is queued or in flight.
	deadline := time.Now().Add(5 * time.Second)
	for {
		c0 := emb.callCount()
		time.Sleep(150 * time.Millisecond)
		if emb.callCount() == c0 || !time.Now().Before(deadline) {
			break
		}
	}

	docs := ix.ListDocs()
	sum := 0
	for _, d := range docs {
		sum += d.Chunks
	}
	ix.mu.RLock()
	direct := len(ix.chunks)
	ix.mu.RUnlock()
	if sum != direct {
		t.Fatalf("ListDocs chunks %d != stored %d", sum, direct)
	}
	if _, err := ix.Search(context.Background(), "query alpha", 6); err != nil {
		t.Fatalf("search after concurrent mix: %v", err)
	}
}

// A model change on disk triggers a background re-embed under the new model.
func TestModelMismatchReembeds(t *testing.T) {
	dir := t.TempDir()
	ix := NewIndex(dir, "m1", &fakeEmb{})
	if err := ix.ingestNow(context.Background(), "SOP.pdf", "alpha text"); err != nil {
		t.Fatal(err)
	}

	before := ix.embed.(*fakeEmb).callCount() // nolint — test owns the embedder
	ix2 := NewIndex(dir, "m2", ix.embed)
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		ix2.mu.RLock()
		done := len(ix2.chunks) > 0 && ix2.chunks[0].Model == "m2"
		ix2.mu.RUnlock()
		if done {
			break
		}
		time.Sleep(10 * time.Millisecond)
	}
	ix2.mu.RLock()
	defer ix2.mu.RUnlock()
	if len(ix2.chunks) == 0 || ix2.chunks[0].Model != "m2" {
		t.Fatalf("re-embed did not land: %+v", ix2.chunks)
	}
	if ix2.embed.(*fakeEmb).callCount() == before {
		t.Error("re-embed made no embedder calls")
	}
}
