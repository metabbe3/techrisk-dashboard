<?php

namespace App\Jobs\Ai;

use App\Models\ChatConversation;
use App\Services\Ai\ConversationMemoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SummarizeConversationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(
        public string $conversationId,
    ) {
        $this->onQueue('default');
    }

    public function handle(ConversationMemoryService $memoryService): void
    {
        $conversation = ChatConversation::find($this->conversationId);

        if (! $conversation || $conversation->memory_archived_at) {
            return;
        }

        $messageCount = $conversation->messages()->count();
        $minMessages = (int) config('ai.memory.min_messages_for_summary', 8);

        if ($messageCount < $minMessages) {
            return;
        }

        $memoryService->archiveConversation($conversation);
    }
}
