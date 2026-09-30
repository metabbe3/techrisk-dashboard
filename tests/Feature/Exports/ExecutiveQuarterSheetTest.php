<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Exports\ExecutiveIncidentsExport;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Owner addendum (2026-09-30): the Executive export gains one KPI tab per
 * quarter with rows, inserted between Executive Summary and Data. Each tab
 * renders the same widget-aligned KPI cards (ExecutiveCalcSheet::computeKpi)
 * scoped to its quarter, plus a severity breakdown row (P1–P4 + one combined
 * X1–X4 card). Quarters with no rows get no tab.
 */
class ExecutiveQuarterSheetTest extends TestCase
{
    use RefreshDatabase;

    private function store(): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $fname = 't-exec-quarter.xlsx';
        Excel::store(new ExecutiveIncidentsExport(Incident::query()), $fname, 'local');
        $spreadsheet = IOFactory::load(Storage::disk('local')->path($fname));
        Storage::disk('local')->delete($fname);

        return $spreadsheet;
    }

    private function quarter(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, string $title): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $sheet = $book->getSheetByName($title);
        $this->assertNotNull($sheet, "sheet {$title} missing");

        return $sheet;
    }

    public function test_one_tab_per_quarter_between_summary_and_data(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-02-15 10:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'incident_date' => '2026-05-15 10:00']);

        $sheets = (new ExecutiveIncidentsExport(Incident::query()))->sheets();

        $this->assertSame(
            ['Executive Summary', 'Q1 2026', 'Q2 2026', 'Data', 'Calc'],
            array_values(array_map(fn ($sheet) => $sheet->title(), $sheets))
        );
    }

    public function test_quarter_with_no_rows_gets_no_tab(): void
    {
        // Q1 and Q3 rows only — the empty Q2 in between must not appear.
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-02-15 10:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-08-15 10:00']);

        $sheets = (new ExecutiveIncidentsExport(Incident::query()))->sheets();

        $this->assertSame(
            ['Executive Summary', 'Q1 2026', 'Q3 2026', 'Data', 'Calc'],
            array_values(array_map(fn ($sheet) => $sheet->title(), $sheets))
        );
    }

    public function test_quarter_kpi_is_quarter_scoped_and_widget_aligned(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-02-01 10:00', 'fund_status' => 'Non fundLoss', 'incident_status' => 'Completed', 'fund_loss' => 100]);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'incident_date' => '2026-02-02 10:00', 'fund_status' => 'Non fundLoss', 'incident_status' => 'In progress', 'potential_fund_loss' => 200]);
        // Excluded fund status: counts nowhere in the Q1 widget cards.
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-02-03 10:00', 'fund_status' => 'Fully recovered', 'incident_status' => 'Completed', 'fund_loss' => 999]);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-05-01 10:00', 'fund_status' => 'Non fundLoss', 'incident_status' => 'Completed', 'fund_loss' => 50]);

        $book = $this->store();

        $q1 = $this->quarter($book, 'Q1 2026');
        // Card layout: row 4 labels / row 5 values (A=Total Cases, E=Potential,
        // G=Actual) — quarter-scoped, not whole-export.
        $this->assertSame('Total Cases', $q1->getCell('A4')->getValue());
        $this->assertSame(2.0, (float) $q1->getCell('A5')->getValue());
        $this->assertSame(200.0, (float) $q1->getCell('E5')->getValue());
        $this->assertSame(100.0, (float) $q1->getCell('G5')->getValue());

        $q2 = $this->quarter($book, 'Q2 2026');
        $this->assertSame(1.0, (float) $q2->getCell('A5')->getValue());
        $this->assertSame(50.0, (float) $q2->getCell('G5')->getValue());
    }

    public function test_severity_breakdown_row_counts_p1_to_p4_and_combined_x(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-01-10 10:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-02-10 10:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P3', 'incident_date' => '2026-03-10 10:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'X2', 'incident_date' => '2026-01-20 10:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'X4', 'incident_date' => '2026-02-20 10:00']);

        $q1 = $this->quarter($this->store(), 'Q1 2026');

        // Row 7 labels / row 8 values: P1, P2, P3, P4, one combined X1–X4.
        $this->assertSame(['P1', 'P2', 'P3', 'P4', 'X1–X4'], [
            $q1->getCell('A7')->getValue(),
            $q1->getCell('B7')->getValue(),
            $q1->getCell('C7')->getValue(),
            $q1->getCell('D7')->getValue(),
            $q1->getCell('E7')->getValue(),
        ]);
        $this->assertSame([2.0, 0.0, 1.0, 0.0, 2.0], [
            (float) $q1->getCell('A8')->getValue(),
            (float) $q1->getCell('B8')->getValue(),
            (float) $q1->getCell('C8')->getValue(),
            (float) $q1->getCell('D8')->getValue(),
            (float) $q1->getCell('E8')->getValue(),
        ]);
    }
}
