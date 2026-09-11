<?php

namespace Tests\Feature;

use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RecalculateMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_recalculates_all_metric_columns_for_existing_rows(): void
    {
        $incident = Incident::factory()->create([
            'severity' => 'P1',
            'classification' => 'Incident',
            'fund_status' => 'Non fundLoss',
            'incident_date' => '2026-03-05 10:00:00',
            'stop_bleeding_at' => '2026-03-05 12:00:00',
        ]);

        // Simulate drifted/stale columns the sweep must repair.
        $incident->forceFill([
            'mttr' => null,
            'mtbf' => null,
            'mtbf_non_fund_loss' => null,
        ])->saveQuietly();

        $version = Cache::get('dashboard_cache_version', 0);

        $this->artisan('incidents:recalculate-metrics', ['--year' => '2026'])
            ->assertSuccessful();

        $incident->refresh();
        $this->assertSame(120.0, (float) $incident->mttr, 'mttr recomputed from stop_bleeding_at');
        $this->assertSame(63.0, (float) $incident->mtbf, 'Jan 1 → Mar 5 = 63 days from year start');
        $this->assertNotNull($incident->mtbf_non_fund_loss, 'category columns recomputed too');
        $this->assertGreaterThan(
            $version,
            Cache::get('dashboard_cache_version', 0),
            'Sweep must bump the dashboard cache version like the job does'
        );
    }

    public function test_dry_run_leaves_values_untouched(): void
    {
        $incident = Incident::factory()->create([
            'severity' => 'P1',
            'classification' => 'Incident',
            'fund_status' => 'Non fundLoss',
            'incident_date' => '2026-03-05 10:00:00',
        ]);

        $incident->forceFill(['mtbf' => null])->saveQuietly();
        $version = Cache::get('dashboard_cache_version', 0);

        $this->artisan('incidents:recalculate-metrics', ['--year' => '2026', '--dry-run' => true])
            ->assertSuccessful();

        $incident->refresh();
        $this->assertNull($incident->mtbf, 'dry run must not persist anything');
        $this->assertSame($version, Cache::get('dashboard_cache_version', 0));
    }
}
