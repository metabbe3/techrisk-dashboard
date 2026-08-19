<?php

namespace Tests\Unit\Services\Ai;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Ai\AiUsageLogger;
use App\Services\Ai\ConversationMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationMemoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConversationMemoryService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ConversationMemoryService($this->createMock(AiUsageLogger::class));
        $this->user = User::factory()->create();
    }

    public function test_relevant_summaries_rank_by_query_overlap(): void
    {
        $vendor = ChatConversation::create([
            'user_id' => $this->user->id,
            'title' => 'Vendor review',
            'summary' => 'Discussed vendor onboarding delays and SLA breaches with the supplier.',
        ]);
        ChatConversation::create([
            'user_id' => $this->user->id,
            'title' => 'Payment gateway',
            'summary' => 'Discussed payment gateway timeouts on several incidents.',
        ]);

        $results = $this->service->getRelevantSummaries($this->user->id, 'tell me about vendor onboarding problems');

        $this->assertCount(2, $results);
        $this->assertSame($vendor->id, $results->first()->id);
    }

    public function test_relevant_summaries_falls_back_to_recency_when_no_overlap(): void
    {
        $older = ChatConversation::create([
            'user_id' => $this->user->id,
            'title' => 'Older',
            'summary' => 'Something unrelated entirely.',
        ]);
        $newer = ChatConversation::create([
            'user_id' => $this->user->id,
            'title' => 'Newer',
            'summary' => 'Also unrelated completely.',
        ]);
        $older->update(['updated_at' => now()->subDays(2)]);

        $results = $this->service->getRelevantSummaries($this->user->id, 'short');

        $this->assertSame($newer->id, $results->first()->id);
    }

    public function test_relevant_summaries_returns_empty_for_user_without_history(): void
    {
        $this->assertTrue(
            $this->service->getRelevantSummaries($this->user->id, 'anything at all')->isEmpty()
        );
    }
}
