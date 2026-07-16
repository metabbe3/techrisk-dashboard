<?php

namespace Tests\Unit\Services\Ai\Evaluation;

use App\Services\Ai\Evaluation\AiEvalService;
use App\Services\Ai\HybridIncidentRetriever;
use Tests\TestCase;

class AiEvalServiceTest extends TestCase
{
    public function test_precision_at_k(): void
    {
        $eval = new AiEvalService(\Mockery::mock(HybridIncidentRetriever::class));

        $this->assertSame(1.0, $eval->precisionAtK([1, 2], [1, 2], 2));
        $this->assertSame(0.0, $eval->precisionAtK([3, 4], [1, 2], 2));
        $this->assertSame(0.0, $eval->precisionAtK([], [1, 2], 5));
        $this->assertEquals(1 / 3, $eval->precisionAtK([1, 2, 3], [1, 9], 3), '', 0.001);
    }

    public function test_evaluate_query_computes_precision_from_retriever(): void
    {
        $retriever = \Mockery::mock(HybridIncidentRetriever::class);
        $retriever->shouldReceive('retrieveForQuery')
            ->andReturn(collect([(object) ['id' => 1], (object) ['id' => 2]]));

        $result = (new AiEvalService($retriever))->evaluateQuery(
            ['query' => 'database connection pool', 'expected_ids' => [1]],
            2,
        );

        $this->assertSame('database connection pool', $result['query']);
        $this->assertSame([1, 2], $result['retrieved']);
        $this->assertEquals(0.5, $result['precision'], '', 0.001);
    }

    public function test_evaluate_set_reports_mean_precision(): void
    {
        $retriever = \Mockery::mock(HybridIncidentRetriever::class);
        $retriever->shouldReceive('retrieveForQuery')->andReturn(collect([(object) ['id' => 1]]));

        $report = (new AiEvalService($retriever))->evaluateSet(
            [
                ['query' => 'a', 'expected_ids' => [1]],
                ['query' => 'b', 'expected_ids' => [99]],
            ],
            1,
        );

        $this->assertCount(2, $report['results']);
        // one perfect (1.0), one miss (0.0) -> mean 0.5
        $this->assertEquals(0.5, $report['mean_precision'], '', 0.001);
    }
}
