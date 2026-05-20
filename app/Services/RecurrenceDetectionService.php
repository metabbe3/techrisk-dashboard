<?php

namespace App\Services;

use App\Models\Incident;
use App\Services\Ai\AiTextService;
use App\Services\Ai\RagService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RecurrenceDetectionService
{
    private const SCORE_THRESHOLD = 3;

    private const MAX_MATCHES = 5;

    private const CANDIDATE_LIMIT = 200;

    private const LOOKBACK_MONTHS = 12;

    private const AI_CANDIDATE_LIMIT = 20;

    public function __construct(
        private AiTextService $aiService,
        private RagService $ragService,
    ) {}

    public function detect(Incident $incident): array
    {
        $incident->loadMissing(['labels', 'actionImprovements']);

        $incidentCategories = array_filter([
            ...(array) ($incident->root_cause_category ?? []),
            ...(array) ($incident->business_category ?? []),
            ...(array) ($incident->responsible_team ?? []),
        ]);

        $incidentLabelNames = $incident->labels->pluck('name')->map('strtolower')->toArray();

        if (empty($incidentCategories) && empty($incidentLabelNames)) {
            return $this->detectViaAi($incident);
        }

        $ragScoreMap = $this->getRagScoreMap($incident);

        $candidates = $this->fetchCandidates($incident);

        if ($candidates->isEmpty()) {
            return $this->storeResult($incident, ['analyzed_at' => now()->toIso8601String()]);
        }

        $scored = $this->scoreCandidates($incident, $candidates, $incidentLabelNames, $ragScoreMap);

        if ($scored->isEmpty()) {
            return $this->storeResult($incident, ['analyzed_at' => now()->toIso8601String()]);
        }

        $topMatches = $scored->take(self::MAX_MATCHES)->values();
        $matchData = $this->buildMatchData($topMatches);

        $aiAnalysis = $this->generateAiAnalysis($incident, $matchData);

        return $this->storeResult($incident, [
            'is_recurring' => true,
            'detection_method' => 'category_match',
            'matches' => $matchData,
            'ai_analysis' => $aiAnalysis,
            'detected_at' => now()->toIso8601String(),
        ]);
    }

    private function detectViaAi(Incident $incident): array
    {
        if (! $this->aiService->isAvailable()) {
            return $this->storeResult($incident, ['analyzed_at' => now()->toIso8601String()]);
        }

        if (empty($incident->summary) && empty($incident->title)) {
            return $this->storeResult($incident, ['analyzed_at' => now()->toIso8601String()]);
        }

        $candidates = $this->fetchAiCandidates($incident);

        if (empty($candidates)) {
            return $this->storeResult($incident, ['analyzed_at' => now()->toIso8601String()]);
        }

        try {
            $incidentData = array_filter([
                'title' => $incident->title,
                'summary' => $incident->summary,
                'root_cause' => $incident->root_cause,
                'severity' => $incident->severity,
                'incident_type' => $incident->incident_type,
                'business_category' => $incident->business_category,
                'root_cause_category' => $incident->root_cause_category,
                'responsible_team' => $incident->responsible_team,
                'classification' => $incident->classification,
            ], fn ($v) => filled($v));

            $result = $this->aiService->detectSimilar(
                incidentData: $incidentData,
                recentIncidents: $candidates,
            );

            $similar = $result['similar'] ?? [];

            if (empty($similar)) {
                return $this->storeResult($incident, ['analyzed_at' => now()->toIso8601String()]);
            }

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
                    'summary' => $sim['summary'] ? Str::limit($sim['summary'], 150) : '',
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

            return $this->storeResult($incident, [
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

            return $this->storeResult($incident, ['analyzed_at' => now()->toIso8601String()]);
        }
    }

    private function getRagScoreMap(Incident $incident): array
    {
        $searchQuery = collect([
            $incident->title,
            $incident->summary,
            $incident->root_cause,
        ])->filter()->implode(' ');

        if (! filled($searchQuery)) {
            return [];
        }

        try {
            $ragResults = $this->ragService->search($searchQuery, [
                'date_from' => now()->subMonths(self::LOOKBACK_MONTHS)->toDateString(),
            ], limit: self::CANDIDATE_LIMIT);

            return $ragResults->keyBy('incident_id')->map->relevance_score->toArray();
        } catch (\Throwable $e) {
            Log::warning('[RecurrenceDetection] RAG scoring failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function fetchCandidates(Incident $incident)
    {
        return Incident::whereIn('classification', ['Incident', 'Issue'])
            ->where('id', '!=', $incident->id)
            ->where('incident_date', '>=', now()->subMonths(self::LOOKBACK_MONTHS))
            ->with(['actionImprovements' => fn ($q) => $q->select(['id', 'incident_id', 'title', 'status', 'due_date']), 'labels:id,name'])
            ->select(Incident::SIMILARITY_COLUMNS)
            ->limit(self::CANDIDATE_LIMIT)
            ->get();
    }

    private function fetchAiCandidates(Incident $incident): array
    {
        $searchQuery = collect([
            $incident->title,
            $incident->summary,
            $incident->root_cause,
        ])->filter()->implode(' ');

        if (filled($searchQuery)) {
            $ragResults = $this->ragService->search($searchQuery, [
                'date_from' => now()->subMonths(self::LOOKBACK_MONTHS)->toDateString(),
            ], limit: self::AI_CANDIDATE_LIMIT);

            $candidateIds = $ragResults
                ->where('incident_id', '!=', $incident->id)
                ->pluck('incident_id')
                ->take(self::AI_CANDIDATE_LIMIT)
                ->toArray();

            if (! empty($candidateIds)) {
                return Incident::whereIn('id', $candidateIds)
                    ->with(['labels:id,name'])
                    ->select(Incident::SIMILARITY_COLUMNS)
                    ->get()
                    ->toArray();
            }
        }

        return Incident::whereIn('classification', ['Incident', 'Issue'])
            ->where('id', '!=', $incident->id)
            ->where('incident_date', '>=', now()->subMonths(self::LOOKBACK_MONTHS))
            ->with(['labels:id,name'])
            ->select(Incident::SIMILARITY_COLUMNS)
            ->latest('incident_date')
            ->limit(self::AI_CANDIDATE_LIMIT)
            ->get()
            ->toArray();
    }

    private function scoreCandidates(Incident $incident, $candidates, array $incidentLabelNames, array $ragScoreMap)
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

            $ragBonus = isset($ragScoreMap[$candidate->id])
                ? (int) round($ragScoreMap[$candidate->id] * 2)
                : 0;
            if ($ragBonus > 0) {
                $score += $ragBonus;
                $matchReasons[] = 'text_similarity';
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
                'summary' => $match->summary ? Str::limit($match->summary, 150) : '',
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
            $resolvedModel = config('ai.similarity_model')
                ?? \App\Models\AiSetting::get('default_model', config('ai.default_model'));

            $systemPrompt = <<<'PROMPT'
You are analyzing incident recurrence patterns. Given a new incident and similar past incidents with their remediation status, provide a concise analysis. Focus on:
1. Why this incident likely recurred (link to incomplete past actions)
2. What pattern connects these incidents
3. What specific overdue/pending actions contributed

Format your response using markdown. Use **bold** for incident numbers and action titles. Use bullet points for listing key findings. Keep it under 5 sentences. Be specific with incident numbers and action titles. Return a JSON object with a single "analysis" field containing your markdown-formatted explanation as a string.
PROMPT;

            $userMessage = "New incident: [{$incident->no}] {$incident->title}\n";
            $userMessage .= 'Summary: '.Str::limit($incident->summary ?? 'N/A', 300)."\n";
            $userMessage .= 'Root cause: '.Str::limit($incident->root_cause ?? 'N/A', 300)."\n";
            $userMessage .= 'Root cause categories: '.implode(', ', (array) ($incident->root_cause_category ?? []))."\n";
            $userMessage .= 'Business categories: '.implode(', ', (array) ($incident->business_category ?? []))."\n";
            $userMessage .= 'Responsible teams: '.implode(', ', (array) ($incident->responsible_team ?? []))."\n";
            $userMessage .= 'Severity: '.$incident->severity."\n\n";
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

    private function generateAiAnalysisForSimilar(Incident $incident, array $matchData): string
    {
        if (! $this->aiService->isAvailable()) {
            return $this->fallbackAnalysis($matchData);
        }

        try {
            $resolvedModel = config('ai.similarity_model')
                ?? \App\Models\AiSetting::get('default_model', config('ai.default_model'));

            $reasons = collect($matchData)->pluck('reason')->filter()->implode('; ');

            $systemPrompt = <<<'PROMPT'
You are analyzing incident recurrence detected via AI similarity matching. Given a new incident and AI-identified similar past incidents, provide a concise analysis. Focus on:
1. What connects these incidents based on the AI similarity reasons
2. Whether past remediation was incomplete (link to overdue/pending actions)
3. What should be done differently

Format your response using markdown. Use **bold** for incident numbers and key terms. Use bullet points for listing key findings. Keep it under 5 sentences. Be specific with incident numbers. Return a JSON object with a single "analysis" field containing your markdown-formatted explanation as a string.
PROMPT;

            $userMessage = "New incident: [{$incident->no}] {$incident->title}\n";
            $userMessage .= 'Summary: '.Str::limit($incident->summary ?? 'N/A', 300)."\n";
            $userMessage .= 'Root cause: '.Str::limit($incident->root_cause ?? 'N/A', 300)."\n";
            $userMessage .= "AI similarity reasons: {$reasons}\n\n";
            $userMessage .= "Similar past incidents:\n";

            foreach ($matchData as $i => $match) {
                $userMessage .= ($i + 1).". [{$match['no']}] {$match['summary']}\n";
                $userMessage .= "   Status: {$match['incident_status']}, Severity: {$match['severity']}, Date: {$match['incident_date']}\n";
                $userMessage .= '   Similarity: '.round(($match['similarity'] ?? 0) * 100)."%\n";
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

    private function storeResult(Incident $incident, array $data): array
    {
        $incident->recurrence_data = $data;
        $incident->saveQuietly();

        return $data;
    }
}
