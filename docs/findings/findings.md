# Technical Findings

*This document tracks technical debt, performance issues, security concerns, and architecture improvements discovered during development and maintenance.*

---

## Template

```markdown
### [FIND-XXX] Finding Title

**Date:** YYYY-MM-DD
**Discovered By:** [Name]
**Category:** [Performance / Security / Architecture / Technical Debt / Code Quality]
**Severity:** [Critical / High / Medium / Low]
**Status:** [Open / In Progress / Resolved / Won't Fix]
**Related Project:** [PROJ-XXX or N/A]

#### Description
[What was found - be specific]

#### Affected Component
- Component: [Model / Service / Controller / View / Migration / Config / etc.]
- Location: `path/to/file.php:line_number`

#### Impact
[Why this matters - potential consequences if not addressed]

#### Evidence
[Code snippets, metrics, logs, or other evidence]

#### Recommended Action
[What should be done to address this finding]

#### Estimated Effort
- Complexity: [Low / Medium / High]
- Time Estimate: [Hours/Days]
- Risk Level: [Low / Medium / High]

#### Priority
[When should this be addressed - Now / Next Sprint / Backlog / Nice to Have]

#### Action Items
- [ ] Action item 1 - [Assigned To] - [Due Date]
- [ ] Action item 2 - [Assigned To] - [Due Date]

#### Resolution
- [Date]: [Description of how it was resolved]

---

**Reviewed By:** [Name]
**Review Date:** YYYY-MM-DD
```

---

## Findings

### AI Agent Flow Audit — 2026-09-21 (PROJ-005)

Audit of the agent runtime (`RunAiAgentJob`, `DispatchDueAiAgentsCommand`, chaining, memory) against agent-orchestration best practice (trigger.dev durable-agent patterns; structured-output literature — explicit format instructions, validate, feed validation errors back for one repair retry). **AG-1..3 fixed same day**; AG-4+ recorded as roadmap.

| ID | Finding | Sev | Status |
|----|---------|-----|--------|
| AG-1 | **Chain handoff gap** — dependent agents never saw the upstream run's output; only ≤3 shared-memory bullets (≤300 chars each) crossed the boundary | H | **Fixed 2026-09-21** — `## Upstream result from "{agent}"` block injected from `triggeredBy` output (`upstream_inject_limit`, default 4000 chars; pruned upstream → block skipped) |
| AG-2 | **No retry** — `$tries=1`; one transient gateway blip failed the run and silently killed the whole chain | H | **Fixed** — one in-`handle()` retry (3s backoff) around the generation call only; side effects (memory/email/chain) still exactly once |
| AG-3 | **No output contract** — no way to declare expected output; nothing validated it | M | **Fixed** — `expected_output` (injected as `## Expected output`) + `require_json` (JSON-validated; one repair retry feeding the parse error back; twice-invalid → Failed with `Output failed JSON validation after retry.` so the chain correctly dies). Both draftable via "Draft with AI" |
| AG-4 | Missed schedules skip silently — cron due-check has no catch-up after downtime/outage | M | Open |
| AG-5 | Memory has no dedup or aging — repeated lessons accumulate; injection bounded at 25×300 chars but the table grows unbounded | L | Open |
| AG-6 | No quick thumbs up/down on a run — feedback requires typing (Feedback memory exists since 2026-09-21) | L | Open |
| AG-7 | Chain-dispatch failure only logged (`Log::warning`) — no surfaced signal in the UI | L | Open |
| AG-8 | No chain-depth cap — cycles impossible via UI but deep chains dispatch unbounded hops | L | Open (upgrade path: cap `triggered_by` hops) |

**Lesson (AG-2):** retry placement matters when a job has side effects — retrying the whole job would re-send email/duplicate memory; the retry loop wraps only the idempotent generation call. `AiTextResult` is readonly — failed-validation conversion goes through `AiTextResult::failure()`, never property mutation.

### Best-Practice Audit — 2026-06-29

Three-angle audit (unified API response, DRY, OOP/architecture). Each finding has a stable ID — pick any ID below and its section has enough `file:line` evidence to implement without re-auditing the codebase. Fix phases and rationale are in the approved plan: `~/.claude/plans/synchronous-conjuring-pillow.md`.

#### Implementation progress (2026-06-29)
- **Foundation done (Phase 1.1–1.3):** `ApiResponser` now defines one `{code,status,message,data,?errors}` shape via a private `respond()`; `use ApiResponser;` moved into the base `Controller` so all controllers inherit it; `bootstrap/app.php` exception handler unified through one `$apiResponse` closure, with the 429 throttle renderer added (R-6), the validation `errors` key folded into the standard shape (R-3), and the debug-mode message leak removed (R-4); `ApiFormRequest` repurposed to route authorization failures through the unified handler (R-7), and the 7 API request classes now extend it. Verified by 3 new `ExceptionHandlingTest` cases (422+errors, 401, no-leak 500).
- **Resolved:** R-3, R-6, R-7 (and the R-4 leak at the handler + `WarRoomCreateController` 500 path). `WarRoomCreateController` no longer catches generic `Throwable` — unexpected errors propagate to the hardened handler.
- **Open / incremental:** R-1 (the ~44 `Ai/` controllers still emit ad-hoc shapes — migrate one at a time, confirming frontend consumers first), R-8 (controllers still catch inconsistently), the remaining R-4 sites (raw messages in SSE `event: error` frames — `ChatStreamController`). Phases 2–6 (D-*, O-*, S-*) are not yet started; each is self-contained and independently shippable.
- **Batch 2 done (behavior-preserving, FE-identical):** **D-7** `scopeExcludedFromCounts` (scope on `Incident`; 17 identical closures replaced). **O-3** `convertToMarkdown` moved verbatim to `IncidentFormatter::toMarkdown` (+ new `IncidentMarkdownTest`). **O-5** service-locator → constructor DI on all 4 chat/warroom controllers. **O-6a (partial)** Severity/`IncidentStatus` comparison literals purged to `Enum::Case->value` (no casts). All verified: full suite stays at the pre-existing baseline, `pint` clean.
- **Latent bug found & fixed (O-3):** `IncidentFormatter::toMarkdown` called `number_format()` on a decimal-cast attribute (a **string**) → `TypeError` 500 on every incident with a fund value. Fixed via `(float)` cast. This was pre-existing in the old `convertToMarkdown`; no test existed until now.
- **Still deferred (would change FE results / behavior):** D-1, D-2, D-4, D-5, O-1, O-2, O-6b (casts), O-8, O-10, S-1, S-2, S-3 — needs consumer coordination. **O-6a remainder** (the broad `where('classification','Incident')` query sweep) is safe but low value-per-churn.
- **Batch 3 done (2026-06-29, behavior-preserving / additive):** **D-6** `IncidentResource` tab literals → enum values (single source). **D-8** MTBF block → `ListIncidents::computeAvgMtbf()`. **D-3** `app/Support/Export::downloadFilename()` helper across 5 export controllers. **D-4** `WeeklyReportExportController` delegates to `WeeklyDataService` (duplicate methods deleted; export counts now match dashboard — approved). Full suite at baseline, `pint` clean.
- **S-1 re-assessed:** already safe — `$dimSql` is a 2-value ternary and the metric goes through a `match` (default `null`); no injection vector. No change needed.
- **R-1 scope confirmed (larger than expected):** consumer surface is 35 Ai controllers + 6 Blade files **+ 4 War Room JS modules** (`resources/js/war-room/{utils,agents,session,form}.js`). A safe migration must update ALL consumers in lockstep → dedicated, coordinated session, not a rush.
- **Remaining blocked items (need prerequisites, not laziness):** R-1 (consumer lockstep), O-6b casts (~134 read sites — needs Incident-read test coverage first), O-1/O-2 extraction (untested 600-line orchestration — needs characterization tests first, per plan), O-10 (the `->can('view incidents')` calls check Spatie *permissions*, not policy methods — migrating is an authz-mechanism change, not purely additive).

**Index** (severity: H = High · M = Medium · L = Low)

| ID | Title | Sev | Status | Phase |
|----|-------|-----|--------|-------|
| R-1 | Two response conventions; trait unused by 44 Ai controllers | H | Open | 1 |
| R-2 | `status` key overloaded (envelope token vs domain value) | M | Open | 1 |
| R-3 | ValidationException adds `errors` key + leaks `$e->getMessage()` | M | Open | 1 |
| R-4 | Raw exception messages leaked to clients | H | Open | 1 |
| R-5 | Hard-coded HTTP codes scattered; no constants | L | Open | 1 |
| R-6 | `ThrottleRequestsException` (429) has no dedicated renderer | M | Open | 1 |
| R-7 | `ApiFormRequest` is dead code | M | Open | 1 |
| R-8 | Inconsistent exception catching (`Exception` vs `Throwable` vs none) | M | Open | 1 |
| D-1 | Incident filter-building duplicated 5× | H | Open | 2 |
| D-2 | Markdown rendering implemented 3× | H | Open | 2 |
| D-3 | Export primitives rebuilt per-format | M | Open | 2 |
| D-4 | `WeeklyDataService::getWeeklyData` re-implemented in a controller | H | Open | 2 |
| D-5 | try/catch + Log::error + errorResponse boilerplate ~11× | M | Open | 1 |
| D-6 | Filament tab filters defined twice (string vs enum) | M | Open | 2 |
| D-7 | `EXCLUDED_FROM_COUNTS` exclusion lambda ≥6× | M | Open | 2 |
| D-8 | MTBF computation byte-identical blocks | M | Open | 2 |
| D-9 | AI invokable skeleton duplicated ~40×, no base class | L | Open | 1 |
| D-10 | Weekly totals summing copy-pasted ×3 callers | L | Open | 2 |
| O-1 | Fat controller: `ChatSendController` (366 lines) | H | Open | 3 |
| O-2 | Fat controller: `ChatStreamController` (343 lines) | H | Open | 3 |
| O-3 | `IncidentController::convertToMarkdown` (125 lines) in controller | H | Open | 3 |
| O-4 | `IncidentController::index` builds 11 inline filter branches | H | Open | 2 |
| O-5 | Service-locator anti-pattern: 6 `app(Service::class)` calls | M | Open | 3 |
| O-6 | Enums defined but no model casts; ~15 raw literals bypass them | H | Open | 4 |
| O-7 | Zero service-layer interfaces/bindings (YAGNI — defer) | L | Open | — |
| O-8 | Untyped array payloads instead of DTOs | M | Open | 3 |
| O-9 | UI logic in `Incident` model | L | Open | — |
| O-10 | Zero authorization Policies; 44 hand-rolled `can()` checks | H | Open | 5 |
| S-1 | Raw SQL with interpolated values (safe by whitelist, fragile) | M | Open | 6 |
| S-2 | `Incident::generateNo` non-transactional retry-loop (race) | L | Open | 6 |
| S-3 | Untagged `Cache::remember` (violates CLAUDE.md) | L | Open | 2 |

---

### [R-1] Two response conventions; `ApiResponser` trait unused by 44 Ai controllers
- **Category:** Code Quality · **Severity:** High · **Status:** Open
- **Location:** `app/Traits/ApiResponser.php`; only `app/Http/Controllers/Api/{Incident,Auth,Token,ActionImprovement,Ai/Export}Controller.php` use it.
- **Impact:** At least 7 distinct response shapes coexist. Clients must special-case each endpoint family. Undermines the whole point of the trait.
- **Evidence:** `Api/IncidentController` calls `$this->successResponse(...)` 19×; `Ai/ChatSendController.php:146,344`, `Ai/ChatListController.php:54`, `Ai/WarRoomCreateController.php:87`, `Ai/TextEnhanceController.php:39` all emit raw `response()->json([...])` with different keys.
- **Recommended Action (Phase 1):** Extend `ApiResponser`, move `use ApiResponser;` into the base `app/Http/Controllers/Controller.php`, migrate Ai controllers onto `successResponse`/`errorResponse` incrementally.
- **Effort:** Complexity High · Risk Medium (envelope change for Ai clients) · Priority Now.

### [R-2] `status` key overloaded
- **Category:** Code Quality · **Severity:** Medium · **Status:** Open
- **Location:** `app/Traits/ApiResponser.php:13,21` (`'Success'`/`'Error'`) vs `app/Http/Controllers/Ai/WarRoomCreateController.php:87` and `WarRoomPollController.php:39` (domain status string).
- **Impact:** Same key, two meanings — clients can't reliably branch on `status`.
- **Recommended Action (Phase 1):** WarRoom controllers put the session/queue status inside `data`, not the top-level envelope. Resolved as part of the Ai migration.

### [R-3] ValidationException adds `errors` key and leaks the validator message
- **Category:** Code Quality · **Severity:** Medium · **Status:** Open
- **Location:** `bootstrap/app.php:38-48`.
- **Evidence:** returns `'message' => $e->getMessage()` (the validator's bag-of-rules blob) and an `errors` key no other path emits.
- **Recommended Action (Phase 1):** Unify via one `$shape()` closure; `errors` becomes a standard optional key; message becomes a fixed string with details in `errors`.

### [R-4] Raw exception messages leaked to clients
- **Category:** Security · **Severity:** High · **Status:** Open
- **Location:** `app/Http/Controllers/Ai/WarRoomCreateController.php:72-74,82-84` (`$e->getMessage()` in 500 body); `bootstrap/app.php:116` (`config('app.debug') ? $e->getMessage() : …`).
- **Impact:** Information disclosure — internal paths, DB errors, provider messages can reach the client, including on staging with `APP_DEBUG=true`.
- **Recommended Action (Phase 1):** Fixed strings only; full message+trace stays in `Log::error`. Log a lesson-learned entry (`docs/bugs/lesson-learned.md`).
- **Effort:** Complexity Low · Risk Low · Priority now.

### [R-5] Hard-coded HTTP codes scattered; no constants
- **Category:** Code Quality · **Severity:** Low · **Status:** Open
- **Location:** literals `422/500/404/401/403` in `IncidentController.php`, `AuthController.php`, `ChatSendController.php`, `WarRoomCreateController.php`, `bootstrap/app.php`.
- **Recommended Action (Phase 1):** After unification, codes live in only two central spots (trait defaults + handler). No `HttpStatusCode` enum — YAGNI until a third centralization point appears.

### [R-6] `ThrottleRequestsException` (429) has no dedicated renderer
- **Category:** Code Quality · **Severity:** Medium · **Status:** Open
- **Location:** `bootstrap/app.php` (falls into the generic `HttpException` renderer; message defaults to `'Error.'`).
- **Recommended Action (Phase 1):** Add a `ThrottleRequestsException` → 429 renderable through `$shape()`.

### [R-7] `ApiFormRequest` is dead code
- **Category:** Code Quality · **Severity:** Medium · **Status:** Open
- **Location:** `app/Http/Requests/Api/ApiFormRequest.php` (zero subclasses; `Requests/Api/V1/*` extend `FormRequest` directly; no `failedValidation`/`failedAuthorization` override).
- **Recommended Action (Phase 1):** Repurpose — override the two response methods; switch the 5 V1 requests to extend it.

### [R-8] Inconsistent exception catching
- **Category:** Code Quality · **Severity:** Medium · **Status:** Open
- **Location:** `Api/IncidentController.php` (bare `Exception`, not `Throwable`); `Ai/ChatSendController.php` (no try/catch on main path); `Ai/ChatStreamController.php` (5 `Throwable` swallowed into logs/SSE).
- **Recommended Action (Phase 1):** Controllers stop catching generic exceptions; the global handler owns them. Keep a local `catch` only to convert a *specific* exception to a non-500 code.

### [D-1] Incident filter-building duplicated 5×
- **Category:** Technical Debt · **Severity:** High · **Status:** Open
- **Location:** `app/Http/Controllers/Api/IncidentController.php:84-149`; `app/Services/Analytics/AnalyticsQueryService.php:96-121`; `app/Livewire/IncidentKanbanBoard.php:362-433`; `app/Filament/Resources/IncidentResource.php:300-360`; `app/Filament/Resources/IncidentResource/Pages/ListIncidents.php:207-234`.
- **Impact:** 157 raw `where(...)` filter clauses across 36 files; filters drift out of sync between API, Filament, Livewire, analytics, exports.
- **Recommended Action (Phase 2):** `app/Services/Incident/IncidentFilterService::apply(Builder, array)`; all 5 consumers call it.

### [D-2] Markdown rendering implemented 3×
- **Category:** Technical Debt · **Severity:** High · **Status:** Open
- **Location:** `app/Http/Controllers/Api/IncidentController.php:476-601`; `app/Http/Controllers/Ai/WarRoomExportMarkdownController.php:29-81`; `app/Services/IncidentFormatter.php:207-243`.
- **Recommended Action (Phase 2):** `IncidentFormatter` is the single renderer; the two controllers delegate to it.

### [D-3] Export primitives rebuilt per-format
- **Category:** Technical Debt · **Severity:** Medium · **Status:** Open
- **Location:** filename pattern ×5 (`WarRoomExport{Json,Markdown,Pdf}Controller`, `ChatExportPdfController`); `new Parsedown` ×3; `Pdf::loadView(...)` ×3; `Content-Disposition` header hand-built in each (`WarRoomExportJsonController.php:23`, etc.).
- **Recommended Action (Phase 2):** `app/Support/Export` helpers (`downloadFilename`, `pdfResponse`).

### [D-4] `WeeklyDataService::getWeeklyData` re-implemented in a controller
- **Category:** Technical Debt · **Severity:** High · **Status:** Open
- **Location:** `app/Http/Controllers/WeeklyReportExportController.php:46-138` duplicates `app/Services/WeeklyDataService.php:13`.
- **⚠ Not a clean duplicate — the two have semantically diverged.** The service filters `whereIn('severity', Severity::METRIC_ELIGIBLE)`, includes per-week `incidents`, and formats `date_range` as `M j - M j`; the controller counts **all** incidents (no severity filter), omits `incidents`, and formats `date_range` as `M j - M j, Y`. Swapping the controller to call the service will **change the export's numbers**.
- **Recommended Action (Phase 2):** First decide the intended counting rule (almost certainly the export should match the service's `METRIC_ELIGIBLE` filter for consistency). Then have the controller call `WeeklyDataService::getWeeklyData()`; move `totalOpen/totalClosed/grandTotal` summing into the service. Add/extend `WeeklyReportTest` to lock the expected counts before + after.

### [D-5] try/catch + Log::error + errorResponse boilerplate ~11×
- **Category:** Technical Debt · **Severity:** Medium · **Status:** Open
- **Location:** `Api/IncidentController.php` (8 blocks: 152,185,217,247,285,305,363,415,466,616,635); `Ai/WarRoomCreateController.php:65-85`; `Ai/ChatStreamController.php`; `Ai/DetectSimilarController.php:156`.
- **Recommended Action (Phase 1):** Closed for free by letting exceptions propagate to the unified handler.

### [D-6] Filament tab filters defined twice (string literals vs enum)
- **Category:** Technical Debt · **Severity:** Medium · **Status:** Open
- **Location:** `app/Filament/Resources/IncidentResource.php:305-316` (raw `'Completed'`, `'P4'`, `'Confirmed loss'`) vs `ListIncidents.php:211-232` (enum values).
- **Recommended Action (Phase 2):** IncidentResource uses enum values; single source.

### [D-7] `EXCLUDED_FROM_COUNTS` exclusion lambda ≥6×
- **Category:** Technical Debt · **Severity:** Medium · **Status:** Open
- **Location:** `app/Services/IncidentStatsService.php:17` (×4 in one method); `WeeklyReportExportController.php:57`; `WeeklyDataService.php`.
- **Recommended Action (Phase 2):** `scopeExcludedFromCounts` on the `Incident` model.

### [D-8] MTBF computation byte-identical blocks
- **Category:** Technical Debt · **Severity:** Medium · **Status:** Open
- **Location:** `ListIncidents.php:139-152` and `245-258` (identical); `METRIC_ELIGIBLE` referenced across 18 files.
- **Recommended Action (Phase 2):** Extract to an `Incident` method or `IncidentMetricsService`.

### [D-9] AI invokable skeleton duplicated ~40×, no base class
- **Category:** Technical Debt · **Severity:** Low · **Status:** Open
- **Location:** `Ai/TextEnhanceController.php:13-45`, `GeneratePostMortemController.php:11-33`, `SuggestLabelsController.php:13-61`, `GenerateWeeklySummaryController.php:13-63`, …; some `extends Controller`, most don't.
- **Recommended Action (Phase 1):** Base `Controller` gains `use ApiResponser;` so all inherit it; migration is then per-controller incremental.

### [D-10] Weekly totals summing copy-pasted ×3 callers
- **Category:** Technical Debt · **Severity:** Low · **Status:** Open
- **Location:** `WeeklyReportExportController.php:25-27`; `GenerateWeeklySummaryController.php:28-30`; `WeeklyReport.php:114-116`.
- **Recommended Action (Phase 2):** Move into `WeeklyDataService` (with D-4).

### [O-1] Fat controller: `ChatSendController` (366 lines)
- **Category:** Architecture · **Severity:** High · **Status:** Open
- **Location:** `app/Http/Controllers/Ai/ChatSendController.php` — slash-command parsing L41, conversation resolution L46-53, history/referenced-ID scan L63-78, web-search enrichment L80-113, persona loop L231-363.
- **Impact:** Violates CLAUDE.md ("complex business logic goes in Services, not controllers"). Hard to test, easy to regress.
- **Recommended Action (Phase 3):** Extract to a `ChatSendService`; controller = validate → call → `successResponse(DTO)`.

### [O-2] Fat controller: `ChatStreamController` (343 lines)
- **Category:** Architecture · **Severity:** High · **Status:** Open
- **Location:** `app/Http/Controllers/Ai/ChatStreamController.php` — 175-line `StreamedResponse` closure L166-334 (HTTP buffering, error mapping, `ChatMessage::create`, usage logging, memory archiving).
- **Recommended Action (Phase 3):** Move closure body into `SseStreamingService`/`ChatStreamService`; controller wires HTTP only.

### [O-3] `IncidentController::convertToMarkdown` (125 lines) in the controller
- **Category:** Architecture · **Severity:** High · **Status:** Open
- **Location:** `app/Http/Controllers/Api/IncidentController.php:476-601` (loops over statusUpdates/actionImprovements/investigationDocuments, date formatting, emoji icons). `IncidentFormatter` exists but is bypassed.
- **Recommended Action (Phase 3):** Move into `IncidentFormatter` (ties off D-2).

### [O-4] `IncidentController::index` builds 11 inline filter branches
- **Category:** Architecture · **Severity:** High · **Status:** Open
- **Location:** `app/Http/Controllers/Api/IncidentController.php:84-149` (overlaps D-1).
- **Recommended Action (Phase 2):** Collapses to one `IncidentFilterService::apply(...)` call.

### [O-5] Service-locator anti-pattern: 6 `app(Service::class)` calls
- **Category:** Architecture · **Severity:** Medium · **Status:** Open
- **Location:** `ChatStreamController.php:144,181,255,309,330`; `ChatSendController.php:81`; `ChatPersonaStreamController.php:312`; `WarRoomPollController.php:29`.
- **Impact:** Hides dependencies, defeats static analysis, complicates testing.
- **Recommended Action (Phase 3):** Constructor-inject them. (`ExternalToolExecutor.php:93` `app($class)` is a legit runtime plugin resolve — leave.)

### [O-6] Enums defined but no model casts; ~15 raw literals bypass them
- **Category:** Architecture · **Severity:** High · **Status:** Open
- **Location:** `app/Models/Incident.php:103-134` (no enum casts). Raw literals: `IncidentObserver.php:43,145` (`['P1','P2']`); `Notifications/NewCriticalIncident.php:44`, `AssignedAsPicNotification.php:35` (`'P1'`); `Filament/Widgets/RiskHeatMatrixWidget.php:147`; `Services/Ai/IncidentPlanningService.php:105,118,145` (`'Open'`); `Services/WarRoom/WarRoomToolExecutor.php:150-152` (`'pending'`/`'done'`); `Models/WarRoomSession.php:146`, `ChatPlanSubtask.php:53`, `AccessRequest.php:63`, `WarRoomMessage.php:57`; `IncidentController.php:564`; `AnalyzeTrendsController.php:71,82` (`'Incident'`).
- **Impact:** No type safety; typos in status strings fail silently; `Severity::P1` exists yet `'P1'` is hand-typed.
- **Recommended Action (Phase 4):** Add enum casts to `Incident`; purge the literals. Risk: casting changes string→enum reads — audit every `===`/`in_array`.

### [O-7] Zero service-layer interfaces/bindings
- **Category:** Architecture · **Severity:** Low · **Status:** Open
- **Location:** `app/Contracts/AgentToolInterface.php` is a legit plugin seam (user-supplied tool classes); zero first-party impls; no bindings in Providers.
- **Recommended Action:** Defer (YAGNI) — revisit when a service has 2+ implementations.

### [O-8] Untyped array payloads instead of DTOs
- **Category:** Architecture · **Severity:** Medium · **Status:** Open
- **Location:** `ChatSendController.php:202-228`; `WarRoomPollController.php:18-46`; `AnalyzeTrendsController.php:50-58`. (Contrast: `AiTextResult`, `AgenticChatResult`, `ApiAuditLogEntry` are good typed DTOs.)
- **Recommended Action (Phase 3):** Extracted services return the existing `*Result` DTOs (or add a `ChatResponseDTO`).

### [O-9] UI logic in the `Incident` model
- **Category:** Architecture · **Severity:** Low · **Status:** Open
- **Location:** `getMttrFormattedAttribute` (`Incident.php:173-211`), `MTBF_TAB_COLUMN_MAP` (`Incident.php:21-33`).
- **Recommended Action:** Move to a presenter/formatter (optional; defer).

### [O-10] Zero authorization Policies; 44 hand-rolled `can()` checks
- **Category:** Architecture · **Severity:** High · **Status:** Open
- **Location:** 44 `auth()->user()->can(...)` / `Auth::user()->can(...)` across 24 files. `IncidentResource.php:46-69` (5 inline in a row); `ListIncidents.php:181,196`; `WeeklyReportExportController.php:19`; `IncidentKanbanBoard.php:137`.
- **Impact:** No single source of truth for who-can-do-what; easy to miss a check on new resources.
- **Recommended Action (Phase 5):** Policy classes (extend `IncidentPolicy`/`ActionImprovementPolicy`); Filament `canView/...` and controller checks delegate.

### [S-1] Raw SQL with interpolated values (safe by whitelist, fragile)
- **Category:** Security · **Severity:** Medium · **Status:** Open
- **Location:** `app/Services/Analytics/AnalyticsQueryService.php:150,171,199,219,247,269,315` — `selectRaw("{$dimSql} as dim ...")`, `selectRaw("{$agg} as value")`. `$dimSql`/`$agg` come from whitelisted `match` branches (currently safe), but any new dimension/metric added without updating the whitelist becomes an injection vector.
- **Recommended Action (Phase 6):** Validate `$dim`/`$agg` against an enum/const set; bind via `selectRaw('... as dim', [...])` where feasible; add a test that a bogus dimension is rejected.

### [S-2] `Incident::generateNo` non-transactional retry-loop (race)
- **Category:** Security · **Severity:** Low · **Status:** Open
- **Location:** `app/Models/Incident.php:232-244`.
- **Recommended Action (Phase 6):** DB unique constraint + catch, or wrap in a transaction.

### [S-3] Untagged `Cache::remember` (violates CLAUDE.md)
- **Category:** Technical Debt · **Severity:** Low · **Status:** Open
- **Location:** `app/Http/Controllers/Api/IncidentController.php:180,212`.
- **Recommended Action (Phase 2):** `Cache::tags(['incidents','labels'])->remember(...)` per CLAUDE.md tag-based caching.

### [A-1] Dual status vocabulary in `status_updates.status` (from 2026-09 audit)
- **Category:** Architecture · **Severity:** Medium · **Status:** Open
- **Location:** Relation manager writes Open/Investigation/Monitoring/Resolved/Closed/Recovered; `IncidentKanbanBoard` writes `IncidentStatus` enum values into the same column.
- **Impact:** Any filter on `status_updates.status` silently misses half the vocabulary; `SendReport`'s status filter depends on this vocabulary and was left unfixed for the same reason.
- **Recommended Action:** Product decision on one vocabulary + data migration; then fix SendReport's filter to match. (Cache-flush and `incident_type` column fixes from the same audit already shipped.)

### [A-2] Three ways to compute MTBF display values
- **Category:** Technical Debt · **Severity:** Medium · **Status:** Open
- **Location:** stored `mtbf_*` columns (IncidentMetricsCalculator) vs `mtbf_display` on-the-fly formatting vs export-time computation in `Exports/Sheets/*`.
- **Impact:** Same number rendered differently per surface; the 2026-09 audit unified the recalculation pipeline but not the display paths.
- **Recommended Action:** One `MtbfFormatter`-style helper consumed by widget, model accessor, and export.

### [A-3] `PlanModeStreamingService::streamPlanResume` duplicates `streamPlanResponse` (~300 lines) + raw curl fallback
- **Category:** Technical Debt · **Severity:** Medium · **Status:** Open
- **Location:** `app/Services/Ai/PlanMode/PlanModeStreamingService.php`.
- **Recommended Action:** Large refactor without a test net — deferred deliberately; extract the shared phase pipeline when plan-mode tests exist.

### [A-4] `DetectSimilarController` has no cache or rate limit
- **Category:** Code Quality · **Severity:** Low · **Status:** Open
- **Location:** `app/Http/Controllers/Ai/DetectSimilarController.php`.
- **Impact:** Every hit runs the similarity pipeline (multiple AI calls).
- **Recommended Action:** Rate limit per user + short-TTL result cache keyed by incident id + content hash.

### [A-5] Recurrence candidate set intentionally includes Issues (kept behavior)
- **Category:** Code Quality · **Severity:** Low · **Status:** Won't Fix
- **Location:** `RecurrenceDetectionService::fetchCandidates` filters `classification IN (Incident, Issue)`.
- **Rationale:** Similarity retrieval ≠ metric counting; `aiCounts()` binds the counts, not retrieval. Finding similar *Issues* is a feature. Documented here so a future audit doesn't flag it.

---

## Summary Statistics

### By Category
| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Performance | 0 | 0 | 0 | 0 | 0 |
| Security | 0 | 1 | 1 | 2 | 4 |
| Architecture | 0 | 6 | 3 | 2 | 11 |
| Technical Debt | 0 | 3 | 7 | 2 | 12 |
| Code Quality | 0 | 1 | 5 | 3 | 9 |
| **Total** | **0** | **11** | **16** | **9** | **36** |

### By Status
| Status | Count |
|--------|-------|
| Open | 35 |
| In Progress | 0 |
| Resolved | 0 |
| Won't Fix | 1 |

---

## Review Cadence

- **Weekly Review:** High and Critical findings
- **Bi-Weekly Review:** Medium findings
- **Monthly Review:** Low findings and backlog

---

*Last Updated: 2026-09-14*

### [A-6] `php artisan serve` uses regular opcache despite `enable_cli=0` (2026-09-29)
`opcache.enable_cli=0` only affects plain CLI scripts. `artisan serve` runs the cli-server SAPI which honors `opcache.enable=1` — file edits appear stale until the serve process restarts. Dev-only (production uses nginx+PHP-FPM with its own reload), but it burned a full debug cycle masquerading as "cache problem". Rule: after editing PHP under `artisan serve`, restart the serve process before concluding anything about caching.

### [A-7] Export action surface (2026-09-29, resolved same day — kept as record)
Export logic briefly lived as a 362-line multi-class file + inline form/handler in `ListIncidents`. Resolved in bf40c08: one class per file under `Exports/` + `Exports/Sheets/`, form+filters extracted to `Filament/Actions/ExportActionSchema`, stats shared via `Statistics/IncidentStatsFooterData`. Recorded so future exports follow the split, not the monolith.

### Register data-quality claims audit — 2026-10-13 (source: VP TechOps gap analysis 2026-10-07)

The "Root cause — register design works against reliability" block in `detection-response-gap-2026-ytd-v2.pdf` §6 (p.16) was fact-checked against the code and the 38-record workbook (`root-cause-attribution-38-2026-ytd.xlsx`). Verdicts:

| Claim | Verdict | Evidence |
|-------|---------|----------|
| "No input validation" | **Partial** | Filament form hardened 2026-09-29 (`IncidentResource.php:167-196`: `maxDate(now())`, `afterOrEqual`, `numeric`+`minValue(0)`). BUT API requests (`StoreIncidentRequest.php:30-40`, `UpdateIncidentRequest.php`) accept future dates/negatives — no `maxDate`/`afterOrEqual`/`minValue`, `recovered_fund` unvalidated; both import paths (`Filament/Importers/IssuesImporter.php:26-29,98`, `Imports/IssuesImport.php:47-55`) allow future dates, importer forces `startOfDay()` = 00:00:00 |
| "Hence 15 negative MTTRs" (data error) | **Misleading** | Negative MTTR is the deliberate day-unit encoding: `Incident.php:453-456` `shouldCalculateMttrByDays()` (fund_status ∈ Confirmed loss/Potential recovery/Fully recovered/Non Tech Loss) → `mttr = -(days+1)` (`IncidentMetricsCalculator.php:63-69`). **Measured on prod export (All Cases, 85 rows, 2026-10-13): 35 negative MTTRs, 100% in the encoding set** (Confirmed loss 15 — the report's exact 15 — Non Tech Loss 12, Fully recovered 6, Potential recovery 2), zero negatives outside it. Date-entry errors would not segregate perfectly by fund status; the encoding does |
| "Placeholder times 00:00:00, 12:00:00 possible" | **True** (measured) | **Prod export: 36 of 85 rows (42%) have placeholder incident-date times — 17× 00:00:00, 19× 12:00:00** (12:00:00 is a clear "time unknown" convention). `DateTimePicker` keeps a time component with no unknown-time handling; importer forces 00:00:00 (`IssuesImporter.php:98`) |
| "Action items require manual transcription" | **Partial** | Manual entry is the main path, but `WarRoomService.php:1224-1252` drafts action improvements from an AI retrospective (user-triggered, `status: draft`). No extraction from uploaded investigation documents |
| "No RCA completeness enforcement before closure" | **True** (measured: 28 of 66 Completed rows have empty root cause — 42%, worse than the report's 6/38) | No gate anywhere: status Select (`IncidentResource.php:141-145`), Update Status action (`:568-593`), observer — only a `missing_root_cause` table *filter* (`:545-555`) |
| "No input validation" (data consequence) | **Contradicted by data** | Prod export: **0 future dates, 0 discovered<incident, 0 stop_bleeding<incident, 0 negative fund values** across 85 rows — current data has zero hard-rule violations; the API/import bypass (below) is latent, not visible in the register yet |
| "Does not flag overdue action items" | **Partial** | Daily 08:00 overdue emails/notifications exist (`SendActionImprovementReminders.php:16-58`) but only for rows with `reminder=true` AND status pending (per-row opt-in); NO overdue badge/column in the relation-manager UI (`ActionImprovementsRelationManager.php:57-71`) |
| "No way to record 'started X days before discovery'" | **True** | Only free-text `timeline` column (`2026_01_14_000001`); no structured estimated-start/start-unknown field in any migration |

**Open gaps (confirmed, unfixed):** RD-1 API+import validation bypass · RD-3 no in-UI overdue indicator (and email flagging is opt-in per row) · RD-5 day-granularity MTTR makes fund-loss timing unusable for detection/response metrics (the legitimate kernel of the report).

**Closed 2026-10-13 (PROJ-011 Waves 1–2):**
- **RD-2 → closed:** closure gate in `IncidentObserver::saving()` — a row cannot move INTO Completed (create or update) without RCA (root_cause, 'N/A' passes as deliberate override, or root_cause_category) and stop_bleeding_at. One guard covers edit form, `update_status`, API, imports. Pre-gate Completed rows stay editable.
- **RD-4 → closed:** `detection_delay_days` + `start_time_known` columns (`2026_10_13_000001`, prod migrate = owner-only guardrail) + `Incident::startTimeKnown()`/`effectiveDetectionLagHours()` — the placeholder-time problem has a structured escape hatch: book the date, record the estimated gap, flag whether the time is real. Governance KPIs consume it: placeholder rows yield no derived lag unless delay-days exist.
- RD-5 mitigated, not fixed: day-granularity MTTR stays (legacy encoding), but the detection-lag KPIs measure timing from `discovered_at`/delay-days instead of MTTR.
- RD-1 unchanged (API/import validation bypass remains latent — no imports in active use).

**KPI conflict:** `techops-pdr-action-plan-2026-v1.pdf` Panel 5 sets "Negative MTTR: green=0 / red=>0" — unachievable as written while negative = days encoding (it tracks Confirmed-loss count, not data quality). **Resolved 2026-10-13:** the Governance page implements the replacement KPI — "FL usable timing %" (share of Confirmed-loss rows with real timing), plus "FL Unknown Channel %" (PDR red >5%) once `detected_via` is backfilled.

---

## FD: DingTalk agent — deferred Wave-1 crash hardening (owner decision 2026-11-11)

The 2026-11-12 audit of `dingtalk-agent/` (BUG-029 in lesson-learned covers the
Wave-2 fixes actually shipped) also produced a **crash** list the owner chose to
DEFER. Recorded here so the next sweep starts from it, not from zero:

1. **Tool panic kills the bot** — `tools/registry.go` `Execute` has no recover;
   each tool runs on its own goroutine so no caller-side recover can catch it.
   Fix when picked up: named returns + `defer recover()` → error string. Covers
   fpdf internals + every future tool.
2. **Agent panic on the card path kills the bot** — the only `recover` in
   `stream.go` lives in `processPlain`; `processStreaming` (card path),
   `processFile` goroutine and `go b.fireWatch` have none.
3. **`memory.Store` marshals the live map after unlocking** — `SaveHistory` /
   `LearnProfile` snapshot aliasing; two concurrent turns → fatal concurrent-map
   iteration (unrecoverable; nothing to catch).
4. **Malformed PDF panics** — `ingest/extract.go` calls `NewReader`/`NumPage`
   outside ledongthuc/pdf's internal recover.
5. **`embedArrayOK` data race** — plain bool shared between kb-ingest and
   `search_knowledge` goroutines (`llm/client.go`).
6. **Semaphore leak + missing turn timeout** — `watches.go` `<-b.sem` skipped on
   the early-return path; chat turns run on bare `context.Background()`.
7. **File branch blocks the SDK handler goroutine**; `file.go` download uses
   `http.DefaultClient` (no timeout).
8. **`stripMention` byte slicing** — `ToLower` length-change + `0xA0` byte edge
   (fixed as part of Wave 2's rune-safe `util.Cut` work — re-check remaining
   raw-slice sites).
9. **kb nil-interface** — `stream.go` passes `b.llm` (typed-nil) where an
   interface is checked with `== nil`.
10. **Unbounded body reads** — `llm/client.go` `io.ReadAll` without limit on the
    two non-stream paths (stream path has 64 KiB).

**Consciously skipped in Wave 3:** the `DINGTALK_DATA_DIR=""` dead branches
(`config.go`, `stream.go`, `watch.go`) — the empty-dir branch is what the
DATA_DIR tests pin against; deleting it costs test coverage for zero runtime
gain. Revisit only if the env var is retired.

**Ship guardrail reminder:** stash@{0} holds PROJ-022 (OPA/guardrails, 2026-11-11)
— recover there before touching the reply-filter/guardrail stack again.

---

### Sync intake request can exceed any gateway timeout (2026-11-17)

**Date:** 2026-11-17
**Category:** Architecture
**Severity:** Medium
**Status:** Resolved (mitigated, then async shipped — 2026-11-17)

#### Description
`InvestigationFormIntake::extractFromSource()` runs the whole intake — Notion
read-all fan-out (up to ~30 sequential MCP calls, `NotionReadService::readFullPage`)
plus the Go-brain LLM extract (Go ctx 360s, PHP Http 420s) — synchronously inside
one Livewire POST. Nothing bounds the request except its work, so any gateway in
front can expire first: nginx `fastcgi_read_timeout 300` produced prod 504s while
PHP kept running. The 2026-11-17 read-all change (48k→96k cap, subtree drains,
child pages) pushed real wall time past 300s for the first time.

#### Mitigation applied
nginx `fastcgi_read_timeout` / `max_execution_time` raised 300→600 (`nginx.conf:30-31`),
above the documented ladder (Go ctx 360 < Http 420 < nginx 600); `connectTimeout(10)`
added to the Go call (`GoAgentBrain.php`, per the lesson-learned connect_timeout checklist).
If an edge proxy sits in front of prod nginx, its read timeout must be raised separately.

#### Status update (2026-11-17, later same day): RESOLVED — async shipped
The pasted prod 504 page proved the emitter is Dana's PaaS edge proxy
(`Server: opsproxy-1-1`, `Powered by Tengine/2.2.3`) — a proxy we don't own, so no
repo-side timeout can fix it. The recommended action was implemented the same day:

- **`app/Jobs/ExtractInvestigationFormSource.php`** — the slow half off the HTTP
  request: collect source (the component's `collectSource()` moved verbatim: paste
  cap 48000, upload conversion, Notion `readFullPage` with its ~30-call fan-out)
  → AI extract → merge into the shared draft. `$tries=1` (a retry would double AI
  spend; the user re-clicks), `$timeout=600` < worker `--timeout=660`.
- **Status lives on the request record** (`extraction_status`/`extraction_started_at`/
  `extraction_result`, migration `2026_11_17_000001`) — a public no-login page can't
  hold state in the session, and a reload mid-extraction resumes polling.
- **Component** dispatches in milliseconds and `pollExtraction()` applies the outcome
  on `wire:poll.3s`; a stale `running` (>15 min) is treated as failed (dead-worker
  guard). Page copy: "you can close this page — we'll email you when it's ready".
- **Ready-email on success only** (`InvestigationFormMailFactory::readyMail()`) —
  no token in it (plain tokens are never stored; rotating would kill a polling
  session), points back to the invitation link.
- **`InvestigationFormDraftMerge`** is the one-logic merge service (prefill +
  applyExtraction) shared by component and job — BUG-005/006/007 drift culture.
- **96k cap stays** (owner's read-ALL requirement; async moves the wall-time cost
  off the user).
- **`runVerify` stays synchronous**: its input is the small answers JSON — no Notion
  fan-out, no 96k source. Follow-up only if it ever trips opsproxy.
