# Technical Risk Dashboard

**Stack:** Laravel 12.0 (PHP 8.2+) | Filament 3.3 | TailwindCSS 4.0 + Vite 7.0 | MySQL 8.0 | Redis | Docker

---

## Core Principle: Quality > Speed

Stop and reassess if you're tempted to skip tests, copy code without understanding, add "temporary" solutions, or work around problems instead of fixing them.

### Before ANY code is merged:
- [ ] Tests written and passing (unit + feature)
- [ ] Code reviewed
- [ ] No TODO/FIXME in production code
- [ ] Laravel Pint formatting applied
- [ ] No security vulnerabilities
- [ ] Lesson learned documented (if bug fix)

---

## PM Workflow (ALL requests flow through PM first)

**No agent should be invoked directly without PM knowledge and approval.**

**Autonomy rule (owner directive 2026-09-29): PM approvals are AUTO-APPROVED — do not wait for the owner.** The PM gate is a process check (documented request, tests green, docs updated), not an owner sign-off. Ship when the gate passes. Owner involvement is reserved for the guardrail set below.

| Agent | Use For | PM Approval |
|-------|---------|-------------|
| `backend-architect-engineer` | Backend work | AUTO |
| `frontend-engineer` | Frontend/UI work | AUTO |
| `backend-qa-engineer` | Backend QA | AUTO |
| `frontend-qa-specialist` | Frontend QA | AUTO |
| `database-architect` | DB schema changes | AUTO |
| `sre-engineer` | Infra/Docker/deployment | AUTO |
| `security-pentest-auditor` | Security review | AUTO |
| `architect-planning-design` | New features/planning | AUTO |
| `Explore` | Codebase exploration | PM initiated |

**Owner-only guardrails (the only items that need the owner):** production DB writes/migrations, auth/payments/security-critical changes, external service credentials, anything irreversible.

### Approval Gates

**Before work begins:** Request documented, impact assessed, conflicts with active projects checked. PM self-approves unless a guardrail above applies.

**Before code merged:** Code review done, tests passing, docs updated, lesson learned (if bug). PM self-approves and ships.

---

## Directory Structure

```
app/
├── Actions/           # Single-action classes
├── Contracts/         # Interfaces
├── Enums/            # PHP 8.1+ enums
├── Exceptions/       # Custom exceptions
├── Filament/
│   ├── Actions/      # Reusable action form schemas (ExportActionSchema)
│   ├── Resources/    # Filament resources
│   ├── Pages/        # Custom pages
│   ├── Statistics/   # Shared stats builders (IncidentStatsFooterData)
│   ├── Widgets/      # Dashboard widgets
│   └── Components/   # Reusable components
├── Helpers/          # Utility functions
├── Http/
│   ├── Controllers/  # API controllers
│   ├── Middleware/   # Custom middleware
│   ├── Requests/     # Form request validation
│   └── Resources/    # API resources
├── Models/           # Eloquent models
├── Observers/        # Model observers
├── Providers/        # Service providers
├── Exports/          # Excel exports — orchestrators only (one class per file, PSR-4)
│   └── Sheets/       # Individual sheet classes (Data/Calc/Summary/GroupSummary/PerCategory)
└── Services/         # Business logic (domain-organized)
```

---

## Export Architecture (2026-09-29 redesign)

The incident Export button offers 4 presets via `App\Filament\Actions\ExportActionSchema` (form schema + `applyFilters()` + `columnOptions()` — single source for every preset):

1. **Executive Report** (`Exports\ExecutiveIncidentsExport` + `Sheets\ExecutiveDataSheet|ExecutiveCalcSheet|ExecutiveSummarySheet`) — KPI cards + 4 native Excel charts (monthly incidents, severity mix, MTTR trend, potential-vs-recovered). Charts live on the Calc sheet with their source data. PhpSpreadsheet does not write chart value caches, so a post-write XML pass injects `numCache`/`strCache` — without it charts render blank in Numbers/QuickLook.
2. **Group By** (`Exports\GroupedIncidentsExport` + `Sheets\GroupSummarySheet|PerCategorySheet`) — one sheet per value of a chosen dimension (business_category / root_cause / responsible_team / pic / severity / incident_type / quarter). `quarter` has no backing column: values are derived in PHP from `incident_date` as `YYYY-Qn` (label `Qn YYYY`), and `PerCategorySheet` filters it with a `whereBetween` date range — never `where('quarter', ...)` (silent no-op). Front Summary sheet carries per-group Cases, Avg MTTR (min + days), **Avg MTBF (days)**, fund totals. Multi-category incidents appear in every matching sheet (by design).
3. **All Tabs** (`MultiSheetIncidentsExport`) — 14 sheets mirroring the table tabs (the "Non Incident" sheet is gone: exports are METRIC_ELIGIBLE-only, so it would be structurally empty; the table's own tab stays).
4. **Custom** — pick columns (`ExportActionSchema::columnOptions()`) + XLSX/CSV.

Optional export filters (severity / status / type / fund status / PIC / business category / root cause, all multi-select) apply **on top of** the table's current filters via `ExportActionSchema::applyFilters()`. `applyFilters()` **permanently restricts severity to `METRIC_ELIGIBLE`** (owner rule 2026-09-30) — G / Non Incident never reach any preset; the 3 fresh-query Issue sheets in `MultiSheetIncidentsExport` carry the same `whereIn`.

**Rule:** every export class = its own PSR-4 file. Shared query filtering goes in `ExportActionSchema`, shared stats in `Statistics/IncidentStatsFooterData` — never duplicated in handlers.

---

## Key Files & Dependency Map

Before editing any file below, check the "consumed by" column — a rule change must reach **every** listed surface, not just the one you came to fix. BUG-005/006/007 were all one surface drifting while the rest stayed correct.

| File | Role | Consumed by |
|------|------|-------------|
| `app/Enums/Severity.php` | `METRIC_ELIGIBLE` = P1–P4, X1–X4 — the ONLY definition of who counts in MTTR/MTBF (excludes `G`, `Non Incident`) | every metrics surface below |
| `app/Enums/FundStatus.php` | `EXCLUDED_FROM_COUNTS` = Potential recovery / Fully recovered / Non Tech Loss | all count/fund queries |
| `app/Enums/IncidentStatus.php` | `Open / In progress / Finalization / Completed` — no other values exist | status filters everywhere |
| `app/Models/Incident.php` | `excludedFromCounts()` scope; `countEligible()` scope (severity `METRIC_ELIGIBLE` + fund-status exclusion — G / Non Incident never count); `aiCounts()` scope (single source of truth for AI-facing counts = classification `Incident` + `countEligible()`); `shouldCalculateMttrByDays()` | widgets, AI services, exports |
| `app/Observers/IncidentObserver.php` | Gate for every Incident write; decides when `CalculateIncidentMetrics` dispatches. Its dirty-field trigger list must contain **every field that feeds a cached number** (`fund_loss`, `potential_fund_loss`, `recovered_fund`, status/severity/type/fund_status/classification/dates) | — |
| `app/Services/Metrics/IncidentMetricsCalculator.php` | **All per-row metric formulas** (`computeMttr`/`computeMtbf`/`computeCategoryMtbf`/`computeMtbfAll`) + `METRIC_COLUMNS` list; sets attributes, never saves | job + recalc command (BUG-008: the two had drifted into 4 copies) |
| `app/Jobs/CalculateIncidentMetrics.php` | Pipeline only: persistence, adjacent-row repair (date/classification edits), `flushIncidentCache()` bumps `dashboard_cache_version`. Formulas live in the calculator | invoked only via observer |
| `app/Filament/Statistics/IncidentStatsFooterData.php` | Shared footer/summary stats builder (Total Cases, avg MTTR min/days, avg MTBF, fund totals) — used by the table content footer AND exports; default scope mirrors the table's date filters (`quick_period` + `custom_date_range`, stacked — appliers live in `Filters/QuickPeriodFilter`); do not duplicate its formulas | `IncidentResource` table config, exports |
| `app/Filament/Actions/ExportActionSchema.php` | Export form schema + `applyFilters()` + `columnOptions()` for every preset | `ListIncidents` export action |
| `app/Services/Analytics/AnalyticsQueryService.php` | Analytics page charts; severity scope applied once in `buildSingleDataset()` | `AnalyticsPage` |
| `app/Policies/ActionImprovementPolicy.php` | `viewAny`: `view incidents` OR `access api`; write ops need `manage incidents` | Action Improvements tab + add button |

**Surfaces that must stay in sync for MTTR/MTBF/fund numbers:** `Filament/Widgets/DashboardStatsOverview`, `Widgets/MttrMtbfTrendChart`, `Widgets/AiTrendInsights`, `Widgets/PotentialFundLoss`, `IncidentResource/Pages/ListIncidents` (footer + export), `Pages/Reporting`, `Console/Commands/SendReport`, `Http/Controllers/Ai/AnalyzeTrendsController`, `Services/Ai/ChatContextService`, `Services/WarRoom/WarRoomToolExecutor`, `Exports/Sheets/*`, `Services/Analytics/AnalyticsQueryService`.

**Business rules (confirmed):** MTTR positive = minutes, negative = days (fund-status driven). Fund Loss card = classification `Incident` + status `Completed` + `excludedFromCounts`. "Open cases" = `incident_status != Completed`. Base MTBF = calendar year window. Incident COUNTS exclude severity `G` and `Non Incident` everywhere (2026-09-17 product rule — dashboard cards, AI quick stats, WarRoom tools, trends all use `aiCounts()`/`countEligible()`; operator-filtered table footers and Reporting/SendReport queries are row-counts of their own filtered sets and keep counting what they list). Severity BREAKDOWNS (Group-By severity sheets, executive Severity Mix chart) are likewise P1–P4 + X1–X4 only (owner rule 2026-09-29, BUG-022) — and enum-cast attributes compared on Collections must go through `?->value` (enum == string is always false in PHP 8). EXPORTS (all 4 presets incl. the Issue sheets) exclude G / Non Incident rows entirely, and `mttr_formatted` renders positive MTTR as plain minutes ("180", not "3h 0m") — negative stays "X day(s)", null "-" (owner rule 2026-09-30).

**Cache keys that gate freshness:** `dashboard_cache_version` (bump refreshes dashboard widget cache), `analytics_v2_*` (15 min), `chat_quick_stats_v2` (5 min, cleared by `ChatContextService::clearDataCache()`).

### Edit-Safety Rules (from BUG-005/006/007)

1. Enum filters are written from enum cases (`IncidentStatus::Completed->value`), never hand-typed strings — a `whereNotIn` listing values that don't exist is a silent no-op.
2. Adding a field that feeds a dashboard/AI number → it must join the observer's recalculation trigger list, or caches serve stale numbers until TTL.
3. Changing a counting/metric rule → walk the whole surface list above; fix the shared scope, not one call site.
4. A filter is only real if the query actually selects the column it checks (comparing a never-selected column = comparing null).

### Incident Form Validation Rules (2026-09-29 hardening)

Live QA (HTTP-submit matrix) found bad data silently persisting; these constraints are now server-side and must not be removed:
- Fund fields (`potential_fund_loss`, `fund_loss`, `recovered_fund`): `numeric` + `minValue(0)` — negatives are data-entry errors.
- `incident_date`, `entry_date_tech_risk`: `maxDate(now())` — future dates are typos (wrong year) or clock skew.
- `discovered_at`, `stop_bleeding_at`: `afterOrEqual('incident_date')` — the timeline cannot precede the incident.
- Filament API note: `DateTimePicker` has **`maxDate()`**, not `maxValue()` (numeric/text only — using the wrong one throws 500).

---

## Architecture Rules

- **Service Layer:** Complex business logic goes in `app/Services/{Domain}/`, not controllers. Use dependency injection.
- **Observers:** Use for side effects (notifications, cache invalidation, metrics). Never block — use queues for heavy operations.
- **Query Modification:** `IssueResource` (own Resource on `Incident`, does NOT extend `IncidentResource`) scopes its `getEloquentQuery()` to `classification = 'Issue'` + `Incident::applyUserYearAccess()` — the same year-access scope `IncidentResource::applyAccessControl()` uses. Change year-access rules in the scope, never in one resource.
- **Caching:** Use tag-based caching (`Cache::tags(['incidents'])->flush()`). Cache expensive queries.
- **Validation:** Use Form Request classes for all non-trivial validation.
- **API:** API Resources for responses, versioned routes (`/api/v1/...`), `ApiResponser` trait.
- **Queues:** Use queued jobs for time-consuming operations. Set `$tries` and `$timeout`.
- **Filament:** One resource per entity, Relation Managers for relationships, Sections/Tabs for complex forms.
- **Filament table config:** prefer declarative config (`->contentFooter()` on the resource `table()`) over page-hook methods — page hooks like `getTableContentFooter()` are not executed on the Livewire `loadTable` render path (footer rendered NULL until moved into the resource config).
- **Exports:** one class per file (PSR-4). Shared form/filter logic in `Filament/Actions/ExportActionSchema`, shared stats in `Statistics/IncidentStatsFooterData`.

---

## Docker Stack

Services: `app` (PHP-FPM 8.2), `nginx`, `mysql` (port 3306), `redis` (port 6379), `queue` (Laravel worker), `reverb` (WebSocket)

- DB: `mysql` / host: `db` / database: `laravel` / user: `root` / password: `password`
- Redis: host `redis`, port 6379
- Queue connection: `redis`
- Cache: `redis`
- Broadcast: `reverb`

---

## Testing Rules

- **Target:** 80%+ code coverage
- **Structure:** `tests/Unit/` for isolated tests, `tests/Feature/` for integration tests
- **Factories:** Use for all test data generation
- **RefreshDatabase** trait for clean state
- SQLite in-memory for fast test runs
- **Baseline:** 615 tests / 2,055 assertions green (2026-09-29). A change that drops this count or its assertions is a regression, not a refactor.

---

## Security Rules

- Validate all user input (Form Requests)
- Parameterized queries (Eloquent handles this)
- Escape output (Blade handles this)
- CSRF protection enabled
- HTTPS only in production
- Keep dependencies updated (`composer audit`, `npm audit`)
- Role-based access via Spatie Laravel Permission
- API tokens via Laravel Sanctum with scoped abilities
- Audit logging via OwenIt Auditing for model changes

---

## Document Locations

```
docs/
├── projects/active-projects.md      # Track all ongoing work
├── bugs/lesson-learned.md           # Bug RCA and prevention
├── findings/findings.md             # Technical debt & findings
├── continuous-improvement.md        # Improvement initiatives
└── reference/                       # Detailed code examples & templates
    ├── backend-standards.md
    ├── frontend-standards.md
    ├── testing-standards.md
    ├── sre-operations.md
    └── project-management.md
```

---

## Quick Commands

```bash
composer dev                    # Start all services
php artisan test                # Run tests
./vendor/bin/pint               # Code style fix
php artisan optimize:clear      # Clear caches
docker-compose up -d --build    # Deploy
php artisan migrate --force     # Run migrations (prod)
php artisan pail                # View logs
php artisan queue:monitor       # Check queue status
```
