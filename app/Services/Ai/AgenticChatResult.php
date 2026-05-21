<?php

namespace App\Services\Ai;

class AgenticChatResult extends AiTextResult
{
    public function __construct(
        bool $success,
        ?string $text = null,
        ?string $error = null,
        ?string $model = null,
        ?int $promptTokens = null,
        ?int $completionTokens = null,
        ?int $totalTokens = null,
        ?float $responseTimeMs = null,
        ?string $apiRequestId = null,
        public readonly array $toolCallsMade = [],
        public readonly ?string $toolSummary = null,
    ) {
        parent::__construct(
            success: $success,
            text: $text,
            error: $error,
            model: $model,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $totalTokens,
            responseTimeMs: $responseTimeMs,
            apiRequestId: $apiRequestId,
        );
    }

    public static function success(
        string $text,
        string $model,
        ?int $promptTokens = null,
        ?int $completionTokens = null,
        ?int $totalTokens = null,
        ?float $responseTimeMs = null,
        ?string $apiRequestId = null,
        array $toolCallsMade = [],
        ?string $toolSummary = null,
    ): self {
        return new self(
            success: true,
            text: $text,
            model: $model,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $totalTokens,
            responseTimeMs: $responseTimeMs,
            apiRequestId: $apiRequestId,
            toolCallsMade: $toolCallsMade,
            toolSummary: $toolSummary,
        );
    }

    public static function failure(
        string $error,
        ?string $model = null,
        ?float $responseTimeMs = null,
    ): self {
        return new self(
            success: false,
            error: $error,
            model: $model,
            responseTimeMs: $responseTimeMs,
        );
    }
}
