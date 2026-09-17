<?php

namespace Tests\Feature\Ai;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Models\WarRoomAgentConfig;
use App\Services\Ai\PersonaStreamingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Persona-mode parity with the main chat path: web search is strictly opt-in,
 * retrieved web results enter the prompt fenced as untrusted data, and the
 * same per-conversation caps apply.
 */
class ChatPersonaParityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access ai chat']);
        $this->user->givePermissionTo('access ai chat');

        WarRoomAgentConfig::factory()->create(['role_key' => 'analyst', 'sort_order' => 1]);

        config([
            'ai.base_url' => 'http://gateway.test',
            'ai.api_key' => 'test-key',
            // Distinct host so the web-search call is distinguishable from
            // gateway chat calls in Http::fake assertions.
            'ai.search.provider' => 'gemini',
            'ai.search.gemini_api_key' => 'test-search-key',
            'ai.search.gemini_base_url' => 'https://search.test',
        ]);
    }

    public function test_web_search_does_not_run_without_the_toggle(): void
    {
        // Wiring check too: expandHistory must run on the persona path.
        $this->partialMock(\App\Services\Ai\ChatAttachmentService::class)
            ->shouldReceive('expandHistory')->once()->andReturnUsing(fn (array $history) => $history);

        // Mock the streamer so no real curl to the gateway happens.
        $this->mock(PersonaStreamingService::class)
            ->shouldReceive('streamConcurrent')->once()->andReturn([]);

        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        $res = $this->actingAs($this->user)->postJson('/admin/ai/chat/stream-personas', [
            'message' => 'summarize the current risk posture',
            'personas' => ['analyst'],
        ]);

        $res->assertStatus(200);
        $res->streamedContent(); // run the SSE callback so requests actually fire
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'search.test'));
    }

    public function test_web_search_toggle_fences_retrieved_results(): void
    {
        $capturedUserMessage = null;
        $this->mock(PersonaStreamingService::class)
            ->shouldReceive('streamConcurrent')
            ->once()
            ->andReturnUsing(function (iterable $personas, string $baseUrl, ?string $apiKey, ?string $defaultModel, array $history, string $userMessage) use (&$capturedUserMessage) {
                $capturedUserMessage = $userMessage;

                return [];
            });

        Http::fake([
            'search.test/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Public web result about kubernetes']]],
                    'groundingMetadata' => ['groundingChunks' => [
                        ['web' => ['title' => 'Example', 'uri' => 'https://example.com/k8s']],
                    ]],
                ]],
            ]),
            '*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
        ]);

        $res = $this->actingAs($this->user)->postJson('/admin/ai/chat/stream-personas', [
            'message' => 'search for kubernetes pod eviction',
            'personas' => ['analyst'],
            'web_search' => true,
        ]);

        $res->assertStatus(200);
        $res->streamedContent(); // run the SSE callback so streamConcurrent actually fires

        $this->assertNotNull($capturedUserMessage, 'streamConcurrent was not called');
        $this->assertStringContainsString('<<<UNTRUSTED_CONTEXT>>>', $capturedUserMessage);
        $this->assertStringContainsString('<<<END_UNTRUSTED_CONTEXT>>>', $capturedUserMessage);
        $this->assertStringContainsString('Retrieved web results', $capturedUserMessage);
        $this->assertStringContainsString('Public web result about kubernetes', $capturedUserMessage);
    }

    public function test_conversation_over_message_cap_is_rejected(): void
    {
        $conversation = ChatConversation::create(['user_id' => $this->user->id, 'title' => 'Long Chat']);
        $max = (int) config('ai.rate_limit.conversation_max_messages', 200);
        for ($i = 0; $i < $max; $i++) {
            ChatMessage::create([
                'conversation_id' => $conversation->id,
                'role' => $i % 2 ? 'assistant' : 'user',
                'content' => "msg {$i}",
                'created_at' => now()->subSeconds($max - $i),
            ]);
        }

        $res = $this->actingAs($this->user)->postJson('/admin/ai/chat/stream-personas', [
            'message' => 'one more question',
            'conversation_id' => $conversation->id,
            'personas' => ['analyst'],
        ]);

        $res->assertStatus(422);
        // The guard fires before the new user message is persisted.
        $this->assertSame($max, ChatMessage::where('conversation_id', $conversation->id)->count());
    }
}
