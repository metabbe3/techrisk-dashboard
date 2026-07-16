# Technical Risk Dashboard — AI Architecture & Workflow

> End-to-end reference for the AI subsystem, derived from the live codebase. Model aliases (`SMART/FAST/REASONING-MODEL`) map to real provider models at deploy time.

## 1. Overview

The AI subsystem is built around a **single OpenAI-compatible LLM gateway**. Every AI call — chat, field enhancement, root-cause analysis, similarity matching, post-mortems, and natural-language search — is an HTTP `POST {base_url}/chat/completions` with a `Bearer AI_API_KEY` header and a standard `{ model, messages, max_tokens, temperature }` body. There is no vendor SDK; the system is provider-agnostic.

**Model aliases** (not model IDs) are configured throughout: `SMART-MODEL`, `FAST-MODEL`, `REASONING-MODEL`. Operators map these to real models (e.g. `gemini-2.5-flash`, `gpt-4o`, `qwen3-235b`) and set per-model input/output limits in `config/ai.php`.

**Settings override pattern.** Every gateway call resolves values as `AiSetting::get($key, config("ai.$key"))` — the database (`ai_settings`) wins, `config/ai.php` is the fallback, `.env` underlies config. Admins change models/keys live from the **AI Settings** Filament page (with a *Sync from Gateway* action) without redeploying.

**Asynchronous work** runs on Redis queues: `default` (recurrence detection, conversation memory, proactive analysis) and `war-room` (Plan-mode subtasks & synthesis, War Room agents).

## 2. Architecture (layered)

```text
┌──────────────────────────────────────────────────────────────────┐
│ 1 · ENTRY POINTS                                                 │
│   Filament UI: AI Chat · Incident forms · Reporting · War Room   │
│   API:  /api/v1/ai/export   (read / export only)                 │
└──────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌──────────────────────────────────────────────────────────────────┐
│ 2 · HTTP CONTROLLERS — app/Http/Controllers/Ai/*                 │
│   ChatStream · TextEnhance · AnalyzeRootCause · SuggestLabels    │
│   ApplyLabels · AiSearch · DetectSimilar · GeneratePostMortem    │
└──────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌──────────────────────────────────────────────────────────────────┐
│ 3 · AI SERVICES — app/Services/Ai/*                              │
│   AiTextService (the gateway)                                    │
│   ChatStream · AiChat · PersonaStreaming · SseStreaming          │
│   SimilarIncidentService · PostMortemService · RagService        │
│   SearchPlanningService · PlanMode/*   (+ WarRoom/* sibling)     │
└──────────────────────────────────────────────────────────────────┘
          │                                       │
          ▼                                       ▼
┌───────────────────────┐       ┌────────────────────────────────────┐
│ 4 · CROSS-CUTTING     │       │ 5 · LLM GATEWAY                    │
│   CircuitBreaker      │       │   POST {base_url}                  │
│   AiUsageLogger       │       │        /chat/completions           │
│   TokenMetricsService │       │   Bearer AI_API_KEY                │
│   PromptOptimizer     │       │   aliases: SMART / FAST / REASONING│
│   AiBudgetAlert       │       └────────────────────────────────────┘
└───────────────────────┘
                                │
                                ▼
┌──────────────────────────────────────────────────────────────────┐
│ 6 · DATA + JOBS                                                  │
│   MySQL: incidents · rag_documents (FULLTEXT)                    │
│          chat_conversations · chat_messages · chat_plan_subtasks │
│          ai_usage_logs · ai_settings                             │
│   Redis queues: default · war-room                               │
│   Jobs: DetectRecurrence · ProcessPlanSubtask · SynthesizePlan…  │
└──────────────────────────────────────────────────────────────────┘
```

*Read top-down: requests enter at the UI/API, pass through controllers into AI services, are guarded by the cross-cutting layer, reach the LLM gateway, and persist to MySQL/Redis.*


## 3. End-to-end flows

### (a) Chat message
Browser `POST /admin/ai/chat/stream` → `ChatStreamController` → `ChatStreamService`. It rate-limits (`ai-chat:{userId}`), resolves or creates a `ChatConversation`, persists the user `ChatMessage`, loads the last 20 messages, and builds context via `ChatContextService` (slash commands, RAG incident context, web-search intent). It returns a `StreamedResponse`; `SseStreamingService` opens a **raw curl** connection to the gateway (required for true token streaming) and re-emits `data: {delta}` SSE events. On completion it emits `metadata` (usage, model), persists the assistant message, logs usage, and archives memory.

- **Personas** (`/stream-personas`): `PersonaStreamingService` fans out N agents concurrently with `curl_multi_init`, interleaving per-persona deltas.
- **Plan mode** (`/stream-plan`): clarify → pre-analyze → plan (`REASONING-MODEL`) → validate → dispatch one `ProcessPlanSubtask` job per subtask (queue `war-room`) → poll status → optional gap analysis + research → `SynthesizePlanResults` (`SMART-MODEL`) → stream synthesis.

### (b) On-demand incident action
A form button → controller → `AiTextService` method → gateway → JSON/text result → side effects (mark fields AI-enhanced, store `recurrence_data`, cache trends). Synchronous; gated by the `ai.available` middleware, per-user rate limits, and the `CircuitBreaker`.

### (c) Background recurrence detection
`IncidentObserver::created/updated` → `DetectRecurrenceJob` (delayed 30 s, 3 tries) → `RecurrenceDetectionService::detect()`. If the incident has categories/labels, it scores DB candidates (12-month lookback) by category overlap + FULLTEXT RAG score (threshold ≥ 3, top 5); otherwise it uses `SimilarIncidentService::analyze()`. Either path then asks the LLM to explain the recurrence (causal vs correlation, confidence, overdue actions) and stores the result on `incident.recurrence_data`.

### (d) RAG indexing
`IncidentObserver` (via `RagDocumentObserver`) → `RagService::indexIncident()` → `RagDocument::updateOrCreate`, denormalizing title/summary/root_cause/timeline/remark/evidence/improvements/labels/categories into a `searchable_content` FULLTEXT column. Retrieval = `MATCH … AGAINST` ranked by MySQL relevance.

## 4. Key subsystems

- **RAG = MySQL FULLTEXT, not embeddings.** One `rag_documents` row per incident; truncated context blocks (≤ `rag.max_context_tokens`) are injected into chat and search.
- **Similarity pipeline (THINK → FIND → VERIFY → DOUBLE-CHECK):** `similarity_think` (REASONING) extracts failure-mode signals → FIND runs **hybrid ranked retrieval** — MySQL FULLTEXT over `rag_documents.searchable_content` (relevance normalized to 0–1) is the primary signal, fused (`rag_weight` + `struct_weight`) with capped boosts from structured dimensions (category / label / team / financial). Candidates are ranked by the fused score so VERIFY receives the strongest matches, not an arbitrary slice → `similarity_verify` scores six dimensions and rejects anything below `0.4` (critical dimensions below `0.3`) → one **batched** `similarity_double_check` call adjudicates borderline `0.4–0.69` matches. Drives both *Detect similar* and recurrence.
- **Plan-mode economics:** token budget split planning 10% / subtasks 60% / synthesis 30%; subtask model routing by type (analysis/retrieval/comparison/research); an ultra-concise "caveman" mode for simple lookups.
- **Budget & limits:** one `AiUsageLog` row per call → `AiUsageLogger` → `AiBudgetAlertService` at 80% of the 1 M daily-token ceiling; `CircuitBreaker` per model (5 failures → 60 s cooldown); per-user `RateLimiter` on enhance and chat.

## 5. Data model

| Table | Purpose |
|---|---|
| `incidents` | Core record; carries `recurrence_data` (JSON) and AI-enhanced field markers. |
| `rag_documents` | One per incident; `searchable_content` is the FULLTEXT column. |
| `chat_conversations` / `chat_messages` | Chat sessions and every turn (role, persona, tokens, feedback, plan metadata). |
| `chat_plan_subtasks` | One per Plan-mode subtask; state machine pending → running → completed/failed. |
| `ai_usage_logs` | Per-call audit: user, feature, model, tokens, latency, success. |
| `ai_settings` | Key/value JSON overrides of `config/ai.php`. |

## 6. Observability & resilience

`AiUsageLog` drives the admin usage widgets (overview, trend chart, top users, cost estimate). Resilience is layered: `CircuitBreaker` opens on repeated provider failures; per-user rate limits cap abuse; budget alerts flag spend; and `FeedbackLearningService` turns chat thumbs-up/down into context rules. Plan-mode coordination state (clarification, synthesis, gap analysis) lives in the cache, while durable state lives in the tables above.
