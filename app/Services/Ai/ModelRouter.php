<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/**
 * Picks a healthy model for a task tier.
 *
 * Combines the cached model-health map (AiTextService::getModelsHealth) with
 * the per-model CircuitBreaker. A candidate is usable when its health is not
 * 'unhealthy' AND its breaker is closed; unknown/missing health counts as
 * available (fail-open on fresh installs / before the first health ping).
 */
class ModelRouter
{
    public function __construct(
        private readonly AiTextService $aiService,
        private readonly CircuitBreaker $circuitBreaker,
    ) {}

    /**
     * @param  string  $tier  reasoning|smart|fast
     * @param  string|null  $preferred  manual UI pick or per-feature override — used unless unhealthy
     */
    public function pick(string $tier, ?string $preferred = null): string
    {
        $chain = $this->chain($tier);
        $candidates = array_values(array_filter(array_unique(array_merge([$preferred], $chain))));

        // ponytail: the health map is static for the lifetime of this pick(), so
        // fetch it once and thread it through isAvailable() — avoids one
        // getModelsHealth() (N Redis gets) per candidate.
        $health = $this->aiService->getModelsHealth();

        foreach ($candidates as $model) {
            if ($this->isAvailable($model, $health)) {
                return $model;
            }
        }

        // Fail-open: never hard-block. Prefer the user/feature pick, else the chain head.
        $fallback = $preferred ?? $chain[0] ?? config('ai.default_model', 'SMART-MODEL');

        Log::warning('[ModelRouter] No healthy model found for tier; failing open', [
            'tier' => $tier,
            'preferred' => $preferred,
            'fallback' => $fallback,
        ]);

        return $fallback;
    }

    /**
     * Ordered alias fallback chain for a tier, from config('ai.tiers').
     *
     * @return array<int, string>
     */
    public function chain(string $tier): array
    {
        return (array) (config("ai.tiers.{$tier}") ?? config('ai.tiers.smart') ?? []);
    }

    /**
     * A model is usable if its cached health is not 'unhealthy' AND its circuit
     * breaker is closed. Unknown/missing health counts as available (fail-open).
     */
    public function isAvailable(string $model, ?array $healthMap = null): bool
    {
        $health = ($healthMap ?? $this->aiService->getModelsHealth())[$model] ?? null;

        if (($health['status'] ?? 'unknown') === 'unhealthy') {
            return false;
        }

        return $this->circuitBreaker->isAvailable($model);
    }

    /**
     * Heuristic intent → tier. Deliberately no LLM call — a classifier per turn
     * would cost the savings the routing is meant to deliver.
     */
    public function tierForIntent(string $text): string
    {
        $t = ' '.strtolower($text).' ';

        foreach (['analyz', 'assess', 'root cause', 'why did', 'why does', 'compare', 'decide', 'investigat', 'architect', 'design', 'strategiz', 'trade-off', 'tradeoff'] as $k) {
            if (str_contains($t, $k)) {
                return 'reasoning';
            }
        }

        foreach (['rephrase', 'rewrite', 'reword', 'summariz', 'simplif', 'make readable', 'plain english', 'paraphrase'] as $k) {
            if (str_contains($t, $k)) {
                return 'fast';
            }
        }

        return 'smart';
    }
}
