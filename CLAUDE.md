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

All specialist agents are AUTO-approved: `backend-architect-engineer`, `frontend-engineer`, `backend-qa-engineer`, `frontend-qa-specialist`, `database-architect`, `sre-engineer`, `security-pentest-auditor`, `architect-planning-design`, `Explore` (PM initiated).

**Owner-only guardrails (the only items that need the owner):** production DB writes/migrations, auth/payments/security-critical changes, external service credentials, anything irreversible.

**Gates:** before work — request documented, impact assessed, conflicts with active projects checked (PM self-approves). Before merge — code review done, tests passing, docs updated, lesson learned if bug (PM self-approves and ships).

---

## Directory Structure

```
app/
├── Actions/           # Single-action classes
├── Contracts/         # Interfaces
├── Enums/            # PHP 8.1+ enums
├── Exceptions/       # Custom exceptions
├── Filament/
│   ├── Actions/      # Reusable action form schemas (ExportActionSchema, ActionImprovementFormSchema)
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

## Reference Docs — READ BEFORE TOUCHING THESE AREAS

| Doc | Read when |
|-----|-----------|
| [`docs/reference/dependency-map.md`](docs/reference/dependency-map.md) | editing ANY file listed in the map. Check its "consumed by" column — a rule change must reach every listed surface. **Adding/changing a metric file → update the map in the same commit.** Also lists the MTTR/MTBF/fund surfaces that must stay in sync. BUG-005/006/007 were one surface drifting while the rest stayed correct. |
| [`docs/reference/exports.md`](docs/reference/exports.md) | touching any export preset, sheet, or export filter (5 presets + markdown ZIP, membership rules, Rupiah formatting, MTBF sequence semantics) |
| [`docs/reference/business-rules.md`](docs/reference/business-rules.md) | touching metrics/counts/funds/outliers, action improvements, cache keys, or the incident form validation |
| [`docs/reference/testing-standards.md`](docs/reference/testing-standards.md) | writing tests; full test-baseline history |

---

## Core Business Rules (full detail in `docs/reference/business-rules.md`)

- Counts exclude severity `G` / `Non Incident` everywhere (`aiCounts()` / `countEligible()`); exports exclude them entirely; severity breakdowns are P1–P4 + X1–X4 only.
- MTTR: positive = minutes, negative = elapsed calendar days, **no `+1`** (owner 2026-10-13). Fund Loss card = classification `Incident` + `Completed` + `excludedFromCounts`.
- `Outlier` label (owner 2026-10-01): out of every MTBF/MTTR *average*, stays in every *count*; tagging fires recalc via the `IncidentLabel` pivot, NOT the observer; its own cells render `Outlier`.
- Action Improvements: reminders/escalation/overdue gate on `status = 'pending'` only; `due_date = null` = TBD — never invent dates; TBD rows are tracked (filter + widget count) but never chased.
- Every version-keyed cache must include `dashboard_cache_version` in its key payload.
- Incident form: fund fields `numeric min:0`; dates never future; timeline fields `afterOrEqual('incident_date')`; `DateTimePicker` has `maxDate()`, not `maxValue()`.

### Edit-Safety Rules (from BUG-005/006/007)

1. Enum filters are written from enum cases (`IncidentStatus::Completed->value`), never hand-typed strings — a `whereNotIn` listing values that don't exist is a silent no-op.
2. Adding a field that feeds a dashboard/AI number → it must join the observer's recalculation trigger list, or caches serve stale numbers until TTL.
3. Changing a counting/metric rule → walk the whole surface list in the dependency map; fix the shared scope, not one call site.
4. A filter is only real if the query actually selects the column it checks (comparing a never-selected column = comparing null).
5. After removing a relation/column, grep for its bare name as a **string** too — `'pic'`, `'pic.name'`, `with('incident.pic')`, column-key maps in forms/exports. Arrow-syntax greps (`->pic`) miss eager-load strings and array keys: PROJ-010's final review caught two shipped-green runtime breaks (a daily reminder command + the Reporting page) that were exactly this.
6. Never `clone()` a builder that already grouped — the clone carries the `GROUP BY` into the new query (per-group rows, not table-wide). PROJ-014: the widget's aging/closeout stats cloned the status-count builder → aging returned the first group's numbers (overdue ≈ 0 in prod) and closeout hydration crashed on a null `completed_at`. Use a fresh builder per stat. Related: a typed-`bool` closure over `auth()->user()?->can(...)` returns **null**, not false, when nobody is logged in — wrap with `(bool)` or tests hit a TypeError on anonymous renders.

---

## Architecture Rules

- **Service Layer:** Complex business logic goes in `app/Services/{Domain}/`, not controllers. Use dependency injection.
- **Observers:** Use for side effects (notifications, cache invalidation, metrics). Never block — use queues for heavy operations. **One owner-approved exception:** the Incident closure gate in `IncidentObserver::saving()` (PROJ-011 Wave 2, RD-2) throws `ValidationException` — data-quality enforcement at the single point every write routes through; no other observer may throw.
- **Query Modification:** `IssueResource` (own Resource on `Incident`, does NOT extend `IncidentResource`) scopes its `getEloquentQuery()` to `classification = 'Issue'` + `Incident::applyUserYearAccess()` — the same year-access scope `IncidentResource::applyAccessControl()` uses. Change year-access rules in the scope, never in one resource.
- **Caching:** Use tag-based caching (`Cache::tags(['incidents'])->flush()`). Cache expensive queries.
- **Validation:** Use Form Request classes for all non-trivial validation.
- **API:** API Resources for responses, versioned routes (`/api/v1/...`), `ApiResponser` trait.
- **Queues:** Use queued jobs for time-consuming operations. Set `$tries` and `$timeout`.
- **Filament:** One resource per entity, Relation Managers for relationships, Sections/Tabs for complex forms.
- **Filament table config:** prefer declarative config (`->contentFooter()` on the resource `table()`) over page-hook methods — page hooks like `getTableContentFooter()` are not executed on the Livewire `loadTable` render path (footer rendered NULL until moved into the resource config).
- **Exports:** one class per file (PSR-4). Shared form/filter logic in `Filament/Actions/ExportActionSchema`, shared stats in `Statistics/IncidentStatsFooterData`, shared action-improvement form in `Filament/Actions/ActionImprovementFormSchema`.

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
- **Baseline: 1020 tests / 3,688 assertions green (2026-10-30, two-step full-sync flow round: "Sync now" opens step 1 — a Full-sync checkbox whose submit reads "Sync", flipping to "Full sync" when ticked; clicking it reads Notion and turns the same modal into the restore picker — Select All included — where the submit is "Import", greyed out until at least one issue is selected — the picker's CheckboxList must be `live()`, or ticks never re-render the footer and Import stays grey (prod 2026-10-30); cancel/X close both steps; the dry-run Preview was deleted from the UI — the picker list IS the preview, `notion:sync-issues --dry-run` keeps the service-level dry run; full history in `docs/reference/testing-standards.md`).** A change that drops this count or its assertions is a regression, not a refactor — unless the delta is a deliberate feature deletion recorded in the baseline history (`docs/reference/testing-standards.md`).

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
    ├── testing-standards.md         # + test baseline history
    ├── sre-operations.md
    ├── project-management.md
    ├── dependency-map.md            # Key files & consumed-by surfaces
    ├── exports.md                   # Export preset architecture
    └── business-rules.md            # Metrics/counts/outlier/TBD/cache/validation rules
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
