<?php

namespace App\Services\Ai\Evaluation;

use App\Services\Ai\HybridIncidentRetriever;

/**
 * Lightweight retrieval evaluation. Given a golden set of {query → expected
 * incident IDs}, runs the hybrid retriever and reports precision@k — the share
 * of the top-k retrieved incidents that are truly relevant. This is the metric
 * that lets every retrieval change (hybrid, reranker, embeddings) be measured.
 */
class AiEvalService
{
    public function __construct(
        private readonly HybridIncidentRetriever $retriever,
    ) {}

    /**
     * Pure metric: fraction of the top-k retrieved IDs that are in the expected set.
     *
     * @param  int[]  $retrievedIds
     * @param  int[]  $expectedIds
     */
    public function precisionAtK(array $retrievedIds, array $expectedIds, int $k = 5): float
    {
        $topK = array_slice(array_map('intval', $retrievedIds), 0, $k);
        if (empty($topK)) {
            return 0.0;
        }

        $expected = array_map('intval', $expectedIds);
        $hits = count(array_filter($topK, fn ($id) => in_array($id, $expected, true)));

        return $hits / count($topK);
    }

    /**
     * @param  array{query: string, expected_ids: int[]}  $case
     * @return array{query: string, precision: float, retrieved: int[], expected: int[]}
     */
    public function evaluateQuery(array $case, int $k = 5): array
    {
        $retrieved = $this->retriever
            ->retrieveForQuery($case['query'], $k)
            ->map(fn ($i) => (int) $i->id)
            ->all();

        return [
            'query' => $case['query'],
            'precision' => $this->precisionAtK($retrieved, $case['expected_ids'] ?? [], $k),
            'retrieved' => $retrieved,
            'expected' => $case['expected_ids'] ?? [],
        ];
    }

    /**
     * @param  array<int, array{query: string, expected_ids: int[]}>  $cases
     * @return array{results: array, mean_precision: float, k: int}
     */
    public function evaluateSet(array $cases, int $k = 5): array
    {
        $results = array_map(fn ($c) => $this->evaluateQuery($c, $k), $cases);
        $mean = empty($results) ? 0.0 : array_sum(array_column($results, 'precision')) / count($results);

        return ['results' => $results, 'mean_precision' => $mean, 'k' => $k];
    }
}
