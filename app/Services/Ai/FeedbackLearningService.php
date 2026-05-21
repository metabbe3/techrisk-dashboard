<?php

namespace App\Services\Ai;

use App\Models\ChatMessage;
use App\Models\UserAiPreference;
use App\Services\Ai\Concerns\InteractsWithAiApi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FeedbackLearningService
{
    use InteractsWithAiApi;
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

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(15)
                ->post($this->buildUrl(), [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'You analyze user feedback patterns and extract concise preference rules. Return a JSON array of 1-3 short preference rules (each under 50 words). Each rule should describe what the user prefers in AI responses. Return only the JSON array, no markdown.'],
                        ['role' => 'user', 'content' => "Based on these negative feedback samples, extract preference rules:\n\n{$feedbackSamples}"],
                    ],
                    'max_tokens' => 300,
                ]);

            $content = $response->json('choices.0.message.content', '');

            $rules = json_decode($content, true);

            if (! is_array($rules)) {
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

        } catch (\Throwable $e) {
            Log::warning('[FeedbackLearning] Failed to extract rules', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
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
