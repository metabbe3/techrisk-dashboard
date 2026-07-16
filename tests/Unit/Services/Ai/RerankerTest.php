<?php

namespace Tests\Unit\Services\Ai;

use App\Models\Incident;
use App\Services\Ai\AiUsageLogger;
use App\Services\Ai\ModelRouter;
use App\Services\Ai\Reranker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RerankerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.api_key' => 'k', 'ai.base_url' => 'https://g.test']);
    }

    public function test_reranks_candidates_by_model_order(): void
    {
        Event::fake();
        [$a, $b] = [
            Incident::factory()->create(['title' => 'Alpha incident']),
            Incident::factory()->create(['title' => 'Beta incident']),
        ];

        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => '{"ranked_ids": ['.$b->id.', '.$a->id.']}']]],
            ], 200),
        ]);

        $router = \Mockery::mock(ModelRouter::class);
        $router->shouldReceive('pick')->andReturn('glm-5.2-fast');
        $logger = \Mockery::mock(AiUsageLogger::class);
        $logger->shouldReceive('log');

        $incidents = collect([$a, $b]);
        $out = (new Reranker($router, $logger))->rerank('which is more relevant', $incidents, 5);

        $this->assertSame([$b->id, $a->id], $out->map(fn ($i) => $i->id)->all());
    }

    public function test_fails_soft_to_retrieval_order_on_error(): void
    {
        Event::fake();
        $incidents = Incident::factory()->count(3)->create();

        Http::fake(['*' => Http::response([], 500)]);

        $router = \Mockery::mock(ModelRouter::class);
        $router->shouldReceive('pick')->andReturn('glm-5.2-fast');
        $logger = \Mockery::mock(AiUsageLogger::class);
        $logger->shouldReceive('log');

        $originalOrder = $incidents->pluck('id')->all();
        $out = (new Reranker($router, $logger))->rerank('q', $incidents, 5);

        // On failure, keeps the original (retrieval) order.
        $this->assertSame($originalOrder, $out->pluck('id')->all());
    }
}
