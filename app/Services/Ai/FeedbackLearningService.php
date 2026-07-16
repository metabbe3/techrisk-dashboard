<?php

namespace App\Services\Ai;

use App\Models\ChatMessage;
use App\Models\UserAiPreference;
use App\Services\Ai\Concerns\InteractsWithAiApi;
use App\Services\Ai\Concerns\JsonExtractor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FeedbackLearningService
{
    use InteractsWithAiApi;

    public function __construct(
        private AiUsageLogger $usageLogger,
    ) {}

    public function processFeedback(ChatMessage $message, string $feedback, ?string $comment): void
    {
        if (! config('ai.perception.feedback_learning.enabled', true)) {
            return;
        }

        if ($feedback !== 'negative' || ! $message->conversation) {
            return;
        }

        // Try to extract preference rule after enough samples
        $userId = $message->conversation->user_id;
        $this->extractPreferenceRules($userId);
    }

    public function extractPreferenceRules(int $userId): void
    {
        $minSamples = (int) config('ai.perception.feedback_learning.min_samples_for_rule', 3);

        // Count recent negative feedback for this user
        $recentNegativeCount = ChatMessage::whereHas('conversation', fn ($q) => $q->where('user_id', $userId))
            ->where('feedback', 'negative')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        if ($recentNegativeCount < $minSamples) {
            return;
        }

        // Only extract once per batch of min_samples
        $lastExtractionCount = UserAiPreference::where('user_id', $userId)
            ->where('source', 'feedback')
            ->count();

        if ($recentNegativeCount < ($lastExtractionCount + 1) * $minSamples) {
            return;
        }

        // Get recent negative feedback samples
        $negativeMessages = ChatMessage::whereHas('conversation', fn ($q) => $q->where('user_id', $userId))
            ->where('feedback', 'negative')
            ->whereNotNull('feedback_comment')
            ->latest('created_at')
            ->take(5)
            ->get();

        if ($negativeMessages->isEmpty()) {
            return;
        }

        $feedbackSamples = $negativeMessages->map(fn ($msg) => [
            'question_context' => str($msg->content)->limit(200),
            'feedback_comment' => $msg->feedback_comment,
        ])->toJson();

        $model = config('ai.perception.feedback_learning.rule_extraction_model', 'FAST-MODEL');
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(15)
                ->post($this->buildUrl(), [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => "You analyze user feedback for an AI incident management assistant. Extract concise preference rules.\n\nConsider patterns: detail level, format preferences (tables vs narrative), topic focus, tone (technical vs executive), what was unhelpful.\n\nReturn ONLY valid JSON array of 1-3 actionable rules (each under 50 words):\n[\"Prefer concise bullet-point summaries over paragraphs\", \"Always cite incident numbers when discussing findings\"]\n\nRules must be actionable (not \"User likes short answers\" but \"Prefer bullet points over paragraphs for multi-incident comparisons\")."],
                        ['role' => 'user', 'content' => "Based on these negative feedback samples, extract preference rules:\n\n{$feedbackSamples}"],
                    ],
                    'max_tokens' => 300,
                ]);

            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];
            $content = $responseData['choices'][0]['message']['content'] ?? '';

            $rules = JsonExtractor::extract($content);

            if (! is_array($rules)) {
                $this->usageLogger->log(
                    fieldType: 'feedback_learning',
                    model: $model,
                    success: false,
                    inputLength: strlen($feedbackSamples),
                    responseTimeMs: $responseTimeMs,
                    errorMessage: 'Invalid JSON response',
                    userId: $userId,
                );

                return;
            }

            foreach ($rules as $rule) {
                if (is_string($rule) && strlen($rule) > 10) {
                    UserAiPreference::create([
                        'user_id' => $userId,
                        'preference_rule' => $rule,
                        'source' => 'feedback',
                        'confidence' => 0.6,
                        'is_active' => true,
                    ]);
                }
            }

            Log::info('[FeedbackLearning] Extracted preference rules', [
                'user_id' => $userId,
                'rules_count' => count($rules),
            ]);

            $this->usageLogger->log(
                fieldType: 'feedback_learning',
                model: $model,
                success: true,
                inputLength: strlen($feedbackSamples),
                outputLength: strlen($content),
                usage: array_filter([
                    'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                    'completion_tokens' => $usage['completion_tokens'] ?? null,
                    'total_tokens' => $usage['total_tokens'] ?? null,
                ]),
                responseTimeMs: $responseTimeMs,
                apiRequestId: $responseData['id'] ?? null,
                metadata: ['rules_count' => count($rules)],
                userId: $userId,
            );

        } catch (\Throwable $e) {
            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            Log::warning('[FeedbackLearning] Failed to extract rules', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            $this->usageLogger->log(
                fieldType: 'feedback_learning',
                model: $model,
                success: false,
                responseTimeMs: $responseTimeMs,
                errorMessage: $e->getMessage(),
                userId: $userId,
            );
        }
    }

    public function getFeedbackPreferences(int $userId): array
    {
        return UserAiPreference::forUser($userId)
            ->active()
            ->orderByDesc('confidence')
            ->limit(5)
            ->pluck('preference_rule')
            ->toArray();
    }

    public function injectPreferences(string $systemPrompt, int $userId): string
    {
        $preferences = $this->getFeedbackPreferences($userId);

        if (empty($preferences)) {
            return $systemPrompt;
        }

        $prefText = collect($preferences)->map(fn ($p) => "- {$p}")->implode("\n");

        return $systemPrompt."\n\n## Learned User Preferences\n{$prefText}";
    }
}
