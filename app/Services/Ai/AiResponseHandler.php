<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

class AiResponseHandler
{
    public static function checkErrors(Response $response, string $model, float $startTime): ?AiTextResult
    {
        $responseTimeMs = (microtime(true) - $startTime) * 1000;

        if ($response->status() === 429) {
            return AiTextResult::failure('Rate limit exceeded. Please wait a moment.', $model, $responseTimeMs);
        }

        if ($response->status() === 401) {
            return AiTextResult::failure('Authentication failed. Check your API key in AI settings.', $model, $responseTimeMs);
        }

        if ($response->status() === 403) {
            return AiTextResult::failure('Access denied. Check your API permissions.', $model, $responseTimeMs);
        }

        if ($response->failed()) {
            Log::warning('AI API error', ['status' => $response->status(), 'body' => $response->body()]);

            return AiTextResult::failure('AI service error (HTTP '.$response->status().'). Please try again.', $model, $responseTimeMs);
        }

        return null;
    }

    public static function extractSuccess(Response $response, string $model, float $startTime): AiTextResult
    {
        $responseTimeMs = (microtime(true) - $startTime) * 1000;
        $responseData = $response->json();
        $usage = $responseData['usage'] ?? [];
        $content = $responseData['choices'][0]['message']['content'] ?? '';

        if (blank($content)) {
            return AiTextResult::failure(
                'AI returned an empty response. The context may be too large — try referencing fewer incidents.',
                $model,
                $responseTimeMs
            );
        }

        return AiTextResult::success(
            text: $content,
            model: $model,
            promptTokens: $usage['prompt_tokens'] ?? null,
            completionTokens: $usage['completion_tokens'] ?? null,
            totalTokens: $usage['total_tokens'] ?? null,
            responseTimeMs: $responseTimeMs,
            apiRequestId: $responseData['id'] ?? null,
        );
    }
}
