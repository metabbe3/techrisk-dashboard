<?php

namespace App\Services\Ai;

use App\Models\Incident;
use App\Services\Ai\Concerns\InteractsWithAiApi;
use App\Services\Ai\Concerns\StripsThinkingTags;
use App\Services\Markdown\IncidentMarkdownExporter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SimilarIncidentService
{
    use InteractsWithAiApi;

    private const MAX_CANDIDATES_PER_DIMENSION = 20;

    public function __construct(
        private readonly AiUsageLogger $usageLogger,
        private readonly CircuitBreaker $circuitBreaker,
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
            $parsed = $this->extractJson($content);

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
     * Phase 2: FIND — gather candidates across multiple dimensions using SQL + RAG.
     */
    private function findPhase(Incident $incident, array $thinkResult): Collection
    {
        $lookbackMonths = config('ai.similarity.lookback_months', 24);
        $maxCandidates = config('ai.similarity.max_candidates', 50);
        $searchHints = $thinkResult['search_hints'] ?? [];

        $candidateIds = collect();

        // 1. Category search — SQL where JSON categories overlap
        $categoryIds = $this->searchByCategories($incident, $lookbackMonths);
        $candidateIds = $candidateIds->merge($categoryIds);

        // 2. Text/root cause search — RAG FULLTEXT with enriched terms
        $textIds = $this->searchByText($incident, $searchHints, $lookbackMonths);
        $candidateIds = $candidateIds->merge($textIds);

        // 3. Financial pattern search — similar fund_status and fund_loss range
        $financialIds = $this->searchByFinancial($incident, $lookbackMonths);
        $candidateIds = $candidateIds->merge($financialIds);

        // 4. Team/source search — same pic_id, incident_source, third_party_client
        $teamIds = $this->searchByTeam($incident, $lookbackMonths);
        $candidateIds = $candidateIds->merge($teamIds);

        // 5. Label search — incidents sharing labels
        $labelIds = $this->searchByLabels($incident, $lookbackMonths);
        $candidateIds = $candidateIds->merge($labelIds);

        // Deduplicate, exclude self, limit
        $candidateIds = $candidateIds
            ->unique()
            ->filter(fn ($id) => $id !== $incident->id)
            ->take($maxCandidates)
            ->values()
            ->toArray();

        if (empty($candidateIds)) {
            return collect();
        }

        return Incident::whereIn('id', $candidateIds)
            ->with(['labels:id,name', 'actionImprovements' => fn ($q) => $q->select(['id', 'incident_id', 'title', 'status', 'due_date'])])
            ->select(Incident::EXTENDED_SIMILARITY_COLUMNS)
            ->get();
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

        $userMessage = "## Source Incident Analysis\n";
        $userMessage .= json_encode($thinkResult, JSON_PRETTY_PRINT)."\n\n";
        $userMessage .= "## Candidate Incidents (verify each one)\n\n";

        foreach ($topCandidates as $i => $candidate) {
            $userMessage .= ($i + 1).". [{$candidate->no}] {$candidate->title}\n";
            $userMessage .= '   ID: '.$candidate->id."\n";
            $userMessage .= '   Summary: '.str_limit($candidate->summary ?? 'N/A', 200)."\n";
            $userMessage .= '   Root Cause: '.str_limit($candidate->root_cause ?? 'N/A', 200)."\n";
            $userMessage .= '   Severity: '.($candidate->severity?->value ?? 'N/A')."\n";
            $userMessage .= '   Type: '.($candidate->incident_type ?? 'N/A')."\n";
            $userMessage .= '   Status: '.($candidate->incident_status?->value ?? 'N/A')."\n";
            $userMessage .= '   Date: '.($candidate->incident_date?->toDateString() ?? 'N/A')."\n";

            $categories = collect([
                ...(array) ($candidate->root_cause_category ?? []),
                ...(array) ($candidate->business_category ?? []),
                ...(array) ($candidate->responsible_team ?? []),
            ])->unique()->implode(', ');
            $userMessage .= '   Categories: '.($categories ?: 'None')."\n";

            $labels = $candidate->labels->pluck('name')->implode(', ');
            $userMessage .= '   Labels: '.($labels ?: 'None')."\n";

            if ($candidate->fund_status) {
                $userMessage .= '   Fund Status: '.$candidate->fund_status->value."\n";
            }
            if ((float) ($candidate->fund_loss ?? 0) > 0) {
                $userMessage .= '   Fund Loss: '.$candidate->fund_loss."\n";
            }
            if ($candidate->mttr) {
                $userMessage .= '   MTTR: '.$candidate->mttr."\n";
            }
            if ($candidate->incident_source) {
                $userMessage .= '   Source: '.$candidate->incident_source."\n";
            }
            if ($candidate->improvements) {
                $userMessage .= '   Improvements: '.str_limit($candidate->improvements, 150)."\n";
            }
            if ($candidate->evidence) {
                $userMessage .= '   Evidence: '.str_limit($candidate->evidence, 100)."\n";
            }

            $userMessage .= "\n";
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
            $parsed = $this->extractJson($content);

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

            $candidateMap = $candidates->keyBy('id');

            $matches = $verified->map(function ($v) use ($candidateMap) {
                $id = $v['id'] ?? null;
                $matched = $candidateMap->get($id);

                if (! $matched) {
                    return null;
                }

                return [
                    'id' => $matched->id,
                    'no' => $matched->no,
                    'title' => $matched->title,
                    'summary' => $matched->summary ? str_limit($matched->summary, 150) : '',
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

        $model = config('ai.similarity.reasoning_model', 'REASONING-MODEL');
        $timeout = config('ai.similarity.double_check_timeout', 30);
        $maxTokens = config('ai.similarity.double_check_max_tokens', 1024);

        $sourceContext = $this->buildIncidentContext($sourceIncident);
        $confirmed = [];

        foreach ($uncertain as $match) {
            $candidate = Incident::with(['labels:id,name', 'actionImprovements'])
                ->select(Incident::EXTENDED_SIMILARITY_COLUMNS)
                ->find($match['id']);

            if (! $candidate) {
                continue;
            }

            $candidateContext = $this->buildIncidentContext($candidate);

            $userMessage = "## SOURCE INCIDENT (the one being analyzed)\n{$sourceContext}\n\n";
            $userMessage .= "## CANDIDATE INCIDENT (previously scored similarity: {$match['similarity']})\n{$candidateContext}\n\n";
            $userMessage .= "Previous verifier said: \"{$match['reasoning']}\"\n\n";
            $userMessage .= 'Is this a TRUE similar incident or a FALSE POSITIVE? Return ONLY valid JSON.';

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
                    Log::warning('[SimilarIncident] DOUBLE-CHECK API error for candidate', [
                        'candidate_id' => $match['id'],
                        'status' => $response->status(),
                    ]);
                    $confirmed[] = $match; // Keep uncertain match if double-check fails

                    continue;
                }

                $content = StripsThinkingTags::stripStatic(
                    $responseData['choices'][0]['message']['content'] ?? ''
                );
                $parsed = $this->extractJson($content);

                $this->usageLogger->log(
                    fieldType: 'similarity_double_check',
                    model: $model,
                    success: true,
                    outputLength: strlen($content),
                    usage: $this->formatUsage($usage),
                    responseTimeMs: $responseTimeMs,
                    metadata: ['candidate_id' => $match['id']],
                );

                if (! is_array($parsed) || ($parsed['confirmed'] ?? false) !== true) {
                    Log::info('[SimilarIncident] DOUBLE-CHECK rejected match', [
                        'candidate_id' => $match['id'],
                        'candidate_no' => $match['no'],
                        'original_similarity' => $match['similarity'],
                        'reason' => $parsed['reasoning'] ?? 'unknown',
                    ]);

                    continue; // Rejected
                }

                // Update with double-checked similarity and reasoning
                $match['similarity'] = min(max((float) ($parsed['similarity'] ?? $match['similarity']), 0), 1);
                $match['match_type'] = $parsed['match_type'] ?? $match['match_type'] ?? 'thematic';
                $match['reasoning'] = $parsed['reasoning'] ?? $match['reasoning'];
                $match['double_checked'] = true;
                $confirmed[] = $match;

                Log::info('[SimilarIncident] DOUBLE-CHECK confirmed match', [
                    'candidate_id' => $match['id'],
                    'candidate_no' => $match['no'],
                    'similarity' => $match['similarity'],
                ]);
            } catch (\Throwable $e) {
                Log::warning('[SimilarIncident] DOUBLE-CHECK failed for candidate', [
                    'candidate_id' => $match['id'],
                    'error' => $e->getMessage(),
                ]);
                $confirmed[] = $match; // Keep uncertain match if double-check fails
            }
        }

        $allMatches = array_merge($confident, $confirmed);
        usort($allMatches, fn ($a, $b) => ($b['similarity'] ?? 0) <=> ($a['similarity'] ?? 0));

        Log::info('[SimilarIncident] DOUBLE-CHECK complete', [
            'confident' => count($confident),
            'uncertain_checked' => count($uncertain),
            'confirmed' => count($confirmed),
            'rejected' => count($uncertain) - count($confirmed),
            'total_final' => count($allMatches),
        ]);

        return $allMatches;
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

    private function searchByText(Incident $incident, array $searchHints, int $lookbackMonths): array
    {
        $textHint = $searchHints['text'] ?? '';
        $rootCauseHints = $searchHints['root_cause'] ?? [];

        $allTerms = collect([
            $incident->title,
            $incident->summary,
            $incident->root_cause,
            $textHint,
            ...(array) $rootCauseHints,
        ])->filter()->flatMap(function ($term) {
            $words = preg_split('/[\s,;]+/', $term, -1, PREG_SPLIT_NO_EMPTY);
            $phrases = [];
            $chunk = '';
            foreach ($words as $word) {
                if (strlen($word) < 3) {
                    continue;
                }
                $chunk .= ($chunk ? ' ' : '').$word;
                if (str_word_count($chunk) >= 2) {
                    $phrases[] = $chunk;
                    $chunk = '';
                }
            }
            if ($chunk) {
                $phrases[] = $chunk;
            }

            return $phrases;
        })->unique()->take(10)->values()->toArray();

        if (empty($allTerms)) {
            return [];
        }

        return Incident::where('id', '!=', $incident->id)
            ->where('incident_date', '>=', now()->subMonths($lookbackMonths))
            ->where(function ($q) use ($allTerms) {
                foreach ($allTerms as $term) {
                    $q->orWhere('title', 'LIKE', "%{$term}%")
                        ->orWhere('summary', 'LIKE', "%{$term}%")
                        ->orWhere('root_cause', 'LIKE', "%{$term}%")
                        ->orWhere('improvements', 'LIKE', "%{$term}%")
                        ->orWhere('evidence', 'LIKE', "%{$term}%");
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
        $query = Incident::where('id', '!=', $incident->id)
            ->where('incident_date', '>=', now()->subMonths($lookbackMonths));

        $hasCondition = false;

        if ($incident->pic_id) {
            $query->orWhere('pic_id', $incident->pic_id);
            $hasCondition = true;
        }
        if ($incident->incident_source) {
            $query->orWhere('incident_source', $incident->incident_source);
            $hasCondition = true;
        }
        if ($incident->third_party_client) {
            $query->orWhere('third_party_client', $incident->third_party_client);
            $hasCondition = true;
        }

        if (! $hasCondition) {
            return [];
        }

        return $query->limit(self::MAX_CANDIDATES_PER_DIMENSION)
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

    private function extractJson(string $content): ?array
    {
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $parsed = json_decode($matches[1], true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        $jsonStart = strpos($content, '{');
        $jsonEnd = strrpos($content, '}');
        if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
            $candidate = substr($content, $jsonStart, $jsonEnd - $jsonStart + 1);
            $parsed = json_decode($candidate, true);
            if (is_array($parsed)) {
                return $parsed;
            }
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
