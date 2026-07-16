<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\AiTextService;
use App\Services\Ai\CircuitBreaker;
use App\Services\Ai\ModelRouter;
use Tests\TestCase;

class ModelRouterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Pin simple, deterministic tiers so these logic tests don't depend on
        // the production multi-vendor chains (which change as models are tuned).
        config(['ai.tiers' => [
            'reasoning' => ['REASONING-MODEL', 'SMART-MODEL', 'FAST-MODEL'],
            'smart' => ['SMART-MODEL', 'FAST-MODEL', 'REASONING-MODEL'],
            'fast' => ['FAST-MODEL', 'SMART-MODEL'],
        ]]);
    }

    private function router(array $healthMap, callable $breaker): ModelRouter
    {
        $ai = \Mockery::mock(AiTextService::class);
        $ai->shouldReceive('getModelsHealth')->andReturn($healthMap);

        $cb = \Mockery::mock(CircuitBreaker::class);
        $cb->shouldReceive('isAvailable')->andReturnUsing($breaker);

        return new ModelRouter($ai, $cb);
    }

    public function test_returns_preferred_when_healthy(): void
    {
        $router = $this->router(
            ['REASONING-MODEL' => ['status' => 'healthy']],
            fn ($m) => true,
        );

        $this->assertSame('REASONING-MODEL', $router->pick('reasoning', 'REASONING-MODEL'));
    }

    public function test_falls_back_to_next_healthy_in_tier_when_preferred_unhealthy(): void
    {
        $router = $this->router(
            [
                'REASONING-MODEL' => ['status' => 'unhealthy'],
                'SMART-MODEL' => ['status' => 'healthy'],
                'FAST-MODEL' => ['status' => 'healthy'],
            ],
            fn ($m) => true,
        );

        // smart chain = [SMART-MODEL, FAST-MODEL, REASONING-MODEL]; preferred unhealthy -> SMART-MODEL.
        $this->assertSame('SMART-MODEL', $router->pick('smart', 'REASONING-MODEL'));
    }

    public function test_skips_circuit_breaker_open_model(): void
    {
        $router = $this->router(
            [
                'FAST-MODEL' => ['status' => 'healthy'],
                'SMART-MODEL' => ['status' => 'healthy'],
            ],
            fn ($m) => $m !== 'FAST-MODEL', // FAST breaker is open
        );

        // fast chain = [FAST-MODEL, SMART-MODEL]; FAST breaker open -> SMART-MODEL.
        $this->assertSame('SMART-MODEL', $router->pick('fast'));
    }

    public function test_fails_open_when_all_unhealthy(): void
    {
        $router = $this->router(
            [
                'SMART-MODEL' => ['status' => 'unhealthy'],
                'FAST-MODEL' => ['status' => 'unhealthy'],
            ],
            fn ($m) => true,
        );

        // No healthy model -> fail open, return the preferred rather than hard-blocking.
        $this->assertSame('REASONING-MODEL', $router->pick('smart', 'REASONING-MODEL'));
    }

    public function test_unknown_health_counts_as_available(): void
    {
        // Empty health map (fresh install, never pinged) -> preferred usable.
        $router = $this->router([], fn ($m) => true);

        $this->assertSame('FAST-MODEL', $router->pick('fast'));
    }

    public function test_tier_for_intent_classifies_keywords(): void
    {
        $router = $this->router([], fn ($m) => true);

        $this->assertSame('reasoning', $router->tierForIntent('Please analyze the root cause of this incident.'));
        $this->assertSame('reasoning', $router->tierForIntent('Compare option A vs option B'));
        $this->assertSame('fast', $router->tierForIntent('Rephrase this paragraph to be simpler.'));
        $this->assertSame('fast', $router->tierForIntent('Summarize the meeting notes.'));
        $this->assertSame('smart', $router->tierForIntent('Hello, how are you?'));
    }
}
