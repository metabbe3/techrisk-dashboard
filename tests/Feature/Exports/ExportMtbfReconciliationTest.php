<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Exports\IncidentTableExport;
use App\Exports\Sheets\SingleIncidentSheetExport;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Owner report (2026-09-30): All-Tabs "Non Fund Loss" sheet — manually
 * averaging the MTBF (days) column gave 14.11 while the bottom Avg MTBF
 * said 14.222. Both are days; the column's first-of-year term was an
 * invented Jan-1 anchor (dayOfYear) the span-based bottom never counted,
 * so the two could never reconcile.
 *
 * Rule now: the first incident of a year has no predecessor → its cell
 * renders '-' (like mttr nulls), and the sheet's bottom Avg MTBF is the
 * MEAN OF THE DISPLAYED COLUMN — which for a single-year set telescopes
 * to exactly span/(n-1), the dashboard widgets' number.
 *
 * Years/titles are unique per test: both export classes key their MTBF
 * sequence in a static cache that outlives RefreshDatabase.
 */
class ExportMtbfReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function store(object $export, string $fname): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        Excel::store($export, $fname, 'local');
        $sheet = IOFactory::load(Storage::disk('local')->path($fname))->getSheet(0);
        Storage::disk('local')->delete($fname);

        return $sheet;
    }

    public function test_all_tabs_sheet_bottom_equals_column_average(): void
    {
        // Year 2027 / title 'All Cases' — untouched by other tests (static cache).
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2027-01-10 10:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'G', 'incident_date' => '2027-01-20 10:00']); // never in the sequence
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'incident_date' => '2027-01-30 10:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P3', 'incident_date' => '2027-02-24 10:00']);

        $query = Incident::query()->whereIn('severity', ['P1', 'P2', 'P3'])->orderBy('incident_date');
        $sheet = $this->store(new SingleIncidentSheetExport($query, 'All Cases', ['Title', 'MTBF (days)'], ['title', 'mtbf']), 't-mtbf-alltabs.xlsx');

        // Column B: first-of-year '-' (no predecessor), then real gaps 20 / 25.
        $this->assertSame('-', $sheet->getCell('B2')->getValue());
        $this->assertSame(20, $sheet->getCell('B3')->getValue());
        $this->assertSame(25, $sheet->getCell('B4')->getValue());

        // Bottom Avg MTBF (3rd summary cell of the last row) = mean of the
        // displayed numeric cells: (20 + 25) / 2 = 22.5 — also exactly the
        // widgets' span/(n-1) for this set. Manual averaging now passes.
        $summaryDataRow = $sheet->getHighestDataRow();
        $this->assertSame(22.5, (float) $sheet->getCell("C{$summaryDataRow}")->getValue());
    }

    public function test_custom_export_bottom_is_computed_not_passed_in(): void
    {
        // Year 2028 + IncidentTableExport's own cache key.
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2028-01-10 10:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'G', 'incident_date' => '2028-01-20 10:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'incident_date' => '2028-01-30 10:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P3', 'incident_date' => '2028-02-24 10:00']);

        $incidents = Incident::query()->whereIn('severity', ['P1', 'P2', 'P3'])->orderBy('incident_date')->get();
        // avgMtbf deliberately wrong (999): the sheet must compute the column
        // mean itself, not trust the footer-sourced stat.
        $stats = ['totalCases' => 3, 'avgMttr' => 10, 'avgMtbf' => 999,
            'totalPotentialFundLoss' => 0.0, 'totalFundLoss' => 0.0, 'totalRecoveredFund' => 0.0];

        $sheet = $this->store(new IncidentTableExport($incidents, $stats, ['Title', 'MTBF (days)'], ['title', 'mtbf']), 't-mtbf-custom.xlsx');

        $this->assertSame('-', $sheet->getCell('B2')->getValue());
        $this->assertSame(20, $sheet->getCell('B3')->getValue());
        $this->assertSame(25, $sheet->getCell('B4')->getValue());

        $summaryDataRow = $sheet->getHighestDataRow();
        $this->assertSame(22.5, (float) $sheet->getCell("C{$summaryDataRow}")->getValue());
    }

    public function test_single_row_year_shows_dash_and_zero_average(): void
    {
        // Same title as test 1 but year 2028 → fresh static-cache key.
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2028-06-15 10:00']);

        $sheet = $this->store(
            new SingleIncidentSheetExport(Incident::query(), 'All Cases', ['Title', 'MTBF (days)'], ['title', 'mtbf']),
            't-mtbf-single.xlsx'
        );

        // One incident = no gap exists: column '-', bottom 0.
        $this->assertSame('-', $sheet->getCell('B2')->getValue());
        $summaryDataRow = $sheet->getHighestDataRow();
        $this->assertSame(0.0, (float) $sheet->getCell("C{$summaryDataRow}")->getValue());
    }
}
