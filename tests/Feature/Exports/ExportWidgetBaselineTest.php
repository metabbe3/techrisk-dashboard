<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Exports\MultiSheetIncidentsExport;
use App\Exports\QuarterlyReportExport;
use App\Filament\Actions\ExportActionSchema;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner rule 2026-10-23 (amended 2026-10-27): the export baseline set is
 * widget-aligned — Confirmed loss + Non fundLoss + null, the same rows
 * the dashboard total tile counts (Incident::excludedFromCounts counts a
 * null fund_status; prod had one such row silently dropping every export
 * one case below the widget). All Cases / Completed (All Tabs), the
 * Quarterly Report's All Cases + quarter tabs, Custom and Executive data
 * run on it by default; an explicit Fund Status filter overrides the
 * default and stays strict (null excluded); the dedicated fund-status
 * tabs keep every fund status (nothing is lost).
 */
class ExportWidgetBaselineTest extends TestCase
{
    use RefreshDatabase;

    private Incident $a;

    private Incident $b;

    private Incident $c;

    private Incident $open;

    protected function setUp(): void
    {
        parent::setUp();

        // 2033 chain: A Jan 10 Confirmed, B Jan 20 Fully recovered
        // (excluded from baseline), C Jan 30 Confirmed. $open is a
        // non-completed Fully recovered row for the tab checks.
        $make = fn (string $date, array $attrs = []) => Incident::factory()->createQuietly(array_merge([
            'classification' => 'Incident', 'incident_status' => 'Completed', 'incident_type' => 'Tech', 'incident_date' => "{$date} 10:00",
        ], $attrs));

        $this->a = $make('2033-01-10', ['severity' => 'P1', 'fund_status' => 'Confirmed loss']);
        $this->b = $make('2033-01-20', ['severity' => 'P1', 'fund_status' => 'Fully recovered', 'recovered_fund' => 100]);
        $this->c = $make('2033-01-30', ['severity' => 'P2', 'fund_status' => 'Confirmed loss']);
        $this->open = $make('2033-01-25', ['severity' => 'P1', 'fund_status' => 'Fully recovered', 'incident_status' => 'Open']);
    }

    private function baseline(): \Illuminate\Database\Eloquent\Builder
    {
        $query = ExportActionSchema::applyFilters(Incident::query(), []);

        return ExportActionSchema::baselineQuery($query, []);
    }

    public function test_baseline_defaults_to_widget_fund_scope(): void
    {
        $this->assertSame(2, $this->baseline()->count());
        $this->assertTrue($this->baseline()->pluck('id')->contains($this->a->id));
        $this->assertTrue($this->baseline()->pluck('id')->contains($this->c->id));
    }

    public function test_explicit_fund_status_filter_overrides_the_default(): void
    {
        $query = ExportActionSchema::applyFilters(Incident::query(), ['f_fund_status' => ['Fully recovered']]);
        $baseline = ExportActionSchema::baselineQuery($query, ['f_fund_status' => ['Fully recovered']]);

        $ids = $baseline->pluck('id');
        $this->assertTrue($ids->contains($this->b->id));
        $this->assertTrue($ids->contains($this->open->id));
        $this->assertFalse($ids->contains($this->a->id));
    }

    public function test_baseline_keeps_other_export_filters(): void
    {
        $query = ExportActionSchema::applyFilters(Incident::query(), ['f_severity' => ['P2']]);
        $baseline = ExportActionSchema::baselineQuery($query, []);

        $this->assertSame(1, $baseline->count());
        $this->assertSame($this->c->id, $baseline->first()->id);
    }

    public function test_null_fund_status_row_is_in_the_default_baseline(): void
    {
        // The prod 39th case: metric-eligible Incident whose fund_status is
        // empty — counted by the widget tile, must export too.
        $null = Incident::factory()->createQuietly([
            'classification' => 'Incident', 'incident_status' => 'Completed', 'incident_type' => 'Tech',
            'incident_date' => '2033-01-15 10:00', 'severity' => 'P1', 'fund_status' => null,
        ]);

        $ids = $this->baseline()->pluck('id');

        $this->assertTrue($ids->contains($null->id));
        $this->assertSame(3, $this->baseline()->count());
    }

    public function test_explicit_fund_status_filter_still_excludes_null(): void
    {
        $null = Incident::factory()->createQuietly([
            'classification' => 'Incident', 'incident_status' => 'Completed', 'incident_type' => 'Tech',
            'incident_date' => '2033-01-15 10:00', 'severity' => 'P1', 'fund_status' => null,
        ]);

        $query = ExportActionSchema::applyFilters(Incident::query(), ['f_fund_status' => ['Confirmed loss']]);
        $baseline = ExportActionSchema::baselineQuery($query, ['f_fund_status' => ['Confirmed loss']]);

        $ids = $baseline->pluck('id');
        $this->assertTrue($ids->contains($this->a->id));
        $this->assertFalse($ids->contains($null->id));
    }

    public function test_all_tabs_general_sheets_are_scoped_dedicated_keep_their_rows(): void
    {
        $sheets = (new MultiSheetIncidentsExport($this->baseline(), ['Title', 'MTBF (days)'], ['title', 'mtbf'], Incident::query()->whereIn('severity', ['P1', 'P2'])))->sheets();
        $counts = [];
        foreach ($sheets as $sheet) {
            if (! str_starts_with($sheet->title(), 'Issues') && $sheet->title() !== 'All Issues') {
                $counts[$sheet->title()] = $sheet->query()->count();
            }
        }

        // Baseline scope: A + C only; the Fully recovered rows live on in
        // their dedicated tabs and the cross-cutting sheets.
        $this->assertSame([
            'All Cases' => 2,
            'Completed Cases' => 2,
            'Recovered Cases' => 1,        // B (recovered_fund > 0)
            'Recovered This Year' => 0,    // current-year only — fixtures live in 2033
            'P4 Incidents' => 0,
            'Non-Tech Incidents' => 0,
            'Fund Loss' => 2,              // A + C (Confirmed loss)
            'On Going' => 1,               // the open Fully recovered row
            'Potential Recovery' => 0,
            'Fully Recovered' => 2,        // B + open — never emptied by the baseline
            'Non Tech Loss' => 0,
            'Non Fund Loss' => 0,
        ], $counts);

        // The scoped All Cases sequence telescopes over B: C reads 20.
        $allCases = $sheets[0];
        $this->assertSame('-', $allCases->map($this->a)[1]);
        $this->assertSame(20, $allCases->map($this->c)[1]);
    }

    public function test_quarterly_report_all_cases_scoped_recovered_tab_keeps_rows(): void
    {
        $sheets = (new QuarterlyReportExport($this->baseline(), Incident::query()->whereIn('severity', ['P1', 'P2'])))->sheets();
        $counts = [];
        foreach ($sheets as $sheet) {
            $counts[$sheet->title()] = $sheet->query()->count();
        }

        $this->assertSame([
            'All Cases' => 2,          // baseline — the owner's sheet
            'Recovered Cases' => 1,    // B via the dedicated base
            'Fund Loss' => 2,
            'Non Fund Loss' => 0,
            'Q1 2033' => 2,            // quarter tabs are baseline breakdowns
        ], $counts);

        // Scoped sequence: C telescopes over the excluded B row.
        $this->assertSame(20, $sheets[0]->map($this->c)[2]);
    }

    public function test_quarterly_all_cases_sheet_carries_a_null_fund_row(): void
    {
        // The prod 39th case at sheet level, not just baselineQuery level —
        // the export the owner ran must show the null-fund row in All Cases.
        $null = Incident::factory()->createQuietly([
            'classification' => 'Incident', 'incident_status' => 'Completed', 'incident_type' => 'Tech',
            'incident_date' => '2033-01-15 10:00', 'severity' => 'P1', 'fund_status' => null,
        ]);

        $sheets = (new QuarterlyReportExport($this->baseline(), Incident::query()->whereIn('severity', ['P1', 'P2'])))->sheets();
        $allCases = $sheets[0];

        $this->assertSame('All Cases', $allCases->title());
        $this->assertSame(3, $allCases->query()->count());
        $this->assertTrue($allCases->query()->pluck('id')->contains($null->id));
    }
}
