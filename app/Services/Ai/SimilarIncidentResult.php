<?php

namespace App\Services\Ai;

class SimilarIncidentResult
{
    /**
     * @param  bool  $success  Whether the pipeline completed successfully
     * @param  array  $matches  Verified similar incidents with scores and reasoning
     * @param  string|null  $thinkAnalysis  The Phase 1 THINK analysis JSON
     * @param  string|null  $error  Error message if pipeline failed
     * @param  string|null  $model  The model used for verification
     * @param  int|null  $totalTokens  Total tokens consumed across all phases
     * @param  int  $candidateCount  How many candidates were found in FIND phase
     * @param  int  $verifiedCount  How many candidates passed verification
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $matches = [],
        public readonly ?string $thinkAnalysis = null,
        public readonly ?string $error = null,
        public readonly ?string $model = null,
        public readonly ?int $totalTokens = null,
        public readonly int $candidateCount = 0,
        public readonly int $verifiedCount = 0,
    ) {}

    /**
     * @param  array  $matches  Verified matches with similarity, dimensions, reasoning
     * @param  string|null  $thinkAnalysis  Phase 1 analysis
     * @param  string|null  $model  Model used
     * @param  int|null  $totalTokens  Tokens consumed
     * @param  int  $candidateCount  Candidates found in FIND phase
     */
    public static function success(
        array $matches,
        ?string $thinkAnalysis = null,
        ?string $model = null,
        ?int $totalTokens = null,
        int $candidateCount = 0,
    ): self {
        return new self(
            success: true,
            matches: $matches,
            thinkAnalysis: $thinkAnalysis,
            model: $model,
            totalTokens: $totalTokens,
            candidateCount: $candidateCount,
            verifiedCount: count($matches),
        );
    }

    public static function failure(string $error, ?string $model = null): self
    {
        return new self(
            success: false,
            error: $error,
            model: $model,
        );
    }

    public function toApiResponse(): array
    {
        return collect($this->matches)->map(fn ($match) => [
            'id' => $match['id'],
            'no' => $match['no'],
            'summary' => $match['summary'] ?? '',
            'severity' => $match['severity'] ?? '',
            'incident_date' => $match['incident_date'] ?? '',
            'incident_status' => $match['incident_status'] ?? '',
            'similarity' => $match['similarity'] ?? 0,
            'reason' => $match['reasoning'] ?? ($match['reason'] ?? ''),
            'match_type' => $match['match_type'] ?? 'thematic',
            'dimensions' => $match['dimensions'] ?? [],
        ])->values()->toArray();
    }
}
