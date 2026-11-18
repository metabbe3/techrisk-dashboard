// Package kb is the RAG knowledge base: uploaded documents are chunked,
// embedded via the AI gateway, and persisted to kb.jsonl; searches embed the
// query and rank chunks by cosine similarity. Brute-force in-memory scan —
// ponytail: fine at ≤1000 chunks (~30 MB); move to a vector index if it hurts.
package kb

import (
	"context"
	"crypto/sha256"
	"encoding/json"
	"fmt"
	"log"
	"math"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"

	"techrisk-dashboard/dingtalk-agent/internal/util"
)

const (
	// embedBatchSize chunks per embeddings request.
	embedBatchSize = 16
	// file path persistence, one JSON object per line.
	fileName = "kb.jsonl"
	// keywordModel marks chunks stored WITHOUT vectors (no embedder
	// configured, or the embed endpoint failed) — searchable by term overlap,
	// re-embedded to vectors at the next boot when an embedder exists.
	keywordModel = "keyword"
)

// Window + caps are vars so tests can shrink them. Eviction ties on TS are
// impossible in practice — TS is UnixNano.
var (
	// chunkSize / chunkOverlap control chunking (runes).
	chunkSize    = 900
	chunkOverlap = 100
	// maxChunksPerDoc caps one document's chunk count.
	maxChunksPerDoc = 150
	// maxChunksTotal caps the whole index; eviction removes the oldest doc.
	maxChunksTotal = 1000
)

// Embedder embeds a batch of texts (llm.Client implements it).
type Embedder interface {
	Embed(ctx context.Context, inputs []string) ([][]float32, error)
}

// chunk is one embedded piece of one document.
type chunk struct {
	Doc   string    `json:"doc"`
	Sum   uint32    `json:"sum"` // sha256 of doc name — stable, path-free
	Seq   int       `json:"seq"`
	Text  string    `json:"text"`
	Vec   []float32 `json:"vec"`
	Model string    `json:"model"`
	TS    int64     `json:"ts"`
}

// Index is the persisted knowledge base.
type Index struct {
	path   string
	model  string
	embed  Embedder
	mu     sync.RWMutex
	chunks []chunk
	ingest chan struct{} // serializes ingests; cap 1 = drop extra concurrent ones
}

// NewIndex loads kb.jsonl from dir ("" = memory-only) and returns the index.
// An out-of-model index is re-embedded in the background.
func NewIndex(dir, model string, embed Embedder) *Index {
	ix := &Index{model: model, embed: embed, ingest: make(chan struct{}, 1)}
	if dir != "" {
		ix.path = filepath.Join(dir, fileName)
		ix.load()
	}
	if ix.needsRebuild() {
		go ix.rebuild()
	}
	return ix
}

func (ix *Index) load() {
	data, err := os.ReadFile(ix.path)
	if err != nil {
		return
	}
	for _, line := range strings.Split(string(data), "\n") {
		if line == "" {
			continue
		}
		var c chunk
		if json.Unmarshal([]byte(line), &c) == nil {
			ix.chunks = append(ix.chunks, c)
		}
	}
}

// needsRebuild reports whether stored chunks were embedded by a different model.
// No embedder = nothing to rebuild into.
func (ix *Index) needsRebuild() bool {
	if ix.embed == nil {
		return false
	}
	for _, c := range ix.chunks {
		if c.Model != ix.model {
			return true
		}
	}
	return false
}

// rebuild re-embeds every stored chunk under the current model (serialized,
// background at boot; searches during the rebuild answer from the old vectors).
// ponytail: an ingest racing the rebuild either trips the length guard (abort,
// retried next boot) or mis-embeds that one doc until the next boot — the
// rebuild window is seconds and boot-only, not worth a generation counter.
func (ix *Index) rebuild() {
	ix.ingest <- struct{}{} // serialized with ingests, same slot
	defer func() { <-ix.ingest }()
	log.Printf("[kb] re-embedding %d chunks under %s", len(ix.chunks), ix.model)
	ix.mu.RLock()
	texts := make([]string, len(ix.chunks))
	for i, c := range ix.chunks {
		texts[i] = c.Text
	}
	ix.mu.RUnlock()

	vecs, err := ix.embedAll(context.Background(), texts)
	if err != nil {
		log.Printf("[kb] re-embed failed: %v", err)
		return
	}
	ix.mu.Lock()
	defer ix.mu.Unlock()
	if len(vecs) != len(ix.chunks) {
		log.Printf("[kb] re-embed mismatch: %d vecs for %d chunks", len(vecs), len(ix.chunks))
		return
	}
	for i := range ix.chunks {
		ix.chunks[i].Vec = vecs[i]
		ix.chunks[i].Model = ix.model
	}
	if err := ix.save(); err != nil {
		log.Printf("[kb] re-embed persist failed: %v", err)
	}
}

// Ingest chunks and embeds one document in the background; errors only log —
// an un-embeddable doc degrades to summary-only knowledge, it never breaks a turn.
func (ix *Index) Ingest(doc, text string) {
	go func() {
		ix.ingest <- struct{}{} // blocking — a concurrent upload waits, never drops
		defer func() { <-ix.ingest }()
		if err := ix.ingestNow(context.Background(), doc, text); err != nil {
			log.Printf("[kb] ingest %s failed: %v", doc, err)
		}
	}()
}

// Save ingests one document synchronously on the ingest slot, so callers
// (LLM tools) can report the outcome to the user. Same slot as Ingest — a
// concurrent upload waits, never drops. A 3-minute deadline (audit
// 2026-11-12) bounds the wait on the ingest slot + embeds so a wedged embed
// batch cannot stall an agent turn forever.
func (ix *Index) Save(ctx context.Context, doc, text string) error {
	ctx, cancel := context.WithTimeout(ctx, 3*time.Minute)
	defer cancel()
	select {
	case ix.ingest <- struct{}{}:
		defer func() { <-ix.ingest }()
		return ix.ingestNow(ctx, doc, text)
	case <-ctx.Done():
		return fmt.Errorf("knowledge-base busy (timed out waiting for the ingest slot): %w", ctx.Err())
	}
}

func (ix *Index) ingestNow(ctx context.Context, doc, text string) error {
	chunks := chunkText(doc, text)
	if len(chunks) == 0 {
		return fmt.Errorf("no text to index")
	}
	// Embed when possible; an unavailable embedder or failed embed call
	// degrades to keyword-only storage — the document still lands (it used to
	// error, losing the picture transcription and file text entirely).
	vecs, embedErr := ix.tryEmbedAll(ctx, chunks)
	model := ix.model
	if vecs == nil {
		if embedErr != nil {
			log.Printf("[kb] embed unavailable for %s (%v) — storing keyword-only", doc, embedErr)
		} else {
			log.Printf("[kb] no embedder configured — storing %s keyword-only", doc)
		}
		model = keywordModel
	}

	sum := docSum(doc)
	now := time.Now().UnixNano()
	newChunks := make([]chunk, len(chunks))
	for i, t := range chunks {
		var vec []float32
		if vecs != nil {
			vec = vecs[i]
		}
		newChunks[i] = chunk{Doc: doc, Sum: sum, Seq: i, Text: t, Vec: vec, Model: model, TS: now}
	}

	ix.mu.Lock()
	defer ix.mu.Unlock()
	// Replace any previous version of the same doc.
	kept := ix.chunks[:0]
	for _, c := range ix.chunks {
		if c.Sum != sum {
			kept = append(kept, c)
		}
	}
	ix.chunks = append(kept, newChunks...)
	// Global cap: evict oldest docs (by TS) until within budget.
	for len(ix.chunks) > maxChunksTotal {
		ix.evictOldestLocked()
	}
	return ix.save()
}

// evictOldestLocked drops all chunks of the document with the smallest TS.
func (ix *Index) evictOldestLocked() {
	minTS := int64(-1)
	for _, c := range ix.chunks {
		if minTS == -1 || c.TS < minTS {
			minTS = c.TS
		}
	}
	kept := ix.chunks[:0]
	for _, c := range ix.chunks {
		if c.TS != minTS {
			kept = append(kept, c)
		}
	}
	ix.chunks = kept
}

// Search embeds the query and returns the top-k chunks by cosine similarity,
// with term-overlap scoring as the fallback: an absent embedder or a failed
// query embed scores every chunk by keyword, and a chunk stored keyword-only
// (Vec nil) is keyword-scored even in a mixed index. A chunk with no positive
// score is not served — "no match" stays the honest result.
func (ix *Index) Search(ctx context.Context, query string, k int) ([]chunk, error) {
	ix.mu.RLock()
	total := len(ix.chunks)
	ix.mu.RUnlock()
	if total == 0 {
		return nil, nil
	}

	// One query embed, best effort.
	embedded := false
	var q []float32
	if ix.embed != nil {
		vecs, err := ix.embed.Embed(ctx, []string{query})
		switch {
		case err != nil:
			log.Printf("[kb] query embed failed (%v) — keyword fallback", err)
		case len(vecs) != 1:
			log.Printf("[kb] query embed: want 1 vector, got %d — keyword fallback", len(vecs))
		default:
			q, embedded = vecs[0], true
		}
	}

	ix.mu.RLock()
	defer ix.mu.RUnlock()
	type scored struct {
		c chunk
		s float64
	}
	all := make([]scored, 0, len(ix.chunks))
	for _, c := range ix.chunks {
		s := 0.0
		if embedded && len(c.Vec) > 0 {
			if cs := cosine(q, c.Vec); cs >= minCosine {
				s = cs
			}
		}
		// Keyword rescue: vec-less chunks always, and embedded chunks the
		// cosine floor excluded (exact identifiers are where cosine fails).
		if s == 0 {
			s = keywordScore(query, c.Text)
		}
		if s > 0 {
			all = append(all, scored{c, s})
		}
	}
	if len(all) == 0 {
		return nil, nil
	}
	sort.Slice(all, func(i, j int) bool { return all[i].s > all[j].s })
	if len(all) > k {
		all = all[:k]
	}
	out := make([]chunk, 0, len(all))
	for _, s := range all {
		out = append(out, s.c)
	}
	return out, nil
}

// DocStat is one document's footprint in the index.
type DocStat struct {
	Doc    string
	Chunks int
	Chars  int
	TS     int64
}

// ListDocs aggregates chunks per document, newest first.
func (ix *Index) ListDocs() []DocStat {
	ix.mu.RLock()
	defer ix.mu.RUnlock()
	byDoc := make(map[string]*DocStat)
	for _, c := range ix.chunks {
		d, ok := byDoc[c.Doc]
		if !ok {
			d = &DocStat{Doc: c.Doc, TS: c.TS}
			byDoc[c.Doc] = d
		}
		d.Chunks++
		d.Chars += len(c.Text)
		if c.TS > d.TS {
			d.TS = c.TS
		}
	}
	out := make([]DocStat, 0, len(byDoc))
	for _, d := range byDoc {
		out = append(out, *d)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].TS > out[j].TS })
	return out
}

// DeleteDoc removes every chunk of doc; reports chunks removed and found.
// Serialized against ingest's chunk swap by ix.mu (embeds happen outside the
// lock, so a delete never waits on the network). A same-doc ingest already
// past its embed may re-add the doc after this returns — last writer wins,
// the same rename-collision exposure as ingest's replace.
// ponytail: Sum is a uint32 doc-name hash, same collision exposure as
// ingestNow's replace; widen to the full sha if it ever matters.
func (ix *Index) DeleteDoc(doc string) (int, bool) {
	sum := docSum(doc)
	ix.mu.Lock()
	defer ix.mu.Unlock()
	kept := ix.chunks[:0]
	n := 0
	for _, c := range ix.chunks {
		if c.Sum == sum {
			n++
			continue
		}
		kept = append(kept, c)
	}
	if n == 0 {
		return 0, false
	}
	ix.chunks = kept
	// In-memory state is authoritative for this process; the next write re-persists.
	if err := ix.save(); err != nil {
		log.Printf("[kb] delete %s persist failed: %v", doc, err)
	}
	return n, true
}

// tryEmbedAll returns (nil, nil) when no embedder is configured; embeds
// otherwise, passing the error through (the caller degrades to keyword-only
// storage instead of failing the push).
func (ix *Index) tryEmbedAll(ctx context.Context, texts []string) ([][]float32, error) {
	if ix.embed == nil {
		return nil, nil
	}
	return ix.embedAll(ctx, texts)
}

// embedAll embeds texts in batches of embedBatchSize.
func (ix *Index) embedAll(ctx context.Context, texts []string) ([][]float32, error) {
	out := make([][]float32, 0, len(texts))
	for start := 0; start < len(texts); start += embedBatchSize {
		end := start + embedBatchSize
		if end > len(texts) {
			end = len(texts)
		}
		vecs, err := ix.embed.Embed(ctx, texts[start:end])
		if err != nil {
			return nil, err
		}
		if len(vecs) != end-start {
			return nil, fmt.Errorf("batch %d: want %d vecs, got %d", start/embedBatchSize, end-start, len(vecs))
		}
		out = append(out, vecs...)
	}
	return out, nil
}

func (ix *Index) save() error {
	if ix.path == "" {
		return nil
	}
	var sb strings.Builder
	for _, c := range ix.chunks {
		b, err := json.Marshal(c)
		if err != nil {
			return err
		}
		sb.Write(b)
		sb.WriteByte('\n')
	}
	return util.AtomicWrite(ix.path, []byte(sb.String()), 0o600)
}

// chunkText splits text into overlapping rune-safe chunks, capped per doc.
func chunkText(doc, text string) []string {
	runes := []rune(strings.TrimSpace(text))
	if len(runes) == 0 {
		return nil
	}
	if len(runes) <= chunkSize {
		return []string{string(runes)}
	}
	var out []string
	for start := 0; start < len(runes) && len(out) < maxChunksPerDoc; start += chunkSize - chunkOverlap {
		end := start + chunkSize
		if end > len(runes) {
			end = len(runes)
		}
		out = append(out, string(runes[start:end]))
		if end == len(runes) {
			break
		}
	}
	return out
}

func docSum(doc string) uint32 {
	h := sha256.Sum256([]byte(doc))
	return uint32(h[0]) | uint32(h[1])<<8 | uint32(h[2])<<16 | uint32(h[3])<<24
}

// minCosine is the cosine similarity floor: without it the top-k of an
// unrelated corpus still got served and the model answered from noise —
// "no match" is the honest result (audit round 4).
const minCosine = 0.35

// keywordScore = the fraction of query tokens found in text; 0 = no match.
// Tokens: lowercase latin word tokens (len ≥ 2 — Indonesian/English words,
// numbers) and CJK character bigrams (CJK has no word boundaries). Both
// score on the same 0..1 scale as cosine, so a mixed index merges in one
// ranking.
func keywordScore(query, text string) float64 {
	qt := keywordTokens(query)
	if len(qt) == 0 {
		return 0
	}
	tt := make(map[string]struct{}, 64)
	for _, t := range keywordTokens(text) {
		tt[t] = struct{}{}
	}
	matched := 0
	for _, t := range qt {
		if _, ok := tt[t]; ok {
			matched++
		}
	}
	if matched == 0 {
		return 0
	}
	return float64(matched) / float64(len(qt))
}

func keywordTokens(s string) []string {
	var toks []string
	var word []rune
	var prevCJK rune
	flush := func() {
		if len(word) >= 2 {
			toks = append(toks, string(word))
		}
		word = word[:0]
	}
	for _, r := range strings.ToLower(s) {
		switch {
		case isCJK(r):
			flush()
			if prevCJK != 0 {
				toks = append(toks, string([]rune{prevCJK, r}))
			}
			prevCJK = r
		case r >= 'a' && r <= 'z' || r >= '0' && r <= '9':
			word = append(word, r)
		default:
			flush()
			prevCJK = 0
		}
	}
	flush()
	return toks
}

func isCJK(r rune) bool {
	return r >= 0x3400 && r <= 0x9FFF
}

// cosine of two vectors (assumes equal length).
func cosine(a, b []float32) float64 {
	if len(a) != len(b) {
		return -1
	}
	var dot, na, nb float64
	for i := range a {
		dot += float64(a[i]) * float64(b[i])
		na += float64(a[i]) * float64(a[i])
		nb += float64(b[i]) * float64(b[i])
	}
	if na == 0 || nb == 0 {
		return 0
	}
	return dot / (math.Sqrt(na) * math.Sqrt(nb))
}
