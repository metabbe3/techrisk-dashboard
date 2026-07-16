<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\ModelRouter;
use App\Services\Ai\RephrasePipeline;
use App\Services\WarRoom\WarRoomStreamingService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RephrasePipelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // InteractsWithAiApi needs a configured gateway for the skeleton HTTP call.
        config(['ai.api_key' => 'test-key', 'ai.base_url' => 'https://gateway.test']);
    }

    public function test_skeleton_uses_reasoning_model_then_renders_with_fast_model(): void
    {
        // Stage 1 (skeleton) — non-streamed HTTP; faked gateway returns a skeleton.
        Http::fake([
            'gateway.test/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '- Point one
- Point two']]],
            ], 200),
        ]);

        $rendered = 'Here is point one in clear prose. And here is point two.';
        $deltas = [];

        $streaming = \Mockery::mock(WarRoomStreamingService::class);
        $streaming->shouldReceive('streamCompletion')
            ->once()
            ->withArgs(fn ($model) => $model === 'FAST-MODEL')
            ->andReturnUsing(function ($model, $messages, $max, $tools, $onDelta) use ($rendered) {
                if ($onDelta) {
                    $onDelta($rendered, strlen($rendered));
                }

                return ['content' => $rendered, 'usage' => ['total_tokens' => 10], 'error' => null];
            });

        $router = \Mockery::mock(ModelRouter::class);
        $router->shouldReceive('pick')->with('reasoning')->andReturn('REASONING-MODEL');
        $router->shouldReceive('pick')->with('fast')->andReturn('FAST-MODEL');

        $pipeline = new RephrasePipeline($router, $streaming);

        $result = $pipeline->render(
            'You are a helpful incident analyst.',
            'Explain the database outage in simple terms.',
            function (string $delta) use (&$deltas) {
                $deltas[] = $delta;
            },
        );

        // Skeleton call hit the reasoning model.
        Http::assertSent(fn ($request) => $request->data()['model'] === 'REASONING-MODEL');

        $this->assertSame($rendered, $result['content']);
        $this->assertSame('REASONING-MODEL', $result['models']['skeleton']);
        $this->assertSame('FAST-MODEL', $result['models']['render']);
        $this->assertNotEmpty($deltas); // stage-2 deltas streamed back
    }

    public function test_falls_back_to_rendering_raw_prompt_when_skeleton_empty(): void
    {
        Http::fake([
            'gateway.test/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '']]],
            ], 200),
        ]);

        $streaming = \Mockery::mock(WarRoomStreamingService::class);
        $streaming->shouldReceive('streamCompletion')->once()->andReturn([
            'content' => 'rendered from raw', 'usage' => [], 'error' => null,
        ]);

        $router = \Mockery::mock(ModelRouter::class);
        $router->shouldReceive('pick')->andReturn('REASONING-MODEL', 'FAST-MODEL');

        $result = (new RephrasePipeline($router, $streaming))->render('sys', 'raw prompt');

        $this->assertSame('rendered from raw', $result['content']);
    }
}
