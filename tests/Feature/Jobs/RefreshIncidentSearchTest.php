<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RefreshIncidentSearch;
use App\Models\Incident;
use App\Models\User;
use App\Services\Ai\ChatContextService;
use App\Services\Ai\RagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Final-review fix: pivot hooks re-indexed RAG + cleared AI caches inline
 * PER attached row — assigning N PICs made N synchronous re-indexes (each
 * possibly an embedding HTTP call) inside the Filament save. The refresh
 * now runs as one queued, unique-per-incident job.
 */
class RefreshIncidentSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_attaching_a_pic_dispatches_the_refresh_job(): void
    {
        Queue::fake();
        config(['broadcasting.default' => 'log']);

        $incident = Incident::factory()->create();
        $pic = User::factory()->create();
        $incident->pics()->attach($pic->id);

        Queue::assertPushed(RefreshIncidentSearch::class, fn (RefreshIncidentSearch $job) => $job->incidentId === $incident->id);
    }

    public function test_handle_reindexes_the_incident_and_clears_chat_cache(): void
    {
        $incident = Incident::factory()->create();

        $this->mock(RagService::class)
            ->shouldReceive('indexIncident')
            ->once()
            ->withArgs(fn (Incident $i) => $i->id === $incident->id);

        $this->mock(ChatContextService::class)
            ->shouldReceive('clearDataCache')
            ->once();

        (new RefreshIncidentSearch($incident->id))->handle(
            app(RagService::class),
            app(ChatContextService::class),
        );

        $this->assertTrue(true); // expectations above are the assertion
    }
}
