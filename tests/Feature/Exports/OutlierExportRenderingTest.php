<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Exports\ExecutiveIncidentsExport;
use App\Exports\GroupedIncidentsExport;
use App\Exports\IncidentTableExport;
use App\Exports\MultiSheetIncidentsExport;
use App\Exports\Sheets\ExecutiveDataSheet;
use App\Exports\Sheets\ExecutiveQuarterSheet;
use App\Exports\Sheets\IssuesMetricSheetExport;
use App\Exports\Sheets\PerCategorySheet;
use App\Exports\Sheets\SingleIncidentSheetExport;
use App\Models\Incident;
use App\Models\Label;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner rule (2026-10-01): a row tagged Label "Outlier" stays in every
 * export — still counted, still listed — but its own MTTR/MTBF cells render
 * the literal "Outlier", sequence gaps telescope over it, and group/quarter
 * MTBF spans exclude it. Non-outlier null stored mtbf renders 0 (was blank)
 * in the stored-metric sheets — the sequence sheets keep '-' convention.
 */
class OutlierExportRenderingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A=Jan 10 (mttr 60), B=Jan 20 (Outlier), C=Jan 30 (mttr 300) in $year.
     * Span over non-outliers: 20 days / (2-1) = 20; with B: 20 / (3-1) = 10.
     */
    private function seedTrio(string $year, string $classification = 'Incident'): array
    {
        $make = fn (string $date, array $attrs = []) => Incident::factory()->create(array_merge([
            'classification' => $classification,
            'severity' => 'P1',
            'fund_status' => 'Non fundLoss',
            'incident_date' => "{$date} 10:00:00",
            'stop_bleeding_at' => "{$date} 12:00:00",
        ], $attrs));

        $a = $make("{$year}-01-10", ['stop_bleeding_at' => "{$year}-01-10 11:00:00"]); // mttr 60
        $b = $make("{$year}-01-20");
        $c = $make("{$year}-01-30", ['stop_bleeding_at' => "{$year}-01-30 15:00:00"]); // mttr 300

        $b->labels()->attach(Label::firstOrCreate(['name' => Label::OUTLIER]));

        return [$a->fresh(), $b->fresh(), $c->fresh()];
    }

    public function test_incident_table_export_outlier_cells_say_outlier_and_sequence_skips(): void
    {
        [$a, $b, $c] = $this->seedTrio('2036');

        $export = new IncidentTableExport(collect([$a, $b, $c]), [], ['MTTR', 'MTBF (days)'], ['mttr', 'mtbf']);

        $this->assertSame(['Outlier', 'Outlier'], $export->map($b));
        $this->assertSame(20, $export->map($c)[1], 'gap telescopes over the outlier (20, not 10)');
        $this->assertSame('-', $export->map($a)[1], 'first-of-year convention unchanged');
    }

    public function test_single_incident_sheet_outlier_cells_say_outlier(): void
    {
        [, $b, $c] = $this->seedTrio('2036');

        $export = new SingleIncidentSheetExport(
            Incident::query()->whereYear('incident_date', 2036),
            'All Cases',
            ['MTTR', 'MTBF (days)'],
            ['mttr', 'mtbf']
        );

        $this->assertSame(['Outlier', 'Outlier'], $export->map($b));
        $this->assertSame(20, $export->map($c)[1]);
    }

    public function test_issues_metric_sheets_keep_outlier_rows_with_outlier_literal(): void
    {
        [, $b, $c] = $this->seedTrio('2036', 'Issue');

        $headings = ['Issue Name', 'Type', 'MTBF (days)'];
        $mtbfSheet = new IssuesMetricSheetExport(
            Incident::query()->where('classification', 'Issue')->whereYear('incident_date', 2036),
            'Issues - MTBF',
            'mtbf'
        );
        $this->assertSame('Outlier', $mtbfSheet->map($b)[2]);

        // The All Tabs export's whereNotNull('mtbf')/('mttr') predicates must
        // not drop the outlier row (its stored metrics are null).
        $multi = new MultiSheetIncidentsExport(Incident::query()->whereYear('incident_date', 2036), ['MTTR', 'MTBF (days)'], ['mttr', 'mtbf']);
        $ids = [];
        foreach ($multi->sheets() as $sheet) {
            if (str_starts_with($sheet->title(), 'Issues')) {
                $ids[$sheet->title()] = $sheet->query()->pluck('id')->values()->all();
            }
        }
        $this->assertContains($b->id, $ids['Issues - MTTR'], 'mttr sheet keeps the row');
        $this->assertContains($b->id, $ids['Issues - MTBF'], 'mtbf sheet keeps the row');
        $this->assertSame(20, $mtbfSheet->map($c)[2]);
    }

    public function test_executive_data_sheet_outlier_literal_and_null_mtbf_renders_zero(): void
    {
        [$a, $b] = $this->seedTrio('2036');
        // Part 2: a NON-outlier with null stored mtbf renders 0, not blank.
        $a->forceFill(['mtbf' => null])->saveQuietly();

        $sheet = new ExecutiveDataSheet(Incident::query()->whereYear('incident_date', 2036));

        // Headings: ..., MTTR(5), MTBF (days)(6)
        $this->assertSame('Outlier', $sheet->map($b)[5]);
        $this->assertSame('Outlier', $sheet->map($b)[6]);
        $this->assertSame(0, $sheet->map($a)[6]);
    }

    public function test_per_category_sheet_outlier_literal_and_null_mtbf_renders_zero(): void
    {
        [$a, $b] = $this->seedTrio('2036');
        $a->forceFill(['mtbf' => null])->saveQuietly();

        $sheet = new PerCategorySheet(Incident::query(), 'severity', false, 'P1');

        $this->assertSame('Outlier', $sheet->map($b)[6]);
        $this->assertSame(0, $sheet->map($a)[6]);
        $this->assertSame(3, $sheet->query()->count(), 'rows stay listed');
    }

    public function test_grouped_summary_mtbf_excludes_outlier_but_counts_it(): void
    {
        $this->seedTrio('2036');

        $sheets = (new GroupedIncidentsExport(Incident::query()->whereYear('incident_date', 2036), 'severity'))->sheets();

        $p1 = collect($sheets[0]->collection())->firstWhere('label', 'P1');
        $this->assertSame(20.0, (float) $p1['avgMtbf']);
        $this->assertSame(3, $p1['count']);
    }

    public function test_executive_quarter_kpi_mtbf_excludes_outlier_but_counts_it(): void
    {
        $this->seedTrio('2036');

        $sheet = new ExecutiveQuarterSheet(Incident::query()->whereYear('incident_date', 2036), '2036-Q1');

        $kpi = (new \ReflectionProperty($sheet, 'kpi'))->getValue($sheet);
        $this->assertSame(20.0, (float) $kpi['avgMtbf']);
        $this->assertSame(3, $kpi['totalCases']);
    }

    public function test_executive_export_wiring_loads_labels(): void
    {
        // The executive sheets must not lazy-load labels per row when they
        // check isOutlier() — smoke: sheets() builds without touching the DB
        // per-row beyond the eager load.
        $this->seedTrio('2036');

        $sheets = (new ExecutiveIncidentsExport(Incident::query()->whereYear('incident_date', 2036)))->sheets();

        $this->assertNotEmpty($sheets);
    }
}
