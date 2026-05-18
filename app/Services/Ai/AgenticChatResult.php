<?php

namespace App\Services\Ai;

class AgenticChatResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $text = null,
        public readonly ?string $error = null,
        public readonly ?string $model = null,
        public readonly ?int $promptTokens = null,
        public readonly ?int $completionTokens = null,
        public readonly ?int $totalTokens = null,
        public readonly ?float $responseTimeMs = null,
        public readonly ?string $apiRequestId = null,
        public readonly array $toolCallsMade = [],
        public readonly ?string $toolSummary = null,
    ) {}

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
