<?php

declare(strict_types=1);
namespace App\Services\Ai;

class SearchPlanQuery
{
    public function __construct(
        public readonly string $query,
        public readonly string $purpose,
        public readonly string $desiredDepth = 'brief',
    ) {}
}
