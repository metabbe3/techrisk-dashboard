---
name: knowledge-base
status: shipped
tools: [search_knowledge, save_knowledge, list_knowledge, delete_knowledge]
gate: AGENT_KB_TOOLS (default on)
prompt: KnowledgePrompt (gated on registry.Has("search_knowledge"), loop.go:329)
files: [internal/tools/knowledge.go, internal/kb/]
---

# Team knowledge base (RAG)

## Purpose
Chat-uploaded SOPs and filed text, searchable across conversations. The model
is taught to call `search_knowledge` FIRST for procedure questions and to use
`save_knowledge` on "remember this" — teaching all four by name in
KnowledgePrompt was the fix for the 2026-11-12 "tools shipped untaught" bug.

## Contract
| Tool | Params | Returns |
|---|---|---|
| `search_knowledge` | `query` (required) | relevant passages with document names |
| `save_knowledge` | `title`, `content` (both required) | confirmation; filing over an existing title REPLACES it |
| `list_knowledge` | — | every doc: title, size, date |
| `delete_knowledge` | `title` (required, exact) | removal; ONE doc per call |

## Guardrails & caps
- Registration: `RegisterKnowledgeTools(ix *kb.Index)` (knowledge.go:16),
  called from `stream.go`'s `NewBot` when `cfg.KBToolsEnabled`; also sets
  `r.kbIndex` (exposed via `KBIndex()`). Default loop cap.
- Writes ride `util.AtomicWrite` under `<DataDir>/`; embeddings via
  `cfg.EmbeddingModel` (`cmp.Or` of `LLM_EMBEDDING_MODEL`/`AI_EMBEDDING_MODEL`,
  default matches the dashboard's `config/ai.php`).
- Constitution scope bullet names all four tools verbatim — keep it that way.

## Tests
- `knowledge_test.go` — `sameEmb{}` fake Embedder + `kb.NewIndex(t.TempDir(),...)`;
  pins search/save/list/delete round-trips.
- `TestKnowledgePromptTeachesAllKBTools` (`agent/loop_test.go:408`) — the
  prompt-parity pattern every new family must copy.
- `TestAllToolSchemasFlat` includes the KB tools (they shipped outside that
  net once — see its comment).

## Gotchas
- This is the family the whole convention exists because of: registry +
  tests + gates were all green while prompt/constitution still forbade three
  of the four tools. Any new KB tool joins BOTH `KnowledgePrompt` AND the
  constitution scope bullet in the same commit.
- Uploaded files ingest separately (README "Document ingestion"); these tools
  are the LLM-facing surface over the same index.
