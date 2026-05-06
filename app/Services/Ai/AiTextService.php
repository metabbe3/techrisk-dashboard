<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class AiTextService
{
    public function enhance(string $text, string $fieldType, ?string $model = null): AiTextResult
    {
        if (blank($text)) {
            return AiTextResult::failure('No text provided for enhancement.');
        }

        $maxLength = config('ai.max_input_length', 5000);
        if (strlen($text) > $maxLength) {
            return AiTextResult::failure("Text exceeds maximum length of {$maxLength} characters.");
        }

        $prompt = config("ai.prompts.{$fieldType}");
        if (! $prompt) {
            return AiTextResult::failure("Unknown field type: {$fieldType}.");
        }

        $resolvedModel = $model
            ?? AiSetting::get('default_model', config('ai.default_model'));

        $userId = auth()->id() ?? 'guest';
        $rateLimitKey = "ai-enhance:{$userId}";

        if (! RateLimiter::attempt($rateLimitKey, config('ai.rate_limit_per_minute', 10), fn () => true)) {
            return AiTextResult::failure('Rate limit exceeded. Please wait a moment before trying again.');
        }

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(config('ai.timeout', 30))
                ->post($this->buildUrl(), $this->buildPayload($prompt['system'], $text, $resolvedModel));
        } catch (ConnectionException $e) {
            Log::warning('AI service connection failed', ['error' => $e->getMessage()]);

            return AiTextResult::failure('AI service is unavailable. Please try again.');
        }

        if ($response->status() === 429) {
            return AiTextResult::failure('AI service rate limit exceeded. Please wait a moment.');
        }

        if ($response->failed()) {
            Log::warning('AI service returned error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return AiTextResult::failure('AI service returned an error. Please try again.');
        }

        $enhancedText = $this->parseResponse($response);

        if (blank($enhancedText)) {
            return AiTextResult::failure('AI returned an empty response.');
        }

        return AiTextResult::success($this->cleanResponse($enhancedText), $resolvedModel);
    }

    public function isAvailable(): bool
    {
        return filled($this->getBaseUrl()) && filled($this->getApiKey());
    }

    public function getAvailableModels(): array
    {
        return AiSetting::get('models', config('ai.models', []));
    }

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
        return config('ai.base_url');
    }

    protected function buildPayload(string $systemPrompt, string $userText, string $model): array
    {
        return [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Please improve the following text:\n\n{$userText}"],
            ],
            'max_tokens' => 1000,
        ];
    }

    protected function parseResponse($response): ?string
    {
        $data = $response->json();

        return $data['choices'][0]['message']['content']
            ?? $data['output']
            ?? $data['text']
            ?? $data['content']
            ?? null;
    }

    protected function cleanResponse(string $text): string
    {
        // Strip bold/italic markdown
        $text = preg_replace('/\*{1,3}([^*]+)\*{1,3}/', '$1', $text);
        // Strip strikethrough
        $text = preg_replace('/~~([^~]+)~~/', '$1', $text);
        // Convert markdown headers (## Heading) to uppercase label with colon
        $text = preg_replace('/^#{1,6}\s+(.+)$/m', '$1:', $text);
        // Convert markdown horizontal rules to a blank line
        $text = preg_replace('/^[-*_]{3,}\s*$/m', '', $text);
        // Convert markdown bullet points to clean dashes
        $text = preg_replace('/^[•\-*]\s+/m', '- ', $text);
        // Collapse 3+ consecutive blank lines into 2
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
