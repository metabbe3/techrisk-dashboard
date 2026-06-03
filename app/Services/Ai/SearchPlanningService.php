<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SearchPlanningService
{
    public function __construct(
        private AiUsageLogger $usageLogger,
    ) {}

    /**
     * Analyze the user's message and incident context, then produce a search plan
     * with 1-3 targeted queries. Returns an empty plan if planning fails.
     */
    public function planSearches(string $userMessage, array $incidentContext = [], array $history = []): SearchPlan
    {
        if (! config('ai.search.planning_enabled', true)) {
            return new SearchPlan([], 'Planning disabled');
        }

        $model = config('ai.search.planning_model', 'FAST-MODEL');
        $baseUrl = rtrim(AiSetting::get('base_url', config('ai.base_url', '')), '/');
        $apiKey = AiSetting::get('api_key', config('ai.api_key', ''));
        $timeout = (int) config('ai.search.planning_timeout', 10);
        $maxTokens = (int) config('ai.search.planning_max_tokens', 512);

        if (! $apiKey || ! $baseUrl) {
            return new SearchPlan([], 'Search planning not configured');
        }

        // Resolve FAST-MODEL alias
        if ($model === 'FAST-MODEL') {
            $model = AiSetting::get('fast_model', config('ai.fast_model', $model));
        }

        $prompt = $this->buildPlanningPrompt($userMessage, $incidentContext, $history);
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ])
                ->timeout($timeout)
                ->post("{$baseUrl}/chat/completions", [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $this->buildSystemPrompt()],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => $maxTokens,
                    'temperature' => 0.3,
                ]);

            $responseTimeMs = (microtime(true) - $startTime) * 1000;

            if (! $response->successful()) {
                Log::warning('Search planning API error', ['status' => $response->status()]);

                return new SearchPlan([], 'Planning API error');
            }

            $content = $response->json('choices.0.message.content') ?? '';
            $usage = $response->json('usage') ?? [];

            $this->usageLogger->log(
                fieldType: 'search_planning',
                model: $model,
                success: true,
                inputLength: strlen($prompt),
                outputLength: strlen($content),
                usage: array_filter([
                    'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                    'completion_tokens' => $usage['completion_tokens'] ?? null,
                    'total_tokens' => $usage['total_tokens'] ?? null,
                ]),
                responseTimeMs: $responseTimeMs,
                metadata: ['message_length' => strlen($userMessage)],
            );

            return $this->parsePlanResponse($content);
        } catch (\Throwable $e) {
            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            Log::warning('Search planning failed', ['error' => $e->getMessage()]);

            $this->usageLogger->log(
                fieldType: 'search_planning',
                model: $model,
                success: false,
                responseTimeMs: $responseTimeMs,
                errorMessage: $e->getMessage(),
            );

            return new SearchPlan([], 'Planning failed: '.$e->getMessage());
        }
    }

    private function buildSystemPrompt(): string
    {
        return "You are a search planning assistant for a technical risk management team.\n"
            ."Your job is to analyze the user's question and available incident context, "
            ."then decide what web searches would provide the most useful supplementary information.\n\n"
            ."RULES:\n"
            ."- Return 1-3 search queries in JSON format.\n"
            ."- Each query must cover a DIFFERENT angle (e.g., root cause, industry benchmarks, remediation).\n"
            ."- NEVER include incident IDs, financial amounts, person names, internal codes, or IP addresses in search queries.\n"
            ."- Use only generic technical terms and industry-standard keywords.\n"
            ."- For each query, specify:\n"
            ."  - query: the search string\n"
            ."  - purpose: what angle this covers (1 short sentence)\n"
            ."  - depth: 'brief' for quick facts or 'thorough' for deep analysis\n\n"
            ."RESPONSE FORMAT (JSON only, no markdown):\n"
            ."{\n"
            ."  \"rationale\": \"Why these searches were chosen\",\n"
            ."  \"queries\": [\n"
            ."    {\"query\": \"...\", \"purpose\": \"...\", \"depth\": \"brief\"},\n"
            ."    {\"query\": \"...\", \"purpose\": \"...\", \"depth\": \"thorough\"}\n"
            ."  ]\n"
            ."}\n";
    }

    private function buildPlanningPrompt(string $userMessage, array $incidentContext, array $history): string
    {
        $prompt = "USER QUESTION:\n{$userMessage}\n\n";

        if (! empty($incidentContext)) {
            $prompt .= "AVAILABLE INCIDENT CONTEXT (sanitized, no confidential data):\n";
            foreach ($incidentContext as $i => $ctx) {
                $parts = [];
                if (! empty($ctx['root_cause_categories'])) {
                    $parts[] = 'Root cause categories: '.implode(', ', $ctx['root_cause_categories']);
                }
                if (! empty($ctx['safe_title_words'])) {
                    $parts[] = 'Key terms: '.implode(' ', $ctx['safe_title_words']);
                }
                if (! empty($ctx['labels'])) {
                    $parts[] = 'Labels: '.implode(', ', $ctx['labels']);
                }
                if (! empty($ctx['technical_keywords'])) {
                    $parts[] = 'Technologies: '.implode(', ', $ctx['technical_keywords']);
                }
                if (! empty($ctx['business_category'])) {
                    $bizCat = is_array($ctx['business_category']) ? implode(', ', $ctx['business_category']) : $ctx['business_category'];
                    $parts[] = "Business category: {$bizCat}";
                }
                if (! empty($ctx['responsible_team'])) {
                    $teams = is_array($ctx['responsible_team']) ? implode(', ', $ctx['responsible_team']) : $ctx['responsible_team'];
                    $parts[] = "Team: {$teams}";
                }
                if (! empty($parts)) {
                    $prompt .= ($i + 1).'. '.implode('; ', $parts)."\n";
                }
            }
            $prompt .= "\n";
        }

        if (! empty($history)) {
            $recentTopics = collect($history)
                ->take(4)
                ->map(fn ($m) => mb_substr($m['content'] ?? '', 0, 100))
                ->implode(' | ');
            $prompt .= "RECENT CONVERSATION TOPICS: {$recentTopics}\n\n";
        }

        $prompt .= "Plan the most useful web searches to supplement the incident data. "
            ."Focus on external references, industry benchmarks, CVEs, vendor advisories, or best practices.";

        return $prompt;
    }

    private function parsePlanResponse(string $content): SearchPlan
    {
        // Strip markdown code fences if present
        $json = $content;
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $match)) {
            $json = $match[1];
        }

        $data = json_decode($json, true);
        if (! is_array($data) || empty($data['queries'])) {
            Log::warning('Search planning returned unparseable response', ['content' => $content]);

            return new SearchPlan([], 'Failed to parse plan');
        }

        $queries = [];
        $maxQueries = (int) config('ai.search.max_parallel_queries', 3);

        foreach (array_slice($data['queries'], 0, $maxQueries) as $q) {
            $query = trim($q['query'] ?? '');
            if (empty($query)) {
                continue;
            }
            $queries[] = new SearchPlanQuery(
                query: $query,
                purpose: $q['purpose'] ?? '',
                desiredDepth: ($q['depth'] ?? 'brief') === 'thorough' ? 'thorough' : 'brief',
            );
        }

        return new SearchPlan(
            queries: $queries,
            rationale: $data['rationale'] ?? '',
        );
    }
}
