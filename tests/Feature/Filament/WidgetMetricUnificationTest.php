<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\Severity;
use App\Filament\Widgets\DashboardStatsOverview;
use App\Filament\Widgets\MttrMtbfTrendChart;
use App\Models\Incident;
use App\Services\Analytics\AnalyticsQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Owner one-logic rule (2026-11-16): every widget tile/series scoped to
 * "Fund Loss" or "Non Fund Loss" reads the SAME rows as the export tabs of
 * that name — fund_status scope, the sign only picking the unit bucket —
 * and the Fund Loss card carries the full excludedFromCounts rule.
 *
 * The sign split alone is wrong for the tiles: the metrics calculator
 * stores days-scale negative MTTR for every shouldCalculateMttrByDays()
 * status (Confirmed loss + Potential recovery + Fully recovered + Non Tech
 * Loss), so a Potential recovery row landed in the "Fund Loss (days)"
 * tile and same-day/minutes rows of any status landed in the "Non Fund
 * Loss (mins)" tile.
 */
class WidgetMetricUnificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * Four rows, four different fund-status/sign combinations. The observer
     * writes mttr from stop_bleeding_at (sync queue in tests) — seeded the
     * production way, never assigned directly.
     */
    private function seedSignVsScopeRows(): void
    {
        $y = now()->year;

        Incident::factory()->create([ // Non fundLoss + minutes → the mins tile
            'severity' => Severity::P1->value,
            'classification' => 'Incident',
            'fund_status' => 'Non fundLoss',
            'incident_date' => "{$y}-03-01 10:00:00",
            'stop_bleeding_at' => "{$y}-03-01 11:00:00", // mttr 60
        ]);
        Incident::factory()->create([ // Confirmed loss + days → the days tile
            'severity' => Severity::P1->value,
            'classification' => 'Incident',
            'fund_status' => 'Confirmed loss',
            'incident_date' => "{$y}-01-01 10:00:00",
            'stop_bleeding_at' => "{$y}-01-11 12:00:00", // mttr -10
        ]);
        Incident::factory()->create([ // days-scale but NO tab's rows — must leave both tiles
            'severity' => Severity::P1->value,
            'classification' => 'Incident',
            'fund_status' => 'Potential recovery',
            'incident_date' => "{$y}-02-01 10:00:00",
            'stop_bleeding_at' => "{$y}-02-21 12:00:00", // mttr -20
        ]);
        Incident::factory()->create([ // minutes but NOT Non fundLoss — must leave the mins tile
            'severity' => Severity::P1->value,
            'classification' => 'Incident',
            'fund_status' => null,
            'incident_date' => "{$y}-03-02 10:00:00",
            'stop_bleeding_at' => "{$y}-03-02 10:30:00", // mttr 30
        ]);
    }

    public function test_dashboard_mttr_tiles_scope_by_fund_status(): void
    {
        $this->seedSignVsScopeRows();

        $widget = app(DashboardStatsOverview::class);
        $stats = \Closure::bind(fn () => $this->calculateStats(), $widget, DashboardStatsOverview::class)();
        $values = collect($stats)->map(fn ($stat) => $stat->getValue())->values();

        $this->assertSame('60.00 mins', $values[5], 'MTTR (Non Fund Loss) must average ONLY Non fundLoss rows');
        $this->assertSame('10.00 days', $values[6], 'MTTR (Fund Loss) must average ONLY Confirmed loss rows');
    }

    public function test_trend_chart_mttr_series_scope_by_fund_status(): void
    {
        $this->seedSignVsScopeRows();

        $widget = app(MttrMtbfTrendChart::class);
        $data = \Closure::bind(fn () => $this->getData(), $widget, MttrMtbfTrendChart::class)();

        $y = now()->year;
        $nonFund = $data['datasets'][0]['data']; // 'MTTR Non Fund Loss (mins)'
        $fund = $data['datasets'][1]['data'];    // 'MTTR Fund Loss (days)'

        $this->assertSame(60.0, $nonFund[2], 'March mins series: only the Non fundLoss row (60), not the null-fund 30');
        $this->assertSame(10.0, $fund[0], 'January days series: the Confirmed loss row (-10 → 10)');
        $this->assertSame(0, $fund[1], 'February days series: a Potential recovery row is no tab\'s rows');
        $this->assertSame(0, $nonFund[0], 'January mins series: days-scale rows stay out of the mins series');
    }

    public function test_incident_stats_fund_loss_card_excludes_recovered_statuses(): void
    {
        $y = now()->year;
        Incident::factory()->create([
            'severity' => Severity::P1->value,
            'classification' => 'Incident',
            'fund_status' => 'Confirmed loss',
            'incident_status' => 'Completed',
            'root_cause' => 'Test RCA',
            'stop_bleeding_at' => "{$y}-03-01 11:00:00",
            'incident_date' => "{$y}-03-01 10:00:00",
            'fund_loss' => 1000,
        ]);
        foreach (['Fully recovered', 'Potential recovery'] as $excluded) {
            Incident::factory()->create([
                'severity' => Severity::P1->value,
                'classification' => 'Incident',
                'fund_status' => $excluded,
                'incident_status' => 'Completed',
                'root_cause' => 'Test RCA',
                'stop_bleeding_at' => "{$y}-03-01 11:00:00",
                'incident_date' => "{$y}-03-0".($excluded === 'Fully recovered' ? 2 : 3).' 10:00:00',
                'fund_loss' => $excluded === 'Fully recovered' ? 500 : 700,
            ]);
        }

        $widget = app(DashboardStatsOverview::class);
        $stats = \Closure::bind(fn () => $this->calculateStats(), $widget, DashboardStatsOverview::class)();
        $values = collect($stats)->map(fn ($stat) => $stat->getValue())->values();

        $this->assertSame('IDR 1.000', $values[2], 'Fund Loss card = Incident + Completed + excludedFromCounts');
    }

    public function test_analytics_group_metrics_divide_each_bucket_by_its_own_count(): void
    {
        $y = now()->year;
        Incident::factory()->create([
            'severity' => Severity::P1->value,
            'classification' => 'Incident',
            'fund_status' => 'Non fundLoss',
            'incident_date' => "{$y}-03-01 10:00:00",
            'stop_bleeding_at' => "{$y}-03-01 11:00:00", // mttr 60
            'business_category' => ['Payments'],
        ]);
        Incident::factory()->create([
            'severity' => Severity::P1->value,
            'classification' => 'Incident',
            'fund_status' => 'Confirmed loss',
            'incident_date' => "{$y}-01-01 10:00:00",
            'stop_bleeding_at' => "{$y}-01-31 10:00:00", // mttr -30 days
            'business_category' => ['Payments'],
        ]);

        $svc = app(AnalyticsQueryService::class);
        $mins = $svc->buildSingleDataset('avg_mttr', 'business_category', []);
        $days = $svc->buildSingleDataset('avg_mttr_days', 'business_category', []);

        $idx = array_search('Payments', $mins['labels']);
        $this->assertNotFalse($idx);
        $this->assertSame(60.0, (float) $mins['values'][$idx], 'mins mean = mins bucket sum / its OWN rows (was divided by the group total: 30)');
        $this->assertSame(30.0, (float) $days['values'][$idx], 'days mean = days bucket sum / its OWN rows (was divided by the group total: 15)');
    }

    public function test_live_table_mtbf_column_uses_export_chain_semantics(): void
    {
        $y = now()->year;
        $first = Incident::factory()->create([ // chain first → '-', was dayOfYear
            'severity' => Severity::P1->value,
            'classification' => 'Incident',
            'fund_status' => 'Non fundLoss',
            'incident_date' => "{$y}-01-01 10:00:00",
        ]);
        $g = Incident::factory()->create([ // non-metric severity → '-', was a counted chain row
            'severity' => Severity::G->value,
            'classification' => 'Incident',
            'fund_status' => 'Non fundLoss',
            'incident_date' => "{$y}-01-15 10:00:00",
        ]);
        $second = Incident::factory()->create([ // gap 31 days over both
            'severity' => Severity::P1->value,
            'classification' => 'Incident',
            'fund_status' => 'Non fundLoss',
            'incident_date' => "{$y}-02-01 10:00:00",
        ]);

        Permission::firstOrCreate(['name' => 'view incidents']);
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('view incidents');

        Livewire::actingAs($user)->test(\App\Filament\Resources\IncidentResource\Pages\ListIncidents::class);

        $map = Cache::get("mtbf_All Cases_{$y}_v2_".Cache::get('dashboard_cache_version', 0));

        $this->assertIsArray($map, 'the MTBF column chain cache must have been built during render');
        $this->assertSame('-', $map[$first->id], 'first of the year chain renders "-" (was the dayOfYear anchor)');
        $this->assertSame(31, $map[$second->id], 'gap telescopes over the G row — same chain the exports use');
        $this->assertArrayNotHasKey($g->id, $map, 'G severity is not in the metric chain (METRIC_ELIGIBLE)');
    }
}
