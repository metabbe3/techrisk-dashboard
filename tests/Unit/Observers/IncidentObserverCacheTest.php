<?php

namespace Tests\Unit\Observers;

use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IncidentObserverCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_and_updating_an_incident_clears_ai_context_caches(): void
    {
        Cache::spy();

        $incident = Incident::factory()->create(['classification' => 'Incident']);
        $incident->update(['title' => 'Renamed title']);

        foreach ([
            'chat_quick_stats_v2',
            'chat_recent_incidents_v2',
            'chat_trend_context',
            'chat_recurring_context',
            'chat_pic_context',
            'chat_rca_context',
        ] as $key) {
            // atLeast once: the observer clears it AND the metrics job's
            // flushIncidentCache() does too (shared flush since BUG-024) —
            // forgets are idempotent, only presence matters.
            Cache::shouldHaveReceived('forget', [$key])->atLeast()->once();
        }
    }

    public function test_a_discovered_at_edit_bumps_the_dashboard_cache_version(): void
    {
        // discovered_at feeds effectiveDetectionLagHours → governance lag KPIs
        // (dashboard_cache_version-keyed) — Edit-Safety rule 2: the observer's
        // cache-bump list must include it or KPIs serve stale numbers until TTL.
        Cache::spy();
        $incident = Incident::factory()->create(['classification' => 'Incident']);

        $incident->update(['discovered_at' => '2026-01-01 08:00:00']);

        Cache::shouldHaveReceived('increment', ['dashboard_cache_version'])->atLeast()->once();
    }

    public function test_a_creating_row_with_a_duplicate_no_is_regenerated_not_a_500(): void
    {
        // S-2: the unique index turns the generateNo race into a duplicate-key
        // exception — the observer's creating hook regenerates instead.
        $existing = Incident::factory()->create([
            'classification' => 'Incident',
            'no' => '20260101_IN_4242',
        ]);

        $racer = Incident::factory()->make([
            'title' => 'Lost the no race',
            'classification' => 'Incident',
        ]);
        $racer->no = '20260101_IN_4242';
        $racer->save();

        $this->assertNotSame('20260101_IN_4242', $racer->no, 'Duplicate no must be regenerated at create');
        $this->assertDatabaseHas('incidents', ['id' => $racer->id, 'no' => $racer->no]);
        $this->assertSame(2, Incident::count());
    }
}
