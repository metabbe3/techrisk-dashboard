<?php

namespace App\Services\Ai;

class SearchPlan
{
    /**
     * @param  SearchPlanQuery[]  $queries
     */
    public function __construct(
        public readonly array $queries,
        public readonly string $rationale,
    ) {}

    public function isEmpty(): bool
    {
        return empty($this->queries);
    }

    public function getQueryString(int $index): string
    {
        return $this->queries[$index]->query ?? '';
    }

    /**
     * @return string[]
     */
    public function getQueries(): array
    {
        return array_map(fn (SearchPlanQuery $q) => $q->query, $this->queries);
    }

    public function hasThoroughQueries(): bool
    {
        foreach ($this->queries as $query) {
            if ($query->desiredDepth === 'thorough') {
                return true;
            }
        }

        return false;
    }
}

class SearchPlanQuery
{
    public function __construct(
        public readonly string $query,
        public readonly string $purpose,
        public readonly string $desiredDepth = 'brief',
    ) {}
}
