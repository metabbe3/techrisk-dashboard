<?php

namespace Tests\Feature\Ai;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Server-side branch truncation backing edit-and-resend / regenerate: deletes
 * the target message and everything after it, scoped to the caller's own
 * conversation. Foreign ids are 404s (not 403s) — indistinguishable.
 */
class ChatMessageTruncateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->other = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access ai chat']);
        $this->user->givePermissionTo('access ai chat');
        $this->other->givePermissionTo('access ai chat');
    }

    private function makeConversation(User $owner, int $messageCount = 0): ChatConversation
    {
        $conversation = ChatConversation::create(['user_id' => $owner->id, 'title' => 'Chat']);
        for ($i = 0; $i < $messageCount; $i++) {
            ChatMessage::create([
                'conversation_id' => $conversation->id,
                'role' => $i % 2 ? 'assistant' : 'user',
                'content' => "message {$i}",
                'created_at' => now()->subMinutes($messageCount - $i),
            ]);
        }

        return $conversation->fresh();
    }

    private function truncateUrl(ChatConversation $conversation, ChatMessage $message): string
    {
        return "/admin/ai/chat/conversations/{$conversation->id}/messages/{$message->id}/truncate";
    }

    public function test_deletes_target_and_later_but_keeps_earlier_messages(): void
    {
        $conversation = $this->makeConversation($this->user, 5);
        $messages = $conversation->messages()->orderBy('created_at')->get();
        $target = $messages[2]; // "message 2"

        $res = $this->actingAs($this->user)->postJson($this->truncateUrl($conversation, $target));

        $res->assertStatus(200)->assertJsonPath('data.deleted', 3);
        $remaining = $conversation->messages()->orderBy('created_at')->pluck('content')->all();
        $this->assertSame(['message 0', 'message 1'], $remaining);
    }

    public function test_foreign_conversation_returns_404(): void
    {
        $conversation = $this->makeConversation($this->other, 3);
        $target = $conversation->messages()->first();

        $this->actingAs($this->user)
            ->postJson($this->truncateUrl($conversation, $target))
            ->assertStatus(404);

        $this->assertSame(3, $conversation->messages()->count());
    }

    public function test_message_from_another_conversation_returns_404(): void
    {
        $mine = $this->makeConversation($this->user, 2);
        $theirs = $this->makeConversation($this->other, 2);
        $foreignMessage = $theirs->messages()->first();

        $this->actingAs($this->user)
            ->postJson($this->truncateUrl($mine, $foreignMessage))
            ->assertStatus(404);

        $this->assertSame(2, $mine->messages()->count());
        $this->assertSame(2, $theirs->messages()->count());
    }
}
