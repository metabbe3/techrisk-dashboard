<?php

namespace App\Services\Ai\Concerns;

use App\Models\AiSetting;

trait InteractsWithAiApi
{
    protected function buildHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->getApiKey(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    protected function buildUrl(): string
    {
        return rtrim($this->getBaseUrl(), '/').'/chat/completions';
    }

    protected function getApiKey(): ?string
    {
        return AiSetting::get('api_key', config('ai.api_key'));
    }

    protected function getBaseUrl(): ?string
    {
        return AiSetting::get('base_url', config('ai.base_url'));
    }

    protected function getTimeout(): int
    {
        return (int) AiSetting::get('timeout', config('ai.timeout', 60));
    }

    protected function elapsedMs(float $startTime): float
    {
        return (microtime(true) - $startTime) * 1000;
    }
}
