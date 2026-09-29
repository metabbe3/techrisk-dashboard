<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Enums\Severity;
use App\Exports\Sheets\ExecutiveCalcSheet;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-022 (executive half): the Calc severity block iterated every
 * Severity case with a Collection where() that enum casts defeat
 * (enum instance never == its string value → every pie count was 0),
 * the "Open" KPI had the inverse blindness (!= matched everything), and
 * the 4 charts anchored at row 12 — floating over the month table that
 * ends at row 13.
 *
 * Owner rule (2026-09-29): severity breakdown is P1–P4 + X1–X4 only.
 */
class ExecutiveCalcSheetTest extends TestCase
{
    use RefreshDatabase;

    public function test_severity_block_is_metric_eligible_and_counts_survive_enum_casts(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_status' => 'Completed']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'incident_status' => 'In progress']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'G', 'incident_status' => 'Completed']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'Non Incident', 'incident_status' => 'Completed']);

        $calc = new ExecutiveCalcSheet(Incident::query());

        // Exactly the 8 eligible severities, in enum order — no G / Non Incident rows.
        $this->assertSame(Severity::METRIC_ELIGIBLE, array_column($calc->agg['sev'], 0));

        // Enum-cast aware counting: P1 and P2 counted, not zeroed.
        $counts = array_column($calc->agg['sev'], 1, 0);
        $this->assertSame(1, $counts['P1']);
        $this->assertSame(1, $counts['P2']);
        $this->assertSame(0, $counts['X1']);

        // "Open" = non-completed among the exported set — not every row.
        $this->assertSame(1, $calc->agg['kpi']['open']);
        $this->assertSame(4, $calc->agg['kpi']['totalCases']);
    }

    public function test_charts_cover_eligible_severity_and_do_not_overlap_data(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1']);

        $calc = new ExecutiveCalcSheet(Incident::query());
        $charts = $calc->charts();
        $this->assertCount(4, $charts);

        // Month table occupies rows 2–13 (cols A–G): every chart must
        // start below it (row 15+ leaves a blank separator row).
        foreach ($charts as $chart) {
            $cell = $chart->getTopLeftPosition()['cell'] ?? '';
            $this->assertMatchesRegularExpression('/^[A-Z]+(\d+)$/', $cell);
            preg_match('/(\d+)$/', $cell, $m);
            $this->assertGreaterThanOrEqual(15, (int) $m[1], "chart anchored at {$cell} overlaps the data table");
        }

        // The severity pie reads exactly the 8 eligible rows (C2:C9 / D2:D9).
        // The severity strings are the pie's CATEGORY series (legend source).
        $sevChart = $charts[1];
        $labels = $sevChart->getPlotArea()->getPlotGroup()[0]->getPlotCategories()[0];
        $values = $sevChart->getPlotArea()->getPlotGroup()[0]->getPlotValues()[0];
        $this->assertSame('Calc!$C$2:$C$9', $labels->getDataSource());
        $this->assertSame('Calc!$D$2:$D$9', $values->getDataSource());
    }
}
