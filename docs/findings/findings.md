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

---

## Summary Statistics

### By Category
| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Performance | 0 | 0 | 0 | 0 | 0 |
| Security | 0 | 1 | 1 | 2 | 4 |
| Architecture | 0 | 6 | 2 | 2 | 10 |
| Technical Debt | 0 | 3 | 5 | 2 | 10 |
| Code Quality | 0 | 1 | 5 | 1 | 7 |
| **Total** | **0** | **11** | **13** | **7** | **31** |

### By Status
| Status | Count |
|--------|-------|
| Open | 31 |
| In Progress | 0 |
| Resolved | 0 |
| Won't Fix | 0 |

---

## Review Cadence

- **Weekly Review:** High and Critical findings
- **Bi-Weekly Review:** Medium findings
- **Monthly Review:** Low findings and backlog

---

*Last Updated: 2026-06-29*
