<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Exports\Concerns\IdrFormat;
use App\Exports\QuarterlyReportExport;
use App\Exports\Sheets\QuarterlyCaseSheet;
use App\Filament\Actions\ExportActionSchema;
use App\Models\Incident;
use App\Models\Label;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Quarterly Report preset (owner decision 2026-10-05): tab membership =
 * All Tabs rules (classification Incident; Recovered = recovered_fund > 0;
 * Fund Loss = Confirmed loss; Non Fund Loss = Non fundLoss) plus one tab
 * per quarter holding only that quarter's incidents. No Summary sheet
 * (owner 2026-10-05). MTBF column = full-year gap sequence
 * (IncidentTableExport semantics, NOT the per-tab All Tabs sequence).
 */
class QuarterlyReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sheet_structure_and_titles(): void
    {
        $sheets = $this->export()->sheets();

        // No Summary sheet (owner 2026-10-05); no rows seeded → no quarter tabs.
        $this->assertSame(
            ['All Cases', 'Recovered Cases', 'Fund Loss', 'Non Fund Loss'],
            array_map(fn ($sheet) => $sheet->title(), $sheets)
        );
    }

    public function test_quarter_tabs_scope_to_that_quarter_and_incidents_only(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-01-10 10:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'incident_date' => '2026-03-20 10:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-04-15 10:00']);
        // An Issue in Q3 must not spawn a Q3 tab — quarters are Incident-only.
        Incident::factory()->createQuietly(['classification' => 'Issue', 'severity' => 'X1', 'incident_date' => '2026-07-10 10:00']);

        $sheets = $this->export()->sheets();
        $titles = array_map(fn ($sheet) => $sheet->title(), $sheets);

        $this->assertContains('Q1 2026', $titles);
        $this->assertContains('Q2 2026', $titles);
        $this->assertNotContains('Q3 2026', $titles);

        $q1 = $sheets[array_search('Q1 2026', $titles, true)];
        $this->assertSame(2, $q1->query()->count());
        $this->assertCount(12, $q1->map($q1->query()->first()));
    }

    public function test_tab_membership_follows_multi_sheet_rules(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Issue', 'severity' => 'X1', 'incident_date' => '2026-01-16 10:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-01-10 10:00', 'recovered_fund' => 500, 'fund_status' => 'Fully recovered']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-02-10 10:00', 'fund_loss' => 1000, 'fund_status' => 'Confirmed loss']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'incident_date' => '2026-03-10 10:00', 'fund_status' => 'Non fundLoss']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P3', 'incident_date' => '2026-04-10 10:00', 'fund_status' => 'Potential recovery']);

        $sheets = $this->export()->sheets();
        $counts = [];
        foreach ($sheets as $sheet) {
            if (method_exists($sheet, 'query')) {
                $counts[$sheet->title()] = $sheet->query()->count();
            }
        }

        // The Issue row never reaches a tab (classification = Incident);
        // quarter tabs carry only their quarter's incidents (Jan–Mar → Q1, Apr → Q2).
        $this->assertSame([
            'All Cases' => 4, 'Recovered Cases' => 1, 'Fund Loss' => 1, 'Non Fund Loss' => 1,
            'Q1 2026' => 3, 'Q2 2026' => 1,
        ], $counts);
    }

    public function test_tab_membership_stacks_with_export_filters(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-01-15 10:00', 'recovered_fund' => 100, 'fund_status' => 'Fully recovered']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-04-15 10:00', 'fund_loss' => 100, 'fund_status' => 'Confirmed loss']);

        $query = ExportActionSchema::applyFilters(Incident::query(), ['f_quarter' => ['2026-Q1']]);
        $sheets = (new QuarterlyReportExport($query))->sheets();
        $counts = [];
        foreach ($sheets as $sheet) {
            if (method_exists($sheet, 'query')) {
                $counts[$sheet->title()] = $sheet->query()->count();
            }
        }

        // The Q2 row drops from every tab — filters stack on top of tab rules
        // (only the Q1 incident remains, so no Q2 tab exists at all).
        $this->assertSame(['All Cases' => 1, 'Recovered Cases' => 1, 'Fund Loss' => 0, 'Non Fund Loss' => 0, 'Q1 2026' => 1], $counts);
    }

    public function test_data_tab_mtbf_column_matches_incident_table_export_semantics(): void
    {
        [$a, $g, $b, $outlier, $c] = $this->seedMtbfYear('2037');

        $sheet = $this->export()->sheets()[1]; // All Cases

        $this->assertSame('-', $sheet->map($a)[2], 'first of the year renders -');
        $this->assertSame('-', $sheet->map($g)[2], 'G rows never join the sequence');
        $this->assertSame(20, $sheet->map($b)[2], 'gap skips the G row (Jan 10 → Jan 30)');
        $this->assertSame('Outlier', $sheet->map($outlier)[2]);
        $this->assertSame(25, $sheet->map($c)[2], 'gap telescopes over the outlier (Jan 30 → Feb 24)');
    }

    public function test_data_tab_footer_is_count_and_mean_of_displayed_column(): void
    {
        $this->seedMtbfYear('2038', withNoise: false);
        $sheet = $this->allCasesSheet();
        $spread = $this->render($sheet);

        $data = $spread->getSheetByName('All Cases');
        // 3 data rows (rows 2–4), footer two rows below the table.
        $this->assertSame('Total Cases', $data->getCell('A6')->getValue());
        $this->assertSame(3, $data->getCell('B6')->getValue());
        $this->assertSame('Avg MTBF (days)', $data->getCell('C6')->getValue());
        // Column displayed '-', 20, 25 → '-' drops out → mean 22.5.
        $this->assertEqualsWithDelta(22.5, (float) $data->getCell('D6')->getValue(), 0.001);

        // Single-incident year: no numeric gaps → avg 0, not a division error.
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2040-01-10 10:00']);
        $spread = $this->render($this->allCasesSheet());
        // Header + 1 data row = lastDataRow 2 → footer at row 4.
        $this->assertSame(0, (int) $spread->getSheetByName('All Cases')->getCell('D4')->getValue());
    }

    public function test_full_render_workbook_cells_and_idr_format(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P4', 'incident_date' => '2026-01-09 19:40',
            'incident_status' => 'Completed', 'fund_status' => 'Confirmed loss', 'fund_loss' => 146250,
            'business_category' => ['Core Payment'], 'root_cause_category' => ['Software Bugs'], 'responsible_team' => ['Dev']]);

        $spread = $this->render($this->export());

        $this->assertSame(
            ['All Cases', 'Recovered Cases', 'Fund Loss', 'Non Fund Loss', 'Q1 2026'],
            $spread->getSheetNames()
        );

        $all = $spread->getSheetByName('All Cases');
        $this->assertSame('-', $all->getCell('C2')->getValue(), 'single incident of the year → -');
        $this->assertSame('Q1 2026', $all->getCell('F2')->getValue());
        $this->assertSame('Core Payment', $all->getCell('J2')->getValue());
        $this->assertSame('Software Bugs', $all->getCell('K2')->getValue());

        // Fund cell: raw summable number + native Rp format, never a string.
        $this->assertSame(DataType::TYPE_NUMERIC, $all->getCell('H2')->getDataType());
        $this->assertEqualsWithDelta(146250.0, (float) $all->getCell('H2')->getValue(), 0.001);
        $this->assertSame(IdrFormat::FORMAT, $all->getStyle('H2')->getNumberFormat()->getFormatCode());

        // The quarter tab mirrors the case-tab layout with the same row.
        $q1 = $spread->getSheetByName('Q1 2026');
        $this->assertSame(DataType::TYPE_NUMERIC, $q1->getCell('H2')->getDataType());
        $this->assertEqualsWithDelta(146250.0, (float) $q1->getCell('H2')->getValue(), 0.001);
    }

    private function export(): QuarterlyReportExport
    {
        return new QuarterlyReportExport(ExportActionSchema::applyFilters(Incident::query(), []));
    }

    private function allCasesSheet(): QuarterlyCaseSheet
    {
        return new QuarterlyCaseSheet(
            ExportActionSchema::applyFilters(Incident::query(), [])->where('classification', 'Incident')->with('labels')->orderBy('incident_date'),
            'All Cases'
        );
    }

    /**
     * 2037-style MTBF year: A=P1 Jan 10, G=Jan 20 (never in sequence),
     * B=P2 Jan 30, Outlier=P1 Feb 1, C=P3 Feb 24. Gaps: B=20 (skips G),
     * C=25 (telescopes over the outlier). Without noise: A, B, C only.
     *
     * @return array{0: \App\Models\Incident, 1: ?\App\Models\Incident, 2: \App\Models\Incident, 3: ?\App\Models\Incident, 4: \App\Models\Incident}
     */
    private function seedMtbfYear(string $year, bool $withNoise = true): array
    {
        $make = fn (string $date, array $attrs = []) => Incident::factory()->createQuietly(array_merge([
            'classification' => 'Incident', 'fund_status' => 'Non fundLoss', 'incident_date' => "{$date} 10:00",
        ], $attrs));

        $a = $make("{$year}-01-10", ['severity' => 'P1']);
        $g = $withNoise ? $make("{$year}-01-20", ['severity' => 'G']) : null;
        $b = $make("{$year}-01-30", ['severity' => 'P2']);
        $outlier = $withNoise ? $make("{$year}-02-01", ['severity' => 'P1']) : null;
        $c = $make("{$year}-02-24", ['severity' => 'P3']);
        if ($outlier) {
            $outlier->labels()->attach(Label::firstOrCreate(['name' => Label::OUTLIER]));
        }

        return [$a, $g, $b, $outlier, $c];
    }

    /** @return \PhpOffice\PhpSpreadsheet\Spreadsheet */
    private function render($export)
    {
        $fname = 'quarterly-report-test-'.uniqid().'.xlsx';
        Excel::store($export, $fname, 'local');
        $spread = IOFactory::load(Storage::disk('local')->path($fname));
        Storage::disk('local')->delete($fname);

        return $spread;
    }
}
