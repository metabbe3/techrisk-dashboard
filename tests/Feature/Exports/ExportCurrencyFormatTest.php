<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Exports\ExecutiveIncidentsExport;
use App\Exports\IncidentTableExport;
use App\Exports\Sheets\GroupSummarySheet;
use App\Exports\Sheets\PerCategorySheet;
use App\Exports\Sheets\SingleIncidentSheetExport;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Owner rule (2026-09-30): every fund number (Potential / Actual Loss /
 * Recovered) renders as Rupiah in every export — via the NATIVE Excel
 * number format '"Rp "#,##0', so cells stay real numbers Excel can sum.
 * The single format definition lives in App\Exports\Concerns\IdrFormat.
 */
class ExportCurrencyFormatTest extends TestCase
{
    use RefreshDatabase;

    private const IDR = '"Rp "#,##0';

    private function store(object $export, string $fname): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        Excel::store($export, $fname, 'local');
        $spreadsheet = IOFactory::load(Storage::disk('local')->path($fname));
        Storage::disk('local')->delete($fname);

        return $spreadsheet;
    }

    private function assertIdrCell(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $cell, ?float $expected = null): void
    {
        $this->assertSame(
            self::IDR,
            $sheet->getStyle($cell)->getNumberFormat()->getFormatCode(),
            "{$sheet->getTitle()}!{$cell} must carry the Rp number format"
        );
        $this->assertSame(
            DataType::TYPE_NUMERIC,
            $sheet->getCell($cell)->getDataType(),
            "{$sheet->getTitle()}!{$cell} must stay a real number, not a text string"
        );
        if ($expected !== null) {
            $this->assertSame($expected, (float) $sheet->getCell($cell)->getValue());
        }
    }

    public function test_per_category_sheet_fund_columns_are_idr_numbers(): void
    {
        Incident::factory()->createQuietly([
            'classification' => 'Incident', 'severity' => 'P1',
            'potential_fund_loss' => 1234567, 'fund_loss' => 100000, 'recovered_fund' => 50000,
        ]);

        $book = $this->store(new PerCategorySheet(Incident::query(), 'severity', false, 'P1', 'P1'), 't-percat.xlsx');
        $sheet = $book->getSheet(0);

        // headings: I=Potential, J=Actual, K=Recovered
        $this->assertIdrCell($sheet, 'I2', 1234567.0);
        $this->assertIdrCell($sheet, 'J2', 100000.0);
        $this->assertIdrCell($sheet, 'K2', 50000.0);
    }

    public function test_group_summary_sheet_fund_columns_are_idr_numbers(): void
    {
        $stats = [[
            'label' => 'Q1 2026', 'value' => '2026-Q1', 'count' => 2,
            'avgMttrMins' => 10.0, 'avgMttrDays' => 0.0, 'avgMtbf' => 5.0, 'mttrDataCount' => 2,
            'potential' => 7654321.0, 'actual' => 200000.0, 'recovered' => 80000.0,
        ]];

        $book = $this->store(new GroupSummarySheet($stats, 'Quarter'), 't-groupsum.xlsx');
        $sheet = $book->getSheet(0);

        // headings: G=Potential, H=Actual, I=Recovered
        $this->assertIdrCell($sheet, 'G2', 7654321.0);
        $this->assertIdrCell($sheet, 'H2', 200000.0);
        $this->assertIdrCell($sheet, 'I2', 80000.0);
    }

    public function test_executive_workbook_fund_cells_are_idr_numbers(): void
    {
        // Two rows: the Potential card counts open cases only and the Actual
        // card Completed only (widget rule 2026-09-30), so no single row can
        // feed both — one open row carries the potential, one completed row
        // the actual loss. Recovered takes either.
        Incident::factory()->createQuietly([
            'classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-02-15 10:00',
            'incident_status' => 'Open',
            'potential_fund_loss' => 1234567, 'recovered_fund' => 50000,
        ]);
        Incident::factory()->createQuietly([
            'classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-02-16 10:00',
            'incident_status' => 'Completed',
            'fund_loss' => 100000,
        ]);

        $book = $this->store(new ExecutiveIncidentsExport(Incident::query()), 't-exec.xlsx');

        // Summary cards E5/F5/G5 = Potential / Recovered / Actual Loss
        $summary = $book->getSheetByName('Executive Summary');
        $this->assertNotNull($summary);
        $this->assertIdrCell($summary, 'E5', 1234567.0);
        $this->assertIdrCell($summary, 'F5', 50000.0);
        $this->assertIdrCell($summary, 'G5', 100000.0);

        // Data sheet H/I/J — row 2 = the open row, row 3 = the completed row.
        $data = $book->getSheetByName('Data');
        $this->assertNotNull($data);
        $this->assertIdrCell($data, 'H2', 1234567.0);
        $this->assertIdrCell($data, 'J2', 50000.0);
        $this->assertIdrCell($data, 'I3', 100000.0);

        // Calc sheet F/G = monthly Potential / Recovered (format only — value bucket depends on month)
        $calc = $book->getSheetByName('Calc');
        $this->assertNotNull($calc);
        $this->assertSame(self::IDR, $calc->getStyle('F2')->getNumberFormat()->getFormatCode());
        $this->assertSame(self::IDR, $calc->getStyle('G2')->getNumberFormat()->getFormatCode());
    }

    public function test_custom_export_fund_columns_and_summary_are_idr_numbers(): void
    {
        $incident = Incident::factory()->createQuietly([
            'classification' => 'Incident', 'severity' => 'P1',
            'potential_fund_loss' => 1234567, 'fund_loss' => 100000, 'recovered_fund' => 50000,
        ]);
        $columns = ['title', 'potential_fund_loss', 'fund_loss', 'recovered_fund'];
        $stats = [
            'totalCases' => 1, 'avgMttr' => 10, 'avgMtbf' => 5,
            'totalPotentialFundLoss' => 1234567.0, 'totalFundLoss' => 100000.0, 'totalRecoveredFund' => 50000.0,
        ];

        $book = $this->store(
            new IncidentTableExport(collect([$incident]), $stats, array_combine($columns, $columns), $columns),
            't-custom.xlsx'
        );
        $sheet = $book->getSheet(0);

        // Dynamic columns: B/C/D are the fund columns here.
        $this->assertIdrCell($sheet, 'B2', 1234567.0);
        $this->assertIdrCell($sheet, 'C2', 100000.0);
        $this->assertIdrCell($sheet, 'D2', 50000.0);

        // Summary block: D/E/F of the summary data row (last row of the sheet).
        $summaryDataRow = $sheet->getHighestDataRow();
        $this->assertIdrCell($sheet, "D{$summaryDataRow}", 1234567.0);
        $this->assertIdrCell($sheet, "E{$summaryDataRow}", 100000.0);
        $this->assertIdrCell($sheet, "F{$summaryDataRow}", 50000.0);
    }

    public function test_all_tabs_sheet_map_returns_float_for_fund_columns(): void
    {
        $incident = Incident::factory()->createQuietly([
            'classification' => 'Incident', 'severity' => 'P1', 'potential_fund_loss' => 1234567.5,
        ]);

        $sheet = new SingleIncidentSheetExport(Incident::query(), 'Fund Loss', ['Potential Fund Loss'], ['potential_fund_loss']);

        $this->assertSame(1234567.5, $sheet->map($incident)[0]);
    }
}
