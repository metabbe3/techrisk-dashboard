<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\AiTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression guard for the model-health ping. The gateway restricts some params
 * per provider (Anthropic rejects any temperature≠1; some models reject bare
 * max_tokens; reasoning models reject low max_completion_tokens). The ping must
 * send the minimal universally-accepted payload, or it falsely marks models
 * unhealthy (which is what happened before this fix).
 */
class AiTextServicePingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.api_key' => 'test-key', 'ai.base_url' => 'https://gateway.test']);
    }

    public function test_ping_sends_minimal_payload_with_no_temperature_or_token_caps(): void
    {
        Http::fake([
            'gateway.test/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']],
            ], 200),
        ]);

        $result = app(AiTextService::class)->pingModel('claude-opus-4-7');

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $data['model'] === 'claude-opus-4-7'
                && ! array_key_exists('temperature', $data)
                && ! array_key_exists('max_tokens', $data)
                && ! array_key_exists('max_completion_tokens', $data);
        });

        $this->assertSame('healthy', $result['status']);
    }

    public function test_ping_marks_a_response_with_no_choices_as_unhealthy(): void
    {
        Http::fake([
            'gateway.test/chat/completions' => Http::response(['choices' => []], 200),
        ]);

        $result = app(AiTextService::class)->pingModel('claude-opus-4-7');

        $this->assertSame('unhealthy', $result['status']);
    }
}
