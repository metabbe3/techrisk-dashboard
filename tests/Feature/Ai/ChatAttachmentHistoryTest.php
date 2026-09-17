<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\ChatAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * expandHistory(): documents sent in earlier turns are re-injected from their
 * sidecar within the turn/token budget; images are never re-sent; the newest
 * user message is left alone (buildMessageContent expands it instead).
 */
class ChatAttachmentHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * @return array{id: string, type: string, filename: string} plus a written sidecar
     */
    private function makeDocument(string $filename = 'report.md', string $content = 'extracted document content'): array
    {
        $id = (string) Str::uuid();
        Storage::disk('local')->put("chat-attachments/{$id}.md", $content);

        return ['id' => $id, 'type' => 'document', 'filename' => $filename];
    }

    private function expand(array $history): array
    {
        return app(ChatAttachmentService::class)->expandHistory($history);
    }

    public function test_recent_document_turn_is_reinjected(): void
    {
        $doc = $this->makeDocument();
        $history = [
            ['role' => 'user', 'content' => 'analyze this', 'attachments' => [$doc]],
            ['role' => 'assistant', 'content' => 'ok'],
            ['role' => 'user', 'content' => 'what was the root cause?', 'attachments' => []],
        ];

        $expanded = $this->expand($history);

        // Prior turn gains the document block…
        $this->assertStringContainsString('Attached Document: report.md', $expanded[0]['content']);
        $this->assertStringContainsString('extracted document content', $expanded[0]['content']);
        // …the newest user message is untouched (buildMessageContent owns it)…
        $this->assertSame('what was the root cause?', $expanded[2]['content']);
        // …and the helper key is stripped for the API payload.
        $this->assertArrayNotHasKey('attachments', $expanded[0]);
        $this->assertArrayNotHasKey('attachments', $expanded[2]);
    }

    public function test_documents_older_than_the_turn_budget_are_skipped(): void
    {
        config(['ai.attachments.history_turns' => 1]);
        $oldDoc = $this->makeDocument('old.md', 'OLD DOC BODY');
        $newDoc = $this->makeDocument('new.md', 'NEW DOC BODY');
        $history = [
            ['role' => 'user', 'content' => 'first question', 'attachments' => [$oldDoc]],
            ['role' => 'assistant', 'content' => 'answer one'],
            ['role' => 'user', 'content' => 'second question', 'attachments' => [$newDoc]],
            ['role' => 'assistant', 'content' => 'answer two'],
            ['role' => 'user', 'content' => 'follow up'],
        ];

        $expanded = $this->expand($history);

        $this->assertStringContainsString('NEW DOC BODY', $expanded[2]['content']);
        $this->assertSame('first question', $expanded[0]['content']);
    }

    public function test_document_is_truncated_at_the_char_cap(): void
    {
        config(['ai.attachments.history_char_cap' => 40]);
        $doc = $this->makeDocument('big.md', str_repeat('x', 500));
        $history = [
            ['role' => 'user', 'content' => 'analyze', 'attachments' => [$doc]],
            ['role' => 'assistant', 'content' => 'ok'],
            ['role' => 'user', 'content' => 'more'],
        ];

        $expanded = $this->expand($history);

        $this->assertStringContainsString('[…truncated…]', $expanded[0]['content']);
        $this->assertLessThan(500 + 200, strlen($expanded[0]['content']));
    }

    public function test_token_budget_stops_further_expansion(): void
    {
        config(['ai.attachments.history_token_budget' => 5]);
        $docA = $this->makeDocument('a.md', 'AAA '.str_repeat('word ', 20));
        $docB = $this->makeDocument('b.md', 'BBB '.str_repeat('word ', 20));
        $history = [
            ['role' => 'user', 'content' => 'q1', 'attachments' => [$docB]],
            ['role' => 'assistant', 'content' => 'a1'],
            ['role' => 'user', 'content' => 'q2', 'attachments' => [$docA]],
            ['role' => 'assistant', 'content' => 'a2'],
            ['role' => 'user', 'content' => 'q3'],
        ];

        $expanded = $this->expand($history);

        $this->assertStringContainsString('AAA', $expanded[2]['content']);
        $this->assertSame('q1', $expanded[0]['content']);
    }

    public function test_prior_turn_images_are_not_reinjected(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'look at this', 'attachments' => [
                ['id' => (string) Str::uuid(), 'type' => 'image', 'filename' => 'pic.png'],
            ]],
            ['role' => 'assistant', 'content' => 'ok'],
            ['role' => 'user', 'content' => 'next'],
        ];

        $expanded = $this->expand($history);

        $this->assertSame('look at this', $expanded[0]['content']);
    }
}
