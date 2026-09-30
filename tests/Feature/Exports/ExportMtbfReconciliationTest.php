<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Exports\IncidentTableExport;
use App\Exports\Sheets\IssuesMetricSheetExport;
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
 * Follow-up (same day, from the owner's downloaded workbook): the sequence
 * caches were `static`, so a long-lived FPM worker kept sequences from the
 * FIRST export it served — incidents created later dashed out mid-year and
 * vanished from column and bottom alike (14 mid-year dashes on the live
 * All Cases tab). Caches are now per-instance; each export re-sequences.
 * The Issues-MTBF sheet got the same reconciliation as the other tabs, and
 * a tab with only day-based MTTR rows shows '-' as Avg MTTR, not 0.
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

    public function test_issues_mtbf_sheet_bottom_equals_column_average(): void
    {
        // Year 2029, classification Issue — the Issues-MTBF tab's own sequence.
        Incident::factory()->createQuietly(['classification' => 'Issue', 'severity' => 'P1', 'incident_date' => '2029-01-10 10:00']);
        Incident::factory()->createQuietly(['classification' => 'Issue', 'severity' => 'P2', 'incident_date' => '2029-01-30 10:00']);
        Incident::factory()->createQuietly(['classification' => 'Issue', 'severity' => 'P3', 'incident_date' => '2029-02-24 10:00']);

        $query = Incident::query()->where('classification', 'Issue')->orderBy('incident_date');
        $sheet = $this->store(new IssuesMetricSheetExport($query, 'Issues - MTBF', 'mtbf'), 't-mtbf-issues.xlsx');

        // Column C: first-of-year '-' (no predecessor — was a Jan-1 dayOfYear
        // anchor), then the real gaps 20 / 25.
        $this->assertSame('-', $sheet->getCell('C2')->getValue());
        $this->assertSame(20, $sheet->getCell('C3')->getValue());
        $this->assertSame(25, $sheet->getCell('C4')->getValue());

        // Bottom "Average MTBF" value (label col A, value col B, last row) =
        // mean of the displayed numeric cells: (20 + 25) / 2 = 22.5.
        $this->assertSame(22.5, (float) $sheet->getCell('B'.$sheet->getHighestDataRow())->getValue());
    }

    public function test_mtbf_sequence_is_not_stale_across_instances(): void
    {
        // Prod bug 2026-09-30: a static sequence cache in long-lived FPM
        // workers froze at the first export — incidents created later got no
        // MTBF cell ('-') and dropped out of the bottom. A fresh export
        // instance must re-sequence the year.
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2030-01-10 10:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'incident_date' => '2030-01-30 10:00']);

        $makeSheet = fn () => new SingleIncidentSheetExport(
            Incident::query()->orderBy('incident_date'),
            'All Cases',
            ['Title', 'MTBF (days)'],
            ['title', 'mtbf']
        );
        $this->store($makeSheet(), 't-mtbf-stale1.xlsx');

        // Incident created AFTER the first export, same year.
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P3', 'incident_date' => '2030-02-24 10:00']);

        $sheet = $this->store($makeSheet(), 't-mtbf-stale2.xlsx');
        $this->assertSame(25, $sheet->getCell('B4')->getValue());
    }

    public function test_all_day_based_mttr_tab_shows_dash_average(): void
    {
        // Fund-loss style rows: mttr stored negative (days). No positive
        // (minutes) MTTR exists → Avg MTTR is no data, not 0.
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2031-01-10 10:00', 'mttr' => -5]);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'incident_date' => '2031-01-30 10:00', 'mttr' => -10]);

        $query = Incident::query()->orderBy('incident_date');
        $sheet = $this->store(new SingleIncidentSheetExport($query, 'Fund Loss', ['Title', 'MTTR (mins)'], ['title', 'mttr']), 't-mttr-days.xlsx');

        $summaryDataRow = $sheet->getHighestDataRow();
        $this->assertSame('-', (string) $sheet->getCell("B{$summaryDataRow}")->getValue());
    }
}
