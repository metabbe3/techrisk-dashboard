<?php

namespace App\Services\Ai;

use App\Models\Incident;
use App\Models\RagDocument;
use App\Services\Ai\Concerns\InteractsWithAiApi;
use App\Services\Ai\Concerns\JsonExtractor;
use App\Services\Ai\Concerns\StripsThinkingTags;
use App\Services\IncidentFormatter;
use App\Services\Markdown\IncidentMarkdownExporter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SimilarIncidentService
{
    use InteractsWithAiApi;

    private const MAX_CANDIDATES_PER_DIMENSION = 20;

    public function __construct(
        private readonly AiUsageLogger $usageLogger,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly RagService $ragService,
    ) {}

    public function isAvailable(): bool
    {
        $model = config('ai.similarity.reasoning_model', 'REASONING-MODEL');

        return filled($this->getApiKey())
            && filled($this->getBaseUrl())
            && $this->circuitBreaker->isAvailable($model);
    }

    /**
     * Run the full THINK → FIND → VERIFY pipeline.
     */
    public function analyze(Incident $sourceIncident): SimilarIncidentResult
    {
        if (! $this->isAvailable()) {
            return SimilarIncidentResult::failure('AI service unavailable or circuit breaker open.');
        }

        $sourceIncident->loadMissing(['labels', 'actionImprovements']);

        // Phase 1: THINK — deep analysis of source incident
        $thinkResult = $this->thinkPhase($sourceIncident);

        if ($thinkResult === null) {
            return SimilarIncidentResult::failure('Think phase failed — could not analyze incident.');
        }

        // Phase 2: FIND — gather candidates across multiple dimensions
        $candidates = $this->findPhase($sourceIncident, $thinkResult);

        if ($candidates->isEmpty()) {
            return SimilarIncidentResult::success(
                matches: [],
                thinkAnalysis: json_encode($thinkResult),
                model: config('ai.similarity.reasoning_model'),
                candidateCount: 0,
            );
        }

        // Phase 3: VERIFY — REASONING-MODEL validates each candidate
        return $this->verifyPhase($sourceIncident, $thinkResult, $candidates);
    }

    /**
     * Phase 1: THINK — REASONING-MODEL deeply analyzes the source incident.
     */
    private function thinkPhase(Incident $incident): ?array
    {
        $model = config('ai.similarity.reasoning_model', 'REASONING-MODEL');
        $timeout = config('ai.similarity.think_timeout', 45);
        $maxTokens = config('ai.similarity.think_max_tokens', 2048);
        $systemPrompt = config('ai.prompts.similarity_think.system');

        if (! $systemPrompt) {
            Log::warning('[SimilarIncident] similarity_think prompt not configured');

            return null;
        }

        $incidentContext = $this->buildIncidentContext($incident);

        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout($timeout)
                ->post($this->buildUrl(), [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $incidentContext],
                    ],
                    'max_tokens' => $maxTokens,
                ]);

            $responseTimeMs = $this->elapsedMs($startTime);
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];

            if (! $response->successful()) {
                Log::warning('[SimilarIncident] THINK phase API error', ['status' => $response->status()]);

                return null;
            }

            $content = $responseData['choices'][0]['message']['content'] ?? '';

            if (blank($content)) {
                return null;
            }

            $content = StripsThinkingTags::stripStatic($content);
            $parsed = JsonExtractor::extract($content);

            $this->usageLogger->log(
                fieldType: 'similarity_think',
                model: $model,
                success: true,
                outputLength: strlen($content),
                usage: $this->formatUsage($usage),
                responseTimeMs: $responseTimeMs,
            );

            return is_array($parsed) ? $parsed : null;
        } catch (\Throwable $e) {
            Log::warning('[SimilarIncident] THINK phase failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Phase 2: FIND — ranked hybrid retrieval.
     *
     * RAG FULLTEXT (rag_documents.searchable_content) is the primary ranked
     * retriever; structured dimensions (category / label / team / financial)
     * add capped boosts. Each candidate gets a fused score; the collection is
     * returned sorted by that score so VERIFY's later `->take(N)` keeps the
     * strongest matches instead of an arbitrary (primary-key) slice.
     */
    private function findPhase(Incident $incident, array $thinkResult): Collection
    {
        $lookbackMonths = (int) config('ai.similarity.lookback_months', 24);
        $maxCandidates = (int) config('ai.similarity.max_candidates', 50);

        $ragScores = $this->searchByRag($incident, $thinkResult, $lookbackMonths);

        $dimensionIds = [
            'category' => $this->searchByCategories($incident, $lookbackMonths),
            'label' => $this->searchByLabels($incident, $lookbackMonths),
            'team' => $this->searchByTeam($incident, $lookbackMonths),
            'financial' => $this->searchByFinancial($incident, $lookbackMonths),
        ];

        $ragWeight = (float) config('ai.similarity.rag_weight', 0.6);
        $structWeight = (float) config('ai.similarity.struct_weight', 0.4);
        $boosts = (array) config('ai.similarity.boost', []);

        $allIds = array_unique(array_merge(
            array_keys($ragScores),
            ...array_values($dimensionIds),
        ));
        $allIds = array_values(array_filter($allIds, fn ($id) => $id !== $incident->id));

        if (empty($allIds)) {
            return collect();
        }

        $scores = [];
        foreach ($allIds as $id) {
            $rag = (float) ($ragScores[$id] ?? 0.0);
            $boostSum = 0.0;
            foreach ($dimensionIds as $dim => $ids) {
                if (in_array($id, $ids, true)) {
                    $boostSum += (float) ($boosts[$dim] ?? 0);
                }
            }
            $scores[$id] = ($rag * $ragWeight) + (min($boostSum, 1.0) * $structWeight);
        }

        arsort($scores);
        $topIds = array_slice(array_keys($scores), 0, $maxCandidates);

        if (empty($topIds)) {
            return collect();
        }

        $candidates = Incident::whereIn('id', $topIds)
            ->with(['labels:id,name', 'pic:id,name,email', 'actionImprovements' => fn ($q) => $q->select(['id', 'incident_id', 'title', 'status', 'due_date'])])
            ->select(Incident::EXTENDED_SIMILARITY_COLUMNS)
            ->get();

        $candidates->each(fn ($c) => $c->retrieval_score = $scores[$c->id] ?? 0.0);

        return $candidates->sortByDesc('retrieval_score')->values();
    }

    /**
     * Primary ranked retriever: MySQL FULLTEXT over rag_documents.
     * Returns [incident_id => normalized score 0..1]. Empty on failure (e.g.
     * SQLite without FULLTEXT) so structured-only retrieval still proceeds.
     */
    private function searchByRag(Incident $incident, array $thinkResult, int $lookbackMonths): array
    {
        $hints = $thinkResult['search_hints'] ?? [];
        $textHint = is_string($hints['text'] ?? null) ? $hints['text'] : '';
        $rootCauseHints = (array) ($hints['root_cause'] ?? []);

        $query = collect([
            $incident->title,
            $incident->summary,
            $incident->root_cause,
            $textHint,
            ...$rootCauseHints,
        ])->filter()->implode(' ');

        if (trim($query) === '') {
            return [];
        }

        $limit = (int) config('ai.similarity.rag_search_limit', 40);

        try {
            $results = $this->ragService->search($query, [
                'date_from' => now()->subMonths($lookbackMonths)->toDateString(),
            ], $limit);
        } catch (\Throwable $e) {
            Log::warning('[SimilarIncident] RAG search failed, using structured-only retrieval', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if ($results->isEmpty()) {
            return [];
        }

        $max = (float) $results->max('relevance_score') ?: 1.0;

        return $results
            ->reject(fn ($doc) => $doc->incident_id === $incident->id)
            ->mapWithKeys(fn ($doc) => [$doc->incident_id => min((float) $doc->relevance_score / $max, 1.0)])
            ->toArray();
    }

    /**
     * Phase 3: VERIFY — REASONING-MODEL validates each candidate.
     */
    private function verifyPhase(Incident $sourceIncident, array $thinkResult, Collection $candidates): SimilarIncidentResult
    {
        $model = config('ai.similarity.reasoning_model', 'REASONING-MODEL');
        $timeout = config('ai.similarity.verify_timeout', 60);
        $maxTokens = config('ai.similarity.verify_max_tokens', 4096);
        $maxVerify = config('ai.similarity.max_verify_candidates', 20);
        $minSimilarity = config('ai.similarity.min_similarity', 0.4);
        $maxResults = config('ai.similarity.max_results', 10);

        $systemPrompt = config('ai.prompts.similarity_verify.system');

        if (! $systemPrompt) {
            Log::warning('[SimilarIncident] similarity_verify prompt not configured');

            return SimilarIncidentResult::failure('Verify prompt not configured.');
        }

        $topCandidates = $candidates->take($maxVerify);

        Log::info('[SimilarIncident] VERIFY selection', [
            'candidates_found' => $candidates->count(),
            'sent_to_verify' => $topCandidates->count(),
            'dropped_at_verify' => $candidates->count() - $topCandidates->count(),
        ]);

        $contextMap = RagDocument::whereIn('incident_id', $topCandidates->pluck('id'))
            ->pluck('context_content', 'incident_id');

        $userMessage = "## Source Incident Analysis\n";
        $userMessage .= json_encode($thinkResult, JSON_PRETTY_PRINT)."\n\n";
        $userMessage .= "## Candidate Incidents (verify each, ranked by retrieval signal)\n\n";

        foreach ($topCandidates as $i => $candidate) {
            $rank = $i + 1;
            $score = number_format((float) ($candidate->retrieval_score ?? 0), 2);
            $userMessage .= "{$rank}. [{$candidate->no}] {$candidate->title}";
            $userMessage .= " · Rank #{$rank} · retrieval {$score} · ID: {$candidate->id}\n";

            // Prefer the prebuilt RAG compact context (richer than truncated fields);
            // fall back to a fresh compact render when no RAG doc exists.
            $context = $contextMap->get($candidate->id) ?? IncidentFormatter::formatCompact($candidate);
            $userMessage .= Str::limit($context, 800)."\n\n";
        }

        $userMessage .= 'Verify each candidate against the source incident analysis. Return ONLY valid JSON.';

        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout($timeout)
                ->post($this->buildUrl(), [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                    'max_tokens' => $maxTokens,
                ]);

            $responseTimeMs = $this->elapsedMs($startTime);
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];

            if (! $response->successful()) {
                Log::warning('[SimilarIncident] VERIFY phase API error', ['status' => $response->status()]);

                return SimilarIncidentResult::failure('Verify API error: HTTP '.$response->status(), $model);
            }

            $content = $responseData['choices'][0]['message']['content'] ?? '';

            if (blank($content)) {
                return SimilarIncidentResult::failure('Verify returned empty response.', $model);
            }

            $content = StripsThinkingTags::stripStatic($content);
            $parsed = JsonExtractor::extract($content);

            $this->usageLogger->log(
                fieldType: 'similarity_verify',
                model: $model,
                success: true,
                outputLength: strlen($content),
                usage: $this->formatUsage($usage),
                responseTimeMs: $responseTimeMs,
                metadata: ['candidate_count' => $topCandidates->count()],
            );

            if (! is_array($parsed)) {
                return SimilarIncidentResult::failure('Could not parse verify response.', $model);
            }

            $verified = collect($parsed['verified'] ?? [])
                ->filter(fn ($v) => is_array($v) && ($v['verified'] ?? false) === true)
                ->filter(fn ($v) => ($v['similarity'] ?? 0) >= $minSimilarity)
                ->sortByDesc('similarity')
                ->take($maxResults)
                ->values();

            $candidateById = $candidates->keyBy('id');
            $candidateByNo = $candidates->keyBy(fn ($c) => $c->no);

            $matches = $verified->map(function ($v) use ($candidateById, $candidateByNo) {
                $matched = $this->resolveCandidate($v, $candidateById, $candidateByNo);

                if (! $matched) {
                    return null;
                }

                return [
                    'id' => $matched->id,
                    'no' => $matched->no,
                    'title' => $matched->title,
                    'summary' => $matched->summary ? Str::limit($matched->summary, 150) : '',
                    'severity' => $matched->severity ?? '',
                    'incident_date' => $matched->incident_date?->toDateString(),
                    'incident_status' => $matched->incident_status ?? '',
                    'similarity' => min(max((float) ($v['similarity'] ?? 0), 0), 1),
                    'match_type' => $v['match_type'] ?? 'thematic',
                    'dimensions' => $v['dimensions'] ?? [],
                    'reasoning' => is_string($v['reasoning'] ?? null) ? $v['reasoning'] : '',
                ];
            })->filter()->values()->toArray();

            // Phase 4: DOUBLE-CHECK uncertain matches
            $matches = $this->doubleCheckPhase($sourceIncident, $matches);

            return SimilarIncidentResult::success(
                matches: $matches,
                thinkAnalysis: json_encode($thinkResult),
                model: $model,
                totalTokens: $usage['total_tokens'] ?? null,
                candidateCount: $candidates->count(),
            );
        } catch (\Throwable $e) {
            Log::warning('[SimilarIncident] VERIFY phase failed', ['error' => $e->getMessage()]);

            return SimilarIncidentResult::failure('Verify phase error: '.$e->getMessage(), $model);
        }
    }

    /**
     * Phase 4: DOUBLE-CHECK — re-evaluate uncertain matches with FULL incident data.
     */
    private function doubleCheckPhase(Incident $sourceIncident, array $matches): array
    {
        if (! config('ai.similarity.double_check_enabled', true)) {
            return $matches;
        }

        $threshold = config('ai.similarity.double_check_threshold', 0.7);
        $systemPrompt = config('ai.prompts.similarity_double_check.system');

        if (! $systemPrompt) {
            return $matches;
        }

        $confident = [];
        $uncertain = [];

        foreach ($matches as $match) {
            if (($match['similarity'] ?? 0) >= $threshold) {
                $confident[] = $match;
            } else {
                $uncertain[] = $match;
            }
        }

        if (empty($uncertain)) {
            Log::info('[SimilarIncident] DOUBLE-CHECK skipped — all matches confident', [
                'confident_count' => count($confident),
            ]);

            return $matches;
        }

        $batchSize = max(1, (int) config('ai.similarity.double_check_batch_size', 8));
        $confirmed = [];

        foreach (array_chunk($uncertain, $batchSize) as $batch) {
            $confirmed = array_merge($confirmed, $this->doubleCheckBatch($sourceIncident, $batch, $systemPrompt));
        }

        $allMatches = array_merge($confident, $confirmed);
        usort($allMatches, fn ($a, $b) => ($b['similarity'] ?? 0) <=> ($a['similarity'] ?? 0));

        Log::info('[SimilarIncident] DOUBLE-CHECK complete', [
            'confident' => count($confident),
            'uncertain_checked' => count($uncertain),
            'confirmed' => count($confirmed),
            'rejected' => count($uncertain) - count($confirmed),
            'batches' => (int) ceil(count($uncertain) / $batchSize),
            'total_final' => count($allMatches),
        ]);

        return $allMatches;
    }

    /**
     * Adjudicate one batch of uncertain matches in a single LLM call.
     * On any failure (HTTP error, unparseable JSON, exception) the batch is
     * returned unchanged so uncertain matches are never silently dropped.
     */
    private function doubleCheckBatch(Incident $sourceIncident, array $batch, string $systemPrompt): array
    {
        $model = config('ai.similarity.reasoning_model', 'REASONING-MODEL');
        $timeout = (int) config('ai.similarity.double_check_timeout', 30);
        // Expand the per-call budget with batch size so multi-verdict output isn't truncated.
        $maxTokens = max((int) config('ai.similarity.double_check_max_tokens', 1024), 220 * count($batch));

        $candidates = Incident::with(['labels:id,name', 'pic:id,name,email', 'actionImprovements'])
            ->select(Incident::EXTENDED_SIMILARITY_COLUMNS)
            ->whereIn('id', array_column($batch, 'id'))
            ->get()
            ->keyBy('id');

        $sourceContext = $this->buildIncidentContext($sourceIncident);

        $userMessage = "## SOURCE INCIDENT (the one being analyzed)\n{$sourceContext}\n\n";
        $userMessage .= "## CANDIDATES TO ADJUDICATE (each previously scored uncertain)\n\n";

        foreach ($batch as $i => $match) {
            $candidate = $candidates->get($match['id']);
            if (! $candidate) {
                continue;
            }
            $userMessage .= ($i + 1).". [{$match['no']}] {$match['title']}";
            $userMessage .= " (prior similarity {$match['similarity']}, ID {$match['id']})\n";
            $userMessage .= 'Previous verifier said: "'.Str::limit($match['reasoning'] ?? '', 240)."\"\n";
            $userMessage .= Str::limit($this->buildIncidentContext($candidate), 1200)."\n\n";
        }

        $userMessage .= 'For EACH numbered candidate decide TRUE similar incident or FALSE POSITIVE. ';
        $userMessage .= 'Return ONLY valid JSON: {"verdicts": [{"id": <candidate id>, "confirmed": true/false, "similarity": 0.0-1.0, "match_type": "deep|thematic|false_positive", "reasoning": "..."}]}';

        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout($timeout)
                ->post($this->buildUrl(), [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                    'max_tokens' => $maxTokens,
                ]);

            $responseTimeMs = $this->elapsedMs($startTime);
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];

            if (! $response->successful()) {
                Log::warning('[SimilarIncident] DOUBLE-CHECK batch API error', [
                    'status' => $response->status(),
                    'batch_size' => count($batch),
                ]);

                return $batch;
            }

            $content = StripsThinkingTags::stripStatic($responseData['choices'][0]['message']['content'] ?? '');

            $this->usageLogger->log(
                fieldType: 'similarity_double_check',
                model: $model,
                success: true,
                outputLength: strlen($content),
                usage: $this->formatUsage($usage),
                responseTimeMs: $responseTimeMs,
                metadata: ['batch_size' => count($batch)],
            );

            $parsed = JsonExtractor::extract($content);

            if (! is_array($parsed)) {
                Log::warning('[SimilarIncident] DOUBLE-CHECK batch unparseable, keeping uncertain', [
                    'batch_size' => count($batch),
                ]);

                return $batch;
            }

            return $this->applyDoubleCheckVerdicts($batch, $parsed['verdicts'] ?? $parsed['candidates'] ?? []);
        } catch (\Throwable $e) {
            Log::warning('[SimilarIncident] DOUBLE-CHECK batch failed, keeping uncertain', [
                'error' => $e->getMessage(),
            ]);

            return $batch;
        }
    }

    /**
     * Apply one verdict per match in a double-check batch (matched by id or no).
     */
    private function applyDoubleCheckVerdicts(array $batch, array $verdicts): array
    {
        $byId = [];
        $byNo = [];
        foreach ($verdicts as $verdict) {
            if (isset($verdict['id'])) {
                $byId[(string) $verdict['id']] = $verdict;
            }
            if (isset($verdict['no'])) {
                $byNo[(string) $verdict['no']] = $verdict;
            }
        }

        $confirmed = [];

        foreach ($batch as $match) {
            $verdict = $byId[(string) $match['id']] ?? $byNo[(string) $match['no']] ?? null;

            if (! $verdict || ($verdict['confirmed'] ?? false) !== true) {
                Log::info('[SimilarIncident] DOUBLE-CHECK rejected match', [
                    'candidate_no' => $match['no'],
                    'original_similarity' => $match['similarity'],
                    'reason' => $verdict['reasoning'] ?? 'unknown',
                ]);

                continue;
            }

            $match['similarity'] = min(max((float) ($verdict['similarity'] ?? $match['similarity']), 0), 1);
            $match['match_type'] = $verdict['match_type'] ?? $match['match_type'] ?? 'thematic';
            $match['reasoning'] = $verdict['reasoning'] ?? $match['reasoning'];
            $match['double_checked'] = true;
            $confirmed[] = $match;

            Log::info('[SimilarIncident] DOUBLE-CHECK confirmed match', [
                'candidate_no' => $match['no'],
                'similarity' => $match['similarity'],
            ]);
        }

        return $confirmed;
    }

    // --- Dimension-specific search methods ---

    private function searchByCategories(Incident $incident, int $lookbackMonths): array
    {
        $categories = array_filter(array_merge(
            (array) ($incident->root_cause_category ?? []),
            (array) ($incident->business_category ?? []),
            (array) ($incident->responsible_team ?? []),
        ));

        if (empty($categories)) {
            return [];
        }

        return Incident::where('id', '!=', $incident->id)
            ->where('incident_date', '>=', now()->subMonths($lookbackMonths))
            ->where(function ($q) use ($categories) {
                foreach ($categories as $cat) {
                    $q->orWhereJsonContains('root_cause_category', $cat)
                        ->orWhereJsonContains('business_category', $cat)
                        ->orWhereJsonContains('responsible_team', $cat);
                }
            })
            ->limit(self::MAX_CANDIDATES_PER_DIMENSION)
            ->pluck('id')
            ->toArray();
    }

    private function searchByFinancial(Incident $incident, int $lookbackMonths): array
    {
        if (! $incident->fund_status || (float) ($incident->fund_loss ?? 0) <= 0) {
            return [];
        }

        $fundLoss = (float) $incident->fund_loss;
        $lowerBound = $fundLoss * 0.7;
        $upperBound = $fundLoss * 1.3;

        return Incident::where('id', '!=', $incident->id)
            ->where('incident_date', '>=', now()->subMonths($lookbackMonths))
            ->where('fund_status', $incident->fund_status->value)
            ->whereBetween('fund_loss', [$lowerBound, $upperBound])
            ->limit(self::MAX_CANDIDATES_PER_DIMENSION)
            ->pluck('id')
            ->toArray();
    }

    private function searchByTeam(Incident $incident, int $lookbackMonths): array
    {
        $conditions = [];
        if ($incident->pic_id) {
            $conditions[] = ['pic_id', '=', $incident->pic_id];
        }
        if ($incident->incident_source) {
            $conditions[] = ['incident_source', '=', $incident->incident_source];
        }
        if ($incident->third_party_client) {
            $conditions[] = ['third_party_client', '=', $incident->third_party_client];
        }

        if (empty($conditions)) {
            return [];
        }

        // Group the ORs so the base filters (exclude self, lookback) still apply
        // to every team/source match — otherwise the whole recent corpus matches.
        return Incident::where('id', '!=', $incident->id)
            ->where('incident_date', '>=', now()->subMonths($lookbackMonths))
            ->where(function ($q) use ($conditions) {
                foreach ($conditions as [$column, $op, $value]) {
                    $q->orWhere($column, $op, $value);
                }
            })
            ->limit(self::MAX_CANDIDATES_PER_DIMENSION)
            ->pluck('id')
            ->toArray();
    }

    private function searchByLabels(Incident $incident, int $lookbackMonths): array
    {
        $labelIds = $incident->labels->pluck('id')->toArray();

        if (empty($labelIds)) {
            return [];
        }

        return Incident::where('id', '!=', $incident->id)
            ->where('incident_date', '>=', now()->subMonths($lookbackMonths))
            ->whereHas('labels', fn ($q) => $q->whereIn('labels.id', $labelIds))
            ->limit(self::MAX_CANDIDATES_PER_DIMENSION)
            ->pluck('id')
            ->toArray();
    }

    // --- Helpers ---

    private function buildIncidentContext(Incident $incident): string
    {
        $exporter = app(IncidentMarkdownExporter::class);

        return $exporter->generateForContext($incident);
    }

    /**
     * Resolve a verified entry to its Incident, tolerating the model echoing
     * either the numeric `id` or the string `no` into either field.
     */
    private function resolveCandidate(array $v, Collection $byId, Collection $byNo): ?Incident
    {
        $id = $v['id'] ?? null;

        if ($id !== null && $id !== '') {
            if (is_numeric($id) && ($matched = $byId->get((int) $id))) {
                return $matched;
            }
            if ($matched = $byNo->get((string) $id)) {
                return $matched;
            }
        }

        if (($no = $v['no'] ?? null) !== null) {
            return $byNo->get((string) $no);
        }

        return null;
    }

    private function elapsedMs(float $startTime): int
    {
        return (int) ((microtime(true) - $startTime) * 1000);
    }

    private function formatUsage(array $usage): array
    {
        return array_filter([
            'prompt_tokens' => $usage['prompt_tokens'] ?? null,
            'completion_tokens' => $usage['completion_tokens'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
        ]);
    }
}
