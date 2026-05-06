<?php

namespace App\Services\Ai;

class AiTextResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $text = null,
        public readonly ?string $error = null,
        public readonly ?string $model = null,
    ) {}

    public static function success(string $text, string $model): self
    {
        return new self(success: true, text: $text, model: $model);
    }

    public static function failure(string $error): self
    {
        return new self(success: false, error: $error);
    }
}
