<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Models\ChatConversation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ConversationMemoryService
{
    public function summarizeConversation(ChatConversation $conversation): string
    {
        $messages = $conversation->messages()
            ->orderBy('created_at')
            ->get();

        if ($messages->count() < 4) {
            return '';
        }

        $conversationText = $messages->map(fn ($msg) => "[{$msg->role}]: ".str($msg->content)->limit(300))->implode("\n\n");

        $model = config('ai.memory.summary_model', 'FAST-MODEL');

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.AiSetting::get('api_key', config('ai.api_key', '')),
                'Content-Type' => 'application/json',
            ])
                ->timeout(20)
                ->post(rtrim(AiSetting::get('base_url', config('ai.base_url', '')), '/').'/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'Summarize this conversation in 2-3 sentences. Focus on: what topics were discussed, what incidents were referenced, and what conclusions or decisions were reached. Be concise.'],
                        ['role' => 'user', 'content' => $conversationText],
                    ],
                    'max_tokens' => 200,
                ]);

            return $response->json('choices.0.message.content', '');

        } catch (\Throwable $e) {
            Log::warning('[Memory] Failed to summarize conversation', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    public function archiveConversation(ChatConversation $conversation): void
    {
        if ($conversation->memory_archived_at) {
            return;
        }

        $summary = $this->summarizeConversation($conversation);

        if (blank($summary)) {
            return;
        }

        $conversation->update([
            'summary' => $summary,
            'memory_archived_at' => now(),
        ]);
    }

    public function getRelevantSummaries(int $userId, string $currentQuery, int $limit = 3): Collection
    {
        return ChatConversation::where('user_id', $userId)
            ->whereNotNull('summary')
            ->latest('updated_at')
            ->limit($limit)
            ->get();
    }
}
