<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;

class CircuitBreaker
{
    public function isAvailable(string $model): bool
    {
        if (! config('ai.circuit_breaker.enabled', true)) {
            return true;
        }

        $key = "ai_circuit:{$model}";
        $state = Cache::get($key);

        if ($state === null) {
            return true;
        }

        if (($state['open'] ?? false) && now()->timestamp >= ($state['reset_at'] ?? 0)) {
            Cache::forget($key);

            return true;
        }

        return ! ($state['open'] ?? false);
    }

    public function recordSuccess(string $model): void
    {
        if (! config('ai.circuit_breaker.enabled', true)) {
            return;
        }

        Cache::forget("ai_circuit:{$model}");
    }

    public function recordFailure(string $model): void
    {
        if (! config('ai.circuit_breaker.enabled', true)) {
            return;
        }

        $key = "ai_circuit:{$model}";
        $threshold = (int) config('ai.circuit_breaker.failure_threshold', 5);
        $cooldown = (int) config('ai.circuit_breaker.cooldown_seconds', 60);

        $state = Cache::get($key, ['failures' => 0, 'open' => false]);
        $state['failures'] = ($state['failures'] ?? 0) + 1;

        if ($state['failures'] >= $threshold) {
            $state['open'] = true;
            $state['reset_at'] = now()->addSeconds($cooldown)->timestamp;
        }

        Cache::put($key, $state, now()->addMinutes(10));
    }
}
