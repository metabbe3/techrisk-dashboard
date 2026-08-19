<?php

namespace Tests\Unit\Jobs\WarRoom;

use App\Jobs\WarRoom\RunPreAnalysis;
use App\Models\WarRoomMessage;
use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RunPreAnalysisJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.base_url', 'https://api.example.test/v1');
        config()->set('ai.api_key', 'test-key');
        Cache::flush(); // clear any cached AiSetting values from prior tests
    }

    public function test_posts_to_chat_completions_endpoint(): void
    {
        Queue::fake();
        Event::fake();

        $session = WarRoomSession::factory()->running()->create([
            'selected_agents' => ['sre', 'dba'],
            'incident_context' => ['Sample incident context'],
        ]);

        Http::fake(function (Request $request) {
            return Http::response([
                'choices' => [['message' => ['content' => '{"key_concerns":[]}']]],
                'usage' => ['total_tokens' => 5],
            ], 200);
        });

        (new RunPreAnalysis($session))->handle(app(WarRoomService::class));

        // Must hit the shared /chat/completions endpoint, not the bare base URL.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/chat/completions'));
    }

    public function test_failure_still_dispatches_round_one(): void
    {
        Queue::fake();
        Event::fake();

        $session = WarRoomSession::factory()->running()->create([
            'selected_agents' => ['sre', 'dba'],
            'incident_context' => ['Sample incident context'],
        ]);

        Http::fake(fn () => Http::response('boom', 500));

        (new RunPreAnalysis($session))->handle(app(WarRoomService::class));

        // Pre-analysis failed, but the graceful-degradation path must still start round 1
        // rather than stranding the session at current_round=0.
        $this->assertCount(
            2,
            WarRoomMessage::where('session_id', $session->id)->where('round', 1)->get()
        );
    }
}
