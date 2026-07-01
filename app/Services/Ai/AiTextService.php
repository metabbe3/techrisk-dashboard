<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Services\Ai\Concerns\InteractsWithAiApi;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AiTextService
{
    use InteractsWithAiApi;

    public function __construct(
        private AiUsageLogger $usageLogger,
        private CircuitBreaker $circuitBreaker,
    ) {}

    public function suggestSkills(string $userMessage, ?string $model = null): array
    {
        $prompt = config('ai.prompts.agent_skill_suggest');

        if (! $prompt) {
            return ['success' => false, 'error' => 'Skill suggestion is not configured.'];
        }

        $resolvedModel = $model ?? AiSetting::get('default_model', config('ai.default_model'));

        $result = $this->callAiForJson(
            'agent_skill_suggest',
            $resolvedModel,
            $prompt['system'],
            $userMessage,
            ['skills' => []]
        );

        $skills = collect($result['skills'] ?? [])
            ->filter(fn ($s) => is_string($s) && strlen($s) <= 50)
            ->values()
            ->toArray();

        return [
            'success' => true,
            'skills' => $skills,
            'model' => $resolvedModel,
        ];
    }

    public function enhance(string $text, string $fieldType, ?string $model = null, ?string $additionalPrompt = null): AiTextResult
    {
        if (blank($text)) {
            return AiTextResult::failure('No text provided for enhancement.');
        }

        $maxLength = config('ai.max_input_length', 5000);
        if (strlen($text) > $maxLength) {
            return AiTextResult::failure("Text exceeds maximum length of {$maxLength} characters.");
        }

        $prompt = config("ai.prompts.{$fieldType}");
        if (! $prompt) {
            return AiTextResult::failure("Unknown field type: {$fieldType}.");
        }

        $resolvedModel = $model
            ?? AiSetting::get('default_model', config('ai.default_model'));

        if (! $this->circuitBreaker->isAvailable($resolvedModel)) {
            return AiTextResult::failure('AI service is temporarily unavailable. Please try again in a minute.', $resolvedModel);
        }

        $userId = auth()->id() ?? 'guest';
        $rateLimitKey = "ai-enhance:{$userId}";

        if (! RateLimiter::attempt($rateLimitKey, config('ai.rate_limit_per_minute', 10), fn () => true)) {
            return AiTextResult::failure('Rate limit exceeded. Please wait a moment before trying again.');
        }

        $inputLength = strlen($text);
        $startTime = microtime(true);
        $result = null;

        $isRefinement = filled($additionalPrompt);

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout($this->getTimeout())
                ->post($this->buildUrl(), $this->buildPayload(
                    $prompt['system'],
                    $text,
                    $resolvedModel,
                    $additionalPrompt,
                ));

            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];
            $promptTokens = $usage['prompt_tokens'] ?? null;
            $completionTokens = $usage['completion_tokens'] ?? null;
            $totalTokens = $usage['total_tokens'] ?? null;
            $apiRequestId = $responseData['id'] ?? null;

            if ($error = AiResponseHandler::checkErrors($response, $resolvedModel, $startTime)) {
                $result = $error;
            } else {
                $enhancedText = $this->parseResponseFromData($responseData);

                if (blank($enhancedText)) {
                    $result = AiTextResult::failure('AI returned an empty response.', $resolvedModel, $responseTimeMs);
                } else {
                    $cleaned = $this->cleanResponse($enhancedText);
                    $result = AiTextResult::success(
                        text: $cleaned,
                        model: $resolvedModel,
                        promptTokens: $promptTokens,
                        completionTokens: $completionTokens,
                        totalTokens: $totalTokens,
                        responseTimeMs: $responseTimeMs,
                        apiRequestId: $apiRequestId,
                    );
                }
            }
        } catch (ConnectionException $e) {
            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            $msg = $e->getMessage();
            Log::warning('AI service connection failed', ['error' => $msg]);

            $error = match (true) {
                str_contains($msg, 'timed out') => 'Request timed out. The AI service took too long to respond. Try again or switch to a faster model.',
                str_contains($msg, 'Could not resolve') || str_contains($msg, 'getaddrinfo') => 'Cannot reach AI service. DNS resolution failed — check network connectivity.',
                str_contains($msg, 'Connection refused') => 'AI service refused the connection. The service may be down.',
                default => 'Cannot connect to AI service. Please check your network and try again.',
            };

            $result = AiTextResult::failure($error, $resolvedModel, $responseTimeMs);
        } catch (\Throwable $e) {
            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            Log::warning('AI service unexpected error', ['error' => $e->getMessage()]);
            $result = AiTextResult::failure('An unexpected error occurred. Please try again.', $resolvedModel, $responseTimeMs);
        }

        $this->logUsage($fieldType, $resolvedModel, $result, $inputLength, $isRefinement);

        if ($result->success) {
            $this->circuitBreaker->recordSuccess($resolvedModel);
        } else {
            $this->circuitBreaker->recordFailure($resolvedModel);
        }

        return $result;
    }

    public function isAvailable(): bool
    {
        return filled($this->getBaseUrl()) && filled($this->getApiKey());
    }

    public function getAvailableModels(): array
    {
        return AiSetting::get('models', config('ai.models', []));
    }

    public function fetchModelsFromGateway(): array
    {
        $baseUrl = $this->getBaseUrl();
        $apiKey = $this->getApiKey();

        if (blank($baseUrl) || blank($apiKey)) {
            return [];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Accept' => 'application/json',
            ])
                ->timeout(10)
                ->get(rtrim($baseUrl, '/').'/models');
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch models from AI gateway', ['error' => $e->getMessage()]);

            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $data = $response->json('data', []);

        return collect($data)
            ->filter(fn ($m) => ($m['object'] ?? '') === 'model')
            ->pluck('id')
            ->filter(fn ($id) => ! str_contains($id, 'embedding'))
            ->mapWithKeys(fn ($id) => [$id => ucwords(str_replace(['-', '_'], ' ', $id))])
            ->toArray();
    }

    public function suggestLabels(array $incidentData, array $availableLabels, ?string $model = null): array
    {
        $resolvedModel = $model ?? AiSetting::get('default_model', config('ai.default_model'));
        $prompt = config('ai.prompts.label_suggest');

        if (! $prompt) {
            return ['matched' => [], 'suggested' => []];
        }

        $userMessage = "Incident data:\n";
        foreach ($incidentData as $key => $value) {
            if (filled($value)) {
                $userMessage .= "- {$key}: {$value}\n";
            }
        }
        $userMessage .= "\nAvailable labels: ".(empty($availableLabels) ? '(none — suggest relevant new labels)' : implode(', ', $availableLabels));

        $inputLength = strlen($userMessage);
        $startTime = microtime(true);
        $success = false;
        $promptTokens = null;
        $completionTokens = null;
        $totalTokens = null;
        $responseTimeMs = null;
        $apiRequestId = null;

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout($this->getTimeout())
                ->post($this->buildUrl(), [
                    'model' => $resolvedModel,
                    'messages' => [
                        ['role' => 'system', 'content' => $prompt['system']],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                    'max_tokens' => config('ai.max_tokens.label_suggest', 512),
                ]);

            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];
            $promptTokens = $usage['prompt_tokens'] ?? null;
            $completionTokens = $usage['completion_tokens'] ?? null;
            $totalTokens = $usage['total_tokens'] ?? null;
            $apiRequestId = $responseData['id'] ?? null;

            if ($response->failed()) {
                Log::warning('[Smart Labels] AI request failed', ['status' => $response->status()]);
                $this->logLabelUsage($resolvedModel, false, $inputLength, null, $promptTokens, $completionTokens, $totalTokens, $responseTimeMs, $apiRequestId, 'HTTP '.$response->status());

                return ['matched' => [], 'suggested' => []];
            }
        } catch (\Throwable $e) {
            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            Log::warning('AI label suggestion failed', ['error' => $e->getMessage()]);
            $this->logLabelUsage($resolvedModel, false, $inputLength, null, null, null, null, $responseTimeMs, null, $e->getMessage());

            return ['matched' => [], 'suggested' => []];
        }

        $content = $response->json('choices.0.message.content', '');

        if (empty($content)) {
            Log::warning('[Smart Labels] Empty content from AI', ['status' => $response->status()]);
            $this->logLabelUsage($resolvedModel, false, $inputLength, null, $promptTokens, $completionTokens, $totalTokens, $responseTimeMs, $apiRequestId, 'Empty response');

            return ['matched' => [], 'suggested' => []];
        }

        Log::info('[Smart Labels] AI response', ['content' => substr($content, 0, 1000)]);
        $result = $this->parseLabelSuggestions($content, $availableLabels);
        $this->logLabelUsage($resolvedModel, true, $inputLength, strlen($content), $promptTokens, $completionTokens, $totalTokens, $responseTimeMs, $apiRequestId);

        return $result;
    }

    private function logLabelUsage(?string $model, bool $success, int $inputLength, ?int $outputLength, ?int $promptTokens, ?int $completionTokens, ?int $totalTokens, ?float $responseTimeMs, ?string $apiRequestId, ?string $errorMessage = null): void
    {
        $this->usageLogger->log(
            fieldType: 'label_suggest',
            model: $model,
            success: $success,
            inputLength: $inputLength,
            outputLength: $outputLength,
            usage: array_filter(['prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens, 'total_tokens' => $totalTokens]),
            responseTimeMs: $responseTimeMs,
            apiRequestId: $apiRequestId,
            errorMessage: $errorMessage,
        );
    }

    private function parseLabelSuggestions(string $content, array $availableLabels): array
    {
        preg_match('/\{.*\}/s', $content, $matches);

        if (empty($matches)) {
            Log::warning('[Smart Labels] No JSON found in AI response', ['content' => substr($content, 0, 500)]);

            return ['matched' => [], 'suggested' => []];
        }

        $parsed = json_decode($matches[0], true);

        if (! is_array($parsed)) {
            Log::warning('[Smart Labels] JSON decode failed', ['raw' => $matches[0], 'error' => json_last_error_msg()]);

            return ['matched' => [], 'suggested' => []];
        }

        $availableLower = array_map('mb_strtolower', $availableLabels);

        $matched = collect($parsed['matched'] ?? [])
            ->filter(fn ($name) => is_string($name))
            ->filter(function ($name) use ($availableLower) {
                return in_array(mb_strtolower($name), $availableLower);
            })
            ->values()
            ->toArray();

        $suggested = collect($parsed['suggested'] ?? [])
            ->filter(fn ($name) => is_string($name) && strlen($name) <= 50)
            ->filter(function ($name) use ($availableLower) {
                return ! in_array(mb_strtolower($name), $availableLower);
            })
            ->unique()
            ->values()
            ->toArray();

        Log::info('[Smart Labels] Parsed', ['matched' => $matched, 'suggested' => $suggested, 'raw_matched' => $parsed['matched'] ?? [], 'raw_suggested' => $parsed['suggested'] ?? []]);

        return ['matched' => $matched, 'suggested' => $suggested];
    }

    public function analyzeRootCause(array $incidentData, ?string $model = null, array $availableLabels = []): array
    {
        $resolvedModel = $model ?? AiSetting::get('default_model', config('ai.default_model'));
        $prompt = config('ai.prompts.root_cause_analysis');

        if (! $prompt) {
            return ['summary' => '', 'root_cause' => '', 'remark' => '', 'categories' => [], 'contributing_factors' => [], 'recommendation' => '', 'labels_matched' => [], 'labels_suggested' => []];
        }

        $userMessage = "Analyze the following incident:\n\n";
        foreach ($incidentData as $key => $value) {
            if (filled($value)) {
                $userMessage .= "- {$key}: {$value}\n";
            }
        }

        if (! empty($availableLabels)) {
            $userMessage .= "\nAvailable labels: ".implode(', ', $availableLabels);
        } else {
            $userMessage .= "\nAvailable labels: (none — suggest relevant new labels)";
        }

        $defaultResult = ['summary' => '', 'root_cause' => '', 'remark' => '', 'categories' => [], 'contributing_factors' => [], 'recommendation' => '', 'labels_matched' => [], 'labels_suggested' => []];
        $result = $this->callAiForJson('root_cause_analysis', $resolvedModel, $prompt['system'], $userMessage, $defaultResult);

        $strings = collect(['summary', 'root_cause', 'remark', 'recommendation'])
            ->mapWithKeys(fn ($k) => [$k => is_string($result[$k] ?? null) ? $this->cleanResponse($result[$k]) : ''])
            ->toArray();

        $labelMapLower = collect($availableLabels)->mapWithKeys(fn ($l) => [mb_strtolower($l) => $l])->toArray();
        $matched = collect($result['labels_matched'] ?? [])
            ->filter(fn ($l) => is_string($l) && isset($labelMapLower[mb_strtolower($l)]))
            ->map(fn ($l) => $labelMapLower[mb_strtolower($l)])
            ->unique()
            ->values()
            ->toArray();

        $suggested = collect($result['labels_suggested'] ?? [])
            ->filter(fn ($l) => is_string($l) && mb_strlen($l) <= 50 && ! isset($labelMapLower[mb_strtolower($l)]))
            ->unique(fn ($l) => mb_strtolower($l))
            ->values()
            ->toArray();

        return [
            ...$strings,
            'categories' => collect($result['categories'] ?? [])->filter(fn ($c) => is_string($c))->values()->toArray(),
            'contributing_factors' => collect($result['contributing_factors'] ?? [])->filter(fn ($f) => is_string($f))->values()->toArray(),
            'labels_matched' => $matched,
            'labels_suggested' => $suggested,
        ];
    }

    public function detectSimilar(array $incidentData, array $recentIncidents): array
    {
        $resolvedModel = config('ai.similarity_model')
            ?? AiSetting::get('default_model', config('ai.default_model'));
        $prompt = config('ai.prompts.similar_incident');

        if (! $prompt) {
            return ['similar' => []];
        }

        $userMessage = "Current incident being reported:\n";
        foreach ($incidentData as $key => $value) {
            if (filled($value)) {
                if (is_array($value)) {
                    $userMessage .= '- '.$key.': '.implode(', ', $value)."\n";
                } else {
                    $userMessage .= "- {$key}: {$value}\n";
                }
            }
        }

        $candidates = array_slice($recentIncidents, 0, 15);

        $userMessage .= "\nCandidate incidents from the database:\n";
        foreach ($candidates as $i => $inc) {
            $userMessage .= ($i + 1).'. ['.($inc['no'] ?? '').'] '.($inc['title'] ?? 'Untitled')."\n";
            $userMessage .= '   Summary: '.Str::limit($inc['summary'] ?? 'N/A', 150)."\n";
            $userMessage .= '   Root Cause: '.Str::limit($inc['root_cause'] ?? 'Not available', 150)."\n";

            $categories = collect([
                ...(array) ($inc['root_cause_category'] ?? []),
                ...(array) ($inc['business_category'] ?? []),
                ...(array) ($inc['responsible_team'] ?? []),
            ])->unique()->implode(', ');
            $userMessage .= '   Categories: '.($categories ?: 'None')."\n";

            $labels = collect($inc['labels'] ?? [])->pluck('name')->implode(', ');
            $userMessage .= '   Labels: '.($labels ?: 'None')."\n";

            $userMessage .= '   Severity: '.($inc['severity'] ?? 'N/A');
            $userMessage .= ' | Type: '.($inc['incident_type'] ?? 'N/A');
            $userMessage .= ' | Date: '.($inc['incident_date'] ?? 'N/A')."\n";
        }

        $result = $this->callAiForJson('similar_incident', $resolvedModel, $prompt['system'], $userMessage, ['similar' => []]);

        $indexedIncidents = collect($recentIncidents)->keyBy('no');

        Log::info('[DetectSimilar] AI parsed result', [
            'raw_similar_count' => count($result['similar'] ?? []),
            'known_nos_sample' => $indexedIncidents->keys()->take(5)->toArray(),
            'ai_returned_nos' => collect($result['similar'] ?? [])->pluck('incident_no')->toArray(),
        ]);

        $similar = collect($result['similar'] ?? [])
            ->filter(fn ($item) => is_array($item) && isset($item['incident_no']))
            ->map(function ($item) use ($indexedIncidents) {
                $incident = $indexedIncidents->get($item['incident_no']);
                if (! $incident) {
                    Log::info('[DetectSimilar] Incident no mismatch', [
                        'ai_returned' => $item['incident_no'],
                    ]);

                    return null;
                }

                return [
                    'id' => $incident['id'],
                    'no' => $incident['no'],
                    'summary' => $incident['summary'] ? Str::limit($incident['summary'], 150) : '',
                    'severity' => $incident['severity'] ?? '',
                    'incident_date' => $incident['incident_date'] ?? '',
                    'incident_status' => $incident['incident_status'] ?? '',
                    'similarity' => min(max((float) ($item['similarity'] ?? 0), 0), 1),
                    'reason' => is_string($item['reason'] ?? null) ? $item['reason'] : '',
                ];
            })
            ->filter()
            ->sortByDesc('similarity')
            ->values()
            ->toArray();

        return ['similar' => $similar];
    }

    private function logFeatureUsage(string $fieldType, ?string $model, bool $success, int $inputLength, ?int $outputLength, array $usage, ?float $responseTimeMs, ?string $apiRequestId, ?string $errorMessage = null): void
    {
        $this->usageLogger->log(
            fieldType: $fieldType,
            model: $model,
            success: $success,
            inputLength: $inputLength,
            outputLength: $outputLength,
            usage: $usage,
            responseTimeMs: $responseTimeMs,
            apiRequestId: $apiRequestId,
            errorMessage: $errorMessage,
        );
    }

    public function generateWeeklySummary(array $weeklyData, array $summaryStats, ?string $model = null): array
    {
        $resolvedModel = $model ?? AiSetting::get('default_model', config('ai.default_model'));
        $prompt = config('ai.prompts.weekly_report_summary');

        if (! $prompt) {
            return ['summary' => '', 'key_highlights' => [], 'areas_of_concern' => [], 'root_cause_insights' => [], 'recommendation' => ''];
        }

        $allIncidents = collect();
        foreach ($weeklyData as $week) {
            $incidents = $week->incidents ?? collect();
            $allIncidents = $allIncidents->merge($incidents);
        }

        $userMessage = "Weekly incident report data for {$summaryStats['year']}:\n\n";
        $userMessage .= "Summary: Total Open: {$summaryStats['totalOpen']}, Total Closed: {$summaryStats['totalClosed']}, Grand Total: {$summaryStats['grandTotal']}\n";

        // Severity breakdown
        $severityCounts = $allIncidents->groupBy('severity')->map->count()->sortDesc();
        $userMessage .= 'Severity breakdown: '.$severityCounts->map(fn ($c, $s) => "{$s}: {$c}")->implode(', ')."\n";

        // Incident type breakdown
        $typeCounts = $allIncidents->groupBy('incident_type')->map->count()->sortDesc();
        $userMessage .= 'Incident types: '.$typeCounts->map(fn ($c, $t) => "{$t}: {$c}")->implode(', ')."\n";

        // Root cause categories
        $rootCauseCats = $allIncidents->pluck('root_cause_category')->filter()->flatMap(fn ($v) => (array) $v)->countBy()->sortDesc();
        if ($rootCauseCats->isNotEmpty()) {
            $userMessage .= 'Root cause categories: '.$rootCauseCats->map(fn ($c, $cat) => "{$cat}: {$c}")->implode(', ')."\n";
        }

        // Business categories
        $bizCats = $allIncidents->pluck('business_category')->filter()->flatMap(fn ($v) => (array) $v)->countBy()->sortDesc();
        if ($bizCats->isNotEmpty()) {
            $userMessage .= 'Business categories: '.$bizCats->map(fn ($c, $cat) => "{$cat}: {$c}")->implode(', ')."\n";
        }

        // Responsible teams
        $teams = $allIncidents->pluck('responsible_team')->filter()->flatMap(fn ($v) => (array) $v)->countBy()->sortDesc();
        if ($teams->isNotEmpty()) {
            $userMessage .= 'Responsible teams: '.$teams->map(fn ($c, $t) => "{$t}: {$c}")->implode(', ')."\n";
        }

        // Top PICs
        $pics = $allIncidents->filter(fn ($i) => $i->relationLoaded('pic') && $i->pic)->groupBy(fn ($i) => $i->pic->name)->map->count()->sortDesc()->take(10);
        if ($pics->isNotEmpty()) {
            $userMessage .= 'Top PICs: '.$pics->map(fn ($c, $n) => "{$n}: {$c}")->implode(', ')."\n";
        }

        // Financial impact
        $totalPotentialLoss = $allIncidents->sum('potential_fund_loss');
        $totalFundLoss = $allIncidents->sum('fund_loss');
        $totalRecovered = $allIncidents->sum('recovered_fund');
        $userMessage .= "Financial: Potential Loss Rp{$totalPotentialLoss}, Actual Loss Rp{$totalFundLoss}, Recovered Rp{$totalRecovered}\n";

        // Labels
        $labelNames = $allIncidents->filter(fn ($i) => $i->relationLoaded('labels'))->flatMap(fn ($i) => $i->labels->pluck('name'))->countBy()->sortDesc()->take(15);
        if ($labelNames->isNotEmpty()) {
            $userMessage .= 'Top labels: '.$labelNames->map(fn ($c, $l) => "{$l}: {$c}")->implode(', ')."\n";
        }

        // Missing root causes
        $missingRootCause = $allIncidents->filter(fn ($i) => empty($i->root_cause))->count();
        if ($missingRootCause > 0) {
            $userMessage .= "Incidents without root cause: {$missingRootCause}\n";
        }

        $userMessage .= "\nWeekly breakdown with incident details:\n";
        foreach ($weeklyData as $week) {
            $incidents = $week->incidents ?? collect();
            $userMessage .= "\n{$week->week} ({$week->date_range}): Open {$week->incident_open}, Closed {$week->incident_closed}, Total {$week->total}\n";

            $wSev = $incidents->groupBy('severity')->map->count()->sortDesc();
            if ($wSev->isNotEmpty()) {
                $userMessage .= '  Severities: '.$wSev->map(fn ($c, $s) => "{$s}={$c}")->implode(', ')."\n";
            }

            $wRootCause = $incidents->pluck('root_cause_category')->filter()->flatMap(fn ($v) => (array) $v)->countBy()->sortDesc();
            if ($wRootCause->isNotEmpty()) {
                $userMessage .= '  Root causes: '.$wRootCause->map(fn ($c, $cat) => "{$cat}={$c}")->implode(', ')."\n";
            }

            $wFundLoss = $incidents->sum('fund_loss');
            if ($wFundLoss > 0) {
                $userMessage .= "  Fund loss: Rp{$wFundLoss}\n";
            }

            $topIncidents = $incidents->sortByDesc(fn ($i) => $this->severityWeight($i->severity?->value ?? ''))->take(5);
            foreach ($topIncidents as $inc) {
                $title = $inc->title ?? 'Untitled';
                $sev = $inc->severity?->value ?? '?';
                $status = $inc->incident_status?->value ?? '?';
                $rootCausePreview = $inc->root_cause ? ' | RC: '.\Illuminate\Support\Str::limit($inc->root_cause, 80) : '';
                $userMessage .= "  - [{$sev}] {$inc->no}: {$title} ({$status}){$rootCausePreview}\n";
            }
        }

        $defaultResult = ['summary' => '', 'key_highlights' => [], 'areas_of_concern' => [], 'root_cause_insights' => [], 'recommendation' => ''];

        $result = $this->callAiForJson('weekly_report_summary', $resolvedModel, $prompt['system'], $userMessage, $defaultResult);

        return [
            'summary' => is_string($result['summary'] ?? null) ? $this->cleanResponse($result['summary']) : '',
            'key_highlights' => collect($result['key_highlights'] ?? [])->filter(fn ($h) => is_string($h))->values()->toArray(),
            'areas_of_concern' => collect($result['areas_of_concern'] ?? [])->filter(fn ($c) => is_string($c))->values()->toArray(),
            'root_cause_insights' => collect($result['root_cause_insights'] ?? [])->filter(fn ($i) => is_string($i))->values()->toArray(),
            'recommendation' => is_string($result['recommendation'] ?? null) ? $this->cleanResponse($result['recommendation']) : '',
        ];
    }

    private function severityWeight(string $severity): int
    {
        return match ($severity) {
            'P1' => 10,
            'P2' => 8,
            'P3' => 6,
            'P4' => 4,
            'X1' => 9,
            'X2' => 7,
            'X3' => 5,
            'X4' => 3,
            'G' => 2,
            default => 1,
        };
    }

    public function analyzeTrends(array $monthlyData, array $topLabels, array $topPics, array $stats, ?string $model = null): array
    {
        $resolvedModel = $model ?? AiSetting::get('default_model', config('ai.default_model'));
        $prompt = config('ai.prompts.trend_analysis');

        if (! $prompt) {
            return ['trends' => [], 'recurring_issues' => [], 'anomalies' => [], 'recommendations' => []];
        }

        $userMessage = "Incident trend data:\n\n";
        $userMessage .= "Stats: Total Incidents: {$stats['total']}, Avg MTTR: {$stats['avg_mttr']} mins, Avg MTBF: {$stats['avg_mtbf']} days";
        if (! empty($stats['fund_loss'])) {
            $userMessage .= ", Fund Loss: {$stats['fund_loss']}";
        }
        $userMessage .= "\n\nMonthly incident counts:\n";
        foreach ($monthlyData as $month => $count) {
            $userMessage .= "- {$month}: {$count}\n";
        }
        $userMessage .= "\nTop incident labels:\n";
        foreach ($topLabels as $label => $count) {
            $userMessage .= "- {$label}: {$count} incidents\n";
        }
        $userMessage .= "\nTop PICs (Person in Charge):\n";
        foreach ($topPics as $pic => $count) {
            $userMessage .= "- {$pic}: {$count} incidents\n";
        }

        $defaultResult = ['trends' => [], 'recurring_issues' => [], 'anomalies' => [], 'recommendations' => []];

        $result = $this->callAiForJson('trend_analysis', $resolvedModel, $prompt['system'], $userMessage, $defaultResult);

        return [
            'trends' => collect($result['trends'] ?? [])->filter(fn ($t) => is_string($t))->values()->toArray(),
            'recurring_issues' => collect($result['recurring_issues'] ?? [])->filter(fn ($i) => is_string($i))->values()->toArray(),
            'anomalies' => collect($result['anomalies'] ?? [])->filter(fn ($a) => is_string($a))->values()->toArray(),
            'recommendations' => collect($result['recommendations'] ?? [])->filter(fn ($r) => is_string($r))->values()->toArray(),
        ];
    }

    public function parseNaturalLanguageQuery(string $query, ?string $model = null): array
    {
        $resolvedModel = $model ?? AiSetting::get('default_model', config('ai.default_model'));
        $prompt = config('ai.prompts.nl_search');

        if (! $prompt) {
            return ['filters' => [], 'explanation' => 'AI search not configured.'];
        }

        $systemPrompt = $prompt['system'];

        $labels = \App\Models\Label::orderBy('name')->pluck('name')->implode(', ') ?: '(none)';
        $bizCats = implode(', ', array_keys(\App\Models\Category::options(\App\Models\Category::TYPE_BUSINESS_CATEGORY))) ?: '(none)';
        $rcCats = implode(', ', array_keys(\App\Models\Category::options(\App\Models\Category::TYPE_ROOT_CAUSE_CATEGORY))) ?: '(none)';
        $teams = implode(', ', array_keys(\App\Models\Category::options(\App\Models\Category::TYPE_RESPONSIBLE_TEAM))) ?: '(none)';
        $pics = \App\Models\User::orderBy('name')->pluck('name')->implode(', ') ?: '(none)';

        $dynamicData = "\n\nCurrent date: ".now()->toDateString()."\n"
            ."Available labels: {$labels}\n"
            ."Available business categories: {$bizCats}\n"
            ."Available root cause categories: {$rcCats}\n"
            ."Available responsible teams: {$teams}\n"
            ."Available PICs (Person in Charge): {$pics}\n"
            .'Always use exact names from these lists.';

        $systemPrompt = str_replace('DYNAMIC_DATA_PLACEHOLDER', $dynamicData, $systemPrompt);

        $defaultResult = ['filters' => [], 'explanation' => ''];

        $result = $this->callAiForJson('nl_search', $resolvedModel, $systemPrompt, "Search query: \"{$query}\"", $defaultResult);

        return [
            'filters' => is_array($result['filters'] ?? null) ? $result['filters'] : [],
            'explanation' => is_string($result['explanation'] ?? null) ? $result['explanation'] : '',
        ];
    }

    public function summarizeDocument(string $content, string $originalFilename, ?string $model = null): AiTextResult
    {
        if (blank($content)) {
            return AiTextResult::failure('No document content available for summarization.');
        }

        $prompt = config('ai.document_summarization');
        if (! $prompt) {
            return AiTextResult::failure('Document summarization prompt not configured.');
        }

        $resolvedModel = $model ?? AiSetting::get('default_model', config('ai.default_model'));

        $userId = auth()->id() ?? 'guest';
        if (! RateLimiter::attempt("ai-summarize:{$userId}", config('ai.rate_limit_per_minute', 10), fn () => true)) {
            return AiTextResult::failure('Rate limit exceeded. Please wait a moment.');
        }

        $truncated = Str::limit($content, 30000);
        $userMessage = str_replace('{content}', $truncated, $prompt['user']);

        $inputLength = strlen($truncated);
        $startTime = microtime(true);
        $result = null;

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(120)
                ->post($this->buildUrl(), [
                    'model' => $resolvedModel,
                    'messages' => [
                        ['role' => 'system', 'content' => $prompt['system']],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                    'max_tokens' => config('ai.max_tokens.document_summary', 8000),
                ]);

            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];

            if ($response->failed()) {
                Log::warning('AI document summarization failed', ['status' => $response->status(), 'filename' => $originalFilename]);
                $result = AiTextResult::failure('AI service error. Please try again.', $resolvedModel, $responseTimeMs);
            } else {
                $text = $this->parseResponseFromData($responseData) ?? '';
                if (blank($text)) {
                    $result = AiTextResult::failure('AI returned empty response.', $resolvedModel, $responseTimeMs);
                } else {
                    $result = AiTextResult::success($this->cleanResponse($text), $resolvedModel, $responseTimeMs);
                }
            }

            $this->logUsage('document_summary', $resolvedModel, $result, $inputLength);

            return $result;
        } catch (ConnectionException $e) {
            Log::error('AI connection timeout during document summarization', ['filename' => $originalFilename]);

            return AiTextResult::failure('Connection timed out. The document may be too large.', $resolvedModel ?? null, 0);
        } catch (\Exception $e) {
            Log::error('Document summarization exception', ['error' => $e->getMessage()]);

            return AiTextResult::failure('An unexpected error occurred while summarizing the document.', $resolvedModel ?? null, 0);
        }
    }

    public function callAiForJson(string $fieldType, string $model, string $systemPrompt, string $userMessage, array $defaultResult, ?int $maxTokens = null): array
    {
        $inputLength = strlen($userMessage);
        $startTime = microtime(true);
        $resolvedMaxTokens = $maxTokens ?? $this->getMaxTokensForType($fieldType);

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout($this->getTimeout())
                ->post($this->buildUrl(), [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                    'max_tokens' => $resolvedMaxTokens,
                    'temperature' => config('ai.temperatures.json_extraction', 0.1),
                ]);

            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];

            if ($response->failed()) {
                Log::warning("[{$fieldType}] AI request failed", ['status' => $response->status()]);
                $this->logFeatureUsage($fieldType, $model, false, $inputLength, null, $usage, $responseTimeMs, $responseData['id'] ?? null, 'HTTP '.$response->status());

                return $defaultResult;
            }
        } catch (\Throwable $e) {
            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            Log::warning("[{$fieldType}] AI request failed", ['error' => $e->getMessage()]);
            $this->logFeatureUsage($fieldType, $model, false, $inputLength, null, [], $responseTimeMs, null, $e->getMessage());

            return $defaultResult;
        }

        $content = $response->json('choices.0.message.content', '');

        if (empty($content)) {
            Log::warning("[{$fieldType}] Empty AI response");
            $this->logFeatureUsage($fieldType, $model, false, $inputLength, null, $usage ?? [], $responseTimeMs, $responseData['id'] ?? null, 'Empty response');

            return $defaultResult;
        }

        Log::info("[{$fieldType}] AI response", ['content' => substr($content, 0, 1000)]);
        $parsed = $this->extractJson($content);
        $this->logFeatureUsage($fieldType, $model, true, $inputLength, strlen($content), $usage, $responseTimeMs, $responseData['id'] ?? null);

        return is_array($parsed) ? array_merge($defaultResult, $parsed) : $defaultResult;
    }

    private function extractJson(string $content): ?array
    {
        preg_match('/\{.*\}/s', $content, $matches);
        if (empty($matches)) {
            return null;
        }
        $parsed = json_decode($matches[0], true);

        return is_array($parsed) ? $parsed : null;
    }

    private function getMaxTokensForType(string $fieldType): int
    {
        $mapping = [
            'label_suggest' => config('ai.max_tokens.label_suggest', 512),
            'agent_skill_suggest' => config('ai.max_tokens.label_suggest', 512),
            'nl_search' => config('ai.max_tokens.nl_search', 2048),
            'trend_analysis' => config('ai.max_tokens.trend_analysis', 2048),
            'weekly_report_summary' => config('ai.max_tokens.weekly_summary', 4096),
            'root_cause_analysis' => config('ai.max_tokens.root_cause_analysis', 8192),
            'similar_incident' => config('ai.max_tokens.similarity', 2048),
        ];

        return $mapping[$fieldType] ?? config('ai.max_tokens.json_default', 4096);
    }

    protected function buildPayload(string $systemPrompt, string $userText, string $model, ?string $additionalPrompt = null): array
    {
        $userMessage = filled($additionalPrompt)
            ? "Please improve the following text with this additional instruction: \"{$additionalPrompt}\"\n\n{$userText}"
            : "Please improve the following text:\n\n{$userText}";

        return [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'max_tokens' => config('ai.max_tokens.text_enhancement', 1000),
            'temperature' => config('ai.temperatures.text_enhancement', 0.3),
        ];
    }

    protected function parseResponseFromData(array $data): ?string
    {
        return $data['choices'][0]['message']['content']
            ?? $data['output']
            ?? $data['text']
            ?? $data['content']
            ?? null;
    }

    protected function cleanResponse(string $text): string
    {
        $text = preg_replace('/^[-*_]{3,}\s*$/m', '', $text);
        $text = preg_replace('/^[•]\s+/m', '- ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    private function logUsage(string $fieldType, ?string $model, AiTextResult $result, int $inputLength, bool $isRefinement = false): void
    {
        $this->usageLogger->logFromResult(
            fieldType: $fieldType,
            model: $model,
            result: $result,
            inputLength: $inputLength,
            metadata: $isRefinement ? ['type' => 'refinement'] : null,
        );
    }
}
