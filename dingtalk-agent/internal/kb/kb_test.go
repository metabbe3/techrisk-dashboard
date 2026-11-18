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
	// beta's cosine vs the query (0.11) sits BELOW the similarity floor, so
	// only alpha survives — the floor is the point (audit round 4).
	if len(hits) != 1 || hits[0].Doc != "Alpha SOP.pdf" {
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
	if len(hits) != 1 {
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

// Embed failures degrade to keyword-only storage (was: a hard error that lost
// the doc): the chunk still lands with no vector and stays searchable by term
// overlap. A no-match query serves nothing — the keyword noise floor.
func TestEmbedFailureDegradesToKeyword(t *testing.T) {
	ix := NewIndex("", "m1", &fakeEmb{err: errors.New("gateway down")})
	if err := ix.ingestNow(context.Background(), "SOP.pdf", "alpha restart procedure"); err != nil {
		t.Fatalf("embed failure must degrade, not error: %v", err)
	}
	hits, err := ix.Search(context.Background(), "alpha restart", 6)
	if err != nil {
		t.Fatal(err)
	}
	if len(hits) != 1 || hits[0].Doc != "SOP.pdf" || hits[0].Model != keywordModel || hits[0].Vec != nil {
		t.Fatalf("keyword hit wrong: %+v", hits)
	}
	if hits, _ := ix.Search(context.Background(), "unrelated zebra words", 6); len(hits) != 0 {
		t.Fatalf("no-match query served noise: %+v", hits)
	}
}

// No embedder at all (EMBEDDING_MODEL=none): the KB is fully keyword-mode —
// push and search both work with zero embed calls.
func TestKeywordModeWithoutEmbedder(t *testing.T) {
	ix := NewIndex("", "", nil)
	if err := ix.Save(context.Background(), "SOP.pdf", "prosedur restart payment gateway"); err != nil {
		t.Fatalf("keyword-mode Save failed: %v", err)
	}
	hits, err := ix.Search(context.Background(), "payment restart", 6)
	if err != nil || len(hits) != 1 || hits[0].Model != keywordModel {
		t.Fatalf("keyword search: %v %+v", err, hits)
	}
	if docs := ix.ListDocs(); len(docs) != 1 || docs[0].Doc != "SOP.pdf" {
		t.Fatalf("ListDocs: %+v", docs)
	}
}

// Keyword chunks stored during an embed outage are upgraded to vectors at the
// next boot with a working embedder (needsRebuild already fires on the model
// mismatch — the outage marker must not require manual re-upload).
func TestKeywordChunksUpgradeAtBoot(t *testing.T) {
	dir := t.TempDir()
	ix := NewIndex(dir, "m1", &fakeEmb{err: errors.New("down")})
	if err := ix.ingestNow(context.Background(), "SOP.pdf", "alpha restart procedure"); err != nil {
		t.Fatal(err)
	}

	ix2 := NewIndex(dir, "m1", &fakeEmb{})
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		ix2.mu.RLock()
		done := len(ix2.chunks) > 0 && ix2.chunks[0].Model == "m1" && len(ix2.chunks[0].Vec) > 0
		ix2.mu.RUnlock()
		if done {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	ix2.mu.RLock()
	defer ix2.mu.RUnlock()
	t.Fatalf("keyword chunks were not upgraded at boot: %+v", ix2.chunks)
}

// A mixed corpus (vector chunks + keyword chunks from an outage) scores both
// ways in one search: cosine for the embedded chunk, term overlap for the
// keyword one, merged into a single ranking.
func TestMixedCorpusSearch(t *testing.T) {
	emb := &fakeEmb{}
	ix := NewIndex("", "m1", emb)
	if err := ix.ingestNow(context.Background(), "Vec SOP.pdf", "alpha restart procedure"); err != nil {
		t.Fatal(err)
	}
	emb.err = errors.New("down")
	if err := ix.ingestNow(context.Background(), "Keyword SOP.pdf", "beta shutdown manual"); err != nil {
		t.Fatal(err)
	}
	emb.err = nil

	// Query words the keyword doc shares 2 of 3 ("beta shutdown" = 0.667) —
	// above the keyword floor; a 1-of-3 overlap (0.33) must NOT rescue since
	// minKeywordScore = minCosine (TestKeywordRescueFloorAndStopwords).
	hits, err := ix.Search(context.Background(), "alpha beta shutdown", 6)
	if err != nil {
		t.Fatal(err)
	}
	if len(hits) != 2 {
		t.Fatalf("mixed corpus not merged: %+v", hits)
	}
	if hits[0].Doc != "Vec SOP.pdf" || hits[1].Doc != "Keyword SOP.pdf" {
		t.Fatalf("ranking wrong: %s then %s", hits[0].Doc, hits[1].Doc)
	}
}

// keywordScore: latin words match case-insensitively (Indonesian/English),
// CJK text matches via character bigrams, and a partial match scores as the
// matched fraction — an exact-phrase subset outranks a single-word hit.
func TestKeywordScore(t *testing.T) {
	text := "Prosedur Restart Payment Gateway: cek koneksi ke provider."
	if s := keywordScore("payment gateway restart", text); s != 1 {
		t.Errorf("all tokens match: got %v", s)
	}
	if s := keywordScore("payment gateway zebra", text); s <= 0 || s >= 1 {
		t.Errorf("partial match = %v, want (0,1)", s)
	}
	if keywordScore("zebra unicorn", text) != 0 {
		t.Error("no match must score 0")
	}
	if keywordScore("a", "a a a") != 0 { // 1-char tokens are not indexed
		t.Error("single-char token matched")
	}
	cjk := "重启支付网关的步骤"
	if keywordScore("支付网关", cjk) == 0 {
		t.Error("CJK bigram match failed")
	}
	if keywordScore("网银转账", cjk) != 0 {
		t.Error("unrelated CJK matched")
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
	// The query ("query alpha" → vec [0.9 0.1]) is orthogonal to beta's
	// vectors, so Search proves nothing here post-floor — prove eviction via
	// ListDocs instead (audit round 4).
	docs := ix.ListDocs()
	if len(docs) != 1 || docs[0].Doc != "new.pdf" {
		t.Fatalf("oldest doc not evicted: %+v", docs)
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

// A hung embedder must not hold the ingest slot / rebuild forever: every
// un-capped embed call carries a 3-minute deadline (audit round 5). The test
// shrinks nothing — it asserts the ctx the embedder RECEIVES is already
// deadline-bounded, which fails while the call sites pass Background.
func TestEmbedCallsCarryDeadline(t *testing.T) {
	var sawDeadline bool
	emb := deadlineProbe{probe: func(ctx context.Context) {
		_, ok := ctx.Deadline()
		sawDeadline = ok
	}}
	dir := t.TempDir()
	ix := NewIndex(dir, "m1", emb)

	// Seed one doc via the public background Ingest so rebuild has real
	// chunks to re-embed (ingestNow takes its ctx from the caller — Ingest
	// is the production path and must bound it).
	sawDeadline = false
	ix.Ingest("D.pdf", "some indexed text")
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		ix.mu.RLock()
		n := len(ix.chunks)
		ix.mu.RUnlock()
		if n > 0 {
			break
		}
		time.Sleep(5 * time.Millisecond)
	}
	if !sawDeadline {
		t.Fatal("Ingest's embed ran with no deadline — a hung embedder holds the ingest slot forever")
	}

	// Boot rebuild path (embedAll on Background).
	sawDeadline = false
	ix.rebuild()
	if !sawDeadline {
		t.Fatal("rebuild's embedAll ran with no deadline — a hung embedder wedges the index forever")
	}

	// Search query embed path.
	sawDeadline = false
	_, _ = ix.Search(context.Background(), "query", 3)
	if !sawDeadline {
		t.Fatal("Search's query embed ran with no deadline")
	}
}

type deadlineProbe struct {
	probe func(ctx context.Context)
}

func (d deadlineProbe) Embed(ctx context.Context, inputs []string) ([][]float32, error) {
	d.probe(ctx)
	out := make([][]float32, len(inputs))
	for i := range inputs {
		out[i] = []float32{1, 0}
	}
	return out, nil
}

// The keyword rescue used to serve any chunk sharing ONE 2-char token with
// the query — undoing the 0.35 cosine floor for vec-less and floor-excluded
// chunks alike. Stopwords drop filler tokens; a score floor on the same
// 0..1 scale as cosine keeps weak one-of-many matches out while exact
// identifiers still rescue (audit round 5).
func TestKeywordRescueFloorAndStopwords(t *testing.T) {
	ix := NewIndex(t.TempDir(), "", nil) // keyword-only: the rescue is the only signal
	if err := ix.Save(context.Background(), "SOP.pdf", "database restart procedure nightly"); err != nil {
		t.Fatal(err)
	}

	// One content token of three (0.33): below the floor, not served.
	hits, _ := ix.Search(context.Background(), "database timeout escalation", 6)
	if len(hits) != 0 {
		t.Fatalf("one-of-three match must stay under the floor: %+v", hits)
	}

	// Two of two: served.
	hits, _ = ix.Search(context.Background(), "database restart", 6)
	if len(hits) != 1 {
		t.Fatalf("strong match must be served: %+v", hits)
	}

	// Pure stopwords: nothing to match on — no hits, not 1/3 noise.
	if err := ix.Save(context.Background(), "Stop.pdf", "the and yang ke di"); err != nil {
		t.Fatal(err)
	}
	hits, _ = ix.Search(context.Background(), "the and yang", 6)
	if len(hits) != 0 {
		t.Fatalf("stopword-only query must not match: %+v", hits)
	}

	// Exact identifiers still rescue: 1 of 2 = 0.5 ≥ floor.
	if err := ix.Save(context.Background(), "INC.pdf", "incident 20261101 0042 gateway down"); err != nil {
		t.Fatal(err)
	}
	hits, _ = ix.Search(context.Background(), "0042 status", 6)
	if len(hits) != 1 {
		t.Fatalf("identifier rescue broken: %+v", hits)
	}
}

// D5: chunk boundaries snap to whitespace — a hard rune cut used to split
// words mid-token, splitting one term across two chunks and weakening both
// their keyword vectors.
func TestChunkTextSnapsToWhitespace(t *testing.T) {
	oldSize, oldOverlap := chunkSize, chunkOverlap
	defer func() { chunkSize, chunkOverlap = oldSize, oldOverlap }()
	chunkSize, chunkOverlap = 25, 0

	text := strings.Repeat("abcdefghij ", 10) // words of 10 + space, 110 runes
	got := chunkText("d.pdf", text)
	if len(got) < 2 {
		t.Fatalf("want multiple chunks, got %d", len(got))
	}
	at := 0
	for i, c := range got {
		at += len([]rune(c))
		// A snapped chunk ends WITH the space, so the rune BEFORE the
		// boundary must be one — that is the word boundary.
		if i < len(got)-1 && at < len([]rune(text)) && []rune(text)[at-1] != ' ' {
			t.Errorf("chunk %d cut mid-word (rune before boundary %q)", i, []rune(text)[at-1])
		}
	}
}
