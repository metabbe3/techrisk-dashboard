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
            Cache::shouldHaveReceived('forget', [$key])->twice(); // once per created(), once per updated()
        }
    }
}
