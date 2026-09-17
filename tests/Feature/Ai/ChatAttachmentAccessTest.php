<?php

namespace Tests\Feature\Ai;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Attachment downloads are scoped: a uuid only resolves for the owner of a
 * conversation whose message references it, and the ORIGINAL file is served —
 * not the {uuid}.md extraction sidecar that sits next to it.
 */
class ChatAttachmentAccessTest extends TestCase
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

        Storage::fake('local');
    }

    /**
     * Store a fake attachment (file + optional sidecar) and attach it to a
     * message in one of the given user's conversations.
     */
    private function attachDocument(User $owner, string $id, string $binary, ?string $sidecar = null): ChatMessage
    {
        Storage::disk('local')->put("chat-attachments/{$id}.pdf", $binary);
        if ($sidecar !== null) {
            Storage::disk('local')->put("chat-attachments/{$id}.md", $sidecar);
        }
        $conversation = ChatConversation::create(['user_id' => $owner->id, 'title' => 'Chat']);
        $message = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'see attached',
            'created_at' => now(),
        ]);
        $message->update(['attachments' => [
            ['id' => $id, 'type' => 'document', 'filename' => 'report.pdf', 'mime_type' => 'application/pdf'],
        ]]);

        return $message;
    }

    public function test_owner_downloads_the_original_pdf_not_the_sidecar(): void
    {
        $id = (string) Str::uuid();
        $this->attachDocument($this->user, $id, "%PDF-1.4\nreal pdf bytes\n", '### extracted markdown');

        $res = $this->actingAs($this->user)->get("/admin/ai/chat/attachment/{$id}");

        $res->assertStatus(200);
        $content = $res->streamedContent();
        $this->assertStringStartsWith('%PDF-1.4', $content);
        $this->assertStringNotContainsString('extracted markdown', $content);
    }

    public function test_other_user_cannot_download_attachment(): void
    {
        $id = (string) Str::uuid();
        $this->attachDocument($this->user, $id, "%PDF-1.4\nreal pdf bytes\n");

        $this->actingAs($this->other)->get("/admin/ai/chat/attachment/{$id}")->assertStatus(404);
    }

    public function test_unknown_uuid_returns_404(): void
    {
        $this->actingAs($this->user)
            ->get('/admin/ai/chat/attachment/'.Str::uuid())
            ->assertStatus(404);
    }

    public function test_attachment_cleanup_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list');

        $descriptions = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($event) => (string) $event->description);

        $this->assertTrue(
            $descriptions->contains(fn ($d) => str_contains($d, 'chat attachment')),
            'Expected a scheduled job deleting chat attachments. Got: '.$descriptions->implode(' | ')
        );
    }
}
