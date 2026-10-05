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

---

## Summary Statistics

| Metric | Count |
|--------|-------|
| Total Bugs | 25 |
| Critical | 0 |
| High | 17 |
| Medium | 6 |
| Low | 0 |
| Resolved | 24 |
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

*Last Updated: 2026-10-05*
