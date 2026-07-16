# AI Test Report — Normal & Edge-Case Coverage

> Status: Pre-deployment review of automated test coverage for the AI subsystem.
> Generated from the live codebase + a fresh test run. Findings are factual, not aspirational.

## Executive verdict: PARTIAL

Structured **normal- and edge-case testing exists for roughly half of the AI surface**, but **the seven core AI features and the LLM gateway they run on have no automated tests at all**. The AI system, as a whole, is **not yet validated for normal and edge conditions before deployment**.

- **Tested:** the **War Room / AI Retrospective** subsystem, the **PromptOptimizer** utility, and the **read-only export & label API endpoints**.
- **Not tested:** AI Chat, Recurrence Detection, Incident Analysis, the Detail/Timeline Enhancer, Smart Labeling, AI-Powered Search, Post-mortem generation, plus the shared LLM gateway (`AiTextService`), the RAG service, the similarity pipeline, and the recurrence job/observer.
- **Latest run (AI-relevant subset):** `198 passed, 3 failed` of **201 tests** (936 assertions). All 3 failures are in the export endpoint, not core AI logic.

## Methodology

1. Enumerated every test under `tests/` touching the AI surface — services, controllers, jobs, observers, RAG, chat, plan mode, and the War Room subsystem.
2. Cross-referenced the test list against the **7 documented AI features** and their backing code paths.
3. Searched the suite for references to `/admin/ai/*` action endpoints and to the core services (`AiTextService`, `RecurrenceDetectionService`, `SimilarIncidentService`, `RagService`, `PostMortemService`, `DetectRecurrenceJob`, `IncidentObserver`).
4. Executed the AI-relevant subset: `php artisan test … -d memory_limit=512M`.

## Coverage by area

| # | Area | Backing code | Tested? | Normal cases | Edge cases | Tests | Result |
|---|------|--------------|:------:|:------:|:------:|:----:|:------|
| 1 | Prompt optimization (cross-cutting) | `Ai/PromptOptimizer` | **Yes** | Yes | Yes — empty string, already-optimized, control chars, `N/A`/`None`/`-` stripping, war-room context preservation, short-prompt skip, token stats | 28 | ✅ Pass |
| 2 | War Room controllers (create/list/show/delete/templates/agents/incident-search) | `Controllers/Ai/WarRoom*` | **Yes** | Yes | Yes — validation, missing records, permissions | 47 | ✅ Pass |
| 3 | War Room services (Service, ToolExecutor, ToolRegistry, AgentPromptBuilder) | `Services/WarRoom/*` | **Yes** | Yes | Yes | 77 | ✅ Pass |
| 4 | War Room jobs (process / start / synthesize) | `Jobs/WarRoom/*` | **Yes** | Yes | Partial | 8 | ✅ Pass |
| 5 | AI Export API (`/api/v1/ai/export`) | `ExportController` | **Yes** | Yes | Yes — filters, pagination, null PIC, empty labels, field types, ordering | 35 | ❌ 3 fail |
| 6 | Labels API (`/api/v1/labels`, library read) | `LabelController` | **Yes** | Yes | Yes — empty array, cache, auth, permissions | 6 | ✅ Pass |
| 7 | **LLM gateway** — all `/chat/completions` calls | `AiTextService` | **No** | — | — | 0 | — |
| 8 | **AI Chat** (stream / personas / Plan mode) | `ChatStreamService`, `Ai/PlanMode/*` | **No** | — | — | 0 | — |
| 9 | **Recurrence Detection** (service / job / observer) | `RecurrenceDetectionService`, `DetectRecurrenceJob`, `IncidentObserver` | **No** | — | — | 0 | — |
| 10 | **Incident Analysis** (root cause) | `AnalyzeRootCauseController`, `AiTextService::analyzeRootCause` | **No** | — | — | 0 | — |
| 11 | **Detail & Timeline Enhancer** | `TextEnhanceController`, `AiTextService::enhance` | **No** | — | — | 0 | — |
| 12 | **Smart Labeling** (suggest / apply) | `SuggestLabelsController`, `ApplyLabelsController` | **No** | — | — | 0 | — |
| 13 | **AI-Powered Search** (NL → filters) | `AiSearchController`, `parseNaturalLanguageQuery` | **No** | — | — | 0 | — |
| 14 | **RAG service** (FULLTEXT index / search) | `RagService`, `RagDocument` | **No** | — | — | 0 | — |
| 15 | **Similarity pipeline** (Think → Find → Verify) | `SimilarIncidentService` | **No** | — | — | 0 | — |
| 16 | **Post-mortem generation** | `PostMortemService` | **No** | — | — | 0 | — |
| 17 | CircuitBreaker / AiUsageLogger / AiBudgetAlert | `Ai/*` | **No** | — | — | 0 | — |

**Totals:** 201 executed tests — 132 War Room, 35 export, 28 PromptOptimizer, 6 labels. 198 passed, 3 failed.

## What “tested” actually covers today

The passing tests exercise **deterministic logic only** — prompt-text reduction, request routing, tool execution, response shaping, and auth/validation. **None of them call the LLM** (real or mocked): the existing suite never reaches `/chat/completions`. As a result, the behaviour most likely to regress or surprise in production — LLM JSON extraction, label parsing, natural-language-to-filter parsing, similarity scoring thresholds, circuit-breaker open/close, and the recurrence verdict — has **zero automated guard-rails**.

## Failures observed (all in the export endpoint)

1. **`Class "App\Jobs\FundStatus" not found`** — `app/Jobs/CalculateIncidentMetrics.php:229–232` uses `FundStatus::ConfirmedLoss` (etc.) but the file imports only `IncidentStatus` and `Severity`, not `App\Enums\FundStatus`. This is a **real bug** (missing `use`), triggered whenever incident metrics are calculated. Affects 2 of the 3 failures.
2. **Export `pic` shape mismatch** — `AiExportTest` expects `pic` = `{ name, email }` but the export resource returns `{ name }` only (email omitted). Affects 1 failure.

> Note: `AiExportTest` is part of the **read-only export API**, not the core AI features. These failures do not change the headline finding about the 7 features.

## Risk assessment

- The features carrying the **highest behavioural risk and external dependency** (LLM output, similarity scoring, recurrence classification) are the **least tested**.
- A regression in `callAiForJson` (the JSON extractor every feature depends on), in similarity verification thresholds, or in the recurrence scoring path would **not** be caught by CI today.
- Plan mode, chat streaming (SSE), persona fan-out, and budget/circuit-breaker logic have **no coverage** despite their complexity.

## Recommendations (prioritised)

1. **Gateway first.** Unit-test `AiTextService` with `Http::fake()` standing in for the gateway — cover JSON extraction (`callAiForJson`), label parsing, model resolution (`AiSetting` override), CircuitBreaker trip/recovery, and per-user rate limiting. Normal + edge (empty input, 429/500, malformed JSON, oversized input, timeout).
2. **Feature smoke tests.** Add a feature test per `/admin/ai/*` action (enhance, root-cause, suggest/apply labels, search, detect-similar, post-mortem) with the LLM mocked — assert happy path + auth + validation edge cases.
3. **Scoring logic.** Unit-test `SimilarIncidentService` (Think→Find→Verify thresholds, `< 0.4` rejection, double-check band `0.4–0.69`) and `RecurrenceDetectionService` (score threshold ≥ 3, AI-pipeline fallback) with mocked AI calls.
4. **RAG.** Test `RagService` indexing + FULLTEXT ranking on the test DB.
5. **Chat/Plan mode.** Add tests for `ChatStreamService` and `PlanModeService` (plan parsing, subtask dispatch, synthesis), gateway mocked.
6. **Fix the two defects.** Add `use App\Enums\FundStatus;` to `CalculateIncidentMetrics`; restore the `email` field to the export `pic` shape (or update the assertion).

## Conclusion — direct answer to the question

**Has the AI been tested in normal and edge cases? Not for the core capabilities.**

Structured normal- and edge-case testing is in place for the **War Room / AI Retrospective subsystem, the PromptOptimizer utility, and the export/label APIs** (201 tests, 198 passing). The **seven core AI features and the LLM gateway are not covered by any automated tests**, so their behaviour under normal and edge conditions is **not validated before deployment**. Completing recommendations 1–3 is the minimum gate before the AI subsystem can be described as tested.
