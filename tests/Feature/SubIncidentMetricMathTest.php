<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FundStatus;
use App\Enums\IncidentClassification;
use App\Enums\Severity;
use App\Filament\Pages\Reporting;
use App\Filament\Widgets\DashboardStatsOverview;
use App\Filament\Widgets\MttrMtbfTrendChart;
use App\Models\Incident;
use App\Models\Label;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Services\Analytics\AnalyticsQueryService;
use App\Services\WeeklyDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * PROJ-022: sub-incidents leave every MTBF/MTTR average, telescoping chain
 * and the Reporting/SendReport/weekly listing — while their own metric
 * columns still compute (parentage is NOT the Outlier rule: no nulling).
 * Money columns keep sub fund loss.
 */
class SubIncidentMetricMathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * Super (Jan 10, mttr 10 — 10-minute bleed) + sub (Jan 20, mttr 100,
     * fund_loss 50). Both metric-eligible and fund-includable, so any
     * sub-dilution of an average or chain is visible in the numbers.
     */
    private function seedSuperAndSub(int $year = 2035): array
    {
        $super = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            'fund_status' => FundStatus::NonFundLoss->value,
            'incident_date' => "{$year}-01-10 10:00:00",
            'stop_bleeding_at' => "{$year}-01-10 10:10:00", // mttr 10
        ]);

        $sub = Incident::factory()->subOf($super)->create([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            'fund_status' => FundStatus::NonFundLoss->value,
            'incident_date' => "{$year}-01-20 10:00:00",
            'stop_bleeding_at' => "{$year}-01-20 11:40:00", // mttr 100
            'fund_loss' => 50,
        ]);

        return [$super, $sub];
    }

    public function test_footer_averages_exclude_sub_but_money_keeps_it(): void
    {
        $this->seedSuperAndSub();

        $stats = app(\App\Filament\Statistics\IncidentStatsFooterData::class)->build(
            Incident::query()->where('classification', '!=', 'Issue')->whereYear('incident_date', 2035)
        );

        $this->assertSame(10.0, (float) $stats['avgMttrMins']);
        $this->assertSame(0.0, (float) $stats['avgMtbf']); // one eligible row left — no span
        $this->assertSame(50.0, (float) $stats['totalFundLoss']);
        // Review I3 (PROJ-022): totalCases is a COUNT — subs roll up into
        // their super everywhere. Money columns keep the sub's fund loss.
        $this->assertSame(1, $stats['totalCases']);
    }

    public function test_footer_total_cases_excludes_subs_on_the_default_base_query(): void
    {
        // No query argument — the table footer's real path (baseQuery()).
        // Review I3: baseQuery() never excluded subs, so the footer's
        // "Total Cases" counted rows the default table view hides.
        $this->seedSuperAndSub(now()->year);

        $stats = app(\App\Filament\Statistics\IncidentStatsFooterData::class)->build();

        $this->assertSame(1, $stats['totalCases']);
        $this->assertSame(50.0, (float) $stats['totalFundLoss']); // money keeps the sub
    }

    public function test_footer_average_excludes_a_sub_even_when_it_is_an_outlier(): void
    {
        [, $sub] = $this->seedSuperAndSub();

        $sub->labels()->attach(Label::firstOrCreate(['name' => Label::OUTLIER]));

        $stats = app(\App\Filament\Statistics\IncidentStatsFooterData::class)->build(
            Incident::query()->where('classification', '!=', 'Issue')->whereYear('incident_date', 2035)
        );

        // Sub-exclusion wins over the Outlier keep-in-count rule — the sub
        // leaves the average by parentage regardless of outlier status.
        $this->assertSame(10.0, (float) $stats['avgMttrMins']);
    }

    public function test_dashboard_mttr_and_mtbf_tiles_exclude_subs(): void
    {
        $this->seedSuperAndSub(2026); // widget defaults to the current year

        Livewire::test(DashboardStatsOverview::class)
            ->assertSee('10.00 mins')
            ->assertDontSee('55.00 mins') // (10+100)/2 — sub must not dilute
            ->assertDontSee('10.00 days'); // MTBF span over both rows — sub must leave the set
    }

    public function test_mttr_mtbf_trend_chart_monthly_series_exclude_subs(): void
    {
        $this->seedSuperAndSub(2026);

        $data = (new \ReflectionMethod(MttrMtbfTrendChart::class, 'getData'))
            ->invoke(new MttrMtbfTrendChart);

        // Seeds sit in January — the monthly bucket is month 1, not "now".
        $this->assertSame(10.0, (float) $data['datasets'][0]['data'][0]); // MTTR Non Fund (mins)
    }

    public function test_mtbf_chain_telescopes_over_subs_but_sub_rows_keep_own_metrics(): void
    {
        [$super, $sub] = $this->seedSuperAndSub();
        Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            'fund_status' => FundStatus::NonFundLoss->value,
            'incident_date' => '2035-01-30 10:00:00',
            'stop_bleeding_at' => '2035-01-30 10:20:00',
        ]);
        $b = Incident::whereDate('incident_date', '2035-01-30')->firstOrFail();

        // B's previous metric-eligible row is the SUPER (Jan 10), not the
        // sub (Jan 20) — the sub telescopes out of the chain.
        $this->assertSame(20.0, (float) $b->mtbf);

        // Parentage is not the Outlier rule — the sub's own columns compute.
        $this->assertSame(100.0, (float) $sub->fresh()->mttr);
        $this->assertSame(10.0, (float) $sub->fresh()->mtbf);
        $this->assertNotNull($super->fresh()->mtbf);
    }

    public function test_reporting_listing_hides_subs(): void
    {
        Permission::firstOrCreate(['name' => 'view incidents']);
        $user = User::factory()->create();
        $user->givePermissionTo('view incidents');

        [$super, $sub] = $this->seedSuperAndSub((int) now()->year);
        $super->update(['title' => 'Super Visible']);
        $sub->update(['title' => 'Sub Hidden']);

        $year = now()->year;
        Livewire::actingAs($user)->test(Reporting::class)
            ->set('data.start_date', "{$year}-01-01")
            ->set('data.end_date', "{$year}-12-31")
            ->set('data.columns', ['title'])
            ->call('generateReport')
            ->assertSee('Super Visible')
            ->assertDontSee('Sub Hidden');
    }

    public function test_send_report_listing_and_averages_exclude_subs(): void
    {
        $this->seedSuperAndSub();
        Mail::fake();

        $template = ReportTemplate::create([
            'name' => 'Sub-incident report',
            'user_id' => User::factory()->create()->id,
            'filters' => ['start_date' => '2035-01-01', 'end_date' => '2035-12-31'],
            'columns' => ['id', 'title'],
            'metrics' => ['total_incidents', 'avg_mttr'],
            'email' => 'owner@example.test',
            'schedule' => 'daily',
        ]);

        // The local disk is real and shared across runs (host-test memory):
        // clear this template's leftovers, then match the new sheet by
        // prefix — ->last() over mixed leftovers reads the WRONG file.
        $disk = Storage::disk('local');
        foreach ($disk->files('reports') as $old) {
            if (str_contains($old, 'Sub-incident report_')) {
                $disk->delete($old);
            }
        }

        $this->artisan('app:send-report', ['report_template_id' => $template->id]);

        $file = collect($disk->files('reports'))
            ->first(fn ($f) => str_contains($f, 'Sub-incident report_'));
        $sheet = IOFactory::load($disk->path($file))->getSheetByName('Metrics');
        $rows = $sheet->toArray();

        $metrics = collect($rows)->skip(1)->mapWithKeys(fn ($r) => [$r[0] => $r[1]]);
        $this->assertEquals(1, $metrics['total_incidents']);
        $this->assertSame(10.0, (float) $metrics['avg_mttr']);
        $disk->delete($file);
    }

    public function test_weekly_counts_exclude_subs(): void
    {
        $year = (int) now()->year;
        $super = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            'incident_date' => now()->subDays(2),
        ]);
        Incident::factory()->subOf($super)->create([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            'incident_date' => now()->subDay(),
        ]);
        Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
            'incident_date' => now(),
        ]);

        $weeks = app(WeeklyDataService::class)->getWeeklyData($year);

        $total = array_sum(array_map(fn ($w) => $w->total, $weeks));
        $this->assertSame(2, $total);
    }

    public function test_analytics_avg_mttr_excludes_subs(): void
    {
        [$super, $sub] = $this->seedSuperAndSub();
        $super->update(['business_category' => ['Payments']]);
        $sub->update(['business_category' => ['Payments']]);

        $result = app(AnalyticsQueryService::class)
            ->buildSingleDataset('avg_mttr', 'business_category', [
                'start_date' => '2035-01-01', 'end_date' => '2035-12-31',
            ]);

        $idx = array_search('Payments', $result['labels']);
        $this->assertNotFalse($idx);
        $this->assertSame(10.0, (float) $result['values'][$idx]);
    }
}
