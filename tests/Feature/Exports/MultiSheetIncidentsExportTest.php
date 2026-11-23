<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Enums\Severity;
use App\Exports\MultiSheetIncidentsExport;
use App\Exports\Sheets\IssuesMetricSheetExport;
use App\Filament\Actions\ExportActionSchema;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Owner rule (2026-09-30): the All Tabs export contains only severity
 * P1–P4 / X1–X4 — including the 3 fresh-query Issue sheets — and the
 * "Non Incident" tab sheet is gone (it would be structurally empty).
 */
class MultiSheetIncidentsExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_sheets_exclude_non_metric_severities(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-01-10 10:00', 'mttr' => 180]);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'G', 'incident_date' => '2026-01-11 10:00', 'mttr' => 60]);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'Non Incident', 'incident_date' => '2026-01-12 10:00', 'mttr' => 30]);
        // Issues bypass the main query with fresh Incident:: queries.
        Incident::factory()->createQuietly(['classification' => 'Issue', 'severity' => 'X1', 'incident_date' => '2026-02-01 10:00', 'mttr' => 45, 'mtbf' => 5]);
        Incident::factory()->createQuietly(['classification' => 'Issue', 'severity' => 'G', 'incident_date' => '2026-02-02 10:00', 'mttr' => 15, 'mtbf' => 2]);

        // Mirrors ListIncidents: the export consumes applyFilters output.
        $query = ExportActionSchema::applyFilters(Incident::query(), []);
        $export = new MultiSheetIncidentsExport(
            $query,
            array_values(ExportActionSchema::columnOptions()),
            array_keys(ExportActionSchema::columnOptions())
        );

        $sheets = $export->sheets();
        $titles = array_map(fn ($sheet) => $sheet->title(), $sheets);

        // 15 sheets, Non Incident tab removed, P4 tab (eligible) kept.
        $this->assertCount(15, $sheets);
        $this->assertNotContains('Non Incident', $titles);
        $this->assertContains('P4 Incidents', $titles);

        foreach ($sheets as $sheet) {
            $rows = $sheet->query()->get();
            foreach ($rows as $row) {
                $sev = $row->severity instanceof \BackedEnum ? $row->severity->value : $row->severity;
                $this->assertContains(
                    $sev,
                    Severity::METRIC_ELIGIBLE,
                    "{$sheet->title()} sheet holds non-metric severity {$sev}"
                );
            }
        }

        // The fresh-query Issues sheets hold the eligible Issue only.
        $issuesSheets = array_values(array_filter(
            $sheets,
            fn ($sheet) => $sheet instanceof IssuesMetricSheetExport || $sheet->title() === 'All Issues'
        ));
        $this->assertCount(3, $issuesSheets);
        foreach ($issuesSheets as $sheet) {
            $this->assertSame(1, $sheet->query()->count(), "{$sheet->title()} excludes the G Issue");
        }
    }

    /** Owner (2026-11-19): compact Recovered dump — current year only, ID | Name | Recovered, totals below. */
    public function test_recovered_this_year_sheet_scopes_membership_and_renders_totals(): void
    {
        $year = now()->year;
        $inYear = Incident::factory()->createQuietly(['no' => 'INC-001', 'title' => 'In year', 'classification' => 'Incident', 'severity' => 'P1', 'incident_date' => $year.'-01-10 10:00', 'recovered_fund' => 1500000]);
        Incident::factory()->createQuietly(['no' => 'INC-002', 'title' => 'Last year', 'classification' => 'Incident', 'severity' => 'P1', 'incident_date' => ($year - 1).'-01-10 10:00', 'recovered_fund' => 999]);
        Incident::factory()->createQuietly(['no' => 'INC-003', 'title' => 'No recovery', 'classification' => 'Incident', 'severity' => 'P1', 'incident_date' => $year.'-02-10 10:00', 'recovered_fund' => 0]);

        $query = ExportActionSchema::applyFilters(Incident::query(), []);
        $export = new MultiSheetIncidentsExport(
            $query,
            array_values(ExportActionSchema::columnOptions()),
            array_keys(ExportActionSchema::columnOptions())
        );

        $sheet = collect($export->sheets())->first(fn ($s) => $s->title() === 'Recovered This Year');
        $this->assertNotNull($sheet);

        $rows = $sheet->query()->get();
        $this->assertSame([$inYear->id], $rows->pluck('id')->all());

        $this->assertSame(['Incident ID', 'Name', 'Recovered'], $sheet->headings());
        $this->assertSame(['INC-001', 'In year', 1500000.0], $sheet->map($rows->first()));

        // Render: Total Recovered + Total Cases directly below the data rows.
        \Maatwebsite\Excel\Facades\Excel::store(new MultiSheetIncidentsExport(
            $query,
            array_values(ExportActionSchema::columnOptions()),
            array_keys(ExportActionSchema::columnOptions())
        ), 'testing/recovered-this-year.xlsx', 'local');
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load(\Illuminate\Support\Facades\Storage::disk('local')->path('testing/recovered-this-year.xlsx'));
        $ws = $book->getSheetByName('Recovered This Year');
        // Header + 1 data row → summary lands on rows 3–4.
        $this->assertSame('Total Recovered', $ws->getCell('A3')->getValue());
        $this->assertSame(1500000.0, $ws->getCell('B3')->getValue());
        $this->assertSame('Total Cases', $ws->getCell('A4')->getValue());
        $this->assertSame(1, $ws->getCell('B4')->getValue());
        $this->assertSame('Rp 1,500,000', $ws->getCell('B3')->getFormattedValue());
    }

    /**
     * PROD 500 (2026-11-23): ListIncidents hands the table query (withCount +
     * withSum sub-incident subqueries, PROJ-022) to the exports, and the
     * footer's grouped stats selectRaw()ed ON TOP of those columns — MySQL
     * ONLY_FULL_GROUP_BY rejects `SELECT incidents.*, (subs), severity,
     * COUNT(*) ... GROUP BY severity` (error 1055). SQLite can't see it, so
     * pin the SQL SHAPE: no grouped statement may inherit the caller's
     * select columns.
     */
    public function test_grouped_footer_stats_do_not_inherit_the_table_select_columns(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-01-10 10:00', 'recovered_fund' => 100]);

        // The table query's exact PROJ-022 shape (IncidentResource::table()).
        $query = ExportActionSchema::applyFilters(Incident::query(), [])
            ->withCount('subIncidents')
            ->withSum('subIncidents', 'potential_fund_loss')
            ->withSum('subIncidents', 'recovered_fund')
            ->withSum('subIncidents', 'fund_loss');

        $export = new MultiSheetIncidentsExport(
            $query,
            array_values(ExportActionSchema::columnOptions()),
            array_keys(ExportActionSchema::columnOptions())
        );

        $connection = $query->getConnection();
        $connection->enableQueryLog();
        try {
            Excel::store($export, 'testing/footer-sql-shape.xlsx', 'local');
        } finally {
            $grouped = collect($connection->getQueryLog())
                ->pluck('query')
                ->filter(fn (string $sql) => str_contains(strtolower($sql), 'group by'));
            $connection->disableQueryLog();
        }

        // The footer's severity + fund_status plucks must actually have run.
        $this->assertNotEmpty($grouped);

        foreach ($grouped as $sql) {
            $this->assertStringNotContainsString(
                'sub_incidents_count',
                $sql,
                "A grouped footer aggregate inherited the table's withCount column: {$sql}"
            );
            $this->assertStringNotContainsString(
                'sub_incidents_sum',
                $sql,
                "A grouped footer aggregate inherited the table's withSum column: {$sql}"
            );
        }
    }
}
