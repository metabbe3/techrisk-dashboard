<?php

namespace Tests\Feature\Ai;

use App\Enums\IncidentClassification;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Incident;
use App\Services\Ai\RagService;
use App\Services\Ai\SimilarIncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SimilarIncidentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The pipeline's isAvailable() gate requires a key + base URL.
        config([
            'ai.api_key' => 'test-key',
            'ai.base_url' => 'https://gateway.test',
        ]);
    }

    /**
     * Drive analyze() with a mocked RagService (FULLTEXT is MySQL-only and
     * unavailable on the SQLite test DB) and a gateway router that dispatches
     * canned responses by each phase's distinct system prompt. Returns the
     * captured gateway requests grouped by phase for assertions.
     */
    private function runPipeline(Incident $source, Collection $ragResults, array $responses): object
    {
        $this->mock(RagService::class)
            ->shouldReceive('search')
            ->andReturn($ragResults);

        $requests = ['think' => [], 'verify' => [], 'double_check' => []];

        Http::fake(function (Request $request) use ($responses, &$requests) {
            $system = $request->data()['messages'][0]['content'] ?? '';

            if (str_contains($system, 'deep incident analysis engine')) {
                $requests['think'][] = $request;

                return Http::response($this->gatewayBody($responses['think'] ?? '{}'), 200);
            }

            if (str_contains($system, 'rigorous incident similarity verifier')) {
                $requests['verify'][] = $request;

                return Http::response($this->gatewayBody($responses['verify'] ?? '{"verified":[]}'), 200);
            }

            if (str_contains($system, 'strict incident similarity judge')) {
                $requests['double_check'][] = $request;

                return Http::response($this->gatewayBody($responses['double_check'] ?? '{"verdicts":[]}'), 200);
            }

            return Http::response($this->gatewayBody('{}'), 200);
        });

        $result = $this->app->make(SimilarIncidentService::class)->analyze($source);

        return (object) ['result' => $result, 'requests' => $requests];
    }

    private function gatewayBody(string $content): array
    {
        return [
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
        ];
    }

    private function makeIncident(array $overrides = []): Incident
    {
        return Incident::factory()->create(array_merge([
            'severity' => Severity::P2,
            'incident_status' => IncidentStatus::Completed,
            'classification' => IncidentClassification::Incident,
            'incident_date' => now()->subMonth(),
            'root_cause' => 'Database connection pool exhaustion under peak load.',
        ], $overrides));
    }

    private function ragHit(int $incidentId, float $score): object
    {
        return (object) ['incident_id' => $incidentId, 'relevance_score' => $score];
    }

    public function test_find_phase_ranks_candidates_by_fused_score_not_by_primary_key(): void
    {
        $source = $this->makeIncident(['title' => 'Payment gateway timeout', 'summary' => 'API timeouts']);
        $low = $this->makeIncident(['title' => 'Unrelated slow query']);
        $mid = $this->makeIncident(['title' => 'Partly related timeout']);
        $high = $this->makeIncident(['title' => 'Payment gateway DB pool exhaustion']);

        // Highest relevance is on the highest id — primary-key order would put it last.
        $rag = collect([
            $this->ragHit($high->id, 0.9),
            $this->ragHit($mid->id, 0.5),
            $this->ragHit($low->id, 0.2),
        ]);

        $run = $this->runPipeline($source, $rag, [
            'think' => '{}',
            'verify' => '{"verified":[]}',
        ]);

        $this->assertCount(1, $run->requests['verify']);
        $body = $run->requests['verify'][0]->data()['messages'][1]['content'];

        $posHigh = strpos($body, "ID: {$high->id}");
        $posMid = strpos($body, "ID: {$mid->id}");
        $posLow = strpos($body, "ID: {$low->id}");

        $this->assertNotFalse($posHigh);
        $this->assertNotFalse($posMid);
        $this->assertNotFalse($posLow);
        $this->assertLessThan($posMid, $posHigh, 'highest-scoring candidate must rank before the mid one');
        $this->assertLessThan($posLow, $posMid, 'mid candidate must rank before the lowest one');
        $this->assertStringContainsString('Rank #1', $body);
    }

    public function test_verify_rejects_matches_below_min_similarity_threshold(): void
    {
        $source = $this->makeIncident();
        $weak = $this->makeIncident(['title' => 'Weak match']);
        $strong = $this->makeIncident(['title' => 'Strong match']);

        $rag = collect([$this->ragHit($weak->id, 0.5), $this->ragHit($strong->id, 0.9)]);

        $run = $this->runPipeline($source, $rag, [
            'think' => '{}',
            'verify' => json_encode([
                'verified' => [
                    ['id' => $weak->id, 'similarity' => 0.3, 'verified' => true, 'reasoning' => 'weak'],
                    ['id' => $strong->id, 'similarity' => 0.85, 'verified' => true, 'reasoning' => 'strong'],
                ],
            ]),
        ]);

        $this->assertTrue($run->result->success);
        $ids = array_column($run->result->matches, 'id');
        $this->assertContains($strong->id, $ids);
        $this->assertNotContains($weak->id, $ids, 'similarity 0.3 < 0.4 minimum must be rejected');
        // 0.85 >= double-check threshold (0.7) so no adjudication call is needed.
        $this->assertCount(0, $run->requests['double_check']);
    }

    public function test_double_check_adjudicates_uncertain_band_in_a_single_batched_call(): void
    {
        $source = $this->makeIncident();
        $confident = $this->makeIncident(['title' => 'Confident match']);
        $uncertain = $this->makeIncident(['title' => 'Uncertain match']);

        $rag = collect([$this->ragHit($confident->id, 0.9), $this->ragHit($uncertain->id, 0.5)]);

        $run = $this->runPipeline($source, $rag, [
            'think' => '{}',
            'verify' => json_encode([
                'verified' => [
                    ['id' => $confident->id, 'similarity' => 0.8, 'verified' => true, 'reasoning' => 'confident'],
                    ['id' => $uncertain->id, 'similarity' => 0.5, 'verified' => true, 'reasoning' => 'uncertain'],
                ],
            ]),
            'double_check' => json_encode(['verdicts' => [
                ['id' => $uncertain->id, 'confirmed' => false, 'similarity' => 0.2, 'reasoning' => 'false positive'],
            ]]),
        ]);

        $this->assertTrue($run->result->success);
        $ids = array_column($run->result->matches, 'id');
        $this->assertContains($confident->id, $ids);
        $this->assertNotContains($uncertain->id, $ids, 'uncertain match rejected by double-check');
        $this->assertCount(1, $run->requests['double_check'], 'double-check must collapse to a single batched call');
    }

    public function test_double_check_can_confirm_an_uncertain_match_and_marks_it(): void
    {
        $source = $this->makeIncident();
        $candidate = $this->makeIncident(['title' => 'Uncertain match']);

        $run = $this->runPipeline($source, collect([$this->ragHit($candidate->id, 0.5)]), [
            'think' => '{}',
            'verify' => json_encode([
                'verified' => [
                    ['id' => $candidate->id, 'similarity' => 0.55, 'verified' => true, 'reasoning' => 'uncertain'],
                ],
            ]),
            'double_check' => json_encode(['verdicts' => [
                ['id' => $candidate->id, 'confirmed' => true, 'similarity' => 0.6, 'match_type' => 'deep', 'reasoning' => 'confirmed'],
            ]]),
        ]);

        $this->assertCount(1, $run->result->matches);
        $this->assertSame($candidate->id, $run->result->matches[0]['id']);
        $this->assertTrue($run->result->matches[0]['double_checked'] ?? false);
        $this->assertSame(0.6, $run->result->matches[0]['similarity']);
    }

    public function test_structured_retrieval_finds_candidates_when_rag_returns_nothing(): void
    {
        $source = $this->makeIncident(['incident_source' => 'Internal']);
        $sibling = $this->makeIncident(['title' => 'Team sibling', 'incident_source' => 'Internal']);
        $unrelated = $this->makeIncident(['title' => 'Unrelated', 'incident_source' => 'External']);

        $run = $this->runPipeline($source, collect([]), [
            'think' => '{}',
            'verify' => '{"verified":[]}',
        ]);

        $this->assertCount(1, $run->requests['verify']);
        $body = $run->requests['verify'][0]->data()['messages'][1]['content'];
        $this->assertStringContainsString("ID: {$sibling->id}", $body, 'team-matched candidate must reach verify');
        $this->assertStringNotContainsString("ID: {$unrelated->id}", $body, 'unrelated candidate must not reach verify');
    }

    public function test_pipeline_returns_failure_when_gateway_is_unreachable(): void
    {
        $source = $this->makeIncident();
        $this->mock(RagService::class)->shouldReceive('search')->andReturn(collect());

        Http::fake(fn () => Http::response([], 500));

        $result = $this->app->make(SimilarIncidentService::class)->analyze($source);

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->error);
    }
}
