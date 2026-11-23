<?php

namespace Tests\Unit\Services\Ai;

use App\Models\AiSetting;
use App\Services\Ai\AiTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The per-minute limit is the admin-editable AiSetting 'rate_limit' (2026-11-21
 * sweep): AiTextService used to read config('ai.rate_limit_per_minute') — a key
 * that does not exist in config/ai.php — so the limit was pinned to the
 * fallback 10 and the System Settings value was display-only.
 */
class AiTextServiceRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.api_key' => 'test-key', 'ai.base_url' => 'https://gateway.test']);
        Cache::flush(); // limiter + AiSetting caches are per-process state
    }

    public function test_the_admin_configured_rate_limit_actually_limits(): void
    {
        AiSetting::set('rate_limit', 1);

        Http::fake([
            'gateway.test/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Enhanced.'], 'finish_reason' => 'stop']],
            ], 200),
        ]);

        $service = app(AiTextService::class);

        $first = $service->enhance('Some text to enhance.', 'summary');
        $this->assertTrue($first->success, 'First call inside the limit must pass');

        $second = $service->enhance('Second text to enhance.', 'summary');
        $this->assertFalse($second->success, 'Second call over the limit of 1 must be blocked');
        $this->assertStringContainsString('Rate limit', $second->error);
    }
}
