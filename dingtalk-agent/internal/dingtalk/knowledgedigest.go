package dingtalk

import (
	"context"
	"fmt"
	"log"
	"strings"

	"techrisk-dashboard/dingtalk-agent/internal/llm"
	"techrisk-dashboard/dingtalk-agent/internal/memory"
	"techrisk-dashboard/dingtalk-agent/internal/schedule"
	"techrisk-dashboard/dingtalk-agent/internal/util"
)

// The compiled document-memory digest. knowledge.jsonl stays the append-only
// raw layer; this merges it (oldest→newest, batches, one cheap no-tools LLM
// call per batch) into a bounded knowledge.md injected every turn — so old
// document memory stays visible past the last-8 window. Same never-blocks,
// never-wipes contract as profile learning (stream.go learnProfile).
const (
	digestBatch       = 20  // knowledge.jsonl entries per LLM merge call
	digestBackfillCap = 400 // entries merged per boot; the rest continue next boot
)

// rememberKnowledge is the ONE door into knowledge.jsonl: appends the entry,
// then kicks an incremental merge in the background (feature-flagged).
func (b *Bot) rememberKnowledge(e memory.KnowledgeEntry) {
	if b.store == nil {
		return
	}
	if err := b.store.AppendKnowledge(e); err != nil {
		log.Printf("[dingtalk] persist knowledge failed: %v", err)
		return
	}
	if b.cfg.KnowledgeDigest && b.llm != nil {
		go b.mergeKnowledgeDigest()
	}
}

// mergeKnowledgeDigest merges every not-yet-covered knowledge.jsonl entry into
// knowledge.md in batches. Single-flight (boot backfill + per-append triggers
// serialize on one mutex — the loser sees pending==0 and no-ops). An empty or
// failed merge NEVER advances the covered cursor: the store refuses empty
// saves, an LLM error just logs, and the next trigger retries — the raw layer
// never loses an entry this way.
func (b *Bot) mergeKnowledgeDigest() {
	if b.store == nil || b.llm == nil || !b.cfg.KnowledgeDigest {
		return
	}
	b.digestMu.Lock()
	defer b.digestMu.Unlock()

	for batchCount := 0; batchCount < digestBackfillCap/digestBatch; batchCount++ {
		entries := b.store.AllKnowledge()
		covered := b.store.DigestCovered()
		if covered >= len(entries) {
			return // everything merged (or nothing to merge)
		}
		batch := entries[covered:]
		if len(batch) > digestBatch {
			batch = batch[:digestBatch]
		}

		// Existing digest lines + the batch rendered like knowledgePrompt
		// does, so the merge sees the same shape it will inject later.
		cur := b.store.KnowledgeDigest()
		if cur == "" {
			cur = "(none yet)"
		}

		var news strings.Builder
		for _, e := range batch {
			summary := util.Cut(e.Summary, 600)
			if summary != e.Summary {
				summary += "…"
			}
			summary = strings.ReplaceAll(summary, "\n", " ")
			fmt.Fprintf(&news, "- [%s] %s — %s\n",
				e.Time.In(schedule.WIB).Format("2006-01-02"), e.File, summary)
		}

		reply, _, err := b.llm.ChatCompletion(context.Background(), []llm.ChatMessage{
			{Role: "system", Content: digestSystemPrompt},
			{Role: "user", Content: fmt.Sprintf(
				"EXISTING DIGEST:\n%s\n\nNEW DOCUMENT SUMMARIES (oldest first):\n%s",
				cur, news.String())},
		}, nil)
		if err != nil {
			log.Printf("[dingtalk] knowledge digest merge failed (will retry on next trigger): %v", err)
			return
		}
		if err := b.store.SaveKnowledgeDigest(reply.Content, covered+len(batch)); err != nil {
			log.Printf("[dingtalk] digest save failed (cursor not advanced, will retry): %v", err)
			return
		}
	}
}

const digestSystemPrompt = "You maintain the assistant's compiled document memory (a digest of " +
	"document summaries collected from user uploads and Notion pages read in chat). " +
	"Merge the NEW DOCUMENT SUMMARIES into the EXISTING DIGEST: keep every distinct fact, " +
	"collapse duplicates, drop lines that no longer matter. Line format: " +
	"'- [YYYY-MM-DD] source — fact'. Output ONLY the updated digest as at most " +
	"40 lines (max 200 chars each), no numbering, no commentary. " +
	"If the new summaries add nothing, output the existing digest unchanged."

// ensureKnowledgeDigest is the boot hook: the one-time backfill that converts
// existing prod memory (all of knowledge.jsonl, oldest→newest) into the
// digest the first time the feature runs — then a no-op.
func (b *Bot) ensureKnowledgeDigest() {
	if !b.cfg.KnowledgeDigest || b.llm == nil {
		return
	}
	go b.mergeKnowledgeDigest()
}
