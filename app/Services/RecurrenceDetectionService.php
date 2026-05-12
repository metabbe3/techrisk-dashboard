<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\AiSetting;
use App\Services\Ai\AiTextService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RecurrenceDetectionService
{
    private const SCORE_THRESHOLD = 3;

    private const MAX_MATCHES = 5;

    private const CANDIDATE_LIMIT = 200;

    private const LOOKBACK_MONTHS = 12;

    public function __construct(
        private AiTextService $aiService
    ) {}

    public function detect(Incident $incident): void
    {
        $incident = $incident->fresh(['labels', 'actionImprovements']);

        $incidentCategories = array_filter([
            ...(array) ($incident->root_cause_category ?? []),
            ...(array) ($incident->business_category ?? []),
            ...(array) ($incident->responsible_team ?? []),
        ]);

        $incidentLabelNames = $incident->labels->pluck('name')->map('strtolower')->toArray();

        // No categories AND no labels — try AI-based similarity instead of skipping
        if (empty($incidentCategories) && empty($incidentLabelNames)) {
            $this->detectViaAi($incident);

            return;
        }

        $candidates = $this->fetchCandidates($incident);

        if ($candidates->isEmpty()) {
            $this->storeResult($incident, ['analyzed_at' => now()->toIso8601String()]);

            return;
        }

        $scored = $this->scoreCandidates($incident, $candidates, $incidentLabelNames);

        if ($scored->isEmpty()) {
            $this->storeResult($incident, ['analyzed_at' => now()->toIso8601String()]);

            return;
        }

        $topMatches = $scored->take(self::MAX_MATCHES)->values();
        $matchData = $this->buildMatchData($topMatches);

        $aiAnalysis = $this->generateAiAnalysis($incident, $matchData);

        $this->storeResult($incident, [
            'is_recurring' => true,
            'detection_method' => 'category_match',
            'matches' => $matchData,
            'ai_analysis' => $aiAnalysis,
            'detected_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * AI-based similarity detection when structured categories are missing.
     * Reuses the same prompt/approach as the "Find Similar" button feature.
     */
    private function detectViaAi(Incident $incident): void
    {
        if (! $this->aiService->isAvailable()) {
            $this->storeResult($incident, ['analyzed_at' => now()->toIso8601String()]);

            return;
        }

        // Need at least a summary or title to compare
        if (empty($incident->summary) && empty($incident->title)) {
            $this->storeResult($incident, ['analyzed_at' => now()->toIso8601String()]);

            return;
        }

        $candidates = Incident::where('classification', 'Incident')
            ->where('id', '!=', $incident->id)
            ->where('incident_date', '>=', now()->subMonths(self::LOOKBACK_MONTHS))
            ->select(['id', 'no', 'summary', 'severity', 'incident_type', 'incident_date', 'incident_status'])
            ->latest('incident_date')
            ->limit(50)
            ->get()
            ->toArray();

        if (empty($candidates)) {
            $this->storeResult($incident, ['analyzed_at' => now()->toIso8601String()]);

            return;
        }

        try {
            $incidentData = array_filter([
                'title' => $incident->title,
                'summary' => $incident->summary,
                'root_cause' => $incident->root_cause,
                'severity' => $incident->severity,
                'incident_type' => $incident->incident_type,
            ], fn ($v) => filled($v));

            $result = $this->aiService->detectSimilar(
                incidentData: $incidentData,
                recentIncidents: $candidates,
            );

            $similar = $result['similar'] ?? [];

            if (empty($similar)) {
                $this->storeResult($incident, ['analyzed_at' => now()->toIso8601String()]);

                return;
            }

            // Enrich matches with action improvement data
            $matchIds = collect($similar)->pluck('id')->toArray();
            $enrichedIncidents = Incident::whereIn('id', $matchIds)
                ->with(['actionImprovements' => fn ($q) => $q->select(['id', 'incident_id', 'title', 'status', 'due_date'])])
                ->get()
                ->keyBy('id');

            $matchData = collect($similar)->take(self::MAX_MATCHES)->map(function ($sim) use ($enrichedIncidents) {
                $matched = $enrichedIncidents->get($sim['id']);
                $actions = $matched
                    ? $matched->actionImprovements->map(fn ($a) => [
                        'title' => $a->title,
                        'status' => $a->status,
                        'due_date' => is_string($a->due_date) ? $a->due_date : $a->due_date?->toDateString(),
                    ])->toArray()
                    : [];

                $pendingActions = collect($actions)->filter(fn ($a) => in_array($a['status'] ?? '', ['Open', 'In Progress']))->count();
                $overdueActions = collect($actions)->filter(fn ($a) => in_array($a['status'] ?? '', ['Open', 'In Progress']) && $a['due_date'] && $a['due_date'] < now()->toDateString())->count();

                return [
                    'id' => $sim['id'],
                    'no' => $sim['no'],
                    'summary' => $sim['summary'] ? Str::limit($sim['summary'], 120) : '',
                    'severity' => $sim['severity'] ?? '',
                    'incident_date' => $sim['incident_date'],
                    'incident_status' => $sim['incident_status'] ?? '',
                    'score' => (int) round(($sim['similarity'] ?? 0) * 10),
                    'similarity' => $sim['similarity'] ?? 0,
                    'reason' => $sim['reason'] ?? '',
                    'match_reasons' => ['ai_similarity'],
                    'action_improvements' => $actions,
                    'pending_actions' => $pendingActions,
                    'overdue_actions' => $overdueActions,
                ];
            })->values()->toArray();

            $aiAnalysis = $this->generateAiAnalysisForSimilar($incident, $matchData);

            $this->storeResult($incident, [
                'is_recurring' => true,
                'detection_method' => 'ai_similarity',
                'matches' => $matchData,
                'ai_analysis' => $aiAnalysis,
                'detected_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[RecurrenceDetection] AI similarity fallback failed', [
                'incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);
            $this->storeResult($incident, ['analyzed_at' => now()->toIso8601String()]);
        }
    }

    private function fetchCandidates(Incident $incident)
    {
        return Incident::where('classification', 'Incident')
            ->where('id', '!=', $incident->id)
            ->where('incident_date', '>=', now()->subMonths(self::LOOKBACK_MONTHS))
            ->with(['actionImprovements' => fn ($q) => $q->select(['id', 'incident_id', 'title', 'status', 'due_date']), 'labels:id,name'])
            ->select(['id', 'no', 'summary', 'severity', 'incident_date', 'incident_status',
                'root_cause_category', 'business_category', 'responsible_team'])
            ->limit(self::CANDIDATE_LIMIT)
            ->get();
    }

    private function scoreCandidates(Incident $incident, $candidates, array $incidentLabelNames)
    {
        $incidentRootCause = (array) ($incident->root_cause_category ?? []);
        $incidentBizCat = (array) ($incident->business_category ?? []);
        $incidentTeam = (array) ($incident->responsible_team ?? []);

        $scored = collect();

        foreach ($candidates as $candidate) {
            $score = 0;
            $matchReasons = [];

            $candRootCause = (array) ($candidate->root_cause_category ?? []);
            $candBizCat = (array) ($candidate->business_category ?? []);
            $candTeam = (array) ($candidate->responsible_team ?? []);

            $rootCauseOverlap = array_intersect($incidentRootCause, $candRootCause);
            if (count($rootCauseOverlap) > 0) {
                $score += count($rootCauseOverlap) * 3;
                $matchReasons[] = 'root_cause_category';
            }

            $bizCatOverlap = array_intersect($incidentBizCat, $candBizCat);
            if (count($bizCatOverlap) > 0) {
                $score += count($bizCatOverlap) * 2;
                $matchReasons[] = 'business_category';
            }

            $teamOverlap = array_intersect($incidentTeam, $candTeam);
            if (count($teamOverlap) > 0) {
                $score += count($teamOverlap) * 2;
                $matchReasons[] = 'responsible_team';
            }

            if (! empty($incidentLabelNames) && $candidate->labels->isNotEmpty()) {
                $candLabelNames = $candidate->labels->pluck('name')->map('strtolower')->toArray();
                $labelOverlap = array_intersect($incidentLabelNames, $candLabelNames);
                if (count($labelOverlap) > 0) {
                    $score += count($labelOverlap) * 2;
                    $matchReasons[] = 'labels';
                }
            }

            if ($incident->severity && $candidate->severity === $incident->severity) {
                $score += 1;
            }

            if ($score >= self::SCORE_THRESHOLD) {
                $candidate->_score = $score;
                $candidate->_match_reasons = $matchReasons;
                $scored->push($candidate);
            }
        }

        return $scored->sortByDesc('_score');
    }

    private function buildMatchData($topMatches): array
    {
        return $topMatches->map(function ($match) {
            $actions = $match->actionImprovements->map(fn ($a) => [
                'title' => $a->title,
                'status' => $a->status,
                'due_date' => is_string($a->due_date) ? $a->due_date : $a->due_date?->toDateString(),
            ])->toArray();

            $pendingActions = collect($actions)->filter(fn ($a) => in_array($a['status'] ?? '', ['Open', 'In Progress']))->count();
            $overdueActions = collect($actions)->filter(fn ($a) => in_array($a['status'] ?? '', ['Open', 'In Progress']) && $a['due_date'] && $a['due_date'] < now()->toDateString())->count();

            return [
                'id' => $match->id,
                'no' => $match->no,
                'summary' => $match->summary ? Str::limit($match->summary, 120) : '',
                'severity' => $match->severity,
                'incident_date' => $match->incident_date?->toDateString(),
                'incident_status' => $match->incident_status,
                'score' => $match->_score,
                'match_reasons' => $match->_match_reasons,
                'action_improvements' => $actions,
                'pending_actions' => $pendingActions,
                'overdue_actions' => $overdueActions,
            ];
        })->toArray();
    }

    private function generateAiAnalysis(Incident $incident, array $matchData): string
    {
        if (! $this->aiService->isAvailable()) {
            return $this->fallbackAnalysis($matchData);
        }

        try {
            $resolvedModel = AiSetting::get('default_model', config('ai.default_model'));

            $systemPrompt = <<<'PROMPT'
You are analyzing incident recurrence patterns. Given a new incident and similar past incidents with their remediation status, provide a concise analysis. Focus on:
1. Why this incident likely recurred (link to incomplete past actions)
2. What pattern connects these incidents
3. What specific overdue/pending actions contributed

Keep it under 3 sentences. Be specific with incident numbers and action titles. Return a JSON object with a single "analysis" field containing your explanation as a string.
PROMPT;

            $userMessage = "New incident: [{$incident->no}] {$incident->summary}\n";
            $userMessage .= 'Root cause categories: '.implode(', ', (array) ($incident->root_cause_category ?? []))."\n";
            $userMessage .= 'Business categories: '.implode(', ', (array) ($incident->business_category ?? []))."\n";
            $userMessage .= 'Responsible teams: '.implode(', ', (array) ($incident->responsible_team ?? []))."\n\n";
            $userMessage .= "Similar past incidents:\n";

            foreach ($matchData as $i => $match) {
                $userMessage .= ($i + 1).". [{$match['no']}] {$match['summary']}\n";
                $userMessage .= "   Status: {$match['incident_status']}, Severity: {$match['severity']}, Date: {$match['incident_date']}\n";
                $userMessage .= "   Pending actions: {$match['pending_actions']}, Overdue: {$match['overdue_actions']}\n";
                foreach ($match['action_improvements'] as $action) {
                    $userMessage .= "   - [{$action['status']}] {$action['title']}".($action['due_date'] ? " (due: {$action['due_date']})" : '')."\n";
                }
            }

            $result = $this->aiService->callAiForJson(
                'recurrence_detection',
                $resolvedModel,
                $systemPrompt,
                $userMessage,
                ['analysis' => '']
            );

            return is_string($result['analysis'] ?? null) && filled($result['analysis'])
                ? $result['analysis']
                : $this->fallbackAnalysis($matchData);
        } catch (\Throwable $e) {
            Log::warning('[RecurrenceDetection] AI analysis failed', ['error' => $e->getMessage()]);

            return $this->fallbackAnalysis($matchData);
        }
    }

    /**
     * Generate AI analysis for AI-similarity-detected matches.
     * Uses the similarity reason from the first match as context.
     */
    private function generateAiAnalysisForSimilar(Incident $incident, array $matchData): string
    {
        if (! $this->aiService->isAvailable()) {
            return $this->fallbackAnalysis($matchData);
        }

        try {
            $resolvedModel = AiSetting::get('default_model', config('ai.default_model'));

            $reasons = collect($matchData)->pluck('reason')->filter()->implode('; ');

            $systemPrompt = <<<'PROMPT'
You are analyzing incident recurrence detected via AI similarity matching. Given a new incident and AI-identified similar past incidents, provide a concise analysis. Focus on:
1. What connects these incidents based on the AI similarity reasons
2. Whether past remediation was incomplete (link to overdue/pending actions)
3. What should be done differently

Keep it under 3 sentences. Be specific with incident numbers. Return a JSON object with a single "analysis" field containing your explanation as a string.
PROMPT;

            $userMessage = "New incident: [{$incident->no}] {$incident->summary}\n";
            $userMessage .= "AI similarity reasons: {$reasons}\n\n";
            $userMessage .= "Similar past incidents:\n";

            foreach ($matchData as $i => $match) {
                $userMessage .= ($i + 1).". [{$match['no']}] {$match['summary']}\n";
                $userMessage .= "   Status: {$match['incident_status']}, Severity: {$match['severity']}, Date: {$match['incident_date']}\n";
                $userMessage .= "   Similarity: ".round(($match['similarity'] ?? 0) * 100)."%, Pending: {$match['pending_actions']}, Overdue: {$match['overdue_actions']}\n";
            }

            $result = $this->aiService->callAiForJson(
                'recurrence_detection',
                $resolvedModel,
                $systemPrompt,
                $userMessage,
                ['analysis' => '']
            );

            return is_string($result['analysis'] ?? null) && filled($result['analysis'])
                ? $result['analysis']
                : $this->fallbackAnalysis($matchData);
        } catch (\Throwable $e) {
            Log::warning('[RecurrenceDetection] AI analysis (similarity) failed', ['error' => $e->getMessage()]);

            return $this->fallbackAnalysis($matchData);
        }
    }

    private function fallbackAnalysis(array $matchData): string
    {
        $totalPending = array_sum(array_column($matchData, 'pending_actions'));
        $totalOverdue = array_sum(array_column($matchData, 'overdue_actions'));
        $incidents = collect($matchData)->pluck('no')->implode(', ');

        $analysis = "This incident shares patterns with {$incidents}.";
        if ($totalOverdue > 0) {
            $analysis .= " There are {$totalOverdue} overdue action improvement(s) from similar past incidents that may have contributed to recurrence.";
        } elseif ($totalPending > 0) {
            $analysis .= " There are {$totalPending} pending action improvement(s) from similar past incidents.";
        }

        return $analysis;
    }

    private function storeResult(Incident $incident, array $data): void
    {
        $incident->recurrence_data = $data;
        $incident->saveQuietly();
    }
}
