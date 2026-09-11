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

## Summary Statistics

| Metric | Count |
|--------|-------|
| Total Bugs | 11 |
| Critical | 0 |
| High | 8 |
| Medium | 2 |
| Low | 0 |
| Resolved | 11 |
| Open | 0 |

### Bug Trends by Component
| Component | Count |
|-----------|-------|
| Model | 0 |
| Controller | 0 |
| Filament Resource | 4 |
| API Endpoint | 0 |
| Database/Migration | 0 |
| Frontend/CSS | 0 |
| Queue/Job | 2 |
| Other | 4 |

---

*Last Updated: 2026-09-11*
