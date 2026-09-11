<?php

namespace Tests\Feature;

use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CalculateIncidentMetricsAdjacentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Creating an incident that has an eligible successor in the same
     * classification/year must not throw — the adjacent-recalc path used to
     * write mtbf_ongoing/mtbf_tech columns that do not exist (BUG-008),
     * killing the job before flushIncidentCache() could run.
     */
    public function test_inserting_between_two_incidents_recalculates_successor_without_error(): void
    {
        Incident::factory()->create([
            'severity' => 'P1',
            'classification' => 'Incident',
            'fund_status' => 'Non fundLoss',
            'incident_date' => '2026-03-01 10:00:00',
        ]);
        $successor = Incident::factory()->create([
            'severity' => 'P1',
            'classification' => 'Incident',
            'fund_status' => 'Non fundLoss',
            'incident_date' => '2026-03-10 10:00:00',
        ]);

        $version = Cache::get('dashboard_cache_version', 0);

        // Insert between the two — successor's mtbf chain must be rebuilt.
        Incident::factory()->create([
            'severity' => 'P1',
            'classification' => 'Incident',
            'fund_status' => 'Non fundLoss',
            'incident_date' => '2026-03-05 10:00:00',
        ]);

        $successor->refresh();
        $this->assertSame(5.0, (float) $successor->mtbf, 'Successor mtbf should now span Mar 5 → Mar 10');
        $this->assertSame(
            5.0,
            (float) $successor->mtbf_non_fund_loss,
            'Adjacent path must recalculate non_fund_loss category too (was missing before BUG-008 fix)'
        );
        $this->assertGreaterThan(
            $version,
            Cache::get('dashboard_cache_version', 0),
            'Job must survive to flushIncidentCache() and bump the dashboard cache version'
        );
    }

    /**
     * Changing classification leaves the old group — the next incident in
     * that group is recalculated via updateAdjacentForClassification(), which
     * shared the same ghost-column path.
     */
    public function test_classification_change_recalculates_old_group_without_error(): void
    {
        $leaver = Incident::factory()->create([
            'severity' => 'P1',
            'classification' => 'Incident',
            'fund_status' => 'Non fundLoss',
            'incident_date' => '2026-03-01 10:00:00',
        ]);
        $successor = Incident::factory()->create([
            'severity' => 'P1',
            'classification' => 'Incident',
            'fund_status' => 'Non fundLoss',
            'incident_date' => '2026-03-10 10:00:00',
        ]);

        $leaver->update(['classification' => 'Issue']);

        // No exception surfaced through the observer is the assertion; the
        // successor's mtbf falls back to year start (Jan 1 → Mar 10 = 68).
        $successor->refresh();
        $this->assertSame(68.0, (float) $successor->mtbf);
    }
}
