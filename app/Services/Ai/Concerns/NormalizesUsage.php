<?php

namespace App\Services\Ai\Concerns;

/**
 * Normalize the `usage` block of an OpenAI-compatible /chat/completions response
 * to canonical OpenAI field names.
 *
 * Everything here routes through one assumed-OpenAI-compatible gateway, but the
 * upstream model behind a given model ID may report tokens using Anthropic-style
 * names (`input_tokens`/`output_tokens`) instead of `prompt_tokens`/
 * `completion_tokens`. Without normalization those read as null, silently
 * breaking token budgets, the usage dashboard, and budget alerts.
 */
trait NormalizesUsage
{
    /**
     * @param  array<string,mixed>|null  $usage
     * @return array{prompt_tokens:int|null,completion_tokens:int|null,total_tokens:int|null}
     */
    protected function normalizeUsage(?array $usage): array
    {
        $usage ??= [];

        $prompt = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null;
        $completion = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? null;
        $total = $usage['total_tokens'] ?? null;

        if ($total === null && ($prompt !== null || $completion !== null)) {
            $total = ($prompt ?? 0) + ($completion ?? 0);
        }

        return [
            'prompt_tokens' => $prompt !== null ? (int) $prompt : null,
            'completion_tokens' => $completion !== null ? (int) $completion : null,
            'total_tokens' => $total !== null ? (int) $total : null,
        ];
    }
}
