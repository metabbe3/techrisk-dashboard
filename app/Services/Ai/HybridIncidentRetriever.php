<?php

namespace App\Services\Ai;

use App\Models\Incident;
use Illuminate\Support\Collection;

/**
 * Ranked hybrid retrieval over the incident corpus.
 *
 * Query mode (chat): pulls FULLTEXT candidates (RagService) and ranks them by
 * normalized lexical relevance + a light recency tilt. When embeddings are
 * enabled and present, semantic cosine similarity is fused in — so a free-text
 * question gets the most relevant incidents by meaning, not just keywords.
 */
class HybridIncidentRetriever
{
    public function __construct(
        private readonly RagService $ragService,
    ) {}

    /**
     * Retrieve incidents relevant to a free-text query.
     *
     * @param  array{date_from?: string, classification?: string}  $filters
     * @return Collection<int, Incident> each with a ->retrieval_score (0..1)
     */
    public function retrieveForQuery(string $query, int $limit = 8, array $filters = []): Collection
    {
        if (trim($query) === '') {
            return collect();
        }

        $results = $this->ragService->search($query, $filters, max($limit * 3, 20));

        if ($results->isEmpty()) {
            return collect();
        }

        $maxRel = (float) $results->max('relevance_score') ?: 1.0;

        // Embed the query once (when enabled) for vector fusion. Null otherwise —
        // callers without embeddings fall back to lexical + recency only.
        $queryVec = config('ai.embeddings.enabled', false)
            ? app(EmbeddingService::class)->embed($query)
            : null;

        // Map incident_id => stored embedding from the RAG candidates.
        $embeddings = $queryVec
            ? $results->mapWithKeys(fn ($doc) => [$doc->incident_id => $doc->embedding ?? null])
            : collect();

        $incidents = Incident::whereIn('id', $results->pluck('incident_id'))
            ->with(['labels:id,name', 'pic:id,name,email', 'actionImprovements:id,incident_id,title,status'])
            ->select(Incident::EXTENDED_SIMILARITY_COLUMNS)
            ->get()
            ->keyBy('id');

        return $results
            ->sortByDesc('relevance_score')
            ->map(function ($doc) use ($incidents, $maxRel, $queryVec, $embeddings) {
                $incident = $incidents->get($doc->incident_id);
                if (! $incident) {
                    return null;
                }

                $relevance = min((float) ($doc->relevance_score ?? 0) / $maxRel, 1.0);
                $recency = $incident->incident_date
                    ? max(0, 1 - ($incident->incident_date->diffInDays(now()) / 365))
                    : 0;

                $docVec = $embeddings[$doc->incident_id] ?? null;
                if ($queryVec && $docVec) {
                    $cosine = $this->cosine($queryVec, $docVec);
                    $incident->retrieval_score = ($relevance * 0.45) + ($cosine * 0.40) + ($recency * 0.15);
                } else {
                    $incident->retrieval_score = ($relevance * 0.85) + ($recency * 0.15);
                }

                return $incident;
            })
            ->filter()
            ->sortByDesc('retrieval_score')
            ->take($limit)
            ->values();
    }

    /**
     * Cosine similarity between two float[] vectors.
     */
    private function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }

        $dot = $na = $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }

        if ($na <= 0 || $nb <= 0) {
            return 0.0;
        }

        return (float) ($dot / (sqrt($na) * sqrt($nb)));
    }
}
