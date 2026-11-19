# Lesson Learned

*This document captures root cause analysis (RCA) and prevention strategies for all bugs encountered in the Technical Risk Dashboard. Every bug MUST be documented here following the template below.*

---

## Template

```markdown
## [BUG-XXX] - Brief Bug Title

**Date:** YYYY-MM-DD
**Discovered By:** [Name]
**Severity:** [Critical / High / Medium / Low]
**Status:** [Open / In Progress / Resolved]

### Description
[Brief description of what happened]

### Affected Component
- [ ] Model
- [ ] Controller
- [ ] Filament Resource
- [ ] API Endpoint
- [ ] Database/Migration
- [ ] Frontend/CSS
- [ ] Queue/Job
- [ ] Other: _____

### Root Cause Analysis (5 Whys)
1. Why did the bug occur?
   - [Answer]

2. Why did that happen?
   - [Answer]

3. Why did that happen?
   - [Answer]

4. Why did that happen?
   - [Answer]

5. Why did that happen?
   - [Root cause identified]

### Impact
- [ ] User facing?
- [ ] Data loss/corruption?
- [ ] Performance degradation?
- [ ] Security vulnerability?
- [ ] Broken workflow?
- [ ] Other: _____

**Impact Assessment:** [Describe severity and scope]

### Prevention Strategy

#### 1. Process Changes
- [ ] Update SOP (specify section)
- [ ] Add validation (specify where)
- [ ] Add monitoring/alerting
- [ ] Update code review checklist
- [ ] Other: _____

#### 2. Code Changes
- [ ] Add unit test: `path/to/test.php`
- [ ] Add integration test: `path/to/test.php`
- [ ] Add E2E test: `path/to/test.php`
- [ ] Refactor code: `path/to/file.php`
- [ ] Other: _____

#### 3. Documentation Updates
- [ ] Update CLAUDE.md
- [ ] Add code comments
- [ ] Update API documentation
- [ ] Other: _____

### Action Items
- [ ] Action item 1 - [Assigned To] - [Due Date]
- [ ] Action item 2 - [Assigned To] - [Due Date]
- [ ] Action item 3 - [Assigned To] - [Due Date]

### Verification
- [ ] Test case added: `path/to/test.php`
- [ ] Code reviewed by: [Name]
- [ ] Deployment verified on: YYYY-MM-DD
- [ ] No regression in existing tests

### References
- Related Issue/PR: #[number]
- Related Commit: [hash]
- Related Project: [PROJ-XXX]

---

**Reviewed By:** [Name]
**Review Date:** YYYY-MM-DD
```

---

## Bugs

### [BUG-001] - Issues Export Tabs Showing Wrong Data (Severity instead of Incident Type)

**Date:** 2026-02-02
**Discovered By:** User Report
**Severity:** High
**Status:** Resolved

### Description
The "Issues - MTTR" and "Issues - MTBF" export tabs were showing severity values (P1, P2, P3, etc.) in the "Type" column instead of the actual Incident Type name (e.g., "Bug", "Feature Request", etc.).

### Affected Component
- [x] Filament Resource (Export functionality)
- [ ] Model
- [ ] Controller
- [ ] API Endpoint
- [ ] Database/Migration
- [ ] Frontend/CSS
- [ ] Queue/Job
- [ ] Other: _____

### Root Cause Analysis (5 Whys)
1. Why did the bug occur?
   - The `IssuesMetricSheetExport` class was displaying `$incident->severity` in the "Type" column.

2. Why did that happen?
   - In commit `429726d` (2026-01-20), the `incident_type_id` foreign key relationship was added to the Incident model, and the UI was updated to show `incidentType.name` as a separate column.

3. Why was the export class not updated?
   - The `IssuesMetricSheetExport` class was not included in the commit that modified the `IssueResource` to use the new relationship.

4. Why wasn't it caught during review?
   - No code review process was followed for the commit, and the export functionality was not tested after the schema change.

5. Root cause identified:
   - **Incomplete migration impact analysis** - When adding the `incident_type_id` relationship, all code that displayed or exported incident type data should have been identified and updated together.

### Impact
- [x] User facing?
- [ ] Data loss/corruption?
- [x] Broken workflow?
- [ ] Performance degradation?
- [ ] Security vulnerability?
- [ ] Other: _____

**Impact Assessment:**
- Users exporting Issues data received incorrect information in the "Type" column
- Data was misleading - showed severity instead of incident type
- Affected decision-making based on exported reports
- Severity: High - Data integrity issue in reporting feature

### Prevention Strategy

#### 1. Process Changes
- [x] Update SOP - Added PM-centralized workflow with approval gates
- [ ] Add schema change impact analysis checklist
- [ ] Require testing of export functionality after any model/schema changes
- [ ] Update code review checklist to include "affected files" search

#### 2. Code Changes
- [x] Fixed `IssuesMetricSheetExport::map()` to show `incidentType.name`
- [x] Added eager loading: `->with(['incidentType'])`
- [x] Added null checking: `$incident->incidentType?->name ?? 'N/A'`
- [ ] Add unit test for IssuesMetricSheetExport
- [ ] Add integration test for multi-sheet export

#### 3. Documentation Updates
- [x] Update CLAUDE.md - SOP already created with this workflow
- [x] Document this bug in lesson-learned.md
- [ ] Add export testing checklist to SOP

### Action Items
- [x] Fix the Type column to show incidentType.name - Done
- [x] Add eager loading for performance - Done
- [x] Add null checking for safety - Done
- [x] Run Laravel Pint for code formatting - Done
- [ ] Add unit test for export functionality - QA Team
- [ ] Test export with real data - User

### Verification
- [x] Code fixed: `app/Exports/Sheets/IssuesMetricSheetExport.php:52`
- [x] Laravel Pint applied
- [ ] Test case added: `tests/Feature/IssuesMetricSheetExportTest.php` - Pending
- [x] Code reviewed: Self-review completed
- [ ] Deployment verified on: YYYY-MM-DD - Pending
- [ ] No regression in existing tests - To be verified

### References
- Related Issue: User report via chat
- Related Commit: `429726d` (Original schema change)
- Related Files:
  - `app/Exports/Sheets/IssuesMetricSheetExport.php`
  - `app/Filament/Resources/IssueResource.php`
  - `database/migrations/2026_01_20_120000_add_incident_type_id_and_extend_severity_to_incidents_table.php`

---

**Reviewed By:** Claude (PM Agent)
**Review Date:** 2026-02-02

---

### [BUG-002] - Issues Export Tabs Empty (Using Wrong Query Source)

**Date:** 2026-02-02
**Discovered By:** User Report
**Severity:** High
**Status:** Resolved

### Description
The "Issues - MTTR", "Issues - MTBF", and "All Issues" export tabs were returning empty or incorrect results because they were using the filtered query from IncidentResource instead of a fresh query for Issues only.

### Affected Component
- [x] Filament Resource (Export functionality)
- [ ] Model
- [ ] Controller
- [ ] API Endpoint
- [ ] Database/Migration
- [ ] Frontend/CSS
- [ ] Queue/Job
- [ ] Other: _____

### Root Cause Analysis (5 Whys)
1. Why did the bug occur?
   - The Issues sheets in `MultiSheetIncidentsExport` were cloning `$this->query` which comes from IncidentResource.

2. Why was this a problem?
   - IncidentResource's query includes all filters/tabs the user has selected (e.g., "Completed Cases", date ranges, severity filters).

3. Why did this cause empty Issues sheets?
   - When users filtered IncidentResource (e.g., by severity or status), those filters were applied to Issues sheets too.
   - For example: If user filtered to show "Completed Cases", the Issues sheets would try to find Issues within already-filtered Completed records, resulting in empty data.

4. Why was the export designed this way?
   - The export was added to IncidentResource, not IssueResource. The Issues sheets were an afterthought, added as filtered subsets of the main query.

5. Root cause identified:
   - **Incorrect query source for separate data domain** - Issues and Incidents are separate domains (classification = 'Issue' vs 'Incident'). Issues sheets should use a fresh query `Incident::where('classification', 'Issue')` instead of cloning the filtered IncidentResource query.

### Impact
- [x] User facing?
- [ ] Data loss/corruption?
- [x] Broken workflow?
- [ ] Performance degradation?
- [ ] Security vulnerability?
- [ ] Other: _____

**Impact Assessment:**
- Issues export tabs (All Issues, Issues - MTTR, Issues - MTBF) returned empty or incomplete data
- Users could not reliably export Issues data from IncidentResource
- Workaround: Users had to go to IssueResource to export Issues separately
- Severity: High - Core export functionality not working for Issues

### Prevention Strategy

#### 1. Process Changes
- [x] Document this pattern in SOP - When exporting separate domains, use fresh queries
- [ ] Add code review item: "Check if export filters affect different data domains"
- [ ] Require testing multi-sheet exports with various filter combinations

#### 2. Code Changes
- [x] Fixed Issues sheets to use `Incident::where('classification', 'Issue')` instead of `$this->query->clone()`
- [ ] Add test for export with active filters
- [ ] Add test for export with different selected tabs

#### 3. Documentation Updates
- [ ] Update CLAUDE.md with query pattern for multi-domain exports
- [x] Document this bug in lesson-learned.md

### Action Items
- [x] Fix Issues sheets to use fresh query - Done
- [x] Run Laravel Pint - Done
- [ ] Add integration test for multi-sheet export with filters - QA Team
- [ ] Test export with various filter combinations - User

### Verification
- [x] Code fixed: `app/Exports/MultiSheetIncidentsExport.php:56-69`
- [x] Laravel Pint applied
- [ ] Test case added: `tests/Feature/MultiSheetExportTest.php` - Pending
- [x] Code reviewed: Self-review completed
- [ ] Deployment verified on: YYYY-MM-DD - Pending
- [ ] No regression in existing tests - To be verified

### References
- Related Issue: User report via chat
- Related Bug: BUG-001 (Related to same export functionality)
- Related Files:
  - `app/Exports/MultiSheetIncidentsExport.php`
  - `app/Filament/Resources/IncidentResource/Pages/ListIncidents.php`
  - `app/Filament/Resources/IssueResource.php`

---

**Reviewed By:** Claude (PM Agent)
**Review Date:** 2026-02-02

### [BUG-003] - Excel Export Crash: Severity Enum Not Convertible to String

**Date:** 2026-07-03
**Discovered By:** User Report (500 error)
**Severity:** High
**Status:** Resolved

### Description
Exporting incidents to Excel (single-sheet CSV/XLSX and the "export all tabs" multi-sheet XLSX) crashed with HTTP 500: "Object of class App\Enums\Severity could not be converted to string". The crash originated in PhpSpreadsheet's `DefaultValueBinder` when binding the severity cell.

### Affected Component
- [x] Filament Resource (Export functionality)
- [ ] Model
- [ ] Controller
- [ ] API Endpoint
- [ ] Database/Migration
- [ ] Frontend/CSS
- [ ] Queue/Job
- [ ] Other: _____

### Root Cause Analysis (5 Whys)
1. Why did the bug occur?
   - The export `map()` pushed `$incident->severity` (a `Severity` enum instance) directly into a spreadsheet cell; PhpSpreadsheet cannot stringify enum objects.

2. Why is `$incident->severity` an enum instance now?
   - Commit `c43fc3c` introduced `App\Casts\EnumCast` and cast `severity`, `incident_status`, `fund_status`, and `classification` on the Incident model to backed enums.

3. Why wasn't the export updated when the cast changed?
   - The commit's message stated it fixed "all enum-string consumers", but missed the Maatwebsite/Excel export `map()` methods (`IncidentTableExport`, `SingleIncidentSheetExport`).

4. Why wasn't it caught?
   - No test exercised the export `map()` with an enum-cast column, so the regression shipped unverified.

5. Root cause identified:
   - **Cast change with an incomplete consumer sweep + no export test coverage.** When a model attribute's type changes (string → enum), every downstream consumer — including export serializers — must be enumerated and tested, not only the ones that fail loudly.

### Impact
- [x] User facing?
- [ ] Data loss/corruption?
- [x] Broken workflow?
- [ ] Performance degradation?
- [ ] Security vulnerability?
- [ ] Other: _____

**Impact Assessment:**
- Users could not export incidents to Excel/CSV at all (hard 500 on both export paths).
- Severity: High — core reporting/export feature fully blocked.

### Prevention Strategy

#### 1. Process Changes
- [x] When changing a model cast, sweep all consumers (Filament, Blade, API, observers, exports/serializers) — not just the ones that break loudly.
- [x] Add export `map()` coverage to the regression-test set.

#### 2. Code Changes
- [x] Coerce `BackedEnum` → scalar value in `IncidentTableExport::map()` and `SingleIncidentSheetExport::map()`.
- [x] Added `tests/Feature/Exports/IncidentTableExportEnumTest.php` (asserts the severity cell is a string, not the enum instance).

#### 3. Documentation Updates
- [x] Document this bug in lesson-learned.md.

### Action Items
- [x] Fix export map() enum coercion - Done
- [x] Add regression test - Done
- [x] Run Laravel Pint - Done
- [ ] Test export end-to-end on the running app (both paths) - User

### Verification
- [x] Code fixed: `app/Exports/Sheets/SingleIncidentSheetExport.php`, `app/Exports/IncidentTableExport.php`
- [x] Test case added: `tests/Feature/Exports/IncidentTableExportEnumTest.php` (2 passing)
- [x] Laravel Pint applied
- [x] Code reviewed: Self-review completed
- [ ] Deployment verified on: YYYY-MM-DD - Pending

### References
- Related Commit: `c43fc3c` (EnumCast regression)
- Related Files:
  - `app/Exports/IncidentTableExport.php`
  - `app/Exports/Sheets/SingleIncidentSheetExport.php`
  - `app/Models/Incident.php` (casts)
  - `app/Casts/EnumCast.php`

---

**Reviewed By:** Claude
**Review Date:** 2026-07-03

---

### [BUG-004] - WarRoom "AI Retrospective" stuck forever in pre-analysis

**Date:** 2026-07-16
**Discovered By:** User Report (prod session `20260622_IS_8125`)
**Severity:** High
**Status:** Resolved

### Description
A WarRoom (AI Retrospective) session pinned at `current_round = 0` showing "Pre-analysis is
running…" indefinitely. It never advanced to round 1 and never recovered on its own, requiring a
manual unstick.

### Affected Component
- [x] Queue/Job (`RunPreAnalysis`)
- [x] Service (`WarRoomService::markStuckMessages`, `dispatchRound`)
- [ ] Model
- [ ] Controller
- [ ] Filament Resource
- [ ] API Endpoint
- [ ] Database/Migration
- [ ] Frontend/CSS
- [ ] Other: _____

### Root Cause Analysis (5 Whys)
1. Why did the session get stuck at `current_round = 0`?
   - The `RunPreAnalysis` job never completed and never dispatched round 1, so no round-1 messages
     were created and the UI's pre-analysis state stayed true forever.
2. Why didn't `RunPreAnalysis` complete or dispatch round 1 on failure?
   - Its AI HTTP call blocked without throwing. The catch and `failed()` — both of which dispatch
     round 1 as graceful degradation — only run *after* an exception propagates. No exception ⇒ no
     round-1 dispatch.
3. Why did the HTTP call block without throwing?
   - It used `Http::timeout(90)` (a total-time cap) with no `connect_timeout` and no low-speed
     guard. A provider that accepts the socket but stalls/trickles keeps the connection "active"
     and defeats `CURLOPT_TIMEOUT`, so the call hangs.
4. Why didn't the poll-driven self-heal recover it?
   - `markStuckMessages()` only reaps `WarRoomMessage` rows; pre-analysis creates **none**. So the
     healer was structurally blind to a hung pre-analysis phase.
5. Why was the pre-analysis HTTP call the weak link in the first place?
   - Unlike the agents/moderator (which stream via `WarRoomStreamingService` and get incremental
     progress), pre-analysis was a hand-rolled non-streaming call that also POSTed to the bare
     `getBaseUrl()` instead of `buildUrl()` (`/chat/completions`) like every other AI call.

### Impact
- [x] User facing?
- [ ] Data loss/corruption?
- [ ] Performance degradation?
- [ ] Security vulnerability?
- [x] Broken workflow?
- [ ] Other: _____

**Impact Assessment:** Affected retrospectives silently hung, blocking incident analysis. No data
loss; manual unstick via tinker was required.

### Prevention Strategy

#### 1. Process Changes
- [ ] Add monitoring/alerting for sessions `running` > N minutes
- [x] Update code review checklist: non-streaming AI calls must set `connect_timeout` + use the shared `buildUrl()`/`buildHeaders()`
- [ ] Other: _____

#### 2. Code Changes
- [x] Add unit test: `tests/Unit/Services/WarRoom/WarRoomServiceTest.php` (dispatchRound idempotency; markStuck pre-analysis recovery)
- [x] Add unit test: `tests/Unit/Jobs/WarRoom/RunPreAnalysisJobTest.php` (posts to `/chat/completions`; failure dispatches round 1)
- [x] Refactor code: `app/Services/WarRoom/WarRoomService.php` (idempotent `dispatchRound`; pre-analysis branch in `markStuckMessages`)
- [x] Refactor code: `app/Jobs/WarRoom/RunPreAnalysis.php` (`connect_timeout` + shared endpoint + config-driven timeouts)
- [x] Config: `config/ai.php` (`pre_analysis_timeout`, `pre_analysis_connect_timeout`)
- [ ] Other: _____

#### 3. Documentation Updates
- [ ] Update CLAUDE.md
- [x] Add code comments (root-cause notes in `RunPreAnalysis` and `markStuckMessages`)
- [ ] Update API documentation
- [ ] Other: _____

### Action Items
- [x] Make `dispatchRound()` idempotent per round - Done
- [x] Add pre-analysis self-heal to `markStuckMessages()` - Done
- [x] Harden `RunPreAnalysis` HTTP call - Done
- [x] Tests added + passing - Done
- [ ] Verify on prod + unstick stranded `8125` session (runbook in plan) - User

### Verification
- [x] Code fixed: `app/Services/WarRoom/WarRoomService.php`, `app/Jobs/WarRoom/RunPreAnalysis.php`, `config/ai.php`
- [x] Test cases added: `tests/Unit/Services/WarRoom/WarRoomServiceTest.php` (+3), `tests/Unit/Jobs/WarRoom/RunPreAnalysisJobTest.php` (+2) — 27/27 passing
- [x] Laravel Pint applied
- [x] Code reviewed: Self-review completed
- [ ] Deployment verified on: YYYY-MM-DD - Pending

### References
- Related Files:
  - `app/Services/WarRoom/WarRoomService.php` (`dispatchRound`, `markStuckMessages`)
  - `app/Jobs/WarRoom/RunPreAnalysis.php`
  - `config/ai.php` (`war_room.pre_analysis_*`)
  - `app/Http/Controllers/Ai/WarRoomPollController.php` (calls `markStuckMessages`)
  - `resources/views/filament/pages/war-room.blade.php` (UI pre-analysis state)

---

**Reviewed By:** Claude
**Review Date:** 2026-07-16

---

### [BUG-005] - AI Chat and WarRoom tools reported inconsistent numbers for the same question

**Date:** 2026-08-19
**Discovered By:** Code audit (AI accuracy review)
**Severity:** High
**Status:** Resolved

### Description
The AI answered from two different data paths that applied different counting rules:
`ChatContextService` (injected Quick Stats / smart search) filtered `classification=Incident`
plus `excludedFromCounts()`, while `WarRoomToolExecutor` tools (used by both WarRoom agents and
the chat tool loop) applied neither — and cut date ranges at midnight (`<= date_to`) while the
context path used end-of-day. The same question ("how many P1s this month?") could return two
different numbers in one answer. Tool output also omitted the `id` column, making the mandated
`[no — title](/admin/incidents/{id})` citation format impossible for tool-grounded answers,
printed raw numbers with no currency/unit (`2500000`, MTTR sign semantics undocumented to the
model), and 5-minute stat caches were never invalidated on incident save.

### Root Cause
No single source of truth for "which incidents count": every call site re-derived the filter
inline, so the two paths drifted. Related: month/quarter name parsing assumed the current year
("January 2025" silently resolved to January of this year), month names leaked into topic
detection (returning zero results), smart-search queries had no row cap, and
`ConversationMemoryService::getRelevantSummaries()` ignored its `$currentQuery` argument.

### Fix
- `Incident::aiCounts()` scope (classification + excludedFromCounts) — single source of truth;
  applied to all 4 tool queries and all ~20 inline repetitions in `ChatContextService`.
- End-of-day date bounds, `id:` in all tool list outputs, `MarkdownFormatter::formatMoney` and
  MTTR units in `WarRoomToolExecutor`.
- `IncidentObserver` now calls `ChatContextService::clearDataCache()` on created/updated
  (all `chat_*` 300s cache keys).
- Smart search/topic queries capped at 50 rows with an honest "showing 50 of N" label;
  empty retrieval now injects an explicit NO RETRIEVAL RESULTS instruction.
- Year-aware date parsing (`Q2 2024`, `January 2025`) and month names excluded from topic
  detection; memory summaries now keyword-ranked against the current query.

### Lesson Learned
When two code paths must answer with the same numbers, define the filter ONCE (model scope) —
copy-pasted query preconditions drift silently. Also: any value a prompt forces the model to
format (citations, currency, units) must be derivable from the data actually given to it.

### Prevention Checklist
- [x] New query precondition lives in one scope, not repeated inline
- [x] Tests written (WarRoomToolExecutorTest +8, IncidentObserverCacheTest, ChatContextServiceTest +5, ConversationMemoryServiceTest +3)
- [ ] Add a golden-set eval (`php artisan ai:eval`) once behavior settles — deferred deliberately

---

**Reviewed By:** Claude
**Review Date:** 2026-08-19

---

### [BUG-006] - Analytics page averaged MTTR over Non Incident / G rows

**Date:** 2026-09-11
**Discovered By:** User report (suspected non-incidents in MTBF/MTTR)
**Severity:** Medium
**Status:** Resolved

### Description
On the Analytics page, `Avg MTTR` / `Avg MTTR (days)` charts included rows with severity
`Non Incident` and `G`. Every other surface (dashboard widgets, incident table footer,
Reporting, SendReport, exports, AI chat, WarRoom) already filtered
`Severity::METRIC_ELIGIBLE` (P1–P4, X1–X4); `AnalyticsQueryService` was the only path that
averaged the `mttr` column unfiltered. The job `CalculateIncidentMetrics` stores `mttr`
for ALL rows by design (per-row display + exports), so ineligible rows carried real values
that leaked into the averages.

Second bug found in the same file: `queryJsonArrayDimension()` never selected the
`severity` column, so its eligibility check compared `null` — `avg_mtbf` grouped by
business_category / root_cause_category / responsible_team was always 0.

### Root Cause
The eligibility filter was re-derived inline per query path (~6 call sites across the
service) instead of being applied once where the query is built. The five dimension
paths (time, enum, relation, pivot, JSON-array) had drifted: MTBF paths filtered,
MTTR paths didn't, and the JSON path filtered on a column it never loaded.

### Fix
- Single choke point in `buildSingleDataset()`: when metric is `avg_mttr`,
  `avg_mttr_days`, or `avg_mtbf`, scope the base query with
  `whereIn('severity', Severity::METRIC_ELIGIBLE)` — covers all dimension paths.
- Removed the now-redundant inline `whereIn` calls in the derived-metric branches.
- Dropped the dead per-row severity check in `queryJsonArrayDimension()` (rows are
  pre-scoped; only the `incident_date` null-check is needed).
- Cache key bumped `analytics_` → `analytics_v2_` (15-min caches held the old scope).

### Lesson Learned
Same class as BUG-005: query preconditions copied into every branch drift one branch at a
time. Apply the invariant once at query construction, not at each aggregate site. Extra
wrinkle: a filter referencing a column the query never selects fails silently (null
comparison), so "the check exists" in code review is not "the check runs".

### Prevention Checklist
- [x] Filter lives in one place (buildSingleDataset), not per-branch
- [x] Tests written (AnalyticsQueryServiceTest, 3 cases incl. JSON-dimension MTBF)
- [x] Full suite: 36 pre-existing failures (auth/notification/export) unchanged

---

**Reviewed By:** Claude
**Review Date:** 2026-09-11

---

### [BUG-007] - Dashboard fund-loss cards: dead status filter, Issues counted, stale after money edits

**Date:** 2026-09-11
**Discovered By:** User report ("fund loss numbers don't update")
**Severity:** Medium
**Status:** Resolved

### Description
Three defects in the dashboard money cards, all reported as one symptom — "numbers don't update":

1. **Potential Fund Loss never dropped when cases completed.** The widget filtered
   `whereNotIn('incident_status', ['Closed', 'Resolved', 'Recovered'])` — none of those values
   exist in `IncidentStatus` (`Open / In progress / Finalization / Completed`). The filter was a
   no-op, so Completed cases stayed in the "open cases" sum forever.
2. **Fund Loss card counted Issues.** No `classification` filter, while every other fund-loss
   surface (AiTrendInsights, AnalyzeTrendsController, IncidentStatsOverview) is Incident-only.
   Rule confirmed by user: Incident + Completed.
3. **Cards went stale for up to 5 minutes after editing money fields.** `IncidentObserver`
   dispatched `CalculateIncidentMetrics` (which bumps `dashboard_cache_version`, busting the
   widget cache key) only on status/severity/type/fund_status/recovered_fund/classification/date
   changes — `fund_loss` and `potential_fund_loss` were missing from the trigger list.

### Root Cause
Same drift class as BUG-005/006: query preconditions and cache-invalidation triggers were
re-derived per surface instead of defined once. The dead enum filter is the nastier variant of
BUG-006's dead null-comparison: a `whereNotIn` listing values that don't exist in the enum
matches everything, silently, forever — no error, no log, just a wrong number.

### Fix
- `PotentialFundLoss.php`: `whereNotIn('incident_status', [IncidentStatus::Completed->value])`.
- `DashboardStatsOverview.php`: Fund Loss query + `classification = Incident`.
- `IncidentObserver.php`: `fund_loss` / `potential_fund_loss` added to `$needsCategoryRecalculation`.
- `DashboardFundLossTest` (3 tests): Completed excluded from potential-loss card, Issues excluded
  from fund-loss card, money edit bumps `dashboard_cache_version`.
- Action Improvements tab/button verified correct post-BUG-004/005 fix (`5c712a0`) — no code change.

### Lesson Learned
A `whereIn`/`whereNotIn` on an enum column is only as good as its literal list — there is no
runtime error when the values don't exist, the filter just matches everything (or nothing).
Write these filters from the enum (`IncidentStatus::Completed->value`), never from memory, and
when a cache is keyed on a version counter, every field that feeds the cached number must be in
the observer's bump triggers.

### Prevention Checklist
- [x] Enum filters reference enum cases, not hand-typed strings
- [x] Tests written (DashboardFundLossTest, 3 cases)
- [x] Full suite compared with/without fix (stashed): 40 → 38 problems, failing families identical (auth/notification/export, pre-existing flaky)

---

**Reviewed By:** Claude
**Review Date:** 2026-09-11

---

### [BUG-008] - Metrics job: ghost columns + observer TypeError killed every adjacent recalculation

**Date:** 2026-09-11
**Discovered By:** Full-codebase audit (code audit)
**Severity:** High
**Status:** Resolved

### Description
Five defects in the `CalculateIncidentMetrics` job / `IncidentObserver` pair, all on the
"adjacent recalculation" path (run when an incident has an eligible successor in the same
classification/year, or when classification changes):

1. `recalculateCategoryMtbfFor()` wrote `mtbf_ongoing` and `mtbf_tech` — columns that exist
   in no migration and have zero consumers. `saveQuietly()` threw `QueryException` (unknown
   column), the job died across all 3 retries, and `flushIncidentCache()` — which runs after
   the adjacent block — never executed, so `dashboard_cache_version` never bumped.
2. The same method's category closures read `$inc->incident_status`, but the `get()` never
   selected that column — every row compared as null (BUG-006's dead-filter class).
3. The adjacent category list lacked `non_fund_loss`, which the main path writes — the two
   lists inside one file had drifted.
4. `IncidentObserver` passed `getOriginal('classification')` (an enum instance via EnumCast)
   into the job's `?string $previousClassification` — `TypeError` on every classification
   change, meaning the classification-change path was unreachable end-to-end.
5. `updateAdjacentForClassification()` never recalculated the old-group successor's base
   `mtbf`/`mtbf_all` at all — masked by defect 4 crashing first.

In production (redis queue) the QueryException/TypeError surfaced only in `failed_jobs`;
the incident's own row metrics were already saved, so the failure was silent.

### Root Cause
The adjacent/classification-change path had no test coverage and had drifted from the main
path inside the same file — the fourth instance of the repo's core drift class
(BUG-005/006/007): the same invariant re-derived per path instead of shared.

### Fix
- One category definition: `calculateCategoryMtbf(Incident $target)` parameterized and used
  by the main path and both adjacent call sites; the drifted `recalculateCategoryMtbfFor()`
  deleted.
- Base MTBF block extracted to `recalculateBaseMtbfFor()` (shared by `calculateMetrics()` and
  the classification-change path, which now also rebuilds base `mtbf` and `mtbf_all`).
- Observer passes `getOriginal('classification')?->value`.
- Tests: `tests/Feature/CalculateIncidentMetricsAdjacentTest.php` (insert-between and
  classification-change, both failed before the fix).

### Lesson Learned
Two implementations of one metric in the same file drift exactly like two implementations
in two files — parameterize the shared function instead of writing a "recalculate" twin.
And an enum-cast model's `getOriginal()` returns the enum instance, not the string: typed
job constructors crash on it.

### Prevention Checklist
- [x] Single parameterized implementation for main + adjacent paths
- [x] Tests written (CalculateIncidentMetricsAdjacentTest, 2 cases)
- [x] Related suites green (Observers 11, Analytics 3, DashboardFundLoss 3)

---

### [BUG-009] - Async hang class: dead catch, missing re-entry guard, queue wait counted as runtime

**Date:** 2026-09-11
**Discovered By:** Full-codebase audit (code audit)
**Severity:** High
**Status:** Resolved

### Description
Four defects in the WarRoom/PlanMode async coordination layer, each capable of hanging a
session or plan permanently (same user-facing class as BUG-004):

1. `WarRoomService::onAgentCompleted` caught
   `\Illuminate\Contracts\Cache\LockTimeout` — a class that does not exist (the contract is
   `LockTimeoutException`). The catch could never match, so a lock timeout escaped the
   service; round completion was skipped and the session hung in `running`.
2. `PlanModeService::onSubtaskCompleted` used `$lock->block(5)` with **no** catch at all.
   On timeout the job failed; the retry hit the subtask's completed guard and returned
   early, so `AnalyzePlanGaps`/`SynthesizePlanResults` were never dispatched — the plan
   never synthesized.
3. `WarRoomService::processAgent` had no re-entry guard. The self-heal re-dispatch
   (pending >120s) races the original job: both stream, content is appended twice, tokens
   double-spent. Conversely a queue-level retry (worker died mid-run, status stuck at
   `running`) must NOT be debounced — the guard needs to distinguish the two via
   `$this->attempts() > 1`.
4. The stuck-running reaper measured "execution time" from `created_at`, which includes
   queue wait — an agent that sat in a busy queue past the timeout was failed the moment
   it started. Fixed with a `running_since` timestamp stamped in `markRunning()`.

### Root Cause
Error handling written per-call-site instead of at the state machine, plus no timing
column to distinguish "waiting" from "executing". The dead catch is the PHP-level twin of
BUG-004's missing `connect_timeout`: an invariant assumed present but never verified by
a runnable check.

### Fix
- `LockTimeout` → `LockTimeoutException` with a warning log (round completion is
  idempotent under the lock — the holder completes the check).
- `onSubtaskCompleted` catches `LockTimeoutException` and returns; the lock holder runs
  the completion check.
- `processAgent(WarRoomSession, string, int, bool $isQueueRetry = false)` skips when the
  message is `completed`, or past `pending` unless this is a queue retry; the job passes
  `$this->attempts() > 1`.
- Migration adds nullable `running_since` to `war_room_messages`; `markRunning()` stamps
  it, `markCompleted()`/`markFailed()` clear it; the reaper measures from it with a
  `created_at` fallback for legacy rows.

### Lesson Learned
A `catch` block whose class doesn't exist is a silent no-op — the code *looks* guarded.
For any cache-lock `block()`, the caught contract is
`Illuminate\Contracts\Cache\LockTimeoutException`; verify with a test that forces the
timeout path. And any idempotency guard on a retried job must account for *which kind* of
duplicate is arriving (healer re-dispatch vs queue retry) — `attempts()` is the signal.

### Prevention Checklist
- [x] Re-entry guard + queue-retry signal tested (skips running/completed, runs retry)
- [x] Reaper measures `running_since`, not `created_at` (both directions tested)
- [x] Related suites green (WarRoomService 28, WarRoom/PlanMode family 156)

---

### [BUG-010] - Chart/report surfaces drifted from count rules + caches ignoring the freshness version

**Date:** 2026-09-11
**Discovered By:** Full-codebase audit (code audit)
**Severity:** High
**Status:** Resolved

### Description
The BUG-005/007 drift class, next generation — dashboard chart and scheduled-report
surfaces that stopped matching the card totals:

1. Incidents-by-Type / by-PIC / by-Label and Fund-Loss-Trend charts used
   `excludedFromCounts()` but no classification filter → **Issues appeared in dashboard
   charts** while the stat cards next to them excluded Issues. `IncidentStatsService`
   fund_loss sum had the same one-query drift (total/open/severity filtered
   classification, fund_loss didn't) → WarRoom quick stats overstated losses.
2. Eight widget caches (monthly, severity, type, pic, label, fund-loss trend, risk heat,
   action improvements) had no `dashboard_cache_version` in the key → after an incident
   edit, stat cards refreshed instantly while the charts beside them served stale data
   for up to 15 minutes — a self-contradicting dashboard.
3. `mtbf_{tab}_{year}` table-column cache (1-hour TTL) was never invalidated by
   `flushIncidentCache()` → the MTBF column showed pre-edit numbers long after every
   other metric updated.
4. `SendReport` filtered `whereIn('incident_type_id', …)` but the saved filter holds
   IncidentType enum values (the Reporting page correctly uses `incident_type`) → the
   scheduled report's type filter silently matched nothing. Its `end_date` was also
   parsed at midnight, excluding the final day (the exact BUG-005 boundary bug, missed
   here).
5. Chat/trends members of the same class: `getCompareContext` averaged `AVG(mttr)` with
   no METRIC_ELIGIBLE / non-negative guard while `getQuickStats` (same feature, same
   number) filtered both → day-encoded negatives dragged the compare average; the
   executive summary's top-3 sorted by a hand-typed `FIELD('P1'..'P4')` so X1–X4 ranked
   0 and crowded out P1s; `AnalyzeTrendsController` counted with classification-only
   while chat used `aiCounts()` → two totals for one question, plus the same midnight
   `end_date` boundary and a `cached` flag computed after `remember()` (always true).

### Root Cause
Each new surface re-derived the count/filter rules instead of calling the shared scope
(`aiCounts()`), and cache keys were copy-pasted without the version component the stat
cards already had.

### Fix
Charts and the stats service now use `aiCounts()` / explicit classification; every widget
cache key embeds `dashboard_cache_version`; the mtbf column key embeds the version (bump
invalidates for free, no pattern enumeration needed); SendReport filters
`incident_type` with `endOfDay()`.

### Lesson Learned
A cache key is part of the freshness contract: if one surface keys on
`dashboard_cache_version`, every surface serving the same data must too — otherwise the
dashboard contradicts itself after every write. And a filter is only as good as the
column it queries: `incident_type` (enum string) vs `incident_type_id` (FK) — a whereIn
on the wrong one is a silent match-nothing.

### Prevention Checklist
- [x] Chart queries via shared `aiCounts()` scope, not hand-rolled filters
- [x] Version component in every incident-derived widget cache key
- [x] getBaseStats fund_loss exclusion tested (IncidentStatsServiceTest)

---

### [BUG-011] - Plan mode shipped incident text to subtask agents unfenced (prompt injection)

**Date:** 2026-09-11
**Discovered By:** Full-codebase audit (code audit)
**Severity:** High
**Status:** Resolved

### Description
The chat path wrapped all retrieved incident data in `fenceUntrusted()` (opening marker +
"DATA ONLY / never obey instructions" guard + closing marker). Plan mode bypassed it twice:

1. `ChatContextService::buildTargetedContext` — the required-context builder used by
   plan subtasks — returned its context raw, with no fence at all.
2. `PlanPromptBuilder` (subtask + research prompts) extracted the data-context block
   from `buildSystemPrompt` by substr-ing from the `--- CURRENT DATA CONTEXT ---`
   header, which sits INSIDE the fence — cutting the opening marker and guard while
   leaving the closing marker dangling (half-fenced).

User-entered incident text (titles, summaries, root causes) reached subtask agents as
plain prompt text — any "ignore previous instructions" planted in an incident field
would be executed as instructions.

### Root Cause
The fence was applied per-builder instead of at the single point every untrusted blob
passes through; the substr extraction then assumed the header was the block boundary
when the fence had been wrapped around it.

### Fix
`buildTargetedContext` returns `fenceUntrusted($context)` like `buildDataContext`;
both PlanPromptBuilder extractions anchor on `<<<UNTRUSTED_CONTEXT>>>` so the complete
fence (guard included) survives the cut.

### Lesson Learned
When a security boundary is a textual delimiter, never extract "the useful part" by
anchoring on content inside the boundary — anchor on the boundary itself, and assert
in a test that the opening marker precedes every piece of untrusted text and the
closing marker follows it.

### Prevention Checklist
- [x] Fencing test covers both paths (targeted + system-prompt extraction)
- [x] Opening-before-content / closing-after-content order asserted, not just presence

---

### [BUG-012] - Access-control batch: prefix-matching token scopes, ungated page, IDOR, dead raw-SQL feature

**Date:** 2026-09-11
**Discovered By:** Full-codebase audit (code audit)
**Severity:** High
**Status:** Resolved

### Description
Five access-control defects, each invisible in normal UI flows but reachable by
URL or token crafting:

1. `ApiEndpoint::matchesRoute` authorized by `str_contains($path, $pattern)` —
   a token scoped to `incidents` also authorized `v1/incidents-by-no` (the
   pattern `v1/incidents` is a substring of the sibling route). Subpath
   matching (`v1/incidents/5`) only worked *through* the same loose check, so
   the fix had to replace it with exact-segment-or-child matching, not delete it.
2. `Reporting` page: `shouldRegisterNavigation(false)` hid it from the menu but
   it had no `canAccess()` — any authenticated user could open `/admin/reporting`
   by URL and run the full incident export.
3. `AnalyticsPage::loadTemplate` used unscoped `ChartConfiguration::find($id)`
   while the list and delete paths scoped by `user_id` — guessable-ID IDOR that
   loaded another user's saved chart config into the form.
4. `IssueResource` never registered its `view` page although `EditIssue` links
   a ViewAction to it (404), and had no query scoping — users restricted by
   year (UserAuditLogSetting) saw all years in Issues while Incidents was
   restricted.
5. Dead widget feature (`StatWidget`/`ChartWidget`/`DashboardWidgetResource`,
   disabled via `canViewAny() === false`) carried a public `?string $query`
   Livewire property executed as raw SQL after a SELECT-prefix check — one
   flipped boolean away from arbitrary-DB-read via deserialization. Deleted
   (7 files); table + migrations kept for data.

### Root Cause
Access checks were written per-page/per-callback instead of following one
pattern; the scoping that existed (year access on IncidentResource) was
inlined rather than shared, so the sibling resource never got it. Substring
route matching is the URL twin of BUG-007's dead enum filter: a check that
silently over-matches instead of silently under-matching.

### Fix
- `matchesRoute`: strip `api/` from both sides, then
  `$path === $pattern || str_starts_with($path, $pattern.'/')` — exact segment
  or true subpath only.
- `Reporting::canAccess()` gated on `view incidents` (house pattern).
- `loadTemplate` scoped `where('user_id', auth()->id())`.
- `scopeApplyUserYearAccess` extracted to `Incident`, reused by
  `IncidentResource::applyAccessControl` and new `IssueResource::getEloquentQuery()`
  (+ `view` page registered).
- Audit claim that the 3 AI-config resources were ungated was **disproven**
  (Filament `canAccess()` defaults to `canViewAny()`, which all three define
  with `manage incidents`) — verified against vendor source before acting.

### Lesson Learned
Two rules: (1) A route authorizer must match path *segments*, never substrings —
`incidents-by-no` is not under `/incidents`. (2) When a permission/scoping rule
is inlined in one surface, its siblings are already drifting — extract the scope
the moment a second consumer appears. And before deleting "ungated" surfaces,
check what the framework's defaults actually gate (a false audit claim would
have added useless `canAccess` noise to 3 already-gated resources).

### Prevention Checklist
- [x] Sibling-prefix negative assertions in MiddlewareTest (incidents ≠ incidents-by-no)
- [x] Year-access scope shared + tested (admin/guest/restricted-user paths)
- [x] `php artisan route:list` boot-check after feature deletion

---

### [BUG-018] Incident footer "Total Cases" counted all years for admins

**Date:** 2026-09-29
**Severity:** P1 (wrong numbers shown to the primary user)
**Component:** `Filament/Statistics/IncidentStatsFooterData`
**Status:** Fixed (commit f8160eb)

### Root Cause
The footer's default query applied `applyUserYearAccess()`, which is a **no-op for admins** — so the admin footer counted every year while the table under it defaults to QuickPeriod "This Year" (58 vs 38 live). Two different concepts were conflated: per-user year *access control* vs the table's active *period filter*.

### Fix
`baseQuery()` now mirrors the active `quick_period` table filter (week/month/year/all, default year). Footer == table by construction.

### Prevention
A summary that sits under a filtered table must read the table's filter state, not assume an access-control scope equals a display filter. Parity check: footer numbers must equal the export of the same filtered set (38 = 38 verified).

**2026-09-29 follow-up:** the parity fix initially mirrored `quick_period` only — the From/Until `custom_date_range` filter was still ignored (summary ≠ table for custom ranges, the same drift one filter at a time). Closed by scoping the footer through the shared appliers (`QuickPeriodFilter::applyPeriod()`/`applyDateRange()`, stacked exactly like the table's chain); parity now asserted per stat key in `IncidentStatsFooterFilterParityTest`.

## [BUG-013] - Usage-log blind spots, unprotected label path, plan-mode research announced but never run

**Date:** 2026-09-14
**Discovered By:** Full-codebase audit (code audit)
**Severity:** Medium
**Status:** Resolved

### Description
Four consistency defects around AI-call accounting and the plan-mode
research decision:

1. `AiTextService::summarizeDocument` logged usage only on the
   HTTP-response paths; the `ConnectionException`/`\Exception` catches
   returned without recording — failed document summaries (the calls that
   cost the most latency) were invisible on the usage dashboard.
2. `ProactiveIncidentAnalysisJob`'s catch block recorded an AI usage
   FAILURE whenever `ProactiveInsight::create` threw (a DB error) — a
   non-AI failure miscategorized as an AI-call failure, inflating the
   failure rate the dashboard shows.
3. `AiTextService::suggestLabels` is a duplicate AI HTTP path that never
   joined the CircuitBreaker contract `enhance()` follows: label-call
   failures never counted toward the breaker, and an open breaker never
   gated it.
4. `PlanModeService::analyzeGaps` acted on the FILTERED research decision
   (`flag && gaps>0 && coverage<threshold`) but cached the model's RAW
   flag; both streamer loops re-derived from the cache without the
   coverage precondition → the UI announced "starting research on N
   topics" for research the `AnalyzePlanGaps` job never dispatched
   (it went straight to synthesis).

### Root Cause
Same drift class as BUG-005/007: a decision (or its recording) re-derived
per surface from an upstream artifact, instead of the artifact carrying
the finished decision. The cache entry and the returned `PlanResult` are
two projections of ONE decision — caching the unfiltered projection let
every consumer reconstruct a different verdict. Item 2 inverted the usual
direction: the audit claimed the success path was unlogged, but the callee
(`callAiForJson` → `logFeatureUsage`) already records every outcome — the
caller's extra failure log was the actual defect.

### Fix
- `summarizeDocument`: `$result` assigned in both catches, `logUsage` moved
  into `finally` (guarded against a still-null result).
- `ProactiveIncidentAnalysisJob`: miscategorized catch-log and dead
  `$startTime`/`$responseTimeMs` removed (callee owns usage logging;
  `sendJsonRequest` never throws transport errors).
- `suggestLabels`: breaker gate before the call + `recordSuccess`/
  `recordFailure` after every outcome — identical contract to `enhance()`.
- `analyzeGaps`: cache the filtered decision
  (`$parsed['research_needed'] = $researchNeeded`) — one write site; both
  streamer loops became consistent without touching them.

### Lesson Learned
Two rules: (1) When a value is cached for consumers to act on, cache the
POST-CONDITION decision, not raw model output — otherwise every consumer
re-implements the filter and drifts. (2) An audit claim is a hypothesis:
verify which layer already owns the invariant before "fixing" it — here
the fix for "success path not logged" was deleting the caller's log, not
adding one.

### Prevention Checklist
- [x] PlanGapAnalysisTest: cached flag must equal the filtered decision
      (RED-verified via stash before the fix)

---

## [BUG-011] Table stats footer rendered NULL — page hook not executed on loadTable path

**Date:** 2026-09-29
**Severity:** P1
**Component:** `IncidentResource/Pages/ListIncidents` + `IncidentResource::table()`
**Status:** Fixed (commit f6779e2)

### Root Cause
`getTableContentFooter()` page-hook is not executed on the Livewire `loadTable` render path used by Filament 3.3 table pages. Server HTML for the initial render included the footer, but every subsequent table interaction (filter/sort/page) re-renders without calling the hook — footer silently disappeared.

### Fix
Declarative config: `->contentFooter()` on the resource `table()`. Stats extracted to `App\Filament\Statistics\IncidentStatsFooterData` (reused by exports — single formula source).

### Prevention
Prefer declarative resource-level config over page hooks for anything that must survive table re-renders. Verified by asserting the footer text appears inside `<tfoot>` of the `loadTable` HTML response.

---

## [BUG-012] DateTimePicker `maxValue()` does not exist — wrong API throws 500

**Date:** 2026-09-29
**Severity:** P2
**Component:** `IncidentResource` form
**Status:** Fixed (commit 45abd9b follow-up)

### Root Cause
`maxValue()` exists for numeric/text fields; `DateTimePicker` exposes `maxDate()`. Using the wrong one throws `BadMethodCallException` at render time — caught immediately in live QA smoke (500 + laravel.log).

### Prevention
When adding date constraints, check `vendor/filament/forms/src/Components/DateTimePicker.php` for the real API. Numeric-style helpers (`minValue`/`maxValue`) do not apply to date pickers (`minDate`/`maxDate` do).

---

## [BUG-013] Native Excel charts blank in Numbers/QuickLook — writer omits value caches

**Date:** 2026-09-29
**Severity:** P2
**Component:** `Exports/Sheets/ExecutiveCalcSheet` charts
**Status:** Fixed for real (commit f8160eb — 6d783c4 documented the pass but shipped no code; charts were being patched by hand off-repo)

### Root Cause
PhpSpreadsheet chart writer emits series references (`<c:f>`) without cached values (`numCache`/`strCache`). Excel recalculates on open; Numbers/QuickLook do not — charts render empty.

### Fix
`App\Exports\Concerns\ChartCacheInjector` — post-write pass over the XLSX zip: parse chart XML, resolve referenced sheet ranges (shared strings included), inject `<c:numCache>`/`<c:strCache>` with `<c:pt>` values. Wired into the executive download path (store → inject → download). E2E verified 2026-09-29: downloaded artifact carries 65 cache points across all 4 charts.

### Prevention
Any exported chart must ship with value caches. Verify by unzipping the artifact and asserting `<c:v>` points exist in `xl/charts/chartN.xml` before handing the file to a non-Excel viewer.

**2026-09-29 round 2 (real Excel repair prompt):** opening the executive export in Microsoft Excel showed "We found a problem with some content… recover?". Root cause was in the injector itself: the replace-branch regex's closing backreference was `</c:\3>` while group 3 already captures the `c:` prefix — it demanded literal `</c:c:numCache>`, never matched, and the filled cache was **appended** beside the writer's empty one. Two `<c:numCache>` elements in one `<c:numRef>` violates the OOXML schema (CT_NumRef allows exactly one); PhpSpreadsheet's reader and Numbers tolerate the duplicate, which is exactly why the original reopen-verification passed while real Excel refused. Fixed with the one-character `</\3>`; the dead `FillsChartCaches` trait (its AfterSheet guard `$event->getConcernable() === $this` can never pass for a `WithMultipleSheets` export — the concernable is the sheet, not the parent) was deleted so exactly one cache mechanism remains. Regression test `tests/Feature/Exports/ChartCacheInjectorTest.php` asserts the structural invariant (cache count == ref count per chart) on the real store→inject sequence. Lesson: a backreference that re-adds what the capture already includes silently disables the branch it guards — and lenient readers make schema violations invisible until the strictest consumer (real Excel) opens the file; assert structural cardinality, not just well-formedness.

---

## [BUG-014] Incident form accepted invalid data silently (negatives, reversed timeline, future dates)

**Date:** 2026-09-29
**Severity:** P1
**Component:** `IncidentResource` form validation
**Status:** Fixed (commit 45abd9b)

### Root Cause
Validation covered required/numeric/unique but not domain sanity. Live HTTP-submit matrix proved: fund fields stored `-5000`, `discovered_at`/`stop_bleeding_at` before `incident_date` stored, `incident_date` in 2027 (typo year) stored.

### Fix
`minValue(0)` on the three fund fields; `maxDate(now())` on `incident_date` + `entry_date_tech_risk`; `afterOrEqual('incident_date')` on `discovered_at` + `stop_bleeding_at`. Re-tested live: all invalid inputs blocked with server-side messages; valid control still saves.

### Prevention
Form QA must submit an adversarial matrix (letters in numeric, negatives, out-of-order dates, far-future dates, duplicates) — not just the happy path. Required/numeric/unique passing does not mean the data is sane.
=======
### [BUG-014] - PHPUnit inside the Docker container silently used live MySQL: RefreshDatabase wiped the database (env override never applied)

**Date:** 2026-09-21
**Discovered By:** PM session (workflows + file-reading task)
**Severity:** Critical
**Status:** Resolved

### Description
Every `docker compose exec app php vendor/bin/phpunit` run reported `Configuration: /var/www/html/phpunit.xml` yet used the **live MySQL `laravel` DB** and `SESSION_DRIVER=file`. RefreshDatabase's `migrate:fresh` dropped every live table; all users, incidents, agents, and demo data were wiped. The same suite on the host was correctly isolated (sqlite `:memory:`, array sessions). Symptom band in the container: every session-bound POST test 419'd, API tests 404'd — ~91 failures vs the real ~25.

### Root Cause
PHPUnit's `<env>` directive only writes `putenv()`/`$_ENV` — **never `$_SERVER`** — and `force="true"` does not change that. The container exports `DB_CONNECTION`, `DB_DATABASE`, `SESSION_DRIVER`, … as real env vars (present in all three of getenv/`$_ENV`/`$_SERVER`), and phpdotenv's default adapter order reads **`$_SERVER` first**. So the container's real values won over phpunit's `<env>` overrides regardless of force; host runs had no real vars to lose to.

### Fix
phpunit.xml now carries `<server … force="true">` twins for every `<env … force="true">` entry (DB, session, cache, queue, mail, telemetry). Verified inside the container with a config probe (`session=array db=sqlite app=testing`) and by the suite count returning to the known failure band.

### Prevention Checklist
- [x] phpunit.xml: every `<env>` that must win in-container has a `<server>` twin with `force="true"`
- [x] Memory note rewritten: container runs are safe now; old "isolation appears functional" conclusion was host-only and wrong for the container
- [x] Recovery documented: `db:seed --force` (admin@/password) + manual re-creation of demo user/data; original live rows were NOT recoverable

---

### [BUG-017] - Auditable trait without the Auditable contract: workflow pages 500 under fpm, pass in tests

**Date:** 2026-09-21
**Discovered By:** User (live 500 on /admin/ai-workflows)
**Severity:** High
**Status:** Resolved

### Description
`GET /admin/ai-workflows` threw `AuditableObserver::retrieved(): Argument #1 ($model) must be of type OwenIt\Auditing\Contracts\Auditable, App\Models\AiWorkflow given`. `AiWorkflow`, `AiWorkflowStep`, and `AiAgentFile` used the `Auditable` **trait** without `implements Auditable`. The observer attaches for any trait-user (no contract check upstream) and type-hints the contract, so the first `retrieved` event under fpm exploded.

### Root Cause
Trait/contract pairing rule: the trait brings behavior, the interface is what the observer's type-hints require — one without the other compiles fine and only fails at runtime on the web. Why every test passed: `bootAuditable()` attaches the observer only when `isAuditingEnabled()` — console processes (phpunit) additionally require `audit.console`, hardcoded `false` in `config/audit.php:197`. So the observer never attaches in tests and the broken pairing was invisible until a real web request.

### Fix
3 models got the `AiAgent` pattern (`Contracts\Auditable` import + `implements Auditable` + FQCN trait use). Regression test attaches `AuditableObserver` explicitly (`AiWorkflow::observe(...)`) before hitting the index route over HTTP — RED-verified to reproduce the exact production TypeError, because the config knob alone is unreliable (model boot happens once per phpunit process, possibly before the test runs).

### Prevention Checklist
- [x] New audited model = trait AND `implements Auditable` (copy `AiAgent.php`)
- [x] Regression test attaches the observer explicitly instead of trusting config/boot order
- [ ] (optional future) static check: scan `app/Models` for `Auditable` trait without the contract

---

### [BUG-020] - Production served a dev-built bundle: every page tried wss://localhost:8081

**Date:** 2026-09-29
**Discovered By:** User (console errors on dashboard/incident pages)
**Severity:** Medium
**Status:** Resolved

### Description
Console on https://techrisk.paas.dana.id: `WebSocket connection to 'wss://localhost:8081/app/local-key' failed` from `app-C6DTV4al.js`. Production serves assets from the git-committed `public/build` (bind-mounted repo, `/.gitignore` line for `/public/build` deliberately commented out) — and that bundle was Vite-built on a dev machine against the **development** `.env`, so `VITE_REVERB_HOST=localhost`, `VITE_REVERB_PORT=8081`, `VITE_REVERB_APP_KEY=local-key` were baked into the minified Echo config. Every production visitor's browser tried to open a WebSocket to its own localhost.

### Root Cause
`VITE_*` values are compile-time: whatever `.env` is present during `npm run build` is permanently embedded in the committed asset. Committing built assets couples the artifact to the machine that built it — the dev/prod env split existed only server-side (.env is not committed), so nothing flagged the mismatch. The failing WS also left `modal.js` `showModal` InvalidStateError noise in its wake (degraded realtime state), which disappears with it.

### Fix
`resources/js/bootstrap.js` now constructs `window.Echo` only when the baked `wsHost` matches `window.location.hostname` (localhost/127.0.0.1 treated as equivalent). A stale dev-built bundle served from any other origin makes zero connection attempts; dev realtime is unchanged. Assets rebuilt and committed (`app-Guw3Ten2.js`). Enabling realtime in production later = build with `VITE_REVERB_HOST=<public host>`, `VITE_REVERB_SCHEME=https`, `VITE_REVERB_PORT=443` and route `/app/*` (WebSocket upgrade) at the ingress to the `reverb` container (published on host port 8081).

### Prevention Checklist
- [x] Echo init is origin-guarded — any future cross-env build is harmless by construction
- [x] `.env.example` documents the production `VITE_REVERB_*` values
- [ ] (optional future) stop committing `public/build`; build in the server deploy step instead

---

### [BUG-021] - strict_types + MySQL DECIMAL aggregates: string into number_format/round killed dashboard & incidents pages in prod

**Date:** 2026-09-29
**Discovered By:** User (prod 500 on /admin/incidents)
**Severity:** High
**Status:** Resolved

### Description
Prod threw `TypeError: number_format(): Argument #1 ($num) must be of type int|float, string given` (`PotentialFundLoss.php:50`, value `'16072236.00'`) plus the same class at the incidents table footer (`round(avg())`). f8160eb's strict_types sweep fixed this fallout in 2 files (`DashboardStatsOverview`, `ProactiveIncidentAnalysisJob`) but missed 6 more — the repo's recurring incomplete-sweep class (BUG-001/005 pattern).

### Root Cause
MySQL returns DECIMAL columns and their aggregates (`SUM`/`AVG` over decimal) as **strings** — mysqlnd has no native decimal type. Under `declare(strict_types=1)` a numeric string flowing into `number_format()`/`round()`/`abs()` throws TypeError (in weak mode it silently coerced for years). Arithmetic operators (`*`, `/`, `+`) still coerce — only *function calls with typed numeric params* throw, which defines the sweep boundary. Why tests never caught it: the suite runs SQLite, whose aggregates return floats.

### Fix
`(float)`/`(int)` casts at every strict-file call site fed by an aggregate: `PotentialFundLoss`, `IncidentStatsOverview`, `AiUsageStatsOverview` (tokens), `IncidentStatsFooterData` (also makes its returned stats contract numeric), `ListIncidents` export stats, `SingleIncidentSheetExport`. Kanban/`ExecutiveCalcSheet`/`IncidentTableExport` were already casting (reference pattern).

### Prevention Checklist
- [x] Regression test feeding the prod-log string values through a stubbed Builder (`IncidentStatsFooterStringAggregateTest`) — mock-based because SQLite aggregates return floats; RED-verified against the exact TypeError pre-fix
- [x] Rule: any `sum()/avg()/average()` over a decimal column feeding `number_format/round/abs` in a strict file gets a cast at the producer
- [x] **Round 2 (same day):** prod still 500ed on the dashboard — `abs('-10.4444')` at `DashboardStatsOverview:103`, plus 8 more sites round 1 missed (`AiUsageStatsOverview` avg, `AiTrendInsights` avg/sum, `AnalyzeTrendsController` avg/sum, `ChatContextService` avg + raw-SQL `AVG()` consumer, `AiBudgetAlertService` token sum). Why round 1 missed them: it trusted f8160eb's *partial* fix of `DashboardStatsOverview` instead of grepping, and fixed token sums while missing the sibling avg. Fixed with producer casts; `GroupedIncidentsExport`/`ExecutiveCalcSheet` Collection-avg sites cast too for pattern uniformity.
- [x] Structural guard: `tests/Unit/StrictTypesNumericAggregateLintTest.php` — tokenizes every strict-typed `app/` file and fails on any statement combining `round/abs/number_format/floor/ceil` with `->avg/->average/->sum` and no `(float)/(int)` cast. Known blind spots (documented in the test): producer/consumer split across statements, and one cast in a multi-entry array literal masking siblings — producer-side casting covers both.

---

### [BUG-022] - Export audit: Group-By severity/PIC/type 500 + executive chart defects

**Date:** 2026-09-29
**Discovered By:** User (500 on Group-By severity export) + owner-directed full export audit
**Severity:** High
**Status:** Resolved

### Description
Four defects across the export presets, found by exercising every preset's sheet
construction live (all 4 presets × all 6 Group-By dimensions):

1. **Group-By × severity / pic / incident_type = HTTP 500.** The row-filter
   closure in `GroupedIncidentsExport::sheets()` was `use ($isJson, $value)`
   but read `$column` — an undefined variable. Tinker prints a WARNING; the web
   handler converts it to `ErrorException` → 500. Even where tolerated, every
   comparison evaluated false → zero group sheets (only Summary). JSON
   dimensions returned before that line, which is why business-category
   exports kept working.
2. **PIC grouping resolved ids by name** (`array_search(label, names)`) — two
   PICs with the same name collapsed onto one id: both sheets held the first
   user's rows, the second user's incidents vanished. (PhpSpreadsheet
   auto-dedupes the duplicate *titles*, so the symptom was silent wrong data.)
3. **Executive Calc severity pie counted zero for every severity** — and the
   "Open" KPI counted completed cases too. `EnumCast::get` returns backed-enum
   instances, and PHP 8 enum-vs-string comparison is always false, so
   `Collection::where('severity', 'P1')` matched nothing while
   `where('incident_status', '!=', 'Completed')` matched everything.
4. **Severity scope wrong (owner rule confirmed):** the pie iterated all 10
   `Severity` cases with a 9-slot chart range. Correct scope is
   `METRIC_ELIGIBLE` (P1–P4, X1–X4) for BOTH the executive pie and Group-By
   severity sheets — G / Non Incident excluded (2026-09-17 counts rule).
   Charts also anchored at row 12, floating over the month table that ends at
   row 13 — moved to B16/J16/B34/J34.

### Root Cause
The Group-By rewrite added `$column` two lines below the closure's `use`
without re-importing it — the exact BUG-005 drift class wearing a new coat:
the invariant (closure captures every variable it reads) is invisible to
static tooling and to tinker (warning ≠ fatal there). The enum-blind
Collection filters are the Collection twin of BUG-003's map() crash: EnumCast
changes the attribute type, and every consumer must go through `?->value`.

### Prevention Checklist
- [x] Every non-JSON Group-By dimension covered by tests (severity / pic /
      incident_type; pic case uses two same-name users)
- [x] Enum-field Collection comparisons go through `?->value ?? raw` — never
      `where('enum_col', 'string')` (enum == string is always false in PHP 8)
- [x] Chart ranges derive from the data block they describe (8 eligible
      severities → C2:C9), chart anchors asserted ≥ 2 rows below the data
- [x] Artifact-level verification: all 4 presets × 6 dimensions generated,
      reopened with PhpSpreadsheet — group sheets non-empty, 4 charts with
      populated value caches (BUG-013 invariant held: 61 cache points)

---

### [BUG-023] - Export workbook audit: stale static MTBF sequence + unreconciled Issues-MTBF sheet + partial prod deploy masquerading as formula bugs

**Date:** 2026-09-30
**Discovered By:** Owner (downloaded `incidents-all-tabs-2026-09-30 (1).xlsx`, manual column averages ≠ bottom summaries)
**Severity:** High
**Status:** Resolved

### Description
Cell-by-cell forensics on the owner's actual downloaded workbook (every tab,
MTBF + MTTR, manual average vs bottom summary) found three distinct problems
stacked on top of each other, only one of which was a formula bug:

1. **Prod ran a mid-development code snapshot.** The file's MTBF column
   rendered the NEW `-` convention while every bottom still used the OLD
   span/(n−1) formula (All Cases bottom 3.708 = 267/72 while its own column
   meant 4.525). No committed commit produces that mix — the deployed worker
   had half the fix loaded. `clear cache.sh` cannot fix this: it clears
   Laravel caches, not deployed code, OPcache, or FPM worker memory. Fix =
   redeploy + restart PHP-FPM/app containers.
2. **Static `$mtbfCache` froze the year's sequence in long-lived FPM
   workers.** All Cases showed 14 MID-YEAR dashes (only first-of-year should
   dash), growing 13→14 across two downloads an hour apart: incidents
   created after the worker's first export fell out of the cached sequence —
   cell rendered `-` and the row vanished from column AND bottom. All three
   export sheet classes (`SingleIncidentSheetExport`,
   `IncidentTableExport`, `IssuesMetricSheetExport`) had it.
3. **`IssuesMetricSheetExport` never received the 2026-09-30 MTBF
   reconciliation** the other sheets got: first-of-year still anchored
   `dayOfYear`, missing ids fell back to `0`, bottom used span/(count−1).

Minor: a tab whose MTTR rows are all day-based (negative, e.g. Fund Loss)
wrote `0.00` as Avg MTTR — no data, now renders `-`.

### Root Cause
`private static array $mtbfCache` was a per-request micro-optimization
written as `static`, which in PHP-FPM means "lives as long as the worker
process". Same-key cache + data created after first fill = silently wrong
output with no error anywhere. The Issues sheet miss is BUG-005's drift class
again: a rule change reached the sheets named in the report, not every sheet
with the same shape. And the mixed old/new rendering shows a deploy
half-applied — two bugs that only reconcile as "stale runtime".

### Prevention Checklist
- [x] Sequence/derivation caches in export classes are per-instance
      (`private array`), never `static` — regression test exports twice with
      a row created in between and asserts the new row sequences
- [x] Bottom-summary rule is uniform across ALL sheets incl. Issues-MTBF:
      Avg MTBF = mean of the displayed column; first-of-year renders `-`
- [x] When a downloaded artifact contradicts the committed code, diff the
      artifact against `git log` versions FIRST — a mix of old and new
      rendering means stale runtime, not a formula bug; redeploy + restart
      FPM before chasing ghosts
- [x] Widget audit on owner request: `DashboardStatsOverview` MTBF tiles and
      `MttrMtbfTrendChart` use span/(count−1) over the eligible scope —
      canonical, untouched

---

### [BUG-024] - Label tagging recalculated nothing: Outlier labels + two unversioned caches + three dormant prod bugs found by the Outlier feature tests

**Date:** 2026-10-01
**Discovered By:** Outlier feature work (TDD — every one of these surfaced as a RED-phase test error)
**Severity:** High
**Status:** Resolved

### Description
Implementing "tag a Label `Outlier` → exclude from MTBF/MTTR, keep in counts"
exposed that **no label write ever recalculated anything**, and three more
dormant bugs that the new tests tripped over on their way to RED:

1. **Label attach/detach fired no recalc and no cache bump.** Filament saves
   labels via pivot sync — no Incident model event, so the observer never saw
   it and `dashboard_cache_version` stayed flat. Metrics only refreshed when
   some *other* field was later edited. Fix: custom pivot `IncidentLabel`
   (`->using()` on both relation sides — pivot events only fire through
   relations declaring the class) dispatches `CalculateIncidentMetrics` for
   Outlier tags.
2. **Two 15-minute caches were not version-keyed**: `mttr_mtbf_trend_v4_*`
   and `analytics_v2_*` ignored `dashboard_cache_version`, so even with the
   pivot fix they would serve stale trend/chart data until TTL. Both now
   carry the version in the key payload (→ `v5` / `v3`).
3. **`app:send-report` fatals on main**: `SendReport.php` called the
   **private** `Reporting::getColumns()` — every scheduled report died with
   BadMethodCallException before writing the workbook. (→ public
   `getColumnsFlattened()`, identical output.)
4. **Scheduled-report metrics were silently 0/null in prod**: the command's
   Collection `whereIn('severity', Severity::METRIC_ELIGIBLE)` matched 0 rows —
   `METRIC_ELIGIBLE` holds strings, enum-cast attributes are enum instances
   (the exact BUG-022 trap already documented). `avg_mttr` null, `avg_mtbf` 0.
5. **Reporting page 500s once incidents render**: `reporting.blade.php`
   `echo`'d a `BackedEnum` (`severity` is a default column) — PHP cannot
   stringify enums. Nobody had rendered the incidents table with data since
   the enum casts landed.

### Root Causes
- Metrics triggers were enumerated per write-path (observer fields) instead
  of per data-dependency: the label↔metrics dependency existed in the data
  model but not in the trigger list.
- Cache keys built from inputs only, not from the freshness signal the rest
  of the app already uses (`dashboard_cache_version`).
- `private` method called cross-class compiled fine until runtime; the
  scheduled command had no test that ever reached line 97.
- The Collection-vs-SQL asymmetry (enum casts exist only after hydration)
  keeps producing the same bug shape (BUG-022, now twice).

### Prevention
- [x] Pivot events are now part of the metrics trigger surface — documented
      in the CLAUDE.md dependency map row for `IncidentLabel`
- [x] Every timed cache that serves metric-derived data must embed
      `dashboard_cache_version` in its key (rule added to CLAUDE.md)
- [x] SendReport has a test that reads the generated workbook (OutlierMetricSurfacesTest)
- [x] Reporting page has a Livewire test that renders the incidents table
- [x] When a Collection filter compares a cast attribute, go through
      `?->value` — grep for `->whereIn('severity'` on Collections before
      trusting a metric

---

### [BUG-025] - Custom profile page dropped the session password-hash refresh: every password change force-logged the user out

**Date:** 2026-10-05
**Discovered By:** Prod report — password change on `/admin/profile` fails (in-app error modal, every attempt)
**Severity:** High
**Status:** Resolved

### Description
`CustomProfilePage` overrode vendor `EditProfile::save()` to add a dashboard
redirect — and in doing so dropped the tail of the vendor pipeline:

1. **No `password_hash_web` session refresh** (vendor save lines 175-179).
   The panel runs Filament's `AuthenticateSession` middleware, which compares
   the session hash against the stored hash on every request — after any
   password change it mismatched → force-logout. Every password change broke
   the session, in every environment.
2. No DB transaction/hooks, no password-field reset after save, and no
   `Password::default()` complexity rule (vendor applies it; the custom form
   had silently dropped it).

### Root Causes
- Overriding a vendor lifecycle method to change one behavior re-implements
  the whole pipeline; vendor fixes (or in this case vendor side effects) are
  silently lost. The vendor shipped a hook (`getRedirectUrl()`,
  `mutateFormDataBeforeSave()`) for exactly this customization.
- No test covered the profile password change, so the dropped side effect
  shipped invisible.

### Prevention
- [x] `CustomProfilePage` now overrides only `getRedirectUrl()`; vendor
      `save()` pipeline (transaction, session hash refresh, field reset)
      runs untouched
- [x] `CustomProfilePageTest` asserts the session hash refresh via a REAL
      `POST /livewire/update` through the web middleware stack
- [x] Gotcha documented: Livewire's test harness disables middleware
      (`RequestBroker::temporarilyDisableExceptionHandlingAndMiddleware`),
      so session-dependent behavior can never be asserted through
      `Livewire::test()` — extract the snapshot from rendered HTML and POST
      with the `X-Livewire` header to exercise the real path
- [x] Rule of thumb: prefer the vendor hook over re-implementing a lifecycle
      method; if a method must be copied, diff it against the parent on every
      dependency upgrade

**2026-10-05 follow-up — the literal prod "400" is diagnosed (evidence, not
theory).** One stateless probe against prod reproduced it: an oversized
`Cookie` header gets **nginx HTML `400 Request Header Or Cookie Too Large`**
from the PaaS ingress — the exact body Livewire's failure modal renders (the
user's "blue page show 400"). All app-side candidates were eliminated
empirically: malformed-JSON and password-shaped Livewire bodies both reach
Laravel and return our own 419 CSRF JSON; forged/conflicting `X-Forwarded-*`
headers (TrustProxies `'*'`) return 200; the local nginx accepts the same
oversized cookie. Prod's own cookies are ~1 KB (XSRF + session), so the
header bloat is **sibling apps' cookies on the shared `.paas.dana.id` parent
domain**, which ride on every request to this host. Livewire POSTs carry
~1 KB more header than GETs (X-CSRF-TOKEN + X-Livewire), which is why pages
kept loading while the password POST 400'd on every attempt. Remedies:
immediate — clear `paas.dana.id` cookies in the browser; durable (owner,
infra) — raise the ingress `large_client_header_buffers` for this host
(e.g. `4 16k`). Independent of the session-hash bug above; both fixes are
required (the ingress 400 would hit ANY Livewire POST from a bloated
browser, and the dropped session refresh broke password changes everywhere).

---

### [BUG-026] - Replacing a document file kept serving the old markdown conversion forever (ZIP/corpus/AI stale)

**Date:** 2026-10-05
**Discovered By:** User report — "export markdown zip: uploaded new PDFs but the zip doesn't contain the new files, is it cached?"
**Severity:** High
**Status:** Resolved

### Description
Replacing a document's file via the Supporting Documents EditAction swaps the
encrypted file and encryption key but never invalidates the persisted
conversion: `markdown_path`, `markdown_conversion_status` and
`markdown_converted_at` survive, and no `ConvertDocumentToMarkdown` job is
dispatched (the CreateAction dispatches one; the EditAction never did).
Because every consumer — the markdown ZIP, the persistent corpus, AI
summarize — is cached-first on the persisted conversion, the row serves the
PREVIOUS file's markdown forever. Reproduced live: zip entry named
`replace-v2.pdf.md` containing the v1 text. The same fix applied live
(reset + dispatch) made the zip serve the v2 content.

New uploads through CreateAction are NOT affected — verified end-to-end in
the live stack (job converts, zip includes it). New files missing from the
zip with a `## Document conversion notes ... skipped` appendix line instead
are conversions that legitimately failed (e.g. image-only PDFs extract no
text → status `failed`).

### Root Causes
- The replace path duplicated the create path's encrypt/store/key logic but
  not its conversion lifecycle. Conversion state is per-row, but nothing
  tied it to the file's identity — replacing the file silently orphaned it.
- Cached-first consumers trust `markdown_conversion_status = completed` as
  "content is current", with no invalidation on the one event that can make
  it wrong (file replacement).

### Prevention
- [x] EditAction now resets `markdown_path`/`markdown_converted_at` to null
      and `markdown_conversion_status` to `pending` when a new file is
      uploaded, and dispatches `ConvertDocumentToMarkdown` (CreateAction
      precedent)
- [x] `InvestigationDocumentReplaceTest` drives the real relation-manager
      EditAction: replace → reset + job dispatched; description-only edit →
      conversion untouched
- [x] Rule of thumb: any per-row derived/cached artifact must name the
      events that invalidate it — a replace path that touches the source
      must touch the cache too

---

### [BUG-027] - Profile save redirected every account to the Dashboard — guaranteed 403 for user-role accounts

**Date:** 2026-10-05
**Discovered By:** Prod report — "still error when change password, shows 403 Forbidden, must go back, no redirect to dashboard"
**Severity:** High
**Status:** Resolved

### Description
Third layer of the password-change onion, exposed only after the first two
were fixed (ingress 400 → session-hash drop → this). The save now succeeds,
the redirect finally fires — and lands on `403 Forbidden` for accounts whose
role lacks `manage incidents`.

`Dashboard::canAccess()` requires `manage incidents` (deliberate, commit
`5cf80ea`, which also taught `Login::getRedirectUrl()` to send such accounts
to the incidents list instead). The profile page's `getRedirectUrl()` returned
`Dashboard::getUrl()` unconditionally. A `user`-role account (seeder grants
`access dashboard` + `view incidents`, not `manage incidents`) can lawfully
open `/admin/profile`, saves fine, then gets redirected into a page that
aborts 403 — Browser shows the error, user presses Back. The password change
was coincidental: ANY successful profile save by such an account 403'd; the
broken save had masked it since `5cf80ea`.

### Root Cause
Same drift class as BUG-005: one rule (post-login landing URL) implemented in
one surface (Login) while the sibling surface (profile redirect) hardcoded
the target. Two surfaces, two implementations, silent divergence.

### Fix
- `App\Support\PanelHome::urlFor($user)` — single source:
  `manage incidents` → dashboard route, else incidents index.
- `Login::getRedirectUrl()` and `CustomProfilePage::getRedirectUrl()` both
  delegate to it.
- Regression test drives the real `POST /livewire/update` as a `user`-role
  account and asserts the redirect is the incidents index, not the Dashboard.

### Prevention
- [x] Shared rule in one class; both call sites delegate
- [x] Test covers the non-privileged role through the real middleware stack
- [x] When a redirect target has a `canAccess()` gate, the redirect decision
      must consult the same gate — a page you can be redirected to must be a
      page you can open

---

### [BUG-031] - DingTalk agent 400'd every LLM call: tool schema sent as a JSON string (double-encoded)

**Date:** 2026-11-10 · **Component:** dingtalk-agent (Go) · **Severity:** full outage — every message failed

#### Symptom
Every DingTalk message → `litellm.BadRequestError: TencentException - The
request parameter tools[N].function.parameters is invalid or missing`. The
index N varied across boots (tool map iteration order). Three hotfix rounds
(`62d6711` maxItems/nested-required removal, `e7f82a4` full flatten) each
shipped with green tests while the error persisted in prod.

#### Root Cause
`llm.ToolFunctionDef.Parameters` is `any`. The two generate tools
(`generate_pdf_report`, `generate_deck`) assigned a raw JSON **string** —
`json.Marshal` then double-encodes to `"parameters":"{\"type\"…}"`, a string
where the API demands an object, and the upstream 400s the WHOLE chat request.
The schema *content* was never the problem; both hotfixes rearranged content
while the encoding bug rode along. The lint test that should have caught it
(`TestAllToolSchemasFlat`) helpfully `case string:`-unwrapped the literal
before validating — it checked the parsed content, never the wire form.

#### Diagnosis (the part worth copying)
A throwaway probe (`cmd/probe`, since deleted) dumped the registry's defs
exactly as the client marshals them; the escaped quotes were visible in the
dump. Replaying 4 payloads against the real gateway isolated it in minutes:
string-encoded deck → 400 (exact prod error), object-encoded deck → 200,
entire fleet object-encoded → 200. Local bisect of the actual bytes beats a
third blind hotfix.

#### Fix
- `rawSchema()` in `internal/tools/registry.go` — decodes a static JSON
  schema literal into a map so it marshals as an object; both generate tools
  use it (panics at boot on a bad literal — static input).
- `TestAllToolSchemasFlat` now asserts the **wire form** too: the marshaled
  `parameters` must start with `{`. A raw string can never pass again.

#### Prevention
- [x] Validate the wire form, not the parsed content — a schema lint test
      must marshal the definition exactly as the client sends it
- [x] When an upstream rejects a payload, replay the actual bytes against the
      real endpoint and bisect (per-item requests) before theorizing
- [x] `any`-typed JSON fields are double-encoding traps — a raw string
      literal must pass through a decoder (`rawSchema`), never ride as-is

---

## Summary Statistics

| Metric | Count |
|--------|-------|
| Total Bugs | 29 |
| Critical | 0 |
| High | 20 |
| Medium | 7 |
| Low | 0 |
| Resolved | 28 |
| Open | 0 |

### Bug Trends by Component
| Component | Count |
|-----------|-------|
| Model | 0 |
| Controller | 0 |
| Filament Resource | 5 |
| API Endpoint | 1 |
| Database/Migration | 0 |
| Frontend/CSS | 1 |
| Queue/Job | 2 |
| Other | 5 |

---

*Last Updated: 2026-10-23*
### [BUG-028] - Netcore transport sent `type: plain` — every text-only email rejected with HTTP 400

**Date:** 2026-10-08
**Discovered By:** Prod report — Email Settings test send fails: `Netcore request failed: HTTP 400 — invalid content type, should be html or amp-content` (manual curl with `type: html` succeeds)
**Severity:** High
**Status:** Resolved

### Description
The Email Settings test send (and any plain-text mail, e.g. `Mail::raw`)
failed against Netcore's v5 API with HTTP 400 `invalid content type`.
The same endpoint + key accepted a hand-rolled curl whose `content[0].type`
was `html`.

### Root Cause
`NetcoreTransport::buildPayload()` mapped the message's text part to
`['type' => 'plain']` — the natural mapping, but Netcore v5 accepts only
`html` / `amp-content`. The test email is `Mail::raw()` (text-only, no HTML
part), so `content[0].type` was literally `plain` → 400. HTML mailables
carrying a text part hit the same rejection on `content[1]`.

The tests were false-green (same class as c9a2ce7): both faked the HTTP
layer and asserted URL + `api_key` header — never the payload's content
types, so an API-contract violation passed CI.

### Fix
`app/Mail/Transports/NetcoreTransport.php`:
- HTML body present → send only `[['type' => 'html', 'value' => $html]]`
  (text part dropped — Netcore has no plain slot).
- Text-only body (`Mail::raw`) → send as `['type' => 'html', 'value' => nl2br(e($text))]`
  — escaped (no markup injection) with line breaks preserved.
- Empty-body fallback → `[['type' => 'html', 'value' => '']]`.
- The string `plain` no longer appears in any payload Netcore receives.

### Prevention
- [x] Transport tests assert the full content contract: types, count,
      escaping — not just URL/headers
- [x] Feature test (`EmailSettingsTest`) asserts the real `Mail::raw` flow
      emits `html`, mirroring the failing prod request
- [x] Lesson: an HTTP fake green-lights whatever payload you build; assert
      the request BODY against the provider's documented contract, or the
      fake is testing nothing

---

### [BUG-029] - Export filters scoped the rows but not the MTBF math

**Date:** 2026-10-23
**Discovered By:** Owner report — export All Cases tab shows Avg MTBF 3,826; the owner's hand-made sheet for Fund Status = Confirmed loss + Non fundLoss reads 7,64
**Severity:** Medium
**Status:** Resolved

### Description
Selecting `f_fund_status = [Confirmed loss, Non fundLoss]` in the export
form filtered every Incident sheet's ROWS in all presets, but the MTBF
columns and Avg MTBF footers kept using the unfiltered chain — gaps of
1–3 days from Fully recovered / Non Tech Loss rows the owner excluded,
so a "filtered" export read exactly like the unfiltered one (Avg MTBF
3,8 instead of ~7,6).

### Root Cause
All three MTBF sequence implementations re-queried globally
(`Incident::whereYear(...)` + fixed constraints) instead of deriving
from the already-filtered builder the orchestrator handed the sheet:
`ComputesMtbfSequence` (Custom + Quarterly Report) and
`SingleIncidentSheetExport::computeMtbfForIncident()` (All Tabs). Rows
and derived metrics had different data sources — the same surface-drift
family as BUG-005/006/007, one layer up: not "one call site missed the
rule" but "the rule was computed from a second, parallel query".

### Fix
- `ComputesMtbfSequence` gains `?Builder $mtbfScope` (null = legacy
  global): sequence runs over the filtered builder, excluded rows
  telescope over. Fed by `IncidentTableExport` (5th ctor arg) and
  `QuarterlyCaseSheet` (3rd ctor arg, always the FULL filtered set —
  never per-tab).
- `SingleIncidentSheetExport` bases its per-tab sequence on its own
  (already filtered) query; tab `match()` clauses re-apply idempotently.
- Issues sheets untouched: their queries carry metric-membership clauses
  (`whereNotNull('mtbf')`, `mttr >= 0`) that must never become a
  sequence base.

### Prevention
- [x] Tests pin both sides: scoped export telescopes (C reads 20, not
      10) AND no-scope construction keeps the legacy global behavior
- [x] Tests assert footer Avg MTBF = scoped mean of column = scoped
      span/(n−1), and that case tabs share the full-set sequence (not
      per-tab gaps)
- [x] Lesson: when a derived column is computed per-row, the "scope" is
      whatever query the sequence is built from — if that query isn't
      the one the user filtered, the filter is cosmetic. Derive metric
      sequences from the handed filtered builder, or thread it in
      explicitly.

---

### [BUG-030] - Ultracode audit of the Notion sync feature: a poison-pill row wedged the unattended cron and orphaned Incidents, OAuth failures surfaced as raw 500s, and a dead refresh token still claimed "(auto-renews)"

**Date:** 2026-10-29
**Discovered By:** Ultracode multi-agent audit (owner request: "lakukan pengecekan ulang, apakah fitur sync notion ini sudah baik dan benar") — 18 agents, 11 confirmed findings
**Severity:** High
**Status:** Resolved

### Description
A full audit of the Notion sync feature (OAuth connection, scheduled
sub-page sync, deleted-issue recovery) confirmed one critical cluster and
three LOW findings. Critical cluster (all in the unattended cron path):
one throwing row aborted the ENTIRE sync AND left an orphan Incident —
the Incident row landed, the synced-page row did not, the watermark still
advanced past it, so the row was permanently invisible to every later
run. Recovery deleted a tombstone BEFORE re-importing, so a throwing
restore lost the restore promise forever. The OAuth callback's success
handler redirected to `route('notion.settings')` — a route name that does
not exist (Filament pages have no named routes), so a successful
connection would have 500'd; only untested-in-CI because the success flow
needs a live Notion round-trip. LOW cluster (owner: "kerjakan 1, 2, 3"):
a dead refresh token still rendered "(auto-renews)"; OAuth failures
(denied consent, expired session state) surfaced as a raw 500; title
dedupe hydrated the whole Issue table for EVERY row.

### Root Cause Analysis (5 Whys)
1. Why did one bad row wedge the sync?
   - The loop had no per-row isolation: any Throwable aborted the run.
2. Why did an orphan Incident result?
   - `Incident::create` + `NotionSyncedPage::create` were two separate
     writes; a concurrent manual sync's unique `page_id` insert could
     fail after the Incident landed.
3. Why was the row permanently invisible?
   - The watermark advanced past the failed row, so no run re-read it.
4. Why did the callback 500 on success and on denial?
   - `route('notion.settings')` does not exist (Filament page), and the
     vendor callback closure throws before the app's handler — with the
     token handler never reached on denial.
5. Why did middleware not fix the OAuth 500s?
   - Laravel 12's Pipeline wraps the route destination (and every layer)
     in `catch (Throwable) → ExceptionHandler::render()` — `$next`
     RETURNS a rendered 500 response; route middleware can never catch
     route-closure throws. The catch belongs in `$exceptions->renderable()`.

### Impact
- [ ] User facing?
- [x] Data loss/corruption? (orphan Incidents; a poison row silently
      blocked every issue behind it from importing — the dashboard went
      stale without any error the admin would see)
- [ ] Performance degradation? (title dedupe was O(rows × table) — hydrating
      the whole Issue table per imported row)
- [ ] Security vulnerability?
- [x] Broken workflow? (a successful OAuth connection would have 500'd;
      denied consent / expired session = raw 500; dead refresh token lied
      about auto-renewal)

**Impact Assessment:** The unattended cron is the feature's whole point —
a single malformed Notion row (e.g. a title longer than 255 chars under
MySQL strict mode) silently stalled ALL future imports while the
dashboard showed nothing. The OAuth 500s hit the one admin performing the
connection, at the exact moment they were trying to fix it.

### Fix
- `NotionIssueSyncService`: per-row try/catch (report FAILED in the
  preview, keep importing); Incident + synced-page writes in ONE
  `DB::transaction`; the watermark advances only over rows BEFORE the
  first failure and status becomes `partial` (fix the Notion row → next
  run re-imports it); recovery's tombstone delete + import in one
  transaction so a throwing restore keeps its tombstone.
- `routes/web.php`: callback redirects to `NotionSettings::getUrl()`.
- `NotionMcpClient`: `notion_mcp_needs_reconnect` Setting flag — set on
  refresh failure, cleared by any successful token store/disconnect
  (transient outages self-heal; good tokens are never deleted).
- `NotionSettings::connectionStatus()`: "Reconnect required" / past-expiry
  "renewal pending" wording instead of a blind "(auto-renews)".
- `bootstrap/app.php`: `$exceptions->renderable` for `OAuthException`
  scoped to `mcp/oauth/*` — Filament danger notification + redirect to
  the settings page.
- `NotionIssueMapper::existingNormalizedTitles()` + per-run in-memory
  title set (per-instance, never static — CLAUDE.md BUG-023 lesson):
  one `pluck('title')` per run; imported titles join the set so two
  identical rows in one run still dedupe.

### Prevention Checklist
- [x] Unattended import loops isolate per row (report-and-continue) and
      NEVER mark a failed row's position processed — the watermark stops
      at the first failure and the run reports `partial`
- [x] Two writes that must agree (Incident + its synced-page pointer)
      share one `DB::transaction`
- [x] A status UI must never claim self-renewal it cannot verify — derive
      "needs reconnect" from an explicit failure flag, not from "token exists"
- [x] Route exceptions are caught in `$exceptions->renderable()`, never in
      route middleware (Laravel 12 pipeline renders route throws itself —
      verified empirically: `$next` returned a 500 response, no throw)
- [x] DB failures in tests are simulated by injecting model events
      (`Incident::creating(... throw ...)`) — no mocks, the real write path
      is exercised
- [x] Lesson: test the SUCCESS path of every externally-triggered flow —
      the callback's `route('notion.settings')` typo shipped because only
      gating (403) was tested; the success redirect needs a live Notion
      round-trip and was never exercised until the feature tests faked it

---

### [BUG-031] - String env values into typed internal functions: AI web search silently returned "no results" once .env set AI_SEARCH_TIMEOUT / AI_SEARCH_MAX_RESULTS

**Date:** 2026-11-10
**Discovered By:** Pre-merge full-suite run on the feature branch — 2 AI
tests failing on a tree that was green on clean HEAD (environment drift,
not the branch's changes)
**Severity:** Medium
**Status:** Resolved

### Description
`WebSearchService::searchViaGemini()` collapsed to the "no relevant
public results" fallback on EVERY call once .env gained
`AI_SEARCH_TIMEOUT=15` and `AI_SEARCH_MAX_RESULTS=8`. Two TypeError
layers, both swallowed by the broad catch in `search()` and converted
into the benign empty-result fallback: first
`PendingRequest::timeout('15')` (int|float), then — after that cast —
`array_slice(..., '8')` in `parseGeminiResponse()` (int $length). No
error surfaced anywhere a human reads; the only visible symptom was the
fenced-context assertions in `ChatPersonaParityTest` failing. Tests
passed until the .env gained those vars — environments that never set
them were fine.

### Root Cause
Same family as BUG-021 (strict_types + string into a typed function),
one layer up the config chain: `config()` returns env values AS
STRINGS — `env('AI_SEARCH_TIMEOUT')` is `'15'`, not `15`. The config
reads for `ai.search.timeout` and `ai.search.max_results` had no
`(int)` cast while their sibling reads in the same file
(`max_parallel_queries`, `max_context_chars`, `relevance_threshold`,
`parallel_timeout`) did — the vars were added to config/ai.php later,
without the cast convention. The typed-literal defaults in
config/ai.php are harmless; only env-provided values break, so the bug
hides until someone sets the var. And a TypeError thrown mid-parse is
caught-and-degraded into "no results" — a crashed fetch indistinguishable
from an empty result set.

### Fix
- `WebSearchService`: `(int)` casts at the four unguarded numeric config
  reads — `ai.search.timeout` (×2) and `ai.search.max_results` (×2) —
  each with a one-line comment naming the trap ("Env values are
  strings — …").
- `RunAiAgentJobTest::test_include_corpus_skips_block_when_corpus_never_built`
  gained `Storage::fake('local')`: the corpus lives on the REAL local
  disk (phpunit forces CACHE_STORE=array, which hides the manifest but
  not the files), so dev-built corpus files leaked into the test and
  flipped its count assertion.
- Pint applied to `WebSearchService` (pre-existing style debt — the
  file pre-dated the current Pint ruleset; HEAD's copy already failed
  `--test` with the same fixers).

### Prevention Checklist
- [x] Any `config()` feeding a typed internal function (timeout, slice
      length, limit, threshold) is cast at the READ SITE — never trust
      an env-backed value's type
- [x] Grep pattern for the next sweep: `config\('ai\.` — every numeric
      read carries a cast
- [x] Search/parse paths must not catch-and-degrade a TypeError into an
      empty result set silently: the fallback DOES log the error string,
      but treat "always-empty search" in prod as a crash signal, not a
      content problem
- [x] Run the full suite on the HOST (where .env carries the real vars)
      before merging — the Docker container's env differs and hides
      env-drift bugs like this one

---

## [BUG-032] - The classification guard blocked EVERY edit of a linked record — the fill state holds the cast enum, the rule compared it strictly against the raw value

**Date:** 2026-11-17
**Discovered By:** Owner (changing a sub-incident's potential loss, PROJ-022)
**Severity:** High
**Status:** Resolved

### Description

Changing a sub-incident's Potential Loss through the Edit form failed with
`Unlink this incident from its super/sub-incidents before changing
classification.` — classification untouched. EVERY edit of any super or sub
through EditIncident failed the same way; standalone records edited fine,
which is exactly why the suite stayed green.

### Affected Component
- [x] Filament Resource (`IncidentResource` form rules — classification guard + the I4 `parent_incident_id` guard)
- [x] Model (enum cast on `classification` — the fill state's type)

### Root Cause Analysis (5 Whys)
1. Why did the guard fire? `$value !== $record->classification?->value` was true.
2. Why? The fill state holds the cast ENUM (`EditRecord` fills from the
   record's cast attributes — state = `IncidentClassification::Incident`),
   while the rule compared it strictly against the string `'Incident'`.
3. Why a strict compare? The rule was written against the create page,
   where the select submits the raw value; nobody ran an EDIT through it.
4. Why did no test catch it? The guard only arms on `hasIncidentLinks()` —
   every existing edit test used an unlinked record.
5. **Root cause:** a type-mismatched comparison (enum instance vs value
   string) in a rule only linked records reach — the one path no test
   exercised.

A SECOND guard false-fired in the same error bag: the I4 `parent_incident_id`
rule ran on the sub's edit page, where the hidden field prefills the record's
own parent — and a super always fails the "sits at the top" check (it has
subs by definition).

### Impact
- [x] User facing?
- [x] Broken workflow?
- [ ] Data loss/corruption? (the save was blocked, nothing written)

### Fix
- Classification guard: normalize the submitted value first
  (`$value instanceof \BackedEnum ? $value->value : $value`), then the
  strict compare.
- `parent_incident_id` guard: on edit, an untouched link
  (`(int) $value === (int) $record->parent_incident_id`) returns early; any
  other value — including a crafted `?parent=` on a standalone's edit page —
  is still validated.
- `SubIncidentCreateParentGuardTest` +2: a linked record's money edit
  passes the guard; a real classification change on a linked record is
  still rejected.

### Time-of-day flake found by the same run
`SubIncidentClassificationGuardTest` seeded `incident_date = today 10:00` —
future-dated whenever the suite runs before 10am (`maxDate(now())`),
failing on date validation instead of its own subject. Helper changed to
`now()->startOfDay()`. Five other files carry the same seed but only render
(never submit the form) — left as-is until one submits.

### Prevention Checklist
- [x] Any form rule comparing a field against a model ENUM attribute
      normalizes the submitted value first — a strict enum-vs-string compare
      is always-true and reads as "changed"
- [x] A guard that arms on record state needs a test driving the REAL form
      path on an ARMED record, not just the guard in isolation
- [x] Never seed form-validated dates with today-10:00 — `startOfDay()` is
      future-safe at every hour the suite can run

---

## [BUG-033] - Board view 500'd since the multi-PIC cutover: a `@php(expr)` one-liner swallowed the next `@endphp` block

**Date:** 2026-11-17
**Discovered By:** Owner (clicking Board view; ParseError at incident-kanban-board.blade.php:284)
**Severity:** High
**Status:** Resolved

### Description

The incident Board view button returned `ParseError: syntax error, unexpected
token "@"` — on EVERY render, in prod and local alike. Present on `main`
since 52f20d0 (PROJ-010 multi-PIC cutover, which introduced the one-liners);
PROJ-022 only shifted the reported line by one.

### Root Cause

Laravel's Blade pre-pass `storePhpBlocks()` runs BEFORE the one-liner
directive compilation, with a dotall regex pairing `@php` with the NEXT
`@endphp` in the file. A `@php(expr)` one-liner (no `@endphp` of its own)
therefore swallows everything up to the next BLOCK `@php…@endphp` (here
lines 283→311 and 468→439-side), storing the span — raw `@if`/`{{ }}`
directives included — as one `<?php{...}?>` blob. The raw directives landed
inside an open PHP tag → parse error. The same line in isolation compiles
fine, so the trap only shows when a later block `@endphp` exists in the
file — a scan across the codebase found exactly ONE file affected.

### Fix
- `incident-kanban-board.blade.php` 283/468: one-liners → inline-block form
  (`@php $firstPic = ...; @endphp` — the shape line 271 already used).
- `UserFacingCrashersTest::test_the_kanban_board_view_compiles_to_valid_php`
  pins the compile shape: no raw `@if(`/`@php`/php-open-paren artifact may
  survive compilation. (Board still cannot RENDER under SQLite — FIELD() —
  but now compiles and renders under MySQL.)

### Prevention Checklist
- [x] NEVER use the `@php(expr)` one-liner in a Blade file that also has a
      block `@php … @endphp` AFTER it — use `@php expr; @endphp` inline
- [x] Views that only render under MySQL get a COMPILE pin in
      UserFacingCrashersTest — compilation is DB-free and catches these

---

### [BUG-029] - DingTalk agent sweep — stale system prompt, dead card streaming, ticket tokens never matched

**Date:** 2026-11-12
**Discovered By:** Owner-requested crash/bug/simplify audit of `dingtalk-agent/` (3 read-only passes, every finding re-verified against source)
**Severity:** High
**Status:** Resolved

### Description
A three-bug cluster in the Go DingTalk agent, all in paths the test suite
never exercised: (1) the LLM system prompt was persisted into chat history
and reused on every later turn — stale clock, sender A's learned profile
applied to sender B, new knowledge ignored; (2) the AI card's streaming
callback had a dirty-flag ordering bug so only the FINAL frame ever rendered
(users saw nothing until the whole answer finished); (3) reminder/watch
ticket tokens were generated by Go 1.20's `rand.Text()` (26-char base32)
while the tool descriptions publish a `[0-9a-f]{16}` regex — tokens could
NEVER match, so scheduled reminders and incident watches silently never
bound.

### Root Cause
1. System prompt: `loop.run` only built the prompt when history lacked a
   `system` role, then returned history WITH it — save/load round-tripped
   the stale copy.
2. Card: `onDelta` set `w.dirty = true` AFTER the `!w.dirty` early-return
   that the delta handler calls first — classic order-of-assignment bug.
3. Tokens: two copies of the pending-request pattern drifted from their
   consumers' format contract. `crypto/rand.Text()` was chosen for
   "crypto-quality random string" without checking the regex the OTHER
   side matches against.

### Fix
- `agent/loop.go`: `run()` strips any incoming `system`, rebuilds the
  prompt fresh every turn via `buildSystemPrompt(sysExtra)`, returns
  `messages[1:]` — history starts at a user message and NEVER contains a
  system role. Trim guards against starting on a `tool` role (orphaned
  tool result → gateway 400).
- `stream.go`: `w.dirty = true` moved into `onDelta` before the flush
  check; `cardWriter` test with a fake stream func pins ≥2 frames.
- `internal/util.Pending[T]`: one generic replaces the watch + schedule
  copies; `Put` emits 16-hex tokens (`rand.Read(8)` + `hex.EncodeToString`)
  matching the ticket regexes; `at` set on Put (zero-`at` made one turn's
  second Put sweep the first away).

### Prevention Checklist
- [x] Any generated string that another regex parses must be tested
      against THAT regex end-to-end (generate → match → claim) — not
      assumed format-compatible
- [x] A prompt/any per-turn state that "sticks" in a history file is
      rebuilt per turn; history stores only user/assistant/tool turns
- [x] Callback flags set AFTER the check they gate = dead first frame;
      write the flag where the event happens, not after the consumer
- [x] When the same pattern exists twice (pending-tokens here), the third
      caller gets a shared generic — drift between copies IS the bug

---

## 2026-11-12 — New agent tools shipped untaught: prompt/constitution not in the plan's task list (KB file ingest)

**Finding (whole-branch review, PROJ-019 KB ingest):** the feature registered `save_knowledge` / `list_knowledge` / `delete_knowledge`, but the plan never listed `internal/agent/prompt.go` as a touched file — `KnowledgePrompt` still taught only `search_knowledge`, and `ConstitutionTail`'s scope line said the only KB interaction is "answered via search_knowledge. Nothing else." The model would have refused (or never chosen) the headline "remember this" flow the feature exists for. Nothing hermetic catches this: the tools work, the tests pass, and the refusal only appears live.

**Root cause:** plans enumerate code seams (registry, index, client, stream) but not prompt surfaces. A tool the system prompt doesn't mention and the constitution forbids is dead code with a passing suite.

**Prevention:**
- [x] Any task adding an agent tool MUST also touch (or explicitly rule out) `KnowledgePrompt`/domain-prompt bullets AND the `ConstitutionTail` scope line — same-day precedent: the reminders feature had to widen this exact clause "so the model doesn't refuse scheduling".
- [x] Plan template: add "prompt surfaces" to the pre-flight interface scan alongside "consumed by" files.
- [x] Pin it: `TestKnowledgePromptTeachesAllKBTools` asserts every registered KB tool appears in BOTH prompts (a new tool that skips the prompt now fails a test, not a live chat).

---

---

### [BUG-032] - Incidents Summary footer ignored the active tab (fund tabs showed All Cases MTTR/MTBF/totals) + Quarterly fund tabs sequenced MTBF off the baseline chain

**Date:** 2026-11-16
**Discovered By:** Owner ("in report MTBF and MTTR is wrong for fundloss / non fundloss, for all incident already correct")
**Severity:** Medium (wrong management numbers, no crash)
**Status:** Resolved

### Description
Two surfaces showed All Cases numbers where the owner expected each surface's own rows:

1. **The Incidents table's Summary footer** (contentFooter on `IncidentResource::table()`) called `IncidentStatsFooterData::build()` with NO query. `baseQuery()` re-derives only the request's date filters (`quick_period` + `custom_date_range` — BUG-018's deliberate fallback), so on the Fund Loss / Non Fund Loss tabs (and any other tab / SelectFilter / search), Total Cases, Avg MTTR (mins/days) and Avg MTBF were the All Cases values.
2. **The Quarterly Report's Fund Loss / Non Fund Loss tabs** passed the baseline query as `mtbfScope`, so their MTBF column and Avg MTBF read gaps against incidents of EVERY fund status. (Quarterly MTTR was already per-tab; the All Tabs export already sequences per-tab — the two surfaces the owner flagged.)

Regression path: the pre-f6779e2 footer used `$this->getFilteredTableQuery()->clone()`; the move to the declarative `->contentFooter()` dropped it, and the BUG-018 rebuild (2026-10-03) intentionally re-derived only date filters — which fixed "footer vs date filter" parity while silently leaving "footer vs tab" broken. The Quarterly baseline-scope behavior was a deliberate 2026-10-23 rule the owner reversed on 2026-11-16.

### Root Cause
A stats builder whose input scope is *re-derived* instead of *received* can only be right for the surface that last wrote the derivation. Every new scope dimension (tab, search, SelectFilter) added to the table after the builder was written silently desyncs it — exactly the BUG-005/006/007 one-surface-drift family, fixed here by passing the table's own filtered query instead of fixing one call site.

### Fix
- `IncidentResource.php`: `->contentFooter(fn ($livewire) => ...build($livewire->getFilteredTableQuery()->clone()))` — Filament injects `$livewire` into table closures; `getFilteredTableQuery()` = table query (tab applied) + filters + search.
- `QuarterlyReportExport.php`: Fund Loss / Non Fund Loss tabs pass their own query clone as `mtbfScope`. All Cases / Recovered Cases / quarter tabs unchanged (baseline chain).
- `IncidentStatsFooterData::build()` doc: preferred input is the table's filtered query; `baseQuery()` stays as the no-table-context fallback (ExecutiveCalcSheet passes its own query).

### Prevention Checklist
- [x] Tab parity pinned: `IncidentStatsFooterTabParityTest` — Fund Loss / Non Fund Loss footers must equal their own rows' stats and differ from All Cases (the exact user-visible symptom).
- [x] Rule for stats builders: take the surface's query as a parameter; re-deriving filters from `request()` is only for callers with no surface (documented on `build()`).
- [x] Quarterly per-tab semantics pinned in `QuarterlyReportExportTest` (Fund Loss C reads its own chain 5, Non Fund Loss first row '-') and `SeverityFooterTest` (Fund Loss banner Avg MTBF = tab's own 41/2).
- [x] Lesson family: when moving logic into a declarative/config form (`contentFooter` etc.), diff the closure's old body — the move itself dropped the query argument, and the regression shipped green because no test rendered the footer per tab.

### [BUG-033] - Widget MTTR tiles/series scoped by MTTR sign instead of fund status + export MTBF chain had three private copies + analytics bucket means diluted by the group total

**Date:** 2026-11-16
**Discovered By:** Owner directive ("check widgets also fix the calculation so report / export and widgets match . also all other export … like 1 logic for all export") during the hand-workbook reconciliation
**Severity:** Medium (wrong management numbers; one latent prod TypeError)
**Status:** Resolved

### Description
The owner asked for ONE metric logic across widgets, report footers and every export. The audit found four divergence families:

1. **Sign was used as a scope.** `IncidentMetricsCalculator::computeMttr()` stores days-scale NEGATIVE mttr for every `shouldCalculateMttrByDays()` status — Confirmed loss AND Potential recovery / Fully recovered / Non Tech Loss. The dashboard's MTTR tiles (`DashboardStatsOverview`, `IncidentStatsOverview`) and the trend chart's two MTTR series split by sign and labeled the buckets "Fund Loss (days)" / "Non Fund Loss (mins)": a Potential recovery row's −20d landed in the "Fund Loss" tile, a null-fund row's 30 min landed in the "Non Fund Loss" tile — neither row belongs to any tab of those names. The export tabs scope by `fund_status` and only use the sign to pick the unit bucket.
2. **IncidentStatsOverview's Fund Loss money card missed `excludedFromCounts()`** (Completed alone) — Fully recovered / Potential recovery money counted, contradicting the Fund Loss card rule (AiTrendInsights + AnalyzeTrendsController fund totals had the same gap).
3. **AnalyticsQueryService's JSON-dimension MTTR means divided each bucket's sum by the GROUP total** (`$stats['count']`), diluting mins by days-bucket rows and vice versa. The days branch also called `abs($incident->mttr)` on the raw attribute — a MySQL DECIMAL string (BUG-021 trap): a latent TypeError that no test had ever hit because no test exercised negative mttr through this path.
4. **The export MTBF gap sequence existed three times** — `ComputesMtbfSequence` (Custom + Quarterly), `SingleIncidentSheetExport::computeMtbfForIncident()` (All-Tabs, per-tab), `IssuesMetricSheetExport::computeIssueMtbf()` (Issues, global) — the 2026-10-23 docs even said "two semantics, do not merge".

### Root Cause
The tile/series code was written when "fund loss ⇒ negative mttr" happened to hold, and the label froze the coincidence into UI copy; the calculator's status set later widened without the tiles following. The sequence triplication was 2026-09-30/2026-10-23 fixes landing in separate files instead of one trait. The diluted denominator came from a shared accumulator keyed by group, not bucket.

### Fix
- Tiles/series scope `fund_status` (enum cases) with the sign kept as the unit bucket; cache keys bumped `dashboard_stats_v7` / `incident_stats_v6` / `mttr_mtbf_trend_v6`.
- IncidentStatsOverview + AiTrendInsights + AnalyzeTrendsController fund totals gain `excludedFromCounts()` (DashboardStatsOverview already had it).
- AnalyticsQueryService: per-bucket `mttr_cnt` / `mttr_days_cnt` denominators + `(float)` cast before the sign test/abs.
- ONE sequence: `ComputesMtbfSequence` with a scope-TRUSTED `$mtbfScope` (only metric hygiene — METRIC_ELIGIBLE + outliers + year + ordering — is added). All-Tabs sheets pass their sheet query (per-tab sequencing preserved); IssuesMetricSheetExport passes a FRESH classification=Issue query — NOT its own row query, whose `whereNotNull('mttr'/'mtbf')` membership clauses would shrink the chain (a stored-metric-less intermediate row must keep telescoping). The live table's `mtbf_display` column joined the same semantics (METRIC_ELIGIBLE, first-of-year `-`, key `mtbf_{tab}_{year}_v2_*`).

### Prevention Checklist
- [x] Rule written down (dependency-map "one-logic rule" + business-rules): a Fund Loss / Non Fund Loss LABEL scopes by `fund_status`; the MTTR sign only picks the unit bucket.
- [x] `WidgetMetricUnificationTest` (6 tests): sign-vs-scope quadruple pinned on both stat widgets + the trend chart; fund card excludedFromCounts; per-bucket denominators; live-table chain cache map (first `-`, gap telescopes over a G row, G id absent).
- [x] The live TypeError was caught by adding the missing days-branch test — coverage gap let a prod-only string cast survive three rounds of BUG-021 fixes elsewhere.
- [x] "Do not merge" doc notes are a smell, not a design: when a second implementation appears for scope reasons, make scope a PARAMETER of the first instead (the trait now trusts `$mtbfScope`), and delete the copies in the same commit.

---

### [BUG-034] - Investigation form intake sent a shifted Notion page id (slug letters glued onto the id)

**Date:** 2026-11-17
**Discovered By:** Owner, first field test of PROJ-024 ("already provide url but … error"; "even dingtalk agent can read it")
**Severity:** Medium (Notion source unusable for every link whose title ends in a hex character)
**Status:** Resolved

### Description
Every pasted Notion link failed with "could not be read". Diagnostic surfacing of Notion's own reply showed `validation_error 400 "URL type webpage not currently supported"` — Notion answered (connection + access fine) and rejected the INPUT. The same page read fine through the DingTalk agent.

### Root Cause
`InvestigationFormIntake::collectSource()` stripped every dash from the whole URL, then took the FIRST 32-hex run. Notion links are `<title-slug>-<32hex id>`; removing dashes glues trailing hex letters/digits of the slug onto the id — `RootCaus[e]-1022…` → `e1022…`, `v[1-0]-d352…` → `10d352…` — and the first-32 match returns a shifted, nonexistent id. The agent path never had the bug because it passes the URL verbatim to `notion-fetch`. My first two "fixes" misdiagnosed it as page-sharing and only reworded the error.

### Fix
`notionPageId()`: the LAST 32-hex (or dashed UUID) in the URL **path** (query/fragment dropped), hex-bounded on both sides, dashes never stripped. Error copy no longer claims a sharing problem; Notion's own refusal stays in the message.

### Prevention Checklist
- [x] `test_notion_link_sends_the_real_page_id_and_surfaces_notions_refusal` pins the exact id sent for both field URLs + dashed UUID + bare id; verified to FAIL on the old parser (`fetch('e1022556…')`).
- [x] When one consumer of a shared service works and another fails on the same input, diff the INPUTS the two send before theorizing about permissions.
- [x] Never normalize (strip separators) before extracting a token from a structured string — extract first, with boundaries.
- [x] Swallowed upstream errors hide the cause: surfacing Notion's reply is what turned this from "sharing?" guesswork into a one-line diagnosis.

---

### [BUG-035] - Open → Completed save silently did nothing (closure-gate error never rendered)

**Date:** 2026-11-17
**Discovered By:** Owner ("when i update status / save … it failed / not saved"; "when i changed from open to completed")
**Severity:** Medium (no data loss — the save rolled back — but users could not tell why Completed would not stick)
**Status:** Resolved

### Description
Setting an incident from Open to Completed, from either the Edit page or the list's "Update Status" modal, did nothing: no error and no change.

### Root Cause
The closure gate (`IncidentObserver::saving()`, RD-2) worked correctly. It threw `ValidationException::withMessages(['incident_status' => …])` because the record had no stop-bleed time or root cause. Filament rolls back and rethrows, so Livewire stores the error under the **bare** key `incident_status`. Filament fields render errors by **state path** (`data.incident_status`, `mountedTableActionsData.0.incident_status`), so no field displayed the message. `IncidentClosureGateTest` only covered the model, so it never checked what the UI showed.

### Fix
`Incident::closureGateError()` is now the one rule. The observer (backstop for API/imports) and two new Filament field rules (the Edit-form Status select reads `$get()` values; the Update Status modal reads the record) all call it. Second trap: in the record-bound modal the Select state arrives as the `IncidentStatus` **enum**, not a string, so the rule normalizes with `tryFrom` before comparing.

### Prevention Checklist
- [x] Livewire tests `assertHasFormErrors` / `assertHasTableActionErrors(['incident_status'])`. Verified failing before the fix.
- [x] A `ValidationException` thrown below Filament (observer/model/service) never reaches a field. Mirror the rule as a field `->rule()` and keep the low-level throw only as a backstop.
- [x] Filament rule closures: compare enum-cast fields as enums (`tryFrom`), not string `->value`.

---

### [BUG-036] - Go selfTruncate printed a NEGATIVE "truncated N chars" on CJK web results + byte-length guards suffixed ellipses onto untouched strings

**Date:** 2026-11-17
**Discovered By:** Claude audit (batch 5, plan item D12/D8)
**Severity:** Low (cosmetic/correctness of truncation notes; no data loss)
**Status:** Resolved

### Description
`internal/tools/web.go` `selfTruncate()` guarded with `len(s) <= webResultMaxChars` (BYTES) but computed the note from `len([]rune(s)) - webResultMaxChars` (RUNES). A CJK-heavy web result — ~2.3 runes per byte — can cross the byte limit while staying far under the rune cap: the guard passed, then `util.Cut(s, webResultMaxChars)` returned the string unchanged, and the suffix printed e.g. `[truncated -4667 chars]`. The same byte-vs-rune confusion lived in `scheduleapi.go`'s `len(text) > 80` guard (appended "…" after a no-op cut) and five hand-rolled cut+suffix copies disagreed about both.

### Root Cause
Mixing byte length (`len(s)`) with rune counts in one expression, and writing the cut+ellipsis pattern per call site instead of one helper — each copy re-decided the guard's unit independently.

### Fix
One helper, `util.CutEllipsis(s, n)`: rune-safe `Cut` + "…" only when `Cut` actually shortened the string. The cut test IS the guard — if `Cut` returns `s` unchanged, nothing was cut, so a rune-count suffix can never be negative. Five call sites converted (stream.go `truncateStr` deleted, watch Describe, scheduleapi describe, mesh queue summary, web selfTruncate — the latter keeps its own count suffix but rune-based now). `llm/client.go`'s byte-slicing `truncate` deleted too (rune-safe `util.Cut`).

### Prevention Checklist
- [x] `TestCutRuneSafe` extended: `CutEllipsis` must not suffix an untouched string and must be rune-based on CJK (a 55-byte Thai string at n=100 stays unchanged).
- [x] Never mix `len(s)` (bytes) with `len([]rune(s))` (runes) in one guard+count expression. In Go, guard on the SAME unit you count.
- [x] The "did anything get cut?" test (`cut(s) != s`) is the correct guard for any suffix/count that assumes a cut happened — a byte-length pre-check is a second, drift-prone copy of that fact.

---

### [WASTE-01] - One MCP round-trip per dangling tombstone on every Notion Settings render (restorableIssues)

**Date:** 2026-11-17
**Discovered By:** Claude audit (batch 5, plan item C13)
**Severity:** Low (Settings page latency; N sequential MCP queries per picker render before memoization)
**Status:** Resolved

### Description
`NotionIssueSyncService::restorableIssues()` — the restore picker's options — issued one `notion-query-data-sources` round-trip PER dangling tombstone (`WHERE url LIKE '%<id>%'` each). Every Sync-now modal render re-ran the closure, so a workspace with N deleted issues paid N sequential Notion round-trips before the modal could open.

### Root Cause
The picker reused `recoverDeletedIssues()`'s per-page lookup shape (correct for a background run that processes each tombstone individually) for an N-items-at-once UI read.

### Fix
One round-trip: a single `url LIKE '%id1%' OR url LIKE '%id2%' …` query (server-side filter keeps the reply inside the result cap — an unfiltered full-table read could silently truncate), then match page ids in PHP. `recoverDeletedIssues()` keeps its per-page shape — pinned mocks document it and the run reports per-tombstone failures.

### Prevention Checklist
- [x] `NotionIssueSyncServiceTest` restorableIssues tests still green (the shared-mock falls through on the combined query).
- [x] Batch reads for UI lists; per-item reads only where per-item failure isolation is the point (a background restore must survive one bad tombstone).
- [x] When replacing N queries with one bulk read over a capped channel, keep a server-side WHERE — an unfiltered bulk read trades a latency bug for a silent-truncation bug.
## BUG-039 — Recurrence panel wrong on every axis: hand-typed statuses, unbounded relevance, starved AI prompt, stale results (2026-11-18)

### Symptom
Incident 20261116_IN_7846's "Recurrence Detected" panel: scores of 147/10 and 130/10 (scale is /10), the top "recurrence" was a P1 trade-drop incident unrelated to the unsettled-transaction fund-loss incident, and the AI analysis claimed "0 pending/overdue actions" on all matches while reasoning from who was @-mentioned in the text.

### Root Cause — four independent defects in `RecurrenceDetectionService`
1. **Hand-typed status strings (Edit-Safety Rule 1 repeat).** Pending/overdue counts filtered actions against `['Open', 'In Progress']`; real statuses are `pending`/`on_progress` (`ActionImprovement::openStatuses()`). A `whereNotIn`-style no-op: every count was 0, so the AI was told there were no open actions anywhere and concluded "nothing contributed".
2. **Raw MATCH…AGAINST relevance used as a bounded bonus.** `ragBonus = round(relevance_score * 2)` — but `relevance_score` is unbounded MySQL fulltext relevance (30–70 typical). A wordy unrelated incident scored 140+ points of "text similarity", drowning category matching and producing 147/10. `SimilarIncidentService` and `HybridIncidentRetriever` already normalize by max — this service didn't.
3. **AI prompt starved.** Each match was fed only `Str::limit(summary, 150)` — for these incidents the summary IS the email greeting ("Dear Tech Risk Team, mas @Kevin…"). No title, no root cause, no match reason → the model reasoned from names. The data was already loaded in `SIMILARITY_COLUMNS`; it just wasn't passed on.
4. **Detection ran once, at creation+30s, when root cause is still empty.** `recurrence_data !== null` then blocked any re-run forever — the analysis could only reflect a half-filled incident.

### Fix
One `actionCounts()` helper on `ActionImprovement::openStatuses()`; RAG normalized by max (bonus 0–3); score capped `min(10, …)`; a match must share a real signal (root_cause_category / labels / text_similarity) — team+severity alone no longer qualifies; prompts get `[no] title — root cause — matched on: …` plus an explicit "names are reporters, not evidence" rule; re-detect via `DetectRecurrenceJob(force: true)` when `root_cause`/`root_cause_category` change.

**Observer write trap (new):** the first fix nulled `recurrence_data` + `saveQuietly()` inside `IncidentObserver::updated()` — that silently broke `GovernanceKpiServiceTest::test_rca_within_5_days_uses_audit_trail`. Saving inside `updated()` syncs the model's dirty state BEFORE the OwenIt auditing observer runs, so the `root_cause` audit entry came back empty and `rcaWithinFiveDays()` (which reads `audits.new_values.root_cause`) lost coverage. Rule: **`updated()` must not write to the model** — re-detect is signaled via a force flag on the job, the null-guard stays in the job.

### Prevention Checklist
- [x] `tests/Unit/Services/RecurrenceDetectionServiceTest` (8 tests): open-status counts, raw-relevance normalization + score cap, team+severity-only no longer flags, category/label overlap flags, title/root_cause carried for the prompt, observer re-detect on root_cause edit, no re-detect on team-only edit.
- [x] Re-running an incident's detection is the owner-run backfill: `php artisan incidents:backfill-recurrence --force` (prod guardrail — writes to prod DB).
- [ ] Never hand-type action status strings; `ActionImprovement::openStatuses()` is the ONE map (same lesson as the 2026-11-18 on_progress batch).
- [ ] Never save the model inside an observer hook that other observers audit after; pass intent forward (job flag), don't mutate.


### Symptom
`/admin/incidents/board` → red Filament 500, `Parse error: syntax error, unexpected token "@"`. Governance 500 on prod (techrisk.paas.dana.id) was a separate surface (schema drift — see Prevention) but reported together.

### Root Cause
Laravel 12.70.1 `BladeCompiler::storePhpBlocks()` runs regex `/(?<!@)@php(.*?)@endphp/s` BEFORE directive compilation — it does NOT skip the expression form `@php(...)`. In `incident-kanban-board.blade.php` the expression form `@php($firstPic = $incident->pics->first())` (line 282, shipped in 52f20d0, PROJ-010 cutover) made that regex match from `@php(` to the NEXT `@endphp` (line 310's), swallowing the 308–310 block and emitting the captured range verbatim as a raw block — an unclosed `<?php(` plus never-compiled `@php`/`@if` text → parse error. Blocks BEFORE the first expression form compiled fine; everything after was mangled. Verified minimal repro on the installed framework: `@php($a = 1)\nX @php\n$y = 2;\n@endphp` → `<?php($a = 1)\nX @php\n$y = 2;\n?>` (raw `@php`, no `; ?>`).

Not caught for 7 weeks: the board cannot render under SQLite (`FIELD(severity, …)` ordering — documented limitation in `UserFacingCrashersTest`), so no component test reached the template.

### Fix
`@php($x = …)` → block form `@php $x = …; @endphp` (the pattern line 271 already used). Never mix the expression form with block-form `@php` in one template.

### Prevention Checklist
- [x] `tests/Feature/BladeTemplatesCompileTest.php` — compile+lint sweep over every `resources/views` template (`Blade::compileString` + `php -l`), DB-free; catches the whole class (would have failed on the broken file).
- [x] Dev DB repair: 8 pending migrations run (dev scheduler `ai:dispatch-due-agents` had failed every ~min since Nov 12 — `Unknown column 'run_at'`), `view:clear` + `docker compose restart app` (OPcache/bind-mount lag trap).
- [ ] Prod (owner-only guardrail): deploy the fixed file, then `php artisan migrate:status` + `migrate --force`; if governance still 500s, read `storage/logs/laravel-<date>.log` — dev renders it clean, so prod's exception is the definitive evidence.
- [ ] Any future `@php(` in a template: check the same file for block-form `@php … @endphp`; if mixed, convert. The lint test now fails loud instead.
## BUG-037 — Multi-year year-access silently showed only the first year (2026-11-17)

### Symptom
A non-admin user granted `allowed_years = ['2025', '2026']` in `UserAuditLogSetting` saw only 2025's incidents — on every surface routing through `Incident::scopeApplyUserYearAccess` (Incidents list, Issues list, ActionImprovement whereHas, Reporting, export footers, `/api/v1/stats`) and in the audit-log copy `ApiAuditLogResource::applyAccessControl()`. No error anywhere.

### Root Cause
`whereYear($col, $array)` does NOT build an `IN` clause. Laravel's `flattenValue()` (`Builder.php:4654`) turns the array into `head(Arr::flatten($value))` — the query compiles to `year(col) = ?` bound to the FIRST element only, order-dependently. Single-element arrays (the default `[(int) date('Y')]`) work by accident, which is why prod never flagged it. Admins and `can_view_all_logs` users skip the restriction entirely and were never affected.

### Fix
OR the years one by one inside a `where()` closure — portable across MySQL (`year(col) = ? or year(col) = ?`) and SQLite (`strftime('%Y', col) = cast(? as text)`):
```php
$query->where(function ($w) use ($settings) {
    foreach ($settings->allowed_years as $year) {
        $w->orWhereYear('incident_date', (int) $year);
    }
});
```
The raw alternative `whereIn(DB::raw('YEAR(col)'), $years)` fails on SQLite ("no such function: YEAR") — do not use it.

### Prevention Checklist
- [x] `IncidentYearAccessScopeTest::test_multi_year_allowed_years_see_every_granted_year` + `ApiAuditLogYearAccessTest` (multi-year + admin-default pins) — were RED on the old code, now green.
- [x] Full suite 1338 / 5,356 green (7 known reverb errors only, Docker down).
- [ ] Any future multi-value `where*Date/whereYear` call: pass scalars one per `orWhere*`, never an array — the array form is a silent head-flatten, not an IN.
## DingTalk agent audit round 5 — 25 verified defects fixed across P0–P3 (2026-11-18)

Branch `fix/dingtalk-agent-audit-round5`, commits `05b3b0a` (P2 batch 2b) + `40b072e` (P3 batch) + the two earlier P1/P2 batches. Full defect table lives in `dingtalk-agent/IMPROVE-BRAINSTORM.md` (STATUS section); this entry records only the transferable lessons.

### The defects that matter (by lesson, not by number)

**Export-path escape (P0, data leak).** `safeExportPath` joined the LLM's ticket to `DataDir`, not `DataDir/exports` — a `[generated-file: history.json]` reply (steerable by injected text in any uploaded doc/Notion page/web page) resolved and DM'd the bot's own KB, user profiles, or token store. Fix: join under the exports dir AND require the cleaned path to keep that prefix. Lesson: **an LLM-supplied filename is user input; validate it against a directory allowlist, not against string blacklists.**

**Inbox send-after-unlock race (P0, message loss).** `enqueue` captured the worker channel under the lock but sent after unlocking; the idle worker could delete the inbox and exit in that gap — the message acked, never processed. Fix: send while holding the lock (channel send is non-blocking). Lesson: **"capture under lock, use after unlock" is a race, not a pattern** — the check and the use must share the lock. Found by line-reading the idle path, not by `-race` (the loser goroutine had already exited).

**Reminder context poisoned the heavy trigger (P2, cost).** `HeavyTurn` matched trigger phrases against the final LLM input, which has the active-reminders list appended — a reminder whose TEXT said "deep research" routed every turn of that chat to the heavy model forever. Fix: match the raw user message only. Lesson: **when a gate must judge "what the user said", feed it exactly what the user said**, not the assembled prompt that happens to also contain tool/reminder content.

**Turn reports logged the wrong model (P2, cost visibility).** `run()` always reported `b.llm.Model()`; heavy-escalated turns were billed in the cost table under the cheap model. Fix: `run`/`runWithEscalation` return the model actually used. Companion fix: escalation now fires only on a typed budget-exhaustion error, not any LLM error (one 429 no longer buys a 30-round heavy run).

**Secret redaction gaps (P1).** The key-value regex missed `"password": "x"` (JSON quote between key and colon), `LLM_API_KEY=` (`\b` doesn't fire after `_`), and `postgresql://` URLs; `*url.Error` in the media-upload path prints full URLs including `access_token=`. Fix: redact before truncate (a secret cut at a truncation boundary falls under the regex minimums), redact input-gate refusals and turn reports, allowlist download hosts with redirect re-checking. Lesson: **redact at the boundary where the string leaves the process, and redact BEFORE any length-based transformation** — order matters.

**Embeddings path `/v1/v1/` (P2, silent degradation).** Chat posts `<base>/chat/completions`, embeddings posted `<base>/v1/embeddings`; with a base URL that already ends in `/v1` the KB silently fell back to keyword-only search. Fix: `/embeddings` + fail loudly on non-200. Lesson: **a silent fallback in a retrieval path must announce itself** — it did, in a README footnote, for weeks.

**Fail-open quota (P2).** The 20-reminder cap was skipped when `List` errored — the only brake on a looping tool minting cron jobs. Fix: refuse on error. Lesson: **a limit that cannot be verified must refuse, not assume.**

**KB overwrites + keyword rescue (P2).** Docs were keyed by file-NAME hash (two users' `report.pdf` overwrote each other) and the keyword rescue returned any chunk sharing one 2-char token, undoing the 0.35 cosine floor. Fix: content-hash keys, stopword set, keyword floor.

**excelize iterator traps (P3).** `Rows()` iterators never Closed (leak), blank rows bypassed the row cap (only non-blank counted), `UnzipSizeLimit` default is 16 GB. Fix: close, count scanned rows, 64 MB unzip limit. Lesson: **a cap that counts only "interesting" rows is not a cap** — count what you scan.

**History starvation (P3, quality).** The 12-message history window counted tool rounds, so one busy turn evicted all dialog. Fix: keep dialog only (drop tool messages and assistant messages carrying tool_calls).

**Prune fallback (P3).** `PruneContextBudget` gave up when only the last user message remained; now it blanks older user messages before giving up — a loaded skill (Track B `load_skill`) is additionally protected from first-pass eviction.

### Prevention Checklist
- [x] New pins per fix (heavy-trigger two-leg test, unzip-limit, interior-blank chart error, redaction cases, escalation-on-budget-only, export-path rejection); full Go suite green + `-race` on `internal/dingtalk`, `internal/agent`, `internal/tools`, `internal/kb`.
- [x] `IMPROVE-BRAINSTORM.md` STATUS table reflects final state, incl. deliberate skips (aicard single-flight, knowledge.jsonl compaction, deep bilingualization — each with a reason).
- [ ] Audit lesson itself: this round found 25 defects by line-reading, all in code that had a green test suite — **test suites prove the paths that exist; only reading finds the paths that interact** (races, fallbacks, gates fed the wrong string).
- [ ] When appending context to LLM input (reminders, docs), enumerate every gate that reads that input and decide whether the gate should see the appendix too.

---

## BUG-040 — Reminder emails sent for Issue-classified records: no classification gate on the reminder lanes (2026-11-18)

**Discovered By:** Owner, first production run of the status-update lane ("the email still send for issue too not incident only")
**Severity:** Low (wrong-target reminder; one prod email to a downgraded issue's PICs)
**Status:** Resolved (commit 014a1a0, recorded as BUG-035 pre-merge — renumbered past main's BUG-035/036/039)
**Note:** the merged number **BUG-037** was also taken by another session mid-merge (multi-year year-access), hence BUG-040.

### Description
After the status-update reminder lane shipped, its email fired for a record classified `Issue` — specifically a case raised as an issue, upgraded to Incident, then downgraded back to Issue after it failed the incident requirements. Other Issues had (so far) received nothing, which made the bug look like a downgrade-handling problem rather than what it was.

### Root Cause
`SendIncidentReminders::dueIncidents()` — the shared query under ALL three lanes (not-done, fund-loss, status-update) — never filtered `classification`. The `incidents` table holds both classifications, so any Issue old/bare enough to satisfy a lane's conditions was reminded. The downgrade was a red herring: the record's age (created back when it was raised) plus its lack of status updates simply made it the FIRST Issue to trip the status-update lane's empty case. Same latent hole in the other two lanes, just not yet triggered in prod.

### Fix
One shared-scope line: `dueIncidents()` filters `classification = IncidentClassification::Incident->value` — every lane inherits it (enum case, edit-safety rule 1). Test: `test_issues_get_no_reminders_from_any_lane` builds an Issue that satisfies all three lanes at once and asserts nothing is sent and `last_reminded_at` stays null.

### Prevention Checklist
- [x] A new command over a table that holds multiple classifications must state its classification scope explicitly — "Incident" in the class name is not a filter.
- [x] When one member of a class (the downgraded issue) misbehaves and its peers don't, suspect a latent hole that age/data shape merely hasn't exposed — check the shared query, not the lifecycle transition.
- [x] `IncidentFactory` randomizes classification: any test that needs a lane to FIRE must pin `classification = Incident` (now defaulted in the test helper), or it flakes 50% once a classification gate exists.
- [x] Rule written down: business-rules.md "Reminder emails are incident-only" + dependency-map ReminderMail row.
- [ ] Merge numbering hazard: two sessions appending to lesson-learned.md concurrently burned BUG-035 twice and skipped 037/038 — re-check the file's highest number immediately before appending, and note the pre-merge number in the entry when renumbering.

## BUG-041 — Token edit says "updated successfully" but endpoints/renewal revert (2026-11-19)

**Discovered By:** Owner, editing a token's permissions in the admin ("it says success edit but when i refresh the permission still not saved — need to make new token")
**Severity:** Medium (silent data loss on an auth surface: endpoint scoping + sliding expiry uneditable; workaround = delete + recreate the token)
**Status:** Resolved

### Description
Editing a token's Allowed Endpoints / Auto-Renewal in ApiTokenResource (edit page or the View-page Edit modal) showed "Token updated successfully", but after a refresh the old values rendered. New tokens saved fine — only edits silently no-oped.

### Root Cause
Filament's edit save is mass assignment (`$record->update($data)` — vendor `EditRecord::handleRecordUpdate`, and the View-page `EditAction` uses the same), but Sanctum's parent model whitelists only `$fillable = ['name','token','abilities','expires_at']`. The form also submits `tokenable_id`, `renewal_minutes`, `allowed_endpoints` — Laravel dropped them silently (strict mode off), the toast still fired, and the DB kept the old values. Create never hit the wall because `CreateApiToken` writes the extra columns via `forceFill()`; Regenerate/Re-enable also `forceFill()`. The edit path was the only bare-`update()` write on the model.

### Fix
One line: `protected $fillable` on `App\Models\PersonalAccessToken` — parent's four + `allowed_endpoints`, `renewal_minutes`, `tokenable_id` (explicit list, not `$guarded = []`, so `id`/`disabled_at` stay guarded). Fixes both save surfaces and any future mass-assignment path at the model level. Test: `ApiTokenEditPersistenceTest` pins DB-level persistence of all three fields through the real EditApiToken Livewire page (red before, green after).

### Prevention Checklist
- [x] When a Filament form adds columns to an existing model, check the model's `$fillable` (or the parent's) — a field missing from the whitelist is a SILENT drop: success toast, no exception, DB unchanged. The tell is "create works, edit doesn't" (create paths often `forceFill`, edit paths mass-assign).
- [x] `Model::preventSilentlyDiscardingAttributes()` in non-prod would have turned this into an instant 500 on day one — candidate for bootstrap/app.php strictness (not enabled this round; would need a sweep of existing mass-assignment call sites).
- [x] A "success" notification is only trustable as far as the write it reports; persistence assertions in tests belong at the DB level (`$token->refresh()`), not on the toast.
- [x] Bug class matches edit-safety rule 5 (grep the bare string) and rule 3 (fix the shared path, not one call site): the model whitelist was the ONE gate every token write routes through.
