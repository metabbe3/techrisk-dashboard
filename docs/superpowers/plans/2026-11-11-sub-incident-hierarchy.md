# Sub-Incident Hierarchy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One-level incident hierarchy — a super-incident links full sub-incident records; dashboard incident-count metrics roll up into the super while fund-loss money keeps counting subs; the list hides subs unless expanded; exports exclude subs entirely.

**Architecture:** Nullable `parent_incident_id` self-FK on `incidents`. Exclusion lives in the counting scopes (`countEligible()`/`aiCounts()` gain a `$withSubIncidents = false` flag) plus one new `withoutSubIncidents()` scope composed by every hand-rolled count/metric surface; money surfaces opt back in explicitly. List behavior rides `ListIncidents::getFilteredTableQuery()` — subs excluded only in the untouched default view.

**Tech Stack:** Laravel 12, Filament 3.3, MySQL 8 (tests: SQLite in-memory), Redis cache.

**Spec:** `docs/superpowers/specs/2026-11-11-sub-incident-hierarchy-design.md` — read it first; this plan argues from it.

## Global Constraints

- Roll-up: subs excluded from every incident-count metric — Total Incidents, severity/status/dimension charts, weekly counts, MTTR/MTBF averages and chains (spec decided 1). The super is the counted row.
- Money stays with subs: Fund Loss tile, `PotentialFundLoss`, `FundLossTrendChart`, `RootCauseFundParetoChart`, chat fund sums (spec decided 2). Never add `whereNull('parent_incident_id')` to a money query.
- Incidents only; Issues untouched (decided 3). One level deep (decided 4).
- List: subs hidden in the untouched default view, surfaced by search/filter/tab/drill-in (decided 5–6).
- Exports (5 presets + markdown ZIP) exclude subs entirely; the AI corpus (`baseExportScope` consumers in `IncidentMarkdownCorpusService`) keeps subs (decided 7 + spec technical notes).
- Reporting page + SendReport mirror the dashboard: listings and counts exclude subs (decided 9). Reporting has no money aggregates — its money is per-row columns on listed rows, so sub money is absent there; that is the faithful reading (recorded as PM call).
- Permission gate = the house pattern `auth()->user()->can('manage incidents')` (`IncidentPolicy::update`, `IncidentResource.php:602-605` style). The spec's "no new permission" holds; its literal `update incidents` string does not exist — PM call, recorded.
- `parent_incident_id` joins the observer dirty-field trigger list (`IncidentObserver.php:136-139`) → `CalculateIncidentMetrics` → `flushIncidentCache()` bumps `dashboard_cache_version`. No other flush path.
- Observers never throw (the closure gate is the sole exception). Classification guard lives in form rules + actions, not the observer.
- Subs keep their own per-row MTTR/MTBF values — no outlier-style nulling of their columns.
- Enum values only from enum cases (`IncidentClassification::Incident->value`), never hand-typed strings (edit-safety rule 1).
- Never `clone()` a grouped builder (edit-safety rule 6). Fresh builder per stat.
- Test baseline **1166 tests / 4,583 assertions green (2026-11-10)** must not drop — a drop is a regression. Run the suite on HOST php: `php -d memory_limit=1G vendor/bin/phpunit` (known reverb env failures excluded per `docs/reference/testing-standards.md`).
- Laravel Pint on every touched file. `docs/reference/dependency-map.md` gains its row in the same commit that adds the field (Task 1).
- Branch: all tasks land on `feature/sub-incident-hierarchy`, created in Task 1. Commit messages end with `Co-Authored-By: Claude Code <noreply@anthropic.com>`.

## Review Focus

Failure modes the spec implies but no single task's happy path exercises — each is pinned by the named test:

1. **Sub money vanishing from a money surface** — a count-surface edit leaking into `FundLossTrendChart`/`RootCauseFundParetoChart`/chat fund sums would silently delete real losses from dashboards. Pinned by Task 2's `SubIncidentMoneyKeepsSubsTest`.
2. **A sub being unfindable** — search, filters, non-default tabs, and `?ids=` drill-in must all surface subs. Pinned by Task 6's `SubIncidentListSurfacingTest`.
3. **Stale dashboard numbers after link/unlink** — the observer trigger must fire on `parent_incident_id` so the cache version bumps. Pinned by Task 1's dispatch test.
4. **Cascade loss on super deletion** — deleting a super must orphan subs, never delete them. Pinned by Task 1's FK test.
5. **Family ordering fighting a user's column sort** — the nested-row ordering may apply only when the table's default sort is in effect. Pinned by Task 6's ordering test.

---

### Task 1: Schema, model relations, scopes, observer trigger

**Files:**
- Create: `database/migrations/2026_11_11_000001_add_parent_incident_id_to_incidents_table.php`
- Modify: `app/Models/Incident.php` (fillable list ends `:118`; scopes `:169-209`)
- Modify: `app/Observers/IncidentObserver.php:136-139`
- Modify: `database/factories/IncidentFactory.php`
- Create: `tests/Feature/SubIncidentHierarchyTest.php`

**Interfaces (produced, consumed by every later task):**
- `Incident::superIncident(): BelongsTo` — `belongsTo(self::class, 'parent_incident_id')`.
- `Incident::subIncidents(): HasMany` — `hasMany(self::class, 'parent_incident_id')->orderBy('incident_date')->orderBy('id')` (ordering lives on the relation so every eager load gets it free).
- `Incident::hasIncidentLinks(): bool` — parent set OR children exist.
- `Incident::scopeWithoutSubIncidents(Builder $query): void` — `whereNull('parent_incident_id')`.
- `Incident::scopeCountEligible(Builder $query, bool $withSubIncidents = false): void` — existing body, plus `whereNull('parent_incident_id')` unless `$withSubIncidents`. Update the docblock (`:175-179`) with the roll-up rule.
- `Incident::scopeAiCounts(Builder $query, bool $withSubIncidents = false): void` — passes the flag through to `countEligible`.
- Factory state: `subOf(Incident $super): self` — `->state(fn () => ['parent_incident_id' => $super->id])`.
- `$fillable` gains `'parent_incident_id'`.

- [ ] **Step 1: Create branch and write the failing test**

`git checkout -b feature/sub-incident-hierarchy`

```php
// tests/Feature/SubIncidentHierarchyTest.php
use App\Enums\IncidentClassification;
use App\Models\Incident;
use App\Jobs\CalculateIncidentMetrics;
use Illuminate\Support\Facades\Bus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class); // PHPUnit: use trait + extends Tests\TestCase per house style

$incidentClass = IncidentClassification::Incident->value; // pin classification — factory randomizes it

it('links sub-incidents to a super and reports link state', function () {
    $super = Incident::factory()->create(['classification' => $incidentClass]);
    $sub = Incident::factory()->subOf($super)->create(['classification' => $incidentClass]);
    $standalone = Incident::factory()->create(['classification' => $incidentClass]);

    expect($super->subIncidents->pluck('id'))->toContain($sub->id)
        ->and($sub->superIncident->is($super))->toBeTrue()
        ->and($super->hasIncidentLinks())->toBeTrue()
        ->and($sub->hasIncidentLinks())->toBeTrue()
        ->and($standalone->hasIncidentLinks())->toBeFalse();
});

it('excludes sub-incidents from count scopes unless flagged', function () {
    $super = Incident::factory()->create(['classification' => $incidentClass, 'severity' => 'P1']);
    Incident::factory()->subOf($super)->create(['classification' => $incidentClass, 'severity' => 'P1']);

    expect(Incident::query()->countEligible()->count())->toBe(1)
        ->and(Incident::query()->aiCounts()->count())->toBe(1)
        ->and(Incident::query()->countEligible(withSubIncidents: true)->count())->toBe(2)
        ->and(Incident::query()->aiCounts(withSubIncidents: true)->count())->toBe(2)
        ->and(Incident::query()->withoutSubIncidents()->count())->toBe(1);
});

it('orphans subs when the super is deleted', function () {
    $super = Incident::factory()->create();
    $sub = Incident::factory()->subOf($super)->create();

    $super->delete();

    expect($sub->fresh()->parent_incident_id)->toBeNull();
});

it('dispatches metric recalculation when the link changes', function () {
    Bus::fake([CalculateIncidentMetrics::class]);
    $super = Incident::factory()->create();
    $sub = Incident::factory()->create();

    $sub->update(['parent_incident_id' => $super->id]);

    Bus::assertPushed(CalculateIncidentMetrics::class); // link/unlink must flush — see job ctor for the closure key
});
```

Severity strings above are illustrative — write them from `Severity` enum cases (`Severity::P1->value`), never literals.

- [ ] **Step 2: Run to verify failure**

Run: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/SubIncidentHierarchyTest.php`
Expected: FAIL (unknown column `parent_incident_id`, missing factory state / methods).

- [ ] **Step 3: Implement**

Migration (new file, date-prefixed `2026_11_11_000001_`):

```php
Schema::table('incidents', function (Blueprint $table) {
    $table->foreignId('parent_incident_id')->nullable()->after('classification')
        ->constrained('incidents')->nullOnDelete();
    $table->index('parent_incident_id');
});
```

`down()` drops the index + column. Model: add `'parent_incident_id'` to `$fillable`; add the three methods and two scope signatures exactly as specified in Interfaces; place the new scopes next to `scopeCountEligible` (`Incident.php:181`). Observer: append `|| $incident->isDirty('parent_incident_id')` to the `$needsCategoryRecalculation` chain at `IncidentObserver.php:136-139`. Factory: add the `subOf` state.

- [ ] **Step 4: Run to verify pass** — same command. Expected: PASS.

- [ ] **Step 5: Full suite + dependency-map row + commit**

Run the full suite (`php -d memory_limit=1G vendor/bin/phpunit`). Baseline must hold. Add the dependency-map row for `parent_incident_id` + the new scopes (role: roll-up exclusion; consumed by: every metric surface — reference the map's sync list) in THIS commit.

```bash
git add -A && git commit -m "feat(incidents): parent_incident_id — sub-incident link, roll-up scopes, observer trigger" -m "Co-Authored-By: Claude Code <noreply@anthropic.com>"
```

---

### Task 2: Money surfaces keep counting subs (opt-in flag)

**Files:**
- Modify: `app/Filament/Widgets/FundLossTrendChart.php:42`
- Modify: `app/Filament/Widgets/RootCauseFundParetoChart.php:77`
- Modify: `app/Services/Ai/ChatContextService.php:2030-2035`
- Create: `tests/Feature/SubIncidentMoneyKeepsSubsTest.php`

**Interfaces:** consumes Task 1's `aiCounts(withSubIncidents: true)`. Produces: no new API — this task only re-points money call sites.

- [ ] **Step 1: Write the failing test**

Seed: super (P1, `fund_status = null`) + sub via `subOf()` (`fund_status = FundStatus::ConfirmedLoss->value`, `fund_loss = 100`, `incident_status` Completed). Scenario asserts, per surface: the COUNT excludes the sub and the MONEY includes its 100.

```php
it('keeps sub money in the fund trend and pareto while counts roll up', function () {
    // seed as above
    // FundLossTrendChart: Livewire::test(FundLossTrendChart::class) — assert rendered total contains 100
    // RootCauseFundParetoChart: Livewire::test(...) — assert the sub's root-cause bucket shows 100
    // DashboardStatsOverview: Livewire::test(...) — assert the total-incidents card reflects 1, not 2
});
```

Write the three assertions against the widgets' actual rendered markup (regex per `OutlierLiveTableTest.php:45-63` house pattern). Exact expected numbers: trend sum `100`, pareto bucket `100`, dashboard total `1`.

- [ ] **Step 2: Run — expect FAIL** (trend/pareto currently compose `aiCounts()`; after Task 1 they exclude subs → 0 money).

- [ ] **Step 3: Implement** — change `->aiCounts()` to `->aiCounts(withSubIncidents: true)` at `FundLossTrendChart.php:42` and `RootCauseFundParetoChart.php:77`. In `ChatContextService.php:2030-2035`, split the single builder: the count keeps plain `aiCounts()`; the `sum('fund_loss')` moves to a fresh `aiCounts(withSubIncidents: true)` builder (never `clone()` a grouped builder — build it fresh). Leave `DashboardStatsOverview:61/66/74` and `PotentialFundLoss` untouched — they are already correct by Task 1.

- [ ] **Step 4: Run — PASS.** Also re-run `tests/Feature/SubIncidentHierarchyTest.php` (funnel semantics unchanged).

- [ ] **Step 5: Commit** — `feat(metrics): fund money surfaces opt into sub-incidents`

---

### Task 3: Metric math — averages and chains exclude subs

**Files:**
- Modify (add `->withoutSubIncidents()` beside every `->withoutOutliers()`): `app/Services/Metrics/IncidentMetricsCalculator.php:91,152,178,216`; `app/Jobs/CalculateIncidentMetrics.php:85,120`; `app/Filament/Widgets/DashboardStatsOverview.php:116,133`; `app/Filament/Widgets/IncidentStatsOverview.php:62,73`; `app/Filament/Widgets/AiTrendInsights.php:192`; `app/Filament/Statistics/IncidentStatsFooterData.php:32`; `app/Filament/Widgets/MttrMtbfTrendChart.php:70,90`; `app/Filament/Pages/Reporting.php:278`; `app/Http/Controllers/Ai/AnalyzeTrendsController.php:111`; `app/Services/Analytics/AnalyticsQueryService.php:99`
- Modify: `app/Filament/Pages/Reporting.php:244` (listing base query — decided 9); `app/Console/Commands/SendReport.php:47` (listing), `:84-85` (collection-side filter gains `&& $i->parent_incident_id === null`)
- Modify: `app/Services/WeeklyDataService.php:24-29` (weekly counts — add `->withoutSubIncidents()`)
- Create: `tests/Feature/SubIncidentMetricMathTest.php`

**Interfaces:** consumes `withoutSubIncidents()`. Do NOT touch `IncidentResource.php:330` (Incidents MTBF tab — covered by Task 6's query override) or `IssueResource.php:151` (subs are classification-Incident; a no-op there).

- [ ] **Step 1: Write the failing tests**

Test A (footer averages): seed super (P1, `mttr = 10`, Completed, `fund_status = null`) + sub (`mttr = 100`, same eligibility, `fund_loss = 50` on the sub). Call `app(IncidentStatsFooterData::class)->build()` — assert avg MTTR `10` (sub excluded) AND fund total includes `50` (footer money untouched). Variant: attach the `Outlier` label to the SUB — the average stays `10` (sub-exclusion wins over Outlier's "stays in every count"; the sub is excluded by parentage regardless of outlier status).

Test B (chain telescoping): three rows same category — A (`incident_date` d1), sub of A (d2), B (d3). Run the metrics pipeline for B (`(new CalculateIncidentMetrics(...))->handle()` or the house job-invocation pattern — check the job ctor). Assert B's stored MTBF spans d1→d3 (the sub telescoped out), while the sub's own `mttr`/`mtbf` columns still hold computed values (no outlier-style nulling).

Test C (Reporting/SendReport mirror): with the same seed, the Reporting page listing must not contain the sub (`Livewire::test(Reporting::class)->assertDontSee($sub->title)` after seeding auth per `OutlierLiveTableTest`), and `WeeklyDataService::getWeeklyData()` weekly `total` excludes the sub.

- [ ] **Step 2: Run — expect FAIL** (averages currently include the sub's rows; the chain consumes the sub).

- [ ] **Step 3: Implement** — the one-line appends listed above. Reporting `:273-275` (`avg_mttr`, which composes no `withoutOutliers` today) gains `->withoutSubIncidents()` too — subs must leave that average without touching its outlier semantics.

- [ ] **Step 4: Run — PASS** (full metric test files that already exist must stay green — this task must not shift any existing number when no subs exist).

- [ ] **Step 5: Commit** — `feat(metrics): sub-incidents leave averages, chains, Reporting/SendReport and weekly counts`

---

### Task 4: Hand-rolled count surfaces (dimension charts)

**Files:**
- Modify: `app/Filament/Widgets/IncidentsByTypeChart.php:37`, `IncidentsByPicChart.php:40`, `IncidentsBySeverityChart.php:65`, `IncidentsByLabelChart.php:38`, `MonthlyIncidentsChart.php:42`, `RiskHeatMatrixWidget.php:58`
- Create: `tests/Feature/SubIncidentCountSurfacesTest.php`

**Interfaces:** consumes `withoutSubIncidents()`. All six are count surfaces (spec decided 1) composing hand-rolled `excludedFromCounts()` chains — none is money.

- [ ] **Step 1: Write the failing test**

Seed super + sub (both P1). One test per widget: `Livewire::test(...)` and assert the sub is not reflected (exact expected numbers: P1 bucket `1`, monthly count `1`, heat-matrix cell `1`, label/pic/type buckets exclude the sub — assert per each widget's rendered data, regex per house pattern). One scenario, six assertions.

- [ ] **Step 2: Run — expect FAIL** (charts count the sub today).

- [ ] **Step 3: Implement** — append `->withoutSubIncidents()` to each of the six chains. No other change.

- [ ] **Step 4: Run — PASS.**

- [ ] **Step 5: Commit** — `feat(dashboard): dimension charts roll sub-incidents into their supers`

---

### Task 5: Exports and ZIP exclude subs; corpus keeps them

**Files:**
- Modify: `app/Filament/Actions/ExportActionSchema.php` — `baselineQuery()` (`:213-218`) and `applyFilters()` (`:223-252`) each gain `->withoutSubIncidents()` on the returned query
- Modify: `app/Filament/Resources/IncidentResource/Pages/ListIncidents.php:139-140` (markdown ZIP action — `->withoutSubIncidents()` on the passed query)
- Modify: `app/Filament/Resources/IncidentResource/Pages/ListIncidents.php:62` — covered via `baselineQuery`; verify the custom preset's `$query` (`:100-129`) also flows through `applyFilters()`; if any preset receives a raw query that bypasses both, append `->withoutSubIncidents()` at that source
- Create: `tests/Feature/SubIncidentExportExclusionTest.php`

**Interfaces:** consumes `withoutSubIncidents()`. NOT touched: `baseExportScope()` (`:185-188`) — `IncidentMarkdownCorpusService.php:39` shares it and the corpus keeps subs (spec technical notes).

- [ ] **Step 1: Write the failing test**

Seed super + sub (metric-eligible, baseline-eligible fund statuses). Assert: `ExportActionSchema::baselineQuery(Incident::query(), [])->count()` = 1 (sub out); with `applyFilters()` applied = 1; the ZIP build (house `IncidentMarkdownZipService` test pattern) contains no folder for the sub; `IncidentMarkdownCorpusService` `catalog()`/`scopedQuery()` still INCLUDES the sub (pin the asymmetry — corpus is the knowledge base).

- [ ] **Step 2: Run — expect FAIL** (sub present in baseline/ZIP).

- [ ] **Step 3: Implement** — the two `ExportActionSchema` appends + the ZIP append + any bypass source found in Step 1's read of `:100-129`.

- [ ] **Step 4: Run — PASS** — including the existing `ExportWidgetBaselineTest` (numbers must not shift when no subs exist).

- [ ] **Step 5: Commit** — `feat(exports): sub-incidents excluded from Excel presets and markdown ZIP, kept in AI corpus`

---

### Task 6: Incident list — hidden unless expanded, surfaced by filters

**Files:**
- Modify: `app/Filament/Resources/IncidentResource/Pages/ListIncidents.php` — override `getFilteredTableQuery()`; add `public array $expandedSubIncidents = []`; add `toggleExpandedSubIncidents(int $id): void`; add private `subIncidentsSurfaced(): bool`; add `unlinkSubIncident(int $id): void` (used by Task 7's panel rows — declare here)
- Modify: `app/Filament/Resources/IncidentResource.php` table() `:295-683` — third `modifyQueryUsing` (`->withCount('subIncidents')`); extend the eager-load line `:298-299` with `'superIncident:id,no,title'`; chevron column (position 0, before `no`); sub-count badge column; title-column "Sub of" description for subs
- Create: `tests/Feature/SubIncidentListSurfacingTest.php`

**Interfaces:**
- `ListIncidents::subIncidentsSurfaced(): bool` — TRUE (subs render as normal rows) when: `filled($this->tableSearch)` OR `filled($this->tableFilters)` OR `$this->activeTab` ≠ the 'All Cases' tab key (read `getTabs()` `:216-243`, pin the literal) OR the `?ids=` drill-in param is present (see `getTableQuery()` `:194-205`) OR the AI-search state used by `applyAiSearch()` (`:245-364`) is active. FALSE only in the untouched default view. Known trap: `QuickPeriodFilter`'s default `'year'` (`QuickPeriodFilter.php:18`) must NOT populate `$this->tableFilters` — verify; if defaults ever pre-populate the prop, compare against default states instead.
- `ListIncidents::unlinkSubIncident(int $id): void` — `auth()->user()->can('manage incidents')` guard (abort 403), `Incident::findOrFail`, `->update(['parent_incident_id' => null])`. Task 7 wires the UI calls.
- Expansion state: `$this->expandedSubIncidents` (public Livewire array; stale ids are harmless).

- [ ] **Step 1: Write the failing test**

```php
it('hides subs by default, surfaces them on search, nests them when expanded', function () {
    // seed super + sub (sub title distinct)
    Livewire::actingAs($viewer) // permission seeding per OutlierLiveTableTest.php:45-63
        ->test(ListIncidents::class)
        ->assertDontSee($sub->title)                      // hidden by default
        ->callAction('expand_sub_incidents', $super)      // chevron toggle
        ->assertSee($sub->title)                          // nested row appears
        ->set('tableSearch', $sub->title)
        ->assertSee($sub->title);                         // surfaced by search even collapsed
});

it('orders children under the parent only under the default sort', function () {
    // expanded + no explicit sort: sub row's DOM position directly follows the super row
    // expanded + explicit column sort: both visible (adjacency not asserted)
});

it('keeps pagination totals free of hidden subs', function () {
    // default view: page reports the top-level count only
});
```

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Implement**

`getFilteredTableQuery()` override:

```php
public function getFilteredTableQuery(): Builder
{
    $query = parent::getFilteredTableQuery();

    if ($this->subIncidentsSurfaced()) {
        return $query;
    }

    return $query->where(fn (Builder $q) => $q->whereNull('parent_incident_id')
        ->when($this->expandedSubIncidents !== [], fn (Builder $q) => $q->orWhereIn('parent_incident_id', $this->expandedSubIncidents)));
}
```

Family adjacency (Review Focus 5): in the same override, when NOT surfaced AND expanded AND `$this->tableSortColumn === null` (default sort in effect), prepend the family order so children land under the parent: `orderByRaw('COALESCE((SELECT s.incident_date FROM incidents s WHERE s.id = incidents.parent_incident_id), incidents.incident_date) DESC, parent_incident_id IS NULL DESC, incident_date DESC')`. MySQL + SQLite both accept this. When the user picked a sort, skip it (children stay visible, adjacency not guaranteed).

Table config: chevron `IconColumn::make('expand_sub_incidents')` — `->icon(fn (Incident $record) => in_array($record->getKey(), $this->expandedSubIncidents) ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right')`, `->hidden(fn (Incident $record) => $record->sub_incidents_count === 0)`, `->action(fn (Incident $record) => $this->toggleExpandedSubIncidents((int) $record->getKey()))`. Badge: `TextColumn::make('sub_incidents_count')->badge()` with the same hidden closure. Sub rows (wherever they render): the `title` column gains `->description(fn (Incident $record) => $record->parent_incident_id ? "Sub of: {$record->superIncident?->no}" : null)`.

- [ ] **Step 4: Run — PASS** + existing list-page tests (tabs, footers, exports) green.

- [ ] **Step 5: Commit** — `feat(incidents): expandable super-incident rows — subs hidden unless expanded or surfaced`

---

### Task 7: Create-sub, link-existing, unlink actions

**Files:**
- Modify: `app/Filament/Resources/IncidentResource.php` — form schema: `Hidden::make('parent_incident_id')->dehydrated()` (+ a `Placeholder` hint visible when a parent is being set, "Recording as a sub-incident of {super}")
- Modify: `app/Filament/Resources/IncidentResource/Pages/CreateIncident.php` — accept a `parent` mount/route parameter into the hidden field
- Modify: `app/Filament/Resources/IncidentResource.php` row actions (ActionGroup `:606-661`): `create_sub_incident` (URL to `CreateIncident` with `parent` param), `link_sub_incident` (modal, searchable Select), `unlink_sub_incident` (surfaced sub rows only — `->visible(fn (Incident $record) => filled($record->parent_incident_id))`, `->action(fn (Incident $record) => $record->update(['parent_incident_id' => null]))`)
- Modify: `app/Filament/Resources/IncidentResource/Pages/ListIncidents.php` panel rows (from Task 6's rendered children): each child row renders an open link (to `ViewIncident`) and an unlink button calling `unlinkSubIncident()` via `wire:click` + `wire:confirm`
- Modify: `app/Filament/Resources/IncidentResource/Pages/ViewIncident.php` header actions: same `link_sub_incident` + `create_sub_incident` entries for the record
- Create: `tests/Feature/SubIncidentLinkActionsTest.php`

**Interfaces:**
- Link picker options query (shared by the modal on list + view): `Incident::query()->where('classification', IncidentClassification::Incident->value)->whereNull('parent_incident_id')->whereDoesntHave('subIncidents')->when($record, fn ($q) => $q->whereNot('id', $record->id))->applyUserYearAccess(auth()->user())->orderBy('no')` — cross-year allowed; access scope still applies.
- Submit handler (shared): re-validate linkability (`! $sub->hasIncidentLinks()` — races between option render and submit), then `$sub->update(['parent_incident_id' => $record->id])` — MOVE semantics: an existing parent is simply replaced (single column).
- All actions gated `auth()->user()->can('manage incidents')` via `->visible(...)` (house pattern `IncidentResource.php:602-605`).

- [ ] **Step 1: Write the failing tests**

```php
it('links an existing standalone incident as a sub (and moves it on re-link)', function () {
    // link standalone to super A via the list action with form data ['sub_incident_id' => $standalone->id]
    // assert parent set; re-link to super B via the same action
    // assert parent moved to B, exactly one parent, no duplicates
});

it('rejects linking a super or an existing sub (linkability validation)', function () {
    // attempt the action with a super as the picked option value -> validation error, no write
});

it('unlinks without deleting either record', function () {
    // ->call('unlinkSubIncident', $sub->id) — both rows exist, sub standalone, cache-triggered recalc dispatched
});

it('creates a sub-incident through the create page with the parent carried', function () {
    // mount CreateIncident with the parent param -> hidden field set; create -> row parented
});

it('hides link actions without manage permission', function () {
    // viewer with only 'view incidents' -> assertActionHidden / 403 on the method
});
```

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Implement** per Interfaces. The modal Select: `->options(fn (Closure $get, ?Incident $record) => ...)` building from the picker query (house pattern: `ExportActionSchema.php:105-109` — options from a query, not `->relationship()`). Validation rule on the Select rejects a picked record with `hasIncidentLinks()` (message: "That incident is already linked in a hierarchy.").

- [ ] **Step 4: Run — PASS.**

- [ ] **Step 5: Commit** — `feat(incidents): create/link/unlink sub-incident actions`

---

### Task 8: View pages — super's sub list, sub's "Sub of" section

**Files:**
- Modify: `app/Filament/Resources/IncidentResource/Pages/ViewIncident.php` — infolist gains two conditional sections: "Sub-incidents (N)" when `subIncidents()->exists()`; "Sub of" chip section when `parent_incident_id` filled. House pattern for custom sections with buttons: `NotionInvestigationAction::section()` (shared section-footer buttons precedent).
- Create: Blade view `resources/views/filament/resources/incident-resource/sub-incidents-list.blade.php` — rows: `no` (linked to `ViewIncident`), title, status badge, severity badge, fund-status badge, `incident_date`; each row an unlink button (`wire:click` on the ViewIncident component, `wire:confirm`, `manage incidents`-guarded)
- Modify: `app/Filament/Resources/IncidentResource/Pages/ViewIncident.php` — `unlinkSubIncident(int $id): void` (same body as Task 6's)
- Create: `tests/Feature/SubIncidentViewSectionsTest.php`

**Interfaces:** consumes Task 1 relations. Super section header actions: "Create sub-incident" + "Link existing" (same actions as Task 7).

- [ ] **Step 1: Write the failing test** — `Livewire::test(ViewIncident::class, ['record' => $super])` sees the section, every sub's `no`/title, and the count; `Livewire::test(ViewIncident::class, ['record' => $sub])` sees the super's `no` with a "Sub of" label; unlink via `->call('unlinkSubIncident', $sub->id)` works and leaves both records.
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement** per Files. Sections render only for their state (a sub shows "Sub of"; a super shows "Sub-incidents"; standalone shows neither).
- [ ] **Step 4: Run — PASS.**
- [ ] **Step 5: Commit** — `feat(incidents): hierarchy sections on the incident view page`

---

### Task 9: Board view chips

**Files:**
- Modify: `app/Livewire/IncidentKanbanBoard.php` — card query gains `withCount('subIncidents')` + eager `superIncident:id,no` (query assembly around `:354-369`)
- Modify: `resources/views/livewire/incident-kanban-board.blade.php` — sub cards show a small "Sub of {super no}" chip (link to `ViewIncident`); super cards with subs show a count badge. Drag mechanics untouched (`updateStatus()` `:128-169` unchanged — subs remain draggable cards)
- Create: `tests/Feature/SubIncidentBoardChipsTest.php`

- [ ] **Step 1: Write the failing test** — `Livewire::test(IncidentKanbanBoard::class)` seeded super+sub: HTML contains the "Sub of" chip referencing the super's `no` and the count badge on the super card.
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement** per Files.
- [ ] **Step 4: Run — PASS** (existing board tests stay green).
- [ ] **Step 5: Commit** — `feat(board): sub-incident chips on kanban cards`

---

### Task 10: Classification guard (Incident ↔ Issue while linked)

**Files:**
- Modify: `app/Filament/Resources/IncidentResource.php:96` — the classification Select gains a closure rule: when the record exists, classification changes, and `$record->hasIncidentLinks()` → fail with "Unlink this incident from its super/sub-incidents before changing classification." (Filament closure-rule pattern: `->rules([fn () => function (string $attribute, $value, Closure $fail) use ($record) { ... }])`)
- Modify: `app/Filament/Resources/IncidentResource.php:646-654` (`downgrade_to_issue`) and `:665-674` (`bulk_downgrade`) — inside the action body: if `$record->hasIncidentLinks()` → danger notification + return (no change); IssueResource is NOT touched (its Select is `disabled()->hidden()` — no path)
- Create: `tests/Feature/SubIncidentClassificationGuardTest.php`

- [ ] **Step 1: Write the failing tests** — (a) edit-form attempt Incident→Issue on a linked record: `->assertHasFormErrors(['classification'])`, record unchanged; (b) same on an unlinked record: succeeds (existing behavior must not regress); (c) `downgrade_to_issue` on a linked record: classification unchanged + notification; (d) `bulk_downgrade` skips linked records with a notification, downgrades unlinked ones.
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement** per Files.
- [ ] **Step 4: Run — PASS** (existing downgrade tests stay green).
- [ ] **Step 5: Commit** — `feat(incidents): block classification changes while linked in a hierarchy`

---

### Task 11: API field, docs, baseline

**Files:**
- Modify: `app/Http/Resources/IncidentApiResource.php` + `app/Http/Resources/IncidentListApiResource.php` — expose `'parent_incident_id'` (no new endpoints; index/query untouched — API is data access, subs listed there as today)
- Modify: `docs/reference/dependency-map.md` (row added in Task 1 — extend consumed-by with the Task 2–10 surfaces), `docs/reference/business-rules.md` (new "Sub-incident roll-up" section: decided 1–9 condensed + the classification guard + the money/count funnel split), `docs/reference/exports.md` (subs-excluded line per preset + ZIP; corpus exception), `CLAUDE.md` (headline bullet: counts exclude G / Non Incident **and sub-incidents**)
- Modify: `docs/reference/testing-standards.md` — new baseline entry
- Create: `tests/Feature/Api/SubIncidentApiFieldTest.php`

- [ ] **Step 1: Write the failing test** — API `GET /api/v1/incidents/{no}` (house API test pattern under `tests/Feature/Api/`) includes `parent_incident_id` (null for supers/standalone, the super id for subs).
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement** — the two resource fields + all doc edits listed above.
- [ ] **Step 4: Run full suite** — `php -d memory_limit=1G vendor/bin/phpunit` — baseline 1166+ tests green with the new tests added; no drop.
- [ ] **Step 5: Pint + commit** — `./vendor/bin/pint --dirty` (or the touched files); `feat(incidents): sub-incident hierarchy — API field and docs` + full-suite note in the body.
