<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CalculateIncidentMetrics;
use App\Models\Incident;
use App\Models\Label;
use App\Services\Metrics\IncidentMetricsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Freshness for the Outlier rule (root cause, not symptom): Filament saves
 * labels via a pivot sync that fires NO Incident model event — so attaching
 * "Outlier" must be caught at the pivot itself (IncidentLabel model events)
 * and dispatch the metrics recalc + cache-version bump. The sync queue in
 * tests runs the job inline, so the end state is assertable directly.
 */
class IncidentLabelPivotRecalcTest extends TestCase
{
    use RefreshDatabase;

    private function makeIncident(array $attrs = []): Incident
    {
        return Incident::factory()->createQuietly(array_merge([
            'classification' => 'Incident',
            'severity' => 'P1',
        ], $attrs));
    }

    public function test_attaching_outlier_label_dispatches_recalc_and_bumps_cache_version(): void
    {
        Cache::put('dashboard_cache_version', 5);
        $this->makeIncident(['incident_date' => '2032-06-10 10:00']);
        $middle = $this->makeIncident([
            'incident_date' => '2032-06-20 10:00',
            'stop_bleeding_at' => '2032-06-20 12:00',
            'mttr' => 999,
        ]);
        $successor = $this->makeIncident(['incident_date' => '2032-06-30 10:00', 'mtbf' => 999]); // stale

        $middle->labels()->attach(Label::firstOrCreate(['name' => Label::OUTLIER]));

        $this->assertNull($middle->fresh()->mttr, 'Attach must recalc the tagged row (metrics nulled)');
        $this->assertSame(20.0, (float) $successor->fresh()->mtbf, 'Attach must repair the next non-outlier row');
        $this->assertSame(6, Cache::get('dashboard_cache_version'), 'Attach must bump the dashboard cache version');
    }

    public function test_detaching_outlier_label_restores_metrics(): void
    {
        $this->makeIncident(['incident_date' => '2032-07-10 10:00']);
        $outlier = $this->makeIncident(['incident_date' => '2032-07-20 10:00', 'stop_bleeding_at' => '2032-07-20 12:00']);
        $outlier->labels()->attach(Label::firstOrCreate(['name' => Label::OUTLIER]));
        $this->assertNull($outlier->fresh()->mttr);

        $outlier->labels()->detach();

        $this->assertSame(120.0, (float) $outlier->fresh()->mttr, 'Detach must restore the row into metric math');
        $this->assertSame(10.0, (float) $outlier->fresh()->mtbf);
    }

    public function test_non_outlier_label_attach_does_not_dispatch(): void
    {
        Cache::put('dashboard_cache_version', 5);
        $incident = $this->makeIncident(['incident_date' => '2032-08-10 10:00', 'mttr' => 999]);

        $incident->labels()->attach(Label::create(['name' => 'Payment']));

        $this->assertSame(999.0, (float) $incident->fresh()->mttr, 'Non-outlier labels do not affect metrics');
        $this->assertSame(5, Cache::get('dashboard_cache_version'));
    }

    public function test_autolabel_never_attaches_outlier_by_text_match(): void
    {
        // "Outlier" is hand-applied only — the auto-labeler must never infer
        // it from incident text, or every incident mentioning the word would
        // silently drop out of all metrics.
        Label::firstOrCreate(['name' => Label::OUTLIER]);
        $payment = Label::create(['name' => 'Payment']);
        $incident = $this->makeIncident([
            'incident_date' => '2032-09-10 10:00',
            'summary' => 'A payment gateway outage — this event is an outlier in the trend',
        ]);

        (new CalculateIncidentMetrics($incident, shouldAutoLabel: true))
            ->handle(app(IncidentMetricsCalculator::class));

        $this->assertTrue($incident->labels->contains($payment->id), 'Normal labels still auto-attach');
        $this->assertFalse(
            $incident->labels->contains(fn ($label) => $label->name === Label::OUTLIER),
            'Outlier must never be auto-attached from text'
        );
    }
}
