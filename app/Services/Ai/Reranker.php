<?php

namespace App\Services\Ai;

use App\Services\Ai\Concerns\InteractsWithAiApi;
use App\Services\Ai\Concerns\JsonExtractor;
use App\Services\Ai\Concerns\NormalizesUsage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Re-orders retrieved incidents by relevance to the query using a cheap FAST-tier
 * model. One small call; cuts context tokens (only the most-relevant reach the
 * LLM) and improves answer grounding. Fails soft — on any error, keeps the
 * retriever's original order.
 */
class Reranker
{
    use InteractsWithAiApi;
    use NormalizesUsage;

    public function __construct(
        private readonly ModelRouter $router,
        private readonly AiUsageLogger $usageLogger,
    ) {}

    public function rerank(string $query, Collection $incidents, int $topK = 5): Collection
    {
        if ($incidents->isEmpty() || trim($query) === '' || $incidents->count() <= 1) {
            return $incidents->take($topK);
        }

        $model = $this->router->pick('fast');

        $list = $incidents->map(fn ($inc, $i) => sprintf(
            '%d. [id:%d] %s | %s',
            $i + 1,
            $inc->id,
            Str::limit($inc->title ?? '', 120),
            Str::limit($inc->summary ?? $inc->root_cause ?? '', 200),
        ))->implode("\n");

        $system = 'You are a relevance ranker. Given a question and a list of incidents, '
            .'return their IDs ordered by relevance to the question (most relevant first). '
            .'Return ONLY valid JSON: {"ranked_ids": [id, id, ...]} with every id exactly once.';
        $user = "Question: {$query}\n\nIncidents:\n{$list}";

        $start = microtime(true);

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(20)
                ->post($this->buildUrl(), [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                ]);

            $usage = $this->normalizeUsage($response->json('usage'));
            $this->usageLogger->log(
                fieldType: 'rerank',
                model: $model,
                success: $response->successful(),
                usage: $usage,
                responseTimeMs: (int) ((microtime(true) - $start) * 1000),
            );

            $rankedIds = $this->extractRankedIds($response->json('choices.0.message.content') ?? '');

            if (empty($rankedIds)) {
                return $incidents->take($topK);
            }

            // Reorder explicitly by the model's ranking, then append any omitted.
            $byId = $incidents->keyBy('id');
            $ordered = [];
            foreach ($rankedIds as $id) {
                if ($model = $byId->get((int) $id)) {
                    $ordered[] = $model;
                }
            }
            foreach ($incidents as $inc) {
                if (! in_array($inc->id, $rankedIds, true)) {
                    $ordered[] = $inc;
                }
            }

            return collect($ordered)->take($topK)->values();
        } catch (\Throwable $e) {
            Log::warning('[Reranker] failed, keeping retrieval order', ['error' => $e->getMessage()]);

            return $incidents->take($topK);
        }
    }

    /**
     * @return int[]
     */
    private function extractRankedIds(string $body): array
    {
        $decoded = JsonExtractor::extract($body);
        $ids = $decoded['ranked_ids'] ?? $decoded['ids'] ?? null;
        if (is_array($ids)) {
            return array_values(array_filter(array_map('intval', $ids), fn ($v) => $v > 0));
        }

        return [];
    }
}
