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
- **Baseline: 1203 tests / 4,744 assertions green (2026-11-12, PROJ-023 agent unification — dashboard control plane + Go engine — +34 tests: `RunAiAgentGoEngineTest` (10 — Go success stores output/tokens/model + one `AiUsageLog` with `metadata.engine=go`, transport failure → run Failed, Static → zero LLM calls + output=input + no usage row, Static delivery POSTs mode `message` + binding, delivery columns filled and a delivery failure does NOT fail the run), `AgentScheduleApiTest` (10 — short_no sequencing, mode→engine mapping message→static/task→go, run_at tz conversion, cap 20 → 422, active-only list, cancel ownership-mismatch 404, invalid cron 422, restricted-token 403/200 with the new `agent-schedules`/`agent-runs` abilities), `AgentTurnReportApiTest` (6 — synthetic chat-turns agent row, usage row, error turns recorded Failed, reporting failures never 5xx), `DispatchDueAgentsTest` +3 (Once fires/disables, future Once doesn't, re-arm after failure while failed runs < 3), `AiAgentScheduleMappingTest` (5 — `resolveScheduleData` once-mapping, run_at cleared on other types, Once preset round-trip, `next_run_at` accessor future/fired/disabled, engine/run_at casts); the 26 pre-existing `RunAiAgentJobTest` cases pass untouched = the Php-path-unchanged proof; Go suite separate, 11 packages green + `-race`; recorded with Docker down so the 7 known reverb env failures fire — excluded per testing-standards), on top of the `updated_since` filter (+3, 2026-11-11) on top of PROJ-019 Notion read-only (2026-11-10) — +11 tests in `NotionReadApiTest` (Laravel `v1/notion` proxy over the existing Notion MCP OAuth + Go-side notion tools with catalog/48k result cap/knowledge drain; Go suite separate, 8 packages green; recorded with Docker down so the 7 known reverb env failures fire — excluded per testing-standards), on top of the incident-list Export group + Notion OAuth connect-failure guards on top of the issue-title date prefix (2026-11-09): imported Notion issues store the title as `"Y M d - {cleaned name}"` — "2026 Nov 09 - timeout to database", owner format — via `NotionIssueMapper::datedTitle()`, the date being the row's own `date_start` (falls back to `createdTime`); every title comparison strips ANY leading date prefix from BOTH sides (`NotionIssueMapper::stripDatePrefix()` — `titleAlreadyExists()`, the in-run set, `findDuplicateByTitle()`/`existingNormalizedTitles()` — the sync's own `Y M d - ` PLUS the record's own `DD Month YYYY - ` style, repeated so stacked pairs strip: the first cut DOUBLED the date on rows whose name already carries "9 November 2026 - ", live row 334) so a re-synced raw name still dedupes against a prefixed stored title (page-ID dedupe stays the primary net; the CSV importer's dedupe sees through prefixed titles too); the composed title carries the date once. Full prior detail (rounds 1–14 in `docs/reference/testing-standards.md`): (1) **the toggle marker is a COLORED traitlet vertically centered against the heading TEXT (round 14, iterated)** — the CSS-border triangle span (`mr-1.5 inline-block h-0! w-0! border-y-4! border-y-transparent! border-l-[6px]! align-middle`) lives INSIDE the heading tag: `vertical-align: middle` centers it against the text itself so it holds at every heading size (the first cut — a span flex-centered in the summary — centered on the line box incl. descender space and rode visibly low on big headings; owner: "should be vertically centered"), the summary is `flex! cursor-pointer` (the `flex!` does double duty — it removes the list-item box Filament's preflight forces on `<summary>`, or every browser paints its NATIVE gray marker next to the indigo one), the triangle is **indigo** (`border-l-indigo-500!`, the panel primary, `Color::Indigo` — reads on light AND dark), rotating `group-open:rotate-90` (needs `class="group"` on the `<details>`); the heading class drops round 13's `inline` and keeps only the indent ladder; **⚠ changing NotionBody's classes REQUIRES `npm run build` — the panel has no viteTheme, it injects the app's Vite build via a render hook, and a stale build means NO CSS for the round classes (round 13 shipped `inline` against an Oct-23 build and never rendered; the root cause of "still not fit")**; **⚠ second trap (round 14): the app build is Tailwind 4 @layer-ed, Filament's own `css/filament/filament/app.css` is UNLAYERED Tailwind 3 preflight — unlayered styles beat layered ones at any specificity, so its `summary{display:list-item}` and universal `*{border-width:0}` silently killed the flex summary and the triangle borders (markers rendered as NOTHING); utilities colliding with a preflight reset carry Tailwind v4's trailing-`!` (`flex!`, `h-0!`, `w-0!`, `border-y-4!`, `border-y-transparent!`, `border-l-[6px]!`, `border-l-indigo-500!`, and the TOC's `list-none! p-0!` vs its prose list rules)**; (2) **the database row's properties fill from the portal (round 13)** — `Incident Category` select = the portal severity verbatim (G / Non Incident → the DB's "N" option; **a severity with no option in the DB is SKIPPED with a note, never an error** — unknown select options would fail the create; live DB options: N, P0–P4, X3), `Entry Date` = the portal record's **created_at** (was `entry_date_tech_risk`), `Incident Date` = the portal form's **"Occurred Time"** (`incident_date`, verified + test-pinned), `Fund Loss` select = **Yes when `fund_status` is set and not `NonFundLoss`**, else No; **select wire format = the plain option-name string (live-probed with a throwaway page)**; (3) **the body content is selectable** (select-none dropped from the details); (4) round 12: the rebuilt TOC is CLICKABLE (`notion-sec-N` positional anchors — pre-substitution MUST capture the counter by reference; arrow fns capture by value), summaries carry REAL heading tags styled by the prose classes, Settings labels "Notion investigation pages" / "Investigation page ID". Round 11: section `<details>` separate wider (`my-5`); **the table of contents is REBUILT from the page's headings** (Notion's TOC is tool-generated; `collectHeadings()` scans raw markdown, ONE combined regex, document order; no headings → nothing written). Round 10: "table content not fully imported" — `inlineAiBlocks()`'s old `?? ''` replaced an UN-GENERATED ai_block's `<blank-page>` envelope with an empty string, deleting the RCA/MOM/Invitation section from every stored body; the placeholder is now KEPT and renders as a muted note ("AI block — not generated in Notion yet. Run it there, then Refresh."); `<file source="notion-file-block://…&name=x.md" />` attachments render as named notes (the name is a query param INSIDE the source URL); **unlink is SOFT on an alive page** — the 5th column `notion_investigation_unlinked_at` (migration now 5 columns, still uncommitted; prod run owner-only) is set, the page id KEPT, the section stays visible with an "Unlinked from Notion" note and the refresh button relabels "Relink investigation"; `refresh()` of a soft-unlinked record IS the relink; `start()` on one CONTINUES the existing page (status not clobbered) and only falls through to a FRESH page when the refresh proves the page deleted; `autoRefresh()` never silently relinks; a flagged-deleted page's unlink stays HARD; lower-hierarchy summary headings indent right (h2 `pl-4`, h3 `pl-8`, h4–h6 `pl-12`, h1 flush); the section footer shows "Synced at Y-m-d H:i" on one line. Prior rounds: Start Investigation in the Incidents row kebab creates a Notion investigation page (independent of the issue sync) under the Settings-configured parent, links it, flips status to In progress — **incident-only**; title `<yyyymmdd> - <title>` with the record title's own leading date prefix stripped; parent TYPE detected by fetching it (page vs database/data-source), a database row created properties-only in DASHED `data_source_id` form + `apply_template` (lands async — the body fetch retries on blank) with the dates/PIC/Category/Fund-Loss filled (PIC = trigger user + portal PICs as Notion user ids); the stored markdown is the FULL `<content>` block RENDERED through `NotionBody::toHtml()` because Filament's HTML sanitizer drops Notion's envelope tags WITH their content — toggles as collapsible `<details open><summary>` sections and **AI blocks inlined at fetch time** (placeholders `<unknown url="…#<block id>" alt="ai_block" />` → fetched generated content); both tables carry a small Notion icon column for linked records; the section footer holds Open in Notion + Refresh/Relink + Unlink (or a single Re-investigate when the page is flagged deleted — restore-from-trash is impossible on the MCP server, live-probed) — and ViewIncident's Post-Mortem PDF + Export to Markdown live behind exactly ONE "Export" dropdown; opening a view page AUTO-REFRESHES a stale copy (>60s) quietly, permission-gated; `IncidentPolicy@view` gates BOTH view pages on `view incidents`; `IncidentFactory` randomizes classification by default — tests touching `start()` must pin it; run the suite on HOST php — inside the app container `localhost:8081` cannot reach reverb and broadcast tests error; full history in `docs/reference/testing-standards.md`).** A change that drops this count or its assertions is a regression, not a refactor — unless the delta is a deliberate feature deletion recorded in the baseline history (`docs/reference/testing-standards.md`).

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
