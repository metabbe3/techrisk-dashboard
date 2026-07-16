<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AiTextService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiModelHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.base_url' => 'http://gateway.test',
            'ai.api_key' => 'test-key',
            'ai.models' => [
                'SMART-MODEL' => 'Smart Model',
                'FAST-MODEL' => 'Fast Model',
                'REASONING-MODEL' => 'Reasoning Model',
            ],
            'ai.model_health.enabled' => true,
            'ai.model_health.slow_threshold_ms' => 20000,
        ]);

        Cache::flush();
    }

    public function test_ping_healthy_caches_and_badges_picker(): void
    {
        Http::fake(fn () => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200));

        $service = app(AiTextService::class);
        $results = $service->checkModelsHealth();

        // Every configured model is pinged with its id in the payload.
        Http::assertSent(fn (Request $request) => str_contains($request->body(), 'SMART-MODEL'));
        Http::assertSentCount(3);

        // All classified healthy (2xx + non-empty content).
        $this->assertSame('healthy', $results['SMART-MODEL']['status']);
        $this->assertSame('healthy', $results['FAST-MODEL']['status']);

        // Cached per model and as an aggregate.
        $this->assertSame('healthy', Cache::get('ai_model_health.SMART-MODEL')['status']);
        $this->assertSame('healthy', Cache::get('ai_model_health')['FAST-MODEL']['status']);

        // Picker lists the (healthy) models with latency.
        $picker = $service->getModelsForPicker();
        $this->assertStringContainsString('Smart Model ·', $picker['SMART-MODEL']);
        $this->assertStringContainsString('s', $picker['SMART-MODEL']);
        $this->assertStringNotContainsString('✗', $picker['FAST-MODEL']);
    }

    public function test_picker_hides_unhealthy_models(): void
    {
        Http::fake(fn (Request $r) => str_contains($r->body(), 'FAST-MODEL')
            ? Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200)
            : Http::response(['error' => 'boom'], 500));

        app(AiTextService::class)->checkModelsHealth();
        $picker = app(AiTextService::class)->getModelsForPicker();

        // Reachable model is listed (with latency); confirmed-broken models are hidden.
        $this->assertArrayHasKey('FAST-MODEL', $picker);
        $this->assertStringContainsString('s', $picker['FAST-MODEL']);
        $this->assertArrayNotHasKey('SMART-MODEL', $picker);
        $this->assertArrayNotHasKey('REASONING-MODEL', $picker);
    }

    public function test_http_error_is_unhealthy(): void
    {
        Http::fake(fn () => Http::response(['error' => 'boom'], 500));

        $result = app(AiTextService::class)->pingModel('SMART-MODEL');

        $this->assertSame('unhealthy', $result['status']);
        $this->assertSame('HTTP 500', $result['error']);
    }

    public function test_empty_response_is_unhealthy(): void
    {
        Http::fake(fn () => Http::response(['choices' => [['message' => ['content' => '']]]], 200));

        $this->assertSame('unhealthy', app(AiTextService::class)->pingModel('SMART-MODEL')['status']);
    }

    public function test_connection_timeout_is_unhealthy(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $result = app(AiTextService::class)->pingModel('SMART-MODEL');

        $this->assertSame('unhealthy', $result['status']);
        $this->assertStringContainsString('timed out', $result['error']);
    }

    public function test_slow_model_is_flagged(): void
    {
        // Http::fake responds instantly (latency rounds toward 0ms), so a negative
        // threshold guarantees "slow" classification independent of timing.
        config(['ai.model_health.slow_threshold_ms' => -1]);

        Http::fake(fn () => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200));

        $results = app(AiTextService::class)->checkModelsHealth();

        $this->assertSame('slow', $results['SMART-MODEL']['status']);
        $this->assertStringContainsString('⚠', app(AiTextService::class)->getModelsForPicker()['SMART-MODEL']);
    }

    public function test_unchecked_models_are_unknown_and_unannotated(): void
    {
        $health = app(AiTextService::class)->getModelsHealth();

        $this->assertSame('unknown', $health['SMART-MODEL']['status']);
        $this->assertSame('Smart Model', app(AiTextService::class)->getModelsForPicker()['SMART-MODEL']);
    }

    public function test_check_is_noop_when_disabled(): void
    {
        config(['ai.model_health.enabled' => false]);

        $this->assertSame([], app(AiTextService::class)->checkModelsHealth());
    }
}
