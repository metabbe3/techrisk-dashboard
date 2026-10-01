<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CalculateIncidentMetrics;
use App\Models\Incident;
use App\Models\Label;
use App\Services\Metrics\IncidentMetricsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner rule 2026-10-01: an incident tagged with the "Outlier" label is
 * excluded from every MTBF/MTTR computation. The calculator (single writer
 * of the stored columns) NULLS all metric columns on outlier rows, and every
 * predecessor lookup skips outlier rows — so a successor's gap is measured
 * to the previous NON-outlier, keeping the telescoping property
 * (sum of gaps = span) that the export reconciliation depends on.
 */
class OutlierMetricsExclusionTest extends TestCase
{
    use RefreshDatabase;

    private function runJob(Incident $incident): void
    {
        (new CalculateIncidentMetrics($incident))->handle(app(IncidentMetricsCalculator::class));
    }

    private function makeIncident(array $attrs = []): Incident
    {
        return Incident::factory()->createQuietly(array_merge([
            'classification' => 'Incident',
            'severity' => 'P1',
        ], $attrs));
    }

    private function tagOutlier(Incident $incident): void
    {
        $incident->labels()->attach(Label::firstOrCreate(['name' => Label::OUTLIER]));
    }

    public function test_outlier_row_gets_all_metric_columns_nulled(): void
    {
        $outlierLabel = Label::create(['name' => Label::OUTLIER]);

        // Pre-filled metric values + a stop_bleeding_at that would normally
        // produce a real MTTR — the guard must null everything regardless.
        $outlier = $this->makeIncident([
            'incident_date' => '2032-03-10 08:00',
            'stop_bleeding_at' => '2032-03-10 10:00',
            'mttr' => 999, 'mtbf' => 999, 'mtbf_completed' => 999, 'mtbf_recovered' => 999,
            'mtbf_p4' => 999, 'mtbf_non_tech' => 999, 'mtbf_fund_loss' => 999,
            'mtbf_non_fund_loss' => 999, 'mtbf_potential_recovery' => 999,
            'mtbf_fully_recovered' => 999, 'mtbf_non_tech_loss' => 999,
            'mtbf_non_incident' => 999, 'mtbf_all' => 999,
        ]);
        $outlier->labels()->attach($outlierLabel);

        $this->runJob($outlier);

        $fresh = $outlier->fresh();
        foreach (IncidentMetricsCalculator::METRIC_COLUMNS as $column) {
            $this->assertNull($fresh->{$column}, "Column {$column} must be null on an outlier row");
        }
    }

    public function test_successor_gap_measures_to_previous_non_outlier(): void
    {
        $this->makeIncident(['incident_date' => '2032-01-10 10:00']);
        $middle = $this->makeIncident(['incident_date' => '2032-01-20 10:00']);
        $this->tagOutlier($middle);
        $successor = $this->makeIncident(['incident_date' => '2032-01-30 10:00']);

        $this->runJob($successor);

        // Gap measured to Jan 10 (previous non-outlier) = 20 days, not the
        // 10 it would be if the outlier Jan 20 were the predecessor.
        $this->assertSame(20.0, (float) $successor->fresh()->mtbf);
    }

    public function test_outlier_repair_lands_on_next_non_outlier_row(): void
    {
        $first = $this->makeIncident(['incident_date' => '2032-02-10 10:00']);
        $middle = $this->makeIncident(['incident_date' => '2032-02-20 10:00']);
        $this->tagOutlier($middle);
        $successor = $this->makeIncident(['incident_date' => '2032-02-28 10:00', 'mtbf' => 999]); // stale

        $this->runJob($first); // adjacent repair must skip the outlier

        $this->assertNull($middle->fresh()->mtbf, 'Repair must not re-populate the outlier row');
        $this->assertSame(18.0, (float) $successor->fresh()->mtbf, 'Repair must reach the next non-outlier row');
    }

    public function test_category_columns_successor_skips_outlier(): void
    {
        $this->makeIncident(['incident_date' => '2033-01-10 10:00', 'incident_status' => 'Completed']);
        $middle = $this->makeIncident(['incident_date' => '2033-01-20 10:00', 'incident_status' => 'Completed']);
        $this->tagOutlier($middle);
        $successor = $this->makeIncident(['incident_date' => '2033-01-30 10:00', 'incident_status' => 'Completed']);

        $this->runJob($successor);

        $this->assertSame(20.0, (float) $successor->fresh()->mtbf_completed);
    }

    public function test_recalculate_command_sweep_nulls_outlier_and_repairs_successor(): void
    {
        $this->makeIncident(['incident_date' => '2034-01-10 10:00', 'stop_bleeding_at' => '2034-01-10 12:00']);
        $middle = $this->makeIncident([
            'incident_date' => '2034-01-20 10:00',
            'stop_bleeding_at' => '2034-01-20 12:00',
            'mttr' => 999,
        ]);
        $this->tagOutlier($middle);
        $successor = $this->makeIncident(['incident_date' => '2034-01-30 10:00', 'stop_bleeding_at' => '2034-01-30 12:00']);

        $this->artisan('incidents:recalculate-metrics', ['--year' => 2034])->assertSuccessful();

        $this->assertNull($middle->fresh()->mttr);
        $this->assertSame(20.0, (float) $successor->fresh()->mtbf);
    }
}
