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

## Summary Statistics

| Metric | Count |
|--------|-------|
| Total Bugs | 5 |
| Critical | 0 |
| High | 4 |
| Medium | 0 |
| Low | 0 |
| Resolved | 5 |
| Open | 0 |

### Bug Trends by Component
| Component | Count |
|-----------|-------|
| Model | 0 |
| Controller | 0 |
| Filament Resource | 3 |
| API Endpoint | 0 |
| Database/Migration | 0 |
| Frontend/CSS | 0 |
| Queue/Job | 1 |
| Other | 1 |

---

*Last Updated: 2026-08-19*
