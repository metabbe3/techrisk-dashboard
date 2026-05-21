<?php

namespace App\Services\Ai;

use App\Models\AiUsageLog;
use Illuminate\Support\Facades\Log;

class AiUsageLogger
{
    public function log(
        string $fieldType,
        ?string $model,
        bool $success,
        ?int $inputLength = null,
        ?int $outputLength = null,
        array $usage = [],
        ?float $responseTimeMs = null,
        ?string $apiRequestId = null,
        ?string $errorMessage = null,
        ?array $metadata = null,
    ): void {
        try {
            AiUsageLog::create([
                'user_id' => auth()->id(),
                'user_email' => auth()->user()?->email,
                'field_type' => $fieldType,
                'model' => $model,
                'input_length' => $inputLength,
                'output_length' => $outputLength,
                'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                'completion_tokens' => $usage['completion_tokens'] ?? null,
                'total_tokens' => $usage['total_tokens'] ?? null,
                'response_time_ms' => $responseTimeMs ? (int) $responseTimeMs : null,
                'success' => $success,
                'error_message' => $errorMessage,
                'api_request_id' => $apiRequestId,
                'metadata' => $metadata,
                'requested_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write AI usage log', ['error' => $e->getMessage()]);
        }
    }

    public function logFromResult(
        string $fieldType,
        ?string $model,
        AiTextResult $result,
        ?int $inputLength = null,
        ?array $metadata = null,
    ): void {
        $this->log(
            fieldType: $fieldType,
            model: $model,
            success: $result->success,
            inputLength: $inputLength,
            outputLength: $result->success ? strlen($result->text ?? '') : null,
            usage: array_filter([
                'prompt_tokens' => $result->promptTokens,
                'completion_tokens' => $result->completionTokens,
                'total_tokens' => $result->totalTokens,
            ]),
            responseTimeMs: $result->responseTimeMs,
            apiRequestId: $result->apiRequestId,
            errorMessage: $result->success ? null : $result->error,
            metadata: $metadata,
        );
    }
}
