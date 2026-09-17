<?php

namespace Tests\Feature\Ai;

use App\Models\AiUsageLog;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Usage logging ownership: the stream path logs, finalize's fallback branch
 * logs. Finalize must NOT log again when the assistant message was already
 * persisted server-side — that double-counted every streamed turn.
 */
class ChatFinalizeUsageLoggingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access ai chat']);
        $this->user->givePermissionTo('access ai chat');

        config(['ai.base_url' => 'http://gateway.test', 'ai.api_key' => 'test-key']);
    }

    private function makeConversation(): ChatConversation
    {
        return ChatConversation::create([
            'user_id' => $this->user->id,
            'title' => 'Test Chat',
        ]);
    }

    private function finalizePayload(ChatConversation $conversation, array $overrides = []): array
    {
        return array_merge([
            'conversation_id' => $conversation->id,
            'content' => 'Here is the analysis.',
            'model' => 'SMART-MODEL',
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'total_tokens' => 15,
        ], $overrides);
    }

    public function test_finalize_does_not_log_usage_when_assistant_message_already_persisted(): void
    {
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'Unused']]]])]);
        $conversation = $this->makeConversation();
        ChatMessage::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'hi', 'created_at' => now()]);
        ChatMessage::create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => 'streamed reply', 'model' => 'SMART-MODEL', 'created_at' => now()->addSecond()]);

        $res = $this->actingAs($this->user)->postJson('/admin/ai/chat/finalize', $this->finalizePayload($conversation));

        $res->assertStatus(200);
        $this->assertSame(0, AiUsageLog::count());
        // No duplicate assistant message either.
        $this->assertSame(1, ChatMessage::where('role', 'assistant')->count());
    }

    public function test_finalize_creates_message_and_logs_usage_once_when_stream_did_not_persist(): void
    {
        $conversation = $this->makeConversation();
        ChatMessage::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'hi', 'created_at' => now()]);

        $res = $this->actingAs($this->user)->postJson('/admin/ai/chat/finalize', $this->finalizePayload($conversation, [
            'response_time_ms' => 123,
        ]));

        $res->assertStatus(200)
            ->assertJsonPath('data.assistant_message.content', 'Here is the analysis.');

        $assistant = ChatMessage::where('role', 'assistant')->first();
        $this->assertNotNull($assistant);
        $this->assertSame('Here is the analysis.', $assistant->content);

        $this->assertSame(1, AiUsageLog::where('field_type', 'chat_assistant')->count());
        $log = AiUsageLog::where('field_type', 'chat_assistant')->first();
        $this->assertSame(15, (int) $log->total_tokens);
        $this->assertSame((string) $assistant->id, $log->metadata['message_id'] ?? null);
    }

    public function test_finalize_still_generates_title_for_new_conversations(): void
    {
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Payment Gateway Outage']]],
        ])]);
        $conversation = ChatConversation::create(['user_id' => $this->user->id, 'title' => 'New Chat']);
        ChatMessage::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'what happened', 'created_at' => now()]);

        $res = $this->actingAs($this->user)->postJson('/admin/ai/chat/finalize', $this->finalizePayload($conversation, [
            'is_new' => true,
            'first_message' => 'what happened',
        ]));

        $res->assertStatus(200)->assertJsonPath('data.updated_title', 'Payment Gateway Outage');
        $this->assertSame('Payment Gateway Outage', $conversation->fresh()->title);
        // Title generation is a separate log row — chat turn usage stays at one.
        $this->assertSame(1, AiUsageLog::where('field_type', 'chat_assistant')->count());
        $this->assertSame(1, AiUsageLog::where('field_type', 'chat_title_generation')->count());
    }
}
