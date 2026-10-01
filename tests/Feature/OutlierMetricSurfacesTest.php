<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\Reporting;
use App\Filament\Statistics\IncidentStatsFooterData;
use App\Filament\Widgets\DashboardStatsOverview;
use App\Models\Incident;
use App\Models\Label;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Services\Analytics\AnalyticsQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The Outlier rule walked across every MTBF/MTTR QUERY surface (footer,
 * widget tiles, analytics, reporting page, scheduled report). Canonical
 * scenario: A=Jan 10, B=Jan 20 (Outlier), C=Jan 30 — span math over the
 * non-outlier set is 20 days / (2-1) = 20; counting B gives 20 / (3-1) = 10.
 * Counts (Total Cases / tiles) keep B everywhere.
 */
class OutlierMetricSurfacesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Non-fund-loss P1 incidents with real stored MTTR (create() runs the
     * metrics job inline under the sync test queue); the Outlier attach goes
     * through the pivot, so its stored metrics get nulled exactly as in prod.
     */
    private function seedTrio(string $year, array $extra = []): Incident
    {
        $make = fn (string $date, array $attrs = []) => Incident::factory()->create(array_merge([
            'classification' => 'Incident',
            'severity' => 'P1',
            'fund_status' => 'Non fundLoss',
            'incident_date' => "{$date} 10:00:00",
            'stop_bleeding_at' => "{$date} 12:00:00",
        ], $attrs, $extra));

        $a = $make("{$year}-01-10", ['stop_bleeding_at' => "{$year}-01-10 11:00:00"]); // mttr 60
        $b = $make("{$year}-01-20");
        $make("{$year}-01-30", ['stop_bleeding_at' => "{$year}-01-30 15:00:00"]); // mttr 300

        $b->labels()->attach(Label::firstOrCreate(['name' => Label::OUTLIER]));

        return $b;
    }

    public function test_footer_avg_mtbf_excludes_outlier_but_total_cases_counts_it(): void
    {
        $this->seedTrio('2035');

        $stats = app(IncidentStatsFooterData::class)->build(
            Incident::query()->where('classification', '!=', 'Issue')->whereYear('incident_date', 2035)
        );

        $this->assertSame(20.0, (float) $stats['avgMtbf']);
        $this->assertSame(3, $stats['totalCases']);
    }

    public function test_dashboard_widget_mtbf_tile_excludes_outlier(): void
    {
        $this->seedTrio('2026'); // widget defaults to the current year

        Livewire::test(DashboardStatsOverview::class)
            ->assertSee('20.00 days')
            ->assertDontSee('10.00 days');
    }

    public function test_analytics_avg_mtbf_excludes_outlier_on_json_dimension(): void
    {
        $this->seedTrio('2035', ['business_category' => ['Payments']]);

        $result = app(AnalyticsQueryService::class)
            ->buildSingleDataset('avg_mtbf', 'business_category', [
                'start_date' => '2035-01-01', 'end_date' => '2035-12-31',
            ]);

        $idx = array_search('Payments', $result['labels']);
        $this->assertNotFalse($idx);
        $this->assertSame(20.0, (float) $result['values'][$idx]);
    }

    public function test_analytics_avg_mttr_not_diluted_by_outlier_on_json_dimension(): void
    {
        $this->seedTrio('2035', ['business_category' => ['Payments']]);

        $result = app(AnalyticsQueryService::class)
            ->buildSingleDataset('avg_mttr', 'business_category', [
                'start_date' => '2035-01-01', 'end_date' => '2035-12-31',
            ]);

        // (60 + 300) / 2 = 180 — the outlier's NULL mttr must leave the
        // group's row count too, not just its sum (denominator dilution).
        $idx = array_search('Payments', $result['labels']);
        $this->assertNotFalse($idx);
        $this->assertSame(180.0, (float) $result['values'][$idx]);
    }

    public function test_analytics_avg_mtbf_excludes_outlier_on_label_dimension(): void
    {
        // B carries Payments AND Outlier: without the scope, B joins the
        // Payments group and drags its MTBF to 10.
        $b = $this->seedTrio('2035');
        $b->labels()->attach(Label::firstOrCreate(['name' => 'Payments']));
        Incident::whereDate('incident_date', '2035-01-10')->first()->labels()
            ->attach(Label::where('name', 'Payments')->firstOrFail());
        Incident::whereDate('incident_date', '2035-01-30')->first()->labels()
            ->attach(Label::where('name', 'Payments')->firstOrFail());

        $result = app(AnalyticsQueryService::class)
            ->buildSingleDataset('avg_mtbf', 'label', [
                'start_date' => '2035-01-01', 'end_date' => '2035-12-31',
            ]);

        $idx = array_search('Payments', $result['labels']);
        $this->assertNotFalse($idx);
        $this->assertSame(20.0, (float) $result['values'][$idx]);
    }

    public function test_reporting_avg_mtbf_excludes_outlier(): void
    {
        $this->seedTrio('2035');
        // Filament Page: renders through the panel — needs Livewire::actingAs
        // (not $this->actingAs) or the snapshot round-trip gets null and the
        // first ->set() dies on an array-offset warning.
        Permission::firstOrCreate(['name' => 'view incidents']);
        $user = User::factory()->create();
        $user->givePermissionTo('view incidents');

        $component = Livewire::actingAs($user)->test(Reporting::class)
            ->set('data.start_date', '2035-01-01')
            ->set('data.end_date', '2035-12-31')
            ->call('generateReport');

        $this->assertSame(20.0, (float) $component->instance()->metrics['avg_mtbf']);
    }

    public function test_send_report_avg_mtbf_excludes_outlier_but_total_counts_it(): void
    {
        $this->seedTrio('2035');
        Mail::fake();

        $template = ReportTemplate::create([
            'name' => 'Outlier report',
            'user_id' => User::factory()->create()->id,
            'filters' => ['start_date' => '2035-01-01', 'end_date' => '2035-12-31'],
            'columns' => ['id', 'title'],
            'metrics' => ['total_incidents', 'avg_mtbf'],
            'email' => 'owner@example.test',
            'schedule' => 'daily',
        ]);

        $this->artisan('app:send-report', ['report_template_id' => $template->id]);

        $file = collect(Storage::disk('local')->files('reports'))->last();
        $sheet = IOFactory::load(Storage::disk('local')->path($file))->getSheetByName('Metrics');
        $rows = $sheet->toArray();

        $metrics = collect($rows)->skip(1)->mapWithKeys(fn ($r) => [$r[0] => $r[1]]);
        $this->assertSame(20.0, (float) $metrics['avg_mtbf']);
        $this->assertEquals(3, $metrics['total_incidents']);
        Storage::disk('local')->delete($file);
    }
}
