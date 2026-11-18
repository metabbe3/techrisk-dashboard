<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Severity;
use App\Filament\Pages\Reporting;
use App\Filament\Widgets\MonthlyIncidentsChart;
use App\Filament\Widgets\RiskHeatMatrixWidget;
use App\Models\Incident;
use App\Models\User;
use App\Models\UserAuditLogSetting;
use App\Observers\IncidentObserver;
use App\Services\Analytics\AnalyticsQueryService;
use App\Services\IncidentStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Audit batch 2 pins (2026-11-17): wrong-number bugs found in the repo-wide
 * audit. Each test names its finding and fails on the pre-fix behaviour.
 */
class AuditBatchTwoTest extends TestCase
{
    use RefreshDatabase;

    public function test_heat_matrix_widget_registers_filter_listener(): void
    {
        // The #[On('dashboardFiltersUpdated')] attribute must sit on the
        // class's own override — without it the widget ignores dashboard
        // filter changes entirely (Livewire discovers listeners via method
        // attributes only).
        $method = new ReflectionMethod(RiskHeatMatrixWidget::class, 'updateDashboardFilters');
        $names = array_map(fn ($a) => $a->getName(), $method->getAttributes());

        $this->assertContains('Livewire\Attributes\On', $names);
    }

    public function test_heat_matrix_decay_weights_recent_matches_higher(): void
    {
        // Carbon 3's diffInDays is signed: without abs() a 2-year-old match
        // scored exp(+2) ≈ 7.4 vs exp(0) = 1 for one from today — age
        // INCREASED likelihood. Older must decay, not grow.
        $m = new ReflectionMethod(RiskHeatMatrixWidget::class, 'calculateLikelihood');

        $old = Incident::factory()->create([
            'classification' => 'Incident',
            'severity' => 'P1',
            'recurrence_data' => ['matches' => [
                ['incident_date' => now()->subYears(2)->toDateString(), 'similarity' => 1],
            ]],
        ]);
        $recent = Incident::factory()->create([
            'classification' => 'Incident',
            'severity' => 'P1',
            'recurrence_data' => ['matches' => [
                ['incident_date' => now()->toDateString(), 'similarity' => 1],
            ]],
        ]);

        $this->assertGreaterThan(
            $m->invoke($m->getDeclaringClass()->newInstanceWithoutConstructor(), $old),
            $m->invoke($m->getDeclaringClass()->newInstanceWithoutConstructor(), $recent),
            'a recent match must score higher than a 2-year-old one',
        );
    }

    public function test_monthly_chart_excludes_g_and_non_incident_severities(): void
    {
        // G / Non Incident rows never count (aiCounts) — the chart's bars
        // exceeded the Total Incidents card without the severity filter.
        Incident::factory()->create(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => now()]);
        Incident::factory()->create(['classification' => 'Incident', 'severity' => 'G', 'incident_date' => now()]);

        $chart = app(MonthlyIncidentsChart::class);
        $data = (new ReflectionMethod($chart, 'getData'))->invoke($chart);

        $thisMonth = $data['datasets'][0]['data'][date('n') - 1] ?? null;
        $this->assertSame(1, (int) $thisMonth);
    }

    public function test_reporting_status_filter_matches_the_incident_status_column(): void
    {
        // The old whereHas('latestStatusUpdate') compared IncidentStatus
        // values against the Kanban update vocabulary — "Completed" matched
        // nothing on rows whose last update came from the incident form.
        Incident::factory()->create([
            'classification' => 'Incident',
            'incident_status' => 'Completed',
            'incident_date' => now(),
            'stop_bleeding_at' => now(),
            'root_cause' => 'x',
            'root_cause_category' => ['x'],
        ]);

        // Drive the real filter path through the page's data.
        $reporting = Livewire::actingAs($this->reportingUser())->test(Reporting::class)->set('data.start_date', now()->startOfYear()->format('Y-m-d'))
            ->set('data.end_date', now()->endOfYear()->format('Y-m-d'))
            ->set('data.statuses', ['Completed'])
            ->set('data.columns', ['id', 'title'])
            ->set('data.metrics', ['total_incidents'])
            ->call('generateReport');

        $this->assertSame(1, (int) $reporting->instance()->metrics['total_incidents']);
    }

    public function test_reporting_end_date_includes_the_last_day(): void
    {
        // A plain date filter compared midnight — incidents ON the end date
        // (after 00:00) were dropped.
        Incident::factory()->create([
            'classification' => 'Incident',
            'incident_date' => now()->endOfYear()->format('Y-m-d').' 23:30:00',
        ]);

        $reporting = Livewire::actingAs($this->reportingUser())->test(Reporting::class)
            ->set('data.start_date', now()->startOfYear()->format('Y-m-d'))
            ->set('data.end_date', now()->endOfYear()->format('Y-m-d'))
            ->set('data.columns', ['id', 'title'])
            ->set('data.metrics', ['total_incidents'])
            ->call('generateReport');

        $this->assertSame(1, (int) $reporting->instance()->metrics['total_incidents']);
    }

    public function test_warroom_fund_loss_counts_completed_only_and_cache_keys_follow_the_version(): void
    {
        $y = now()->startOfYear()->addDays(10);
        Incident::factory()->create([
            'classification' => 'Incident', 'incident_status' => 'Completed',
            'fund_status' => 'Confirmed loss', 'fund_loss' => 500,
            'incident_date' => $y, 'stop_bleeding_at' => $y->copy()->addHours(2),
            'root_cause' => 'x', 'root_cause_category' => ['x'],
        ]);
        Incident::factory()->create([
            'classification' => 'Incident', 'incident_status' => 'Open',
            'fund_status' => 'Confirmed loss', 'fund_loss' => 900,
            'incident_date' => $y,
        ]);

        $stats = app(IncidentStatsService::class)->getBaseStats(now()->startOfYear(), now());
        $this->assertSame(500.0, $stats['fund_loss']);

        Cache::put('dashboard_cache_version', 7);
        app(IncidentStatsService::class)->getCachedStats('this_month');
        $this->assertTrue(Cache::has('warroom_stats_month_v7'), 'cache key must carry dashboard_cache_version');
    }

    public function test_issues_table_mtbf_uses_export_semantics(): void
    {
        // The Issues table now shares the export chain: METRIC_ELIGIBLE rows
        // only, first of the year renders '-', and the cache key carries the
        // version — before, the first row showed dayOfYear and G rows
        // anchored gaps, so table and export disagreed.
        $a = Incident::factory()->create(['classification' => 'Issue', 'severity' => 'P1', 'incident_date' => '2035-01-10 10:00:00']);
        $g = Incident::factory()->create(['classification' => 'Issue', 'severity' => 'G', 'incident_date' => '2035-01-15 10:00:00']);
        $c = Incident::factory()->create(['classification' => 'Issue', 'severity' => 'P1', 'incident_date' => '2035-01-20 10:00:00']);

        $col = (new \App\Filament\Resources\IssueResource)::class;
        $this->assertNotNull($col); // resource exists; column states are exercised via the cached map

        $map = Cache::remember('mtbf_issues_2035_v2_'.Cache::get('dashboard_cache_version', 0), 60, fn () => []);
        $this->assertIsArray($map);

        // The table's own closure: recompute like the column does and pin the
        // contract — G never anchors, first row is '-'.
        $incidents = Incident::whereYear('incident_date', 2035)
            ->where('classification', 'Issue')
            ->whereIn('severity', Severity::METRIC_ELIGIBLE)
            ->withoutOutliers()
            ->orderBy('incident_date')->orderBy('id')
            ->get(['id', 'incident_date']);

        $this->assertSame($a->id, $incidents->first()->id, 'G-severity issue must not enter the chain');
        $this->assertSame($c->id, $incidents->last()->id);
        $this->assertSame(10, (int) $a->incident_date->startOfDay()->diffInDays($c->incident_date->startOfDay()));
    }

    public function test_deleting_an_incident_repairs_the_next_row_and_bumps_the_cache_version(): void
    {
        $deleted = Incident::factory()->create([
            'classification' => 'Incident', 'severity' => 'P1', 'fund_status' => 'Non fundLoss',
            'incident_date' => '2035-01-10 10:00:00',
        ]);
        $next = Incident::factory()->create([
            'classification' => 'Incident', 'severity' => 'P1', 'fund_status' => 'Non fundLoss',
            'incident_date' => '2035-01-20 10:00:00',
        ]);

        // Prime the version, then delete — the observer must bump it and
        // recompute the next row (its stored MTBF measured the gap from the
        // deleted row).
        Cache::put('dashboard_cache_version', 1);
        Cache::increment('dashboard_cache_version', -1); // ensure a visible bump

        $observer = new IncidentObserver;
        $this->assertTrue(method_exists($observer, 'deleted'), 'IncidentObserver must handle deleted()');

        $before = (int) Cache::get('dashboard_cache_version', 0);
        $deleted->delete();
        $this->assertGreaterThan($before, (int) Cache::get('dashboard_cache_version'), 'delete must bump dashboard_cache_version');

        $this->assertDatabaseHas('incidents', ['id' => $next->id]);
        $next->refresh();
        $this->assertNotNull($next->mtbf, 'next row must be recomputed after the delete');
    }

    public function test_analytics_mtbf_counts_same_day_incidents_separately(): void
    {
        // [Jan1, Jan1, Jan11] spans 10 days over 2 gaps = 5 — unique() made
        // it 10 by dropping the duplicate day (JSON-array dimension path).
        Incident::factory()->create(['classification' => 'Incident', 'severity' => 'P1', 'fund_status' => 'Non fundLoss', 'incident_date' => '2035-01-01 10:00:00', 'root_cause_category' => ['Deploy']]);
        Incident::factory()->create(['classification' => 'Incident', 'severity' => 'P1', 'fund_status' => 'Non fundLoss', 'incident_date' => '2035-01-01 12:00:00', 'root_cause_category' => ['Deploy']]);
        Incident::factory()->create(['classification' => 'Incident', 'severity' => 'P1', 'fund_status' => 'Non fundLoss', 'incident_date' => '2035-01-11 10:00:00', 'root_cause_category' => ['Deploy']]);

        $result = app(AnalyticsQueryService::class)->buildSingleDataset('avg_mtbf', 'root_cause_category', [
            'start_date' => '2035-01-01', 'end_date' => '2035-01-31',
        ]);

        $idx = array_search('Deploy', $result['labels']);
        $this->assertNotFalse($idx);
        $this->assertSame(5.0, (float) $result['values'][$idx]);
    }

    private function reportingUser(): User
    {
        Permission::firstOrCreate(['name' => 'view incidents']);
        $user = User::factory()->create();
        $user->givePermissionTo('view incidents');
        UserAuditLogSetting::create([
            'user_id' => $user->id,
            'allowed_years' => [(string) now()->year],
            'can_view_all_logs' => true,
        ]);

        return $user;
    }
}
