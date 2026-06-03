<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

class TokenMetricsService
{
    /**
     * Log estimated vs actual token usage for an AI call.
     */
    public function logCall(
        string $fieldType,
        string $model,
        int $estimatedInputTokens,
        ?int $actualPromptTokens = null,
        ?int $actualCompletionTokens = null,
        ?float $responseTimeMs = null,
    ): void {
        if (! config('ai.token_metrics.enabled', true)) {
            return;
        }

        Log::debug('[TokenMetrics] AI call completed', [
            'field_type' => $fieldType,
            'model' => $model,
            'estimated_input_tokens' => $estimatedInputTokens,
            'actual_prompt_tokens' => $actualPromptTokens,
            'actual_completion_tokens' => $actualCompletionTokens,
            'estimation_accuracy' => $actualPromptTokens
                ? round(($actualPromptTokens / max(1, $estimatedInputTokens)) * 100, 1).'%'
                : null,
            'response_time_ms' => $responseTimeMs ? (int) $responseTimeMs : null,
        ]);
    }

    /**
     * Estimate input tokens for a prompt and log the estimation.
     */
    public function estimateAndLog(string $prompt, string $fieldType, string $model): int
    {
        if (! config('ai.token_metrics.log_input_estimation', true)) {
            return TokenEstimator::estimate($prompt);
        }

        $estimated = TokenEstimator::estimate($prompt);

        Log::debug('[TokenMetrics] Input estimation', [
            'field_type' => $fieldType,
            'model' => $model,
            'prompt_length' => mb_strlen($prompt),
            'estimated_tokens' => $estimated,
        ]);

        return $estimated;
    }
}
