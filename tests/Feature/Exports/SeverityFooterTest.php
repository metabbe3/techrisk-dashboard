<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Exports\GroupedIncidentsExport;
use App\Exports\IncidentTableExport;
use App\Exports\MultiSheetIncidentsExport;
use App\Exports\QuarterlyReportExport;
use App\Filament\Actions\ExportActionSchema;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Owner rule 2026-10-27 (banner restyle, iteration 3): every
 * incident-listing tab ends with two MERGED single-line banners spanning
 * the table's width — a per-column grid inherited the incident table's
 * column widths and read badly. Banner 1: severity counts P1..X4 (zeros
 * shown, METRIC_ELIGIBLE order). Banner 2: stats whose Avg MTTR is SPLIT
 * by the storage convention (owner 2026-10-13): positive mttr = minutes,
 * negative mttr = elapsed days (averaged abs) — buckets never mix units.
 * Fund counts describe the tab's OWN rows (a null-fund row counts in
 * severity but lands in no fund bucket).
 */
class SeverityFooterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Six rows, distinct severities, mixed fund statuses incl. a
        // null-fund one. Minutes-rows 60,60,60,60,30 + one days-row
        // (mttr -3, Confirmed loss — the elapsed-days convention); the
        // varied 30 makes the minutes average membership-sensitive.
        $make = fn (string $date, array $attrs = []) => Incident::factory()->createQuietly(array_merge([
            'classification' => 'Incident', 'incident_status' => 'Completed', 'incident_type' => 'Tech',
            'incident_date' => "{$date} 10:00", 'mttr' => 60, 'business_category' => ['Core Payment'],
        ], $attrs));

        $make('2033-01-10', ['severity' => 'P1', 'fund_status' => 'Confirmed loss']);
        $make('2033-01-20', ['severity' => 'P2', 'fund_status' => 'Non fundLoss', 'mttr' => 30]); // varied so minutes-bucket membership is pinned
        $make('2033-01-25', ['severity' => 'P1', 'fund_status' => 'Confirmed loss']);
        $make('2033-01-30', ['severity' => 'X2', 'fund_status' => null]);            // severity counts, no fund bucket
        $make('2033-02-10', ['severity' => 'P3', 'fund_status' => 'Fully recovered']); // excluded from the baseline
        $make('2033-02-20', ['severity' => 'P1', 'fund_status' => 'Confirmed loss', 'mttr' => -3]); // days bucket
    }

    private function baseline(): \Illuminate\Database\Eloquent\Builder
    {
        return ExportActionSchema::baselineQuery(
            ExportActionSchema::applyFilters(Incident::query(), []),
            [],
        );
    }

    /** @return Spreadsheet */
    private function render($export)
    {
        $fname = 'severity-footer-test-'.uniqid().'.xlsx';
        Excel::store($export, $fname, 'local');
        $spread = IOFactory::load(Storage::disk('local')->path($fname));
        Storage::disk('local')->delete($fname);

        return $spread;
    }

    public function test_quarterly_all_cases_banners_below_total_cases(): void
    {
        // Baseline = 5 rows (P3 Fully recovered excluded): header 1 + 5 data
        // → Total Cases at 8 → severity banner 9, stats banner 10.
        $data = $this->render(new QuarterlyReportExport($this->baseline(), Incident::query()))
            ->getSheetByName('All Cases');

        $this->assertSame('Total Cases', $data->getCell('A8')->getValue());
        $this->assertSame('P1: 3 | P2: 1 | P3: 0 | P4: 0 | X1: 0 | X2: 1 | X3: 0 | X4: 0', $data->getCell('A9')->getValue());
        // Minutes bucket = (60+30+60+60)/4 = 52.5 — the varied 30 pins
        // membership; days bucket = |−3| = 3 must NOT pollute it (would be
        // 45). Fund 3 / Non-Fund 1.
        $this->assertSame('Avg MTTR (mins): 52.5 | Avg MTTR (days): 3 | Avg MTBF (days): 10.25 | Fund Loss: 3 | Non Fund Loss: 1', $data->getCell('A10')->getValue());
        // The banners are merged across the table width (12 fixed columns
        // → L), not one cell per table column.
        $this->assertContains('A9:L9', $data->getMergeCells());
        $this->assertContains('A10:L10', $data->getMergeCells());
    }

    public function test_quarterly_fund_loss_tab_banner_matches_its_own_rows(): void
    {
        // Fund Loss tab = the 3 Confirmed loss rows: banner counts equal
        // the tab's own membership, not the workbook's.
        $data = $this->render(new QuarterlyReportExport($this->baseline(), Incident::query()))
            ->getSheetByName('Fund Loss');

        // 3 data rows → Total Cases at 6 → banners 7/8. Minutes avg is the
        // tab's own two 60s — P2's 30 is not on this tab. MTBF 20.5 = the
        // tab's own chain now (owner 2026-11-16: 01-10→01-25 = 15,
        // 01-25→02-20 = 26; 41/2) — the old 13 counted the Non fundLoss
        // and fund-null rows' dates in the baseline chain.
        $this->assertSame('P1: 3 | P2: 0 | P3: 0 | P4: 0 | X1: 0 | X2: 0 | X3: 0 | X4: 0', $data->getCell('A7')->getValue());
        $this->assertSame('Avg MTTR (mins): 60 | Avg MTTR (days): 3 | Avg MTBF (days): 20.5 | Fund Loss: 3 | Non Fund Loss: 0', $data->getCell('A8')->getValue());
    }

    public function test_all_tabs_all_cases_banner_below_summary_block(): void
    {
        // Summary block: label at lastDataRow+2, header +3, values +4 →
        // banners at +6/+7.
        $export = new MultiSheetIncidentsExport($this->baseline(), ['Title', 'MTBF (days)'], ['title', 'mtbf'],
            Incident::query()->whereIn('severity', ['P1', 'P2', 'P3', 'X2']));
        $data = $this->render($export)->getSheetByName('All Cases');

        $this->assertSame('P1: 3 | P2: 1 | P3: 0 | P4: 0 | X1: 0 | X2: 1 | X3: 0 | X4: 0', $data->getCell('A12')->getValue());
        $this->assertSame('Avg MTTR (mins): 52.5 | Avg MTTR (days): 3 | Avg MTBF (days): 10.25 | Fund Loss: 3 | Non Fund Loss: 1', $data->getCell('A13')->getValue());
    }

    public function test_group_by_category_sheet_has_banner(): void
    {
        // PerCategorySheet had no footer at all before — its first AfterSheet.
        $export = new GroupedIncidentsExport(
            ExportActionSchema::applyFilters(Incident::query(), []),
            'business_category',
        );
        $data = $this->render($export)->getSheetByName('Core Payment');

        // 6 rows share Core Payment (JSON membership — the excluded P3 too):
        // header 1 + 6 → banners 9/10. Minutes = (60+30+60+60+60)/5 = 54.
        $this->assertSame('P1: 3 | P2: 1 | P3: 1 | P4: 0 | X1: 0 | X2: 1 | X3: 0 | X4: 0', $data->getCell('A9')->getValue());
        // No MTBF segment here — PerCategorySheet is a stored-metric sheet; its
        // group averages live on the GroupSummary front sheet.
        $this->assertSame('Avg MTTR (mins): 54 | Avg MTTR (days): 3 | Fund Loss: 3 | Non Fund Loss: 1', $data->getCell('A10')->getValue());
    }

    public function test_day_based_only_tab_banner_shows_dash_for_minutes_bucket(): void
    {
        // Empty buckets must render '-', not 0 — a day-based-only tab (the
        // norm for Fund Loss) nulls the minutes bucket. Scoped directly to
        // the mttr<0 row so the setUp minutes-rows can't populate it.
        $sheet = new \App\Exports\Sheets\SingleIncidentSheetExport(
            Incident::query()->where('mttr', '<', 0),
            'Fund Loss',
            ['Title'],
            ['title'],
        );
        $data = $this->render($sheet)->getSheetByName('Fund Loss');

        $r = $data->getHighestDataRow() - 1; // stats banner = last row
        $this->assertSame('P1: 1 | P2: 0 | P3: 0 | P4: 0 | X1: 0 | X2: 0 | X3: 0 | X4: 0', $data->getCell("A{$r}")->getValue());
        $this->assertSame('Avg MTTR (mins): - | Avg MTTR (days): 3 | Avg MTBF (days): 0 | Fund Loss: 1 | Non Fund Loss: 0', $data->getCell('A'.($r + 1))->getValue());
    }

    public function test_custom_preset_banner_counts_from_collection(): void
    {
        // FromCollection path: counts derive from the loaded collection —
        // the sign split must match the SQL split. Custom columns are few
        // (Title + MTBF = B), so the banner merges to the min-L floor —
        // merged cells clip, the floor keeps the line readable.
        $incidents = $this->baseline()->orderBy('incident_date')->get();
        $stats = ['totalCases' => 5, 'avgMttr' => 52.5, 'totalPotentialFundLoss' => 0, 'totalFundLoss' => 0, 'totalRecoveredFund' => 0];
        $export = new IncidentTableExport($incidents, $stats, ['Title', 'MTBF (days)'], ['title', 'mtbf']);
        $data = $this->render($export)->getSheet(0);

        // 5 data rows: summary values at 10 → banners 12/13. MTBF 7.5:
        // no mtbfScope ctor arg → legacy GLOBAL chain (P3 included,
        // 02-20 telescopes to 10): 10+5+5+10 / 4.
        $this->assertSame('P1: 3 | P2: 1 | P3: 0 | P4: 0 | X1: 0 | X2: 1 | X3: 0 | X4: 0', $data->getCell('A12')->getValue());
        $this->assertSame('Avg MTTR (mins): 52.5 | Avg MTTR (days): 3 | Avg MTBF (days): 7.5 | Fund Loss: 3 | Non Fund Loss: 1', $data->getCell('A13')->getValue());
        $this->assertContains('A12:L12', $data->getMergeCells(), 'narrow sheet merges to the min-L floor — the ~95-char stats line must fit');
    }
}
