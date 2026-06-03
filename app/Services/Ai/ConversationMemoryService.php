<?php

namespace App\Services\Ai;

use App\Models\ChatConversation;
use App\Services\Ai\Concerns\InteractsWithAiApi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ConversationMemoryService
{
    use InteractsWithAiApi;

    public function __construct(
        private AiUsageLogger $usageLogger,
    ) {}

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
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(20)
                ->post($this->buildUrl(), [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => "Summarize this TechRisk incident management conversation in 2-3 sentences.\nInclude: (1) which specific incidents were discussed by ID number, (2) what analytical conclusions were reached, (3) any actions or decisions made, (4) unresolved follow-up items.\nBe specific with incident numbers."],
                        ['role' => 'user', 'content' => $conversationText],
                    ],
                    'max_tokens' => 200,
                    'temperature' => config('ai.temperatures.json_extraction', 0.1),
                ]);

            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];
            $content = $responseData['choices'][0]['message']['content'] ?? '';

            $this->usageLogger->log(
                fieldType: 'conversation_summary',
                model: $model,
                success: $response->successful() && ! blank($content),
                inputLength: strlen($conversationText),
                outputLength: strlen($content),
                usage: array_filter([
                    'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                    'completion_tokens' => $usage['completion_tokens'] ?? null,
                    'total_tokens' => $usage['total_tokens'] ?? null,
                ]),
                responseTimeMs: $responseTimeMs,
                apiRequestId: $responseData['id'] ?? null,
                errorMessage: $response->successful() ? null : 'HTTP '.$response->status(),
                metadata: ['conversation_id' => $conversation->id],
                userId: $conversation->user_id,
                userEmail: $conversation->user?->email,
            );

            return $content;

        } catch (\Throwable $e) {
            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            Log::warning('[Memory] Failed to summarize conversation', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            $this->usageLogger->log(
                fieldType: 'conversation_summary',
                model: $model,
                success: false,
                inputLength: strlen($conversationText),
                responseTimeMs: $responseTimeMs,
                errorMessage: $e->getMessage(),
                metadata: ['conversation_id' => $conversation->id],
                userId: $conversation->user_id,
            );

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
