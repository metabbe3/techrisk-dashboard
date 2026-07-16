<?php

namespace Tests\Unit\Services\Ai;

use App\Models\Incident;
use App\Services\Ai\HybridIncidentRetriever;
use App\Services\Ai\RagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HybridIncidentRetrieverTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_ranked_incidents_with_retrieval_score(): void
    {
        $old = Incident::factory()->create(['title' => 'DB pool exhaustion', 'incident_date' => now()->subMonths(10)]);
        $recent = Incident::factory()->create(['title' => 'Connection starvation', 'incident_date' => now()->subDays(5)]);

        $rag = \Mockery::mock(RagService::class);
        $rag->shouldReceive('search')
            ->andReturn(collect([
                (object) ['incident_id' => $old->id, 'relevance_score' => 5.0],
                (object) ['incident_id' => $recent->id, 'relevance_score' => 3.0],
            ]));

        $results = (new HybridIncidentRetriever($rag))->retrieveForQuery('database connection pool', 5);

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('id', $old->id));
        $this->assertTrue($results->contains('id', $recent->id));
        $this->assertNotNull($results->first()->retrieval_score);
    }

    public function test_returns_empty_for_blank_query(): void
    {
        $rag = \Mockery::mock(RagService::class);
        $rag->shouldNotReceive('search');

        $this->assertTrue((new HybridIncidentRetriever($rag))->retrieveForQuery('   ')->isEmpty());
    }
}
