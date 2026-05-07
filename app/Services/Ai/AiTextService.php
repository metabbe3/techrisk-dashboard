<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Models\AiUsageLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class AiTextService
{
    public function enhance(string $text, string $fieldType, ?string $model = null, ?string $additionalPrompt = null): AiTextResult
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

        $inputLength = strlen($text);
        $startTime = microtime(true);
        $result = null;

        $isRefinement = filled($additionalPrompt);

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(config('ai.timeout', 30))
                ->post($this->buildUrl(), $this->buildPayload(
                    $prompt['system'],
                    $text,
                    $resolvedModel,
                    $additionalPrompt,
                ));

            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];
            $promptTokens = $usage['prompt_tokens'] ?? null;
            $completionTokens = $usage['completion_tokens'] ?? null;
            $totalTokens = $usage['total_tokens'] ?? null;
            $apiRequestId = $responseData['id'] ?? null;

            if ($response->status() === 429) {
                $result = AiTextResult::failure('Rate limit exceeded. Please wait a moment before trying again.', $resolvedModel, $responseTimeMs);
            } elseif ($response->status() === 401) {
                Log::warning('AI service auth error', ['body' => $response->json('error.message', '')]);
                $result = AiTextResult::failure('Authentication failed. Check your API key in AI settings.', $resolvedModel, $responseTimeMs);
            } elseif ($response->status() === 403) {
                $result = AiTextResult::failure('Access denied. Your API key does not have permission for this model.', $resolvedModel, $responseTimeMs);
            } elseif ($response->failed()) {
                Log::warning('AI service returned error', ['status' => $response->status(), 'body' => $response->body()]);
                $result = AiTextResult::failure('AI service error (HTTP '.$response->status().'). Please try again.', $resolvedModel, $responseTimeMs);
            } else {
                $enhancedText = $this->parseResponseFromData($responseData);

                if (blank($enhancedText)) {
                    $result = AiTextResult::failure('AI returned an empty response.', $resolvedModel, $responseTimeMs);
                } else {
                    $cleaned = $this->cleanResponse($enhancedText);
                    $result = AiTextResult::success(
                        text: $cleaned,
                        model: $resolvedModel,
                        promptTokens: $promptTokens,
                        completionTokens: $completionTokens,
                        totalTokens: $totalTokens,
                        responseTimeMs: $responseTimeMs,
                        apiRequestId: $apiRequestId,
                    );
                }
            }
        } catch (ConnectionException $e) {
            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            $msg = $e->getMessage();
            Log::warning('AI service connection failed', ['error' => $msg]);

            $error = match (true) {
                str_contains($msg, 'timed out') => 'Request timed out. The AI service took too long to respond. Try again or switch to a faster model.',
                str_contains($msg, 'Could not resolve') || str_contains($msg, 'getaddrinfo') => 'Cannot reach AI service. DNS resolution failed — check network connectivity.',
                str_contains($msg, 'Connection refused') => 'AI service refused the connection. The service may be down.',
                default => 'Cannot connect to AI service. Please check your network and try again.',
            };

            $result = AiTextResult::failure($error, $resolvedModel, $responseTimeMs);
        } catch (\Throwable $e) {
            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            Log::warning('AI service unexpected error', ['error' => $e->getMessage()]);
            $result = AiTextResult::failure('Unexpected error: '.$e->getMessage(), $resolvedModel, $responseTimeMs);
        }

        $this->logUsage($fieldType, $resolvedModel, $result, $inputLength, $isRefinement);

        return $result;
    }

    public function isAvailable(): bool
    {
        return filled($this->getBaseUrl()) && filled($this->getApiKey());
    }

    public function getAvailableModels(): array
    {
        return AiSetting::get('models', config('ai.models', []));
    }

    public function fetchModelsFromGateway(): array
    {
        $baseUrl = $this->getBaseUrl();
        $apiKey = $this->getApiKey();

        if (blank($baseUrl) || blank($apiKey)) {
            return [];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Accept' => 'application/json',
            ])
                ->timeout(10)
                ->get(rtrim($baseUrl, '/').'/models');
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch models from AI gateway', ['error' => $e->getMessage()]);

            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $data = $response->json('data', []);

        return collect($data)
            ->filter(fn ($m) => ($m['object'] ?? '') === 'model')
            ->pluck('id')
            ->filter(fn ($id) => ! str_contains($id, 'embedding'))
            ->mapWithKeys(fn ($id) => [$id => ucwords(str_replace(['-', '_'], ' ', $id))])
            ->toArray();
    }

    public function suggestLabels(array $incidentData, array $availableLabels, ?string $model = null): array
    {
        $resolvedModel = $model ?? AiSetting::get('default_model', config('ai.default_model'));
        $prompt = config('ai.prompts.label_suggest');

        if (! $prompt) {
            return ['matched' => [], 'suggested' => []];
        }

        $userMessage = "Incident data:\n";
        foreach ($incidentData as $key => $value) {
            if (filled($value)) {
                $userMessage .= "- {$key}: {$value}\n";
            }
        }
        $userMessage .= "\nAvailable labels: " . (empty($availableLabels) ? '(none — suggest relevant new labels)' : implode(', ', $availableLabels));

        $inputLength = strlen($userMessage);
        $startTime = microtime(true);
        $success = false;
        $promptTokens = null;
        $completionTokens = null;
        $totalTokens = null;
        $responseTimeMs = null;
        $apiRequestId = null;

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(config('ai.timeout', 30))
                ->post($this->buildUrl(), [
                    'model' => $resolvedModel,
                    'messages' => [
                        ['role' => 'system', 'content' => $prompt['system']],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                    'max_tokens' => 1000,
                ]);

            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];
            $promptTokens = $usage['prompt_tokens'] ?? null;
            $completionTokens = $usage['completion_tokens'] ?? null;
            $totalTokens = $usage['total_tokens'] ?? null;
            $apiRequestId = $responseData['id'] ?? null;

            if ($response->failed()) {
                Log::warning('[Smart Labels] AI request failed', ['status' => $response->status()]);
                $this->logLabelUsage($resolvedModel, false, $inputLength, null, $promptTokens, $completionTokens, $totalTokens, $responseTimeMs, $apiRequestId, 'HTTP '.$response->status());

                return ['matched' => [], 'suggested' => []];
            }
        } catch (\Throwable $e) {
            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            Log::warning('AI label suggestion failed', ['error' => $e->getMessage()]);
            $this->logLabelUsage($resolvedModel, false, $inputLength, null, null, null, null, $responseTimeMs, null, $e->getMessage());

            return ['matched' => [], 'suggested' => []];
        }

        $content = $response->json('choices.0.message.content', '');

        if (empty($content)) {
            Log::warning('[Smart Labels] Empty content from AI', ['status' => $response->status()]);
            $this->logLabelUsage($resolvedModel, false, $inputLength, null, $promptTokens, $completionTokens, $totalTokens, $responseTimeMs, $apiRequestId, 'Empty response');

            return ['matched' => [], 'suggested' => []];
        }

        Log::info('[Smart Labels] AI response', ['content' => substr($content, 0, 1000)]);
        $result = $this->parseLabelSuggestions($content, $availableLabels);
        $this->logLabelUsage($resolvedModel, true, $inputLength, strlen($content), $promptTokens, $completionTokens, $totalTokens, $responseTimeMs, $apiRequestId);

        return $result;
    }

    private function logLabelUsage(?string $model, bool $success, int $inputLength, ?int $outputLength, ?int $promptTokens, ?int $completionTokens, ?int $totalTokens, ?float $responseTimeMs, ?string $apiRequestId, ?string $errorMessage = null): void
    {
        try {
            AiUsageLog::create([
                'user_id' => auth()->id(),
                'user_email' => auth()->user()?->email,
                'field_type' => 'label_suggest',
                'model' => $model,
                'input_length' => $inputLength,
                'output_length' => $outputLength,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'response_time_ms' => $responseTimeMs ? (int) $responseTimeMs : null,
                'success' => $success,
                'error_message' => $errorMessage,
                'api_request_id' => $apiRequestId,
                'requested_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write AI usage log', ['error' => $e->getMessage()]);
        }
    }

    private function parseLabelSuggestions(string $content, array $availableLabels): array
    {
        preg_match('/\{.*\}/s', $content, $matches);

        if (empty($matches)) {
            Log::warning('[Smart Labels] No JSON found in AI response', ['content' => substr($content, 0, 500)]);

            return ['matched' => [], 'suggested' => []];
        }

        $parsed = json_decode($matches[0], true);

        if (! is_array($parsed)) {
            Log::warning('[Smart Labels] JSON decode failed', ['raw' => $matches[0], 'error' => json_last_error_msg()]);

            return ['matched' => [], 'suggested' => []];
        }

        $availableLower = array_map('mb_strtolower', $availableLabels);

        $matched = collect($parsed['matched'] ?? [])
            ->filter(fn ($name) => is_string($name))
            ->filter(function ($name) use ($availableLower) {
                return in_array(mb_strtolower($name), $availableLower);
            })
            ->values()
            ->toArray();

        $suggested = collect($parsed['suggested'] ?? [])
            ->filter(fn ($name) => is_string($name) && strlen($name) <= 50)
            ->filter(function ($name) use ($availableLower) {
                return ! in_array(mb_strtolower($name), $availableLower);
            })
            ->unique()
            ->values()
            ->toArray();

        Log::info('[Smart Labels] Parsed', ['matched' => $matched, 'suggested' => $suggested, 'raw_matched' => $parsed['matched'] ?? [], 'raw_suggested' => $parsed['suggested'] ?? []]);

        return ['matched' => $matched, 'suggested' => $suggested];
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

    protected function buildPayload(string $systemPrompt, string $userText, string $model, ?string $additionalPrompt = null): array
    {
        $userMessage = filled($additionalPrompt)
            ? "Please improve the following text with this additional instruction: \"{$additionalPrompt}\"\n\n{$userText}"
            : "Please improve the following text:\n\n{$userText}";

        return [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'max_tokens' => 1000,
        ];
    }

    protected function parseResponseFromData(array $data): ?string
    {
        return $data['choices'][0]['message']['content']
            ?? $data['output']
            ?? $data['text']
            ?? $data['content']
            ?? null;
    }

    protected function cleanResponse(string $text): string
    {
        $text = preg_replace('/\*{1,3}([^*]+)\*{1,3}/', '$1', $text);
        $text = preg_replace('/~~([^~]+)~~/', '$1', $text);
        $text = preg_replace('/^#{1,6}\s+(.+)$/m', '$1:', $text);
        $text = preg_replace('/^[-*_]{3,}\s*$/m', '', $text);
        $text = preg_replace('/^[•\-*]\s+/m', '- ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    private function logUsage(string $fieldType, ?string $model, AiTextResult $result, int $inputLength, bool $isRefinement = false): void
    {
        try {
            AiUsageLog::create([
                'user_id' => auth()->id(),
                'user_email' => auth()->user()?->email,
                'field_type' => $fieldType,
                'model' => $model,
                'input_length' => $inputLength,
                'output_length' => $result->success ? strlen($result->text ?? '') : null,
                'prompt_tokens' => $result->promptTokens,
                'completion_tokens' => $result->completionTokens,
                'total_tokens' => $result->totalTokens,
                'response_time_ms' => $result->responseTimeMs ? (int) $result->responseTimeMs : null,
                'success' => $result->success,
                'error_message' => $result->error,
                'api_request_id' => $result->apiRequestId,
                'metadata' => $isRefinement ? ['type' => 'refinement'] : null,
                'requested_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write AI usage log', ['error' => $e->getMessage()]);
        }
    }
}
