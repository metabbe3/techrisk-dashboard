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

---

## Summary Statistics

| Metric | Count |
|--------|-------|
| Total Bugs | 2 |
| Critical | 0 |
| High | 2 |
| Medium | 0 |
| Low | 0 |
| Resolved | 2 |
| Open | 0 |

### Bug Trends by Component
| Component | Count |
|-----------|-------|
| Model | 0 |
| Controller | 0 |
| Filament Resource | 2 |
| API Endpoint | 0 |
| Database/Migration | 0 |
| Frontend/CSS | 0 |
| Queue/Job | 0 |
| Other | 0 |

---

*Last Updated: 2026-02-02*
