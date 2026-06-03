<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebSearchService
{
    public function __construct(
        private AiUsageLogger $usageLogger,
    ) {}

    /**
     * Patterns that identify confidential/internal data to strip from search queries.
     * These must never be sent to external search APIs.
     */
    private const CONFIDENTIAL_PATTERNS = [
        // Incident IDs: 20260501_IN_0001, 20260501_IS_0002
        Incident::ID_PATTERN,
        // Database IDs referenced as id:123
        '/\bid:\d+\b/i',
        // Indonesian Rupiah amounts: Rp 50.000.000, Rp50jt, 50.000.000
        '/\bRp\.?\s*[\d.,]+[jtJTMkbB]?/i',
        // Large numbers with Indonesian formatting (dots as thousands): 50.000.000
        '/\b\d{1,3}(?:\.\d{3}){2,}\b/',
        // IP addresses
        '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/',
        // Email addresses
        '/\b[\w.+-]+@[\w.-]+\.\w{2,}\b/',
        // URLs with internal hostnames
        '/\bhttps?:\/\/(?:localhost|127\.|10\.|172\.(?:1[6-9]|2\d|3[01])\.|192\.168\.)/i',
        // Server/hostnames with internal patterns: server-prod-01, db-master.internal
        '/\b[\w-]+\.(internal|local|corp|intranet|private)\b/i',
        // Kafka topics, queue names with internal patterns
        '/\b[\w.-]+\.(topic|queue|exchange)\.?(internal|corp|prod)?\b/i',
    ];

    public function search(string $query, array $incidentContext = [], ?int $userId = null): array
    {
        $provider = config('ai.search.provider', 'gateway');

        // Step 1: Sanitize the query — strip confidential data
        $sanitized = $this->sanitizeQuery($query);

        if (empty(trim($sanitized))) {
            Log::warning('Web search skipped: query fully sanitized to empty string', ['original' => $query]);

            return ['results' => [], 'context' => '', 'error' => 'Query too specific, no safe search terms'];
        }

        return match ($provider) {
            'gemini' => $this->searchViaGemini($sanitized, $incidentContext, $userId),
            'gateway' => $this->searchViaGateway($sanitized, $incidentContext, $userId),
            default => ['results' => [], 'context' => '', 'error' => 'Unknown search provider'],
        };
    }

    /**
     * Run multiple search queries in parallel and merge results.
     * Each query is sanitized individually; empty sanitized queries are dropped.
     */
    public function searchMulti(array $queries, array $incidentContext = [], ?int $userId = null, int $maxQueries = 3): array
    {
        $maxQueries = $maxQueries ?: (int) config('ai.search.max_parallel_queries', 3);
        $queries = array_slice(array_values($queries), 0, $maxQueries);

        // Sanitize each query and filter out empties
        $sanitized = [];
        foreach ($queries as $query) {
            $clean = $this->sanitizeQuery($query);
            if (! empty(trim($clean))) {
                $sanitized[] = ['original' => $query, 'clean' => $clean];
            }
        }

        if (empty($sanitized)) {
            return ['results' => [], 'context' => '', 'error' => 'All queries sanitized to empty'];
        }

        // Single query — just delegate to search()
        if (count($sanitized) === 1) {
            return $this->search($sanitized[0]['original'], $incidentContext, $userId);
        }

        $provider = config('ai.search.provider', 'gateway');
        $timeout = (int) config('ai.search.parallel_timeout', 20);

        try {
            $startTime = microtime(true);

            // Run all queries in parallel
            $responses = Http::pool(function ($pool) use ($sanitized, $provider, $incidentContext, $timeout) {
                foreach ($sanitized as $i => $q) {
                    $method = $provider === 'gemini' ? 'buildGeminiPoolRequest' : 'buildGatewayPoolRequest';
                    $this->{$method}($pool, $i, $q['clean'], $incidentContext, $timeout);
                }
            });

            $responseTimeMs = (microtime(true) - $startTime) * 1000;

            // Parse each response
            $allResults = [];
            foreach ($sanitized as $i => $q) {
                $response = $responses[$i] ?? null;
                if (! $response || ! $response->successful()) {
                    Log::warning("Parallel search query {$i} failed", ['query' => $q['clean']]);
                    continue;
                }

                $parsed = $provider === 'gemini'
                    ? $this->parseGeminiResponse($response->json())
                    : $this->parseGatewayResponse($response->json(), $q['clean']);

                $parsed['_label'] = $q['clean'];
                $allResults[] = $parsed;
            }

            if (empty($allResults)) {
                return ['results' => [], 'context' => '', 'error' => 'All parallel queries failed'];
            }

            // Log aggregate usage
            $this->usageLogger->log(
                fieldType: 'web_search_multi',
                model: config('ai.search.gemini_model', 'gemini-2.5-flash'),
                success: true,
                inputLength: array_sum(array_map(fn ($q) => strlen($q['clean']), $sanitized)),
                responseTimeMs: $responseTimeMs,
                metadata: ['query_count' => count($sanitized), 'provider' => $provider],
                userId: $userId,
            );

            return $this->mergeMultiResults($allResults);
        } catch (\Throwable $e) {
            Log::warning('Parallel web search failed', ['error' => $e->getMessage()]);

            return ['results' => [], 'context' => '', 'error' => $e->getMessage()];
        }
    }

    private function buildGatewayPoolRequest($pool, int $index, string $query, array $incidentContext, int $timeout): void
    {
        $apiKey = config('ai.search.gemini_api_key')
            ?: AiSetting::get('api_key', config('ai.api_key', ''));
        $model = config('ai.search.gemini_model', 'gemini-2.5-flash');
        $baseUrl = rtrim(config('ai.search.gemini_base_url') ?? '', '/')
            ?: rtrim(AiSetting::get('base_url', config('ai.base_url', '')), '/');

        $pool->as($index)->withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
        ])->timeout($timeout)->post("{$baseUrl}/chat/completions", [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $this->buildGatewaySearchPrompt($incidentContext)],
                ['role' => 'user', 'content' => "Search the web for: {$query}"],
            ],
            'max_tokens' => 2000,
        ]);
    }

    private function buildGeminiPoolRequest($pool, int $index, string $query, array $incidentContext, int $timeout): void
    {
        $apiKey = config('ai.search.gemini_api_key');
        $model = config('ai.search.gemini_model', 'gemini-2.5-flash');
        $baseUrl = rtrim(config('ai.search.gemini_base_url') ?? '', '/');

        $pool->as($index)->withHeaders([
            'Content-Type' => 'application/json',
        ])->timeout($timeout)->post("{$baseUrl}/models/{$model}:generateContent?key={$apiKey}", [
            'contents' => [
                ['parts' => [['text' => $this->buildGeminiSearchPrompt($query)]]],
            ],
            'tools' => [['googleSearch' => (object) []]],
        ]);
    }

    /**
     * Merge results from multiple parallel queries.
     * Deduplicates sources by URL, combines context under sub-headings.
     */
    private function mergeMultiResults(array $allResults): array
    {
        $maxResults = (int) config('ai.search.max_results', 8);
        $seenUrls = [];
        $mergedSources = [];
        $contextParts = [];
        $summaries = [];

        foreach ($allResults as $result) {
            $label = $result['_label'] ?? 'Unknown query';
            unset($result['_label']);

            // Collect context under sub-headings
            if (! empty($result['context'])) {
                $contextParts[] = "### Query: \"{$label}\"\n{$result['context']}";
            }
            if (! empty($result['summary'])) {
                $summaries[] = $result['summary'];
            }

            // Deduplicate sources by URL
            foreach ($result['results'] ?? [] as $source) {
                $url = $source['url'] ?? '';
                if ($url && ! isset($seenUrls[$url])) {
                    $seenUrls[$url] = true;
                    $mergedSources[] = $source;
                }
            }
        }

        $mergedSources = array_slice($mergedSources, 0, $maxResults);

        // Build combined context with sub-headings
        $context = implode("\n\n---\n\n", $contextParts);

        // Add unified sources list
        if (! empty($mergedSources)) {
            $context .= "\n\n### All Sources\n";
            foreach ($mergedSources as $i => $source) {
                $context .= ($i + 1).". [{$source['title']}]({$source['url']})\n";
            }
        }

        // Cap context to avoid overwhelming the answering LLM
        $maxChars = (int) config('ai.search.max_context_chars', 4000);
        if (strlen($context) > $maxChars) {
            $context = mb_substr($context, 0, $maxChars)."\n\n[Results truncated to fit context window]";
        }

        return [
            'results' => $mergedSources,
            'context' => $context,
            'summary' => implode("\n\n", $summaries),
            'error' => '',
        ];
    }

    /**
     * Filter search results by relevance to the original query and incident context.
     * Lightweight TF-based scoring — no extra LLM call needed.
     */
    public function filterRelevantResults(array $results, string $query, array $incidentContext = []): array
    {
        if (empty($results)) {
            return $results;
        }

        $threshold = (float) config('ai.search.relevance_threshold', 0.2);

        // Extract key terms from query
        $queryTerms = $this->extractKeyTerms($query);

        // Extract key terms from incident context
        $contextTerms = [];
        foreach ($incidentContext as $ctx) {
            if (! empty($ctx['root_cause_categories'])) {
                $contextTerms = array_merge($contextTerms, $ctx['root_cause_categories']);
            }
            if (! empty($ctx['safe_title_words'])) {
                $contextTerms = array_merge($contextTerms, $ctx['safe_title_words']);
            }
            if (! empty($ctx['labels'])) {
                $contextTerms = array_merge($contextTerms, $ctx['labels']);
            }
            if (! empty($ctx['technical_keywords'])) {
                $contextTerms = array_merge($contextTerms, $ctx['technical_keywords']);
            }
        }
        $contextTerms = array_unique(array_map('strtolower', $contextTerms));

        if (empty($queryTerms) && empty($contextTerms)) {
            return $results;
        }

        $scored = [];
        foreach ($results as $result) {
            $title = strtolower($result['title'] ?? '');
            $url = strtolower($result['url'] ?? '');
            $text = $title.' '.$url;

            $queryOverlap = 0;
            if (! empty($queryTerms)) {
                $matches = 0;
                foreach ($queryTerms as $term) {
                    if (str_contains($text, $term)) {
                        $matches++;
                    }
                }
                $queryOverlap = $matches / count($queryTerms);
            }

            $contextOverlap = 0;
            if (! empty($contextTerms)) {
                $matches = 0;
                foreach ($contextTerms as $term) {
                    if (str_contains($text, $term)) {
                        $matches++;
                    }
                }
                $contextOverlap = $matches / count($contextTerms);
            }

            $score = ($queryOverlap * 0.6) + ($contextOverlap * 0.4);

            if ($score >= $threshold) {
                $scored[] = ['result' => $result, 'score' => $score];
            }
        }

        // Sort by relevance descending
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_map(fn ($item) => $item['result'], $scored);
    }

    /**
     * Extract meaningful key terms from a string (lowercased, stop words removed).
     */
    private function extractKeyTerms(string $text): array
    {
        $stopWords = ['the', 'a', 'an', 'of', 'in', 'on', 'at', 'to', 'for', 'and', 'or', 'is', 'was', 'by', 'with', 'from', 'due', 'not', 'this', 'that', 'it', 'how', 'what', 'why', 'can', 'are', 'be', 'do', 'does', 'has', 'have', 'but', 'if', 'so', 'no', 'all', 'any', 'each', 'every', 'some', 'such'];

        $words = preg_split('/[\s\-_:;,.\(\)\[\]]+/', strtolower($text));
        $terms = [];
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) < 3 || in_array($word, $stopWords)) {
                continue;
            }
            $terms[] = $word;
        }

        return array_unique($terms);
    }

    /**
     * Strip confidential/internal data from search query before sending externally.
     * Removes: incident IDs, financial amounts, person names, internal hostnames,
     * IP addresses, email addresses, and other identifying information.
     */
    public function sanitizeQuery(string $query): string
    {
        $sanitized = $query;

        // Strip known confidential patterns
        foreach (self::CONFIDENTIAL_PATTERNS as $pattern) {
            $sanitized = preg_replace($pattern, '', $sanitized);
        }

        // Strip PIC/person names from database
        $sanitized = $this->stripPersonNames($sanitized);

        // Strip internal system identifiers
        $sanitized = $this->stripInternalSystems($sanitized);

        // Clean up leftover artifacts
        $sanitized = preg_replace('/\s+/', ' ', $sanitized);
        $sanitized = preg_replace('/^\s*[\-,;:]\s*/m', '', $sanitized);
        $sanitized = trim($sanitized);
        $sanitized = rtrim($sanitized, '.,;:-');

        return $sanitized;
    }

    /**
     * Remove person names (PIC names) from query.
     * Loads all user names from database and strips them.
     */
    private function stripPersonNames(string $query): string
    {
        $names = Cache::remember('web_search_pic_names', 3600, function () {
            return User::pluck('name')->filter()->values()->toArray();
        });

        foreach ($names as $name) {
            // Match full name and also individual words that are clearly names
            $escaped = preg_quote($name, '/');
            $query = preg_replace('/\b'.$escaped.'\b/i', '', $query);
        }

        return $query;
    }

    /**
     * Remove internal system/service names that appear in incidents.
     * Scans incident summaries/root_cause for recurring internal terms.
     */
    private function stripInternalSystems(string $query): string
    {
        // Common internal identifier patterns that should be stripped
        $internalPatterns = [
            // Internal app/service codes: APP-001, SVC-PROD, SYS-AUTH
            '/\b(?:APP|SVC|SYS|SRV|API)-[\w-]+\b/i',
            // Internal ticket/issue references: JIRA-123, TICKET-456
            '/\b(?:JIRA|TICKET|ISSUE|CASE|REF)-\d+\b/i',
            // Internal project codes with numbers
            '/\b(?:PROJ|PRJ|PROJECT)-[\w-]+\b/i',
            // Specific internal terms that shouldn't leak
            '/\b(?:third.?party.?client|vendor.?name|client.?name)\s*[:=]\s*[\w-]+/i',
        ];

        foreach ($internalPatterns as $pattern) {
            $query = preg_replace($pattern, '', $query);
        }

        return $query;
    }

    /**
     * Use AI gateway (OpenAI-compatible /chat/completions) with Gemini model.
     */
    private function searchViaGateway(string $sanitizedQuery, array $incidentContext = [], ?int $userId = null): array
    {
        $apiKey = config('ai.search.gemini_api_key')
            ?: AiSetting::get('api_key', config('ai.api_key', ''));
        $model = config('ai.search.gemini_model', 'gemini-2.5-flash');
        $baseUrl = rtrim(config('ai.search.gemini_base_url') ?? '', '/')
            ?: rtrim(AiSetting::get('base_url', config('ai.base_url', '')), '/');
        $timeout = config('ai.search.timeout', 15);

        if (! $apiKey || ! $baseUrl) {
            Log::warning('Web search skipped: API key or base URL not configured');

            return ['results' => [], 'context' => '', 'error' => 'Search not configured'];
        }

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
                        ['role' => 'system', 'content' => $this->buildGatewaySearchPrompt($incidentContext)],
                        ['role' => 'user', 'content' => "Search the web for: {$sanitizedQuery}"],
                    ],
                    'max_tokens' => 2000,
                ]);

            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];

            if (! $response->successful()) {
                Log::warning('Gateway search API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                $this->usageLogger->log(
                    fieldType: 'web_search',
                    model: $model,
                    success: false,
                    inputLength: strlen($sanitizedQuery),
                    usage: array_filter([
                        'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                        'completion_tokens' => $usage['completion_tokens'] ?? null,
                        'total_tokens' => $usage['total_tokens'] ?? null,
                    ]),
                    responseTimeMs: $responseTimeMs,
                    errorMessage: 'HTTP '.$response->status(),
                    userId: $userId,
                );

                return ['results' => [], 'context' => '', 'error' => 'Search API error'];
            }

            $this->usageLogger->log(
                fieldType: 'web_search',
                model: $model,
                success: true,
                inputLength: strlen($sanitizedQuery),
                outputLength: strlen($responseData['choices'][0]['message']['content'] ?? ''),
                usage: array_filter([
                    'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                    'completion_tokens' => $usage['completion_tokens'] ?? null,
                    'total_tokens' => $usage['total_tokens'] ?? null,
                ]),
                responseTimeMs: $responseTimeMs,
                apiRequestId: $responseData['id'] ?? null,
                userId: $userId,
            );

            return $this->parseGatewayResponse($responseData, $sanitizedQuery);
        } catch (\Throwable $e) {
            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            Log::warning('Web search failed', ['error' => $e->getMessage()]);

            $this->usageLogger->log(
                fieldType: 'web_search',
                model: $model,
                success: false,
                responseTimeMs: $responseTimeMs,
                errorMessage: $e->getMessage(),
                userId: $userId,
            );

            return ['results' => [], 'context' => '', 'error' => $e->getMessage()];
        }
    }

    /**
     * Use direct Gemini API with Google Search grounding.
     */
    private function searchViaGemini(string $sanitizedQuery, array $incidentContext = [], ?int $userId = null): array
    {
        $apiKey = config('ai.search.gemini_api_key');
        $model = config('ai.search.gemini_model', 'gemini-2.5-flash');
        $baseUrl = rtrim(config('ai.search.gemini_base_url') ?? '', '/');
        $timeout = config('ai.search.timeout', 15);

        if (! $apiKey) {
            Log::warning('Web search skipped: Gemini API key not configured');

            return ['results' => [], 'context' => '', 'error' => 'Search not configured'];
        }

        $startTime = microtime(true);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])
                ->timeout($timeout)
                ->post("{$baseUrl}/models/{$model}:generateContent?key={$apiKey}", [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $this->buildGeminiSearchPrompt($sanitizedQuery)],
                            ],
                        ],
                    ],
                    'tools' => [
                        ['googleSearch' => (object) []],
                    ],
                ]);

            $responseTimeMs = (microtime(true) - $startTime) * 1000;

            if (! $response->successful()) {
                Log::warning('Gemini search API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                $this->usageLogger->log(
                    fieldType: 'web_search',
                    model: $model,
                    success: false,
                    inputLength: strlen($sanitizedQuery),
                    responseTimeMs: $responseTimeMs,
                    errorMessage: 'HTTP '.$response->status(),
                    userId: $userId,
                );

                return ['results' => [], 'context' => '', 'error' => 'Search API error'];
            }

            $this->usageLogger->log(
                fieldType: 'web_search',
                model: $model,
                success: true,
                inputLength: strlen($sanitizedQuery),
                responseTimeMs: $responseTimeMs,
                metadata: ['provider' => 'gemini'],
                userId: $userId,
            );

            return $this->parseGeminiResponse($response->json());
        } catch (\Throwable $e) {
            $responseTimeMs = (microtime(true) - $startTime) * 1000;
            Log::warning('Web search failed', ['error' => $e->getMessage()]);

            $this->usageLogger->log(
                fieldType: 'web_search',
                model: $model,
                success: false,
                responseTimeMs: $responseTimeMs,
                errorMessage: $e->getMessage(),
                userId: $userId,
            );

            return ['results' => [], 'context' => '', 'error' => $e->getMessage()];
        }
    }

    private function buildGatewaySearchPrompt(array $incidentContext = []): string
    {
        $prompt = 'You are a web search assistant for a technical risk management team. '
            ."Search the web for the user's query and provide factual, well-sourced information.\n\n"
            .'Focus on: root cause analysis, known issues, CVEs, vendor advisories, industry standards, '
            ."best practices, or similar incidents reported publicly.\n\n";

        if (! empty($incidentContext)) {
            $prompt .= "INCIDENT CONTEXT (use this to understand what technical domain to search in):\n";
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
                if (! empty($parts)) {
                    $prompt .= ($i + 1).'. '.implode('; ', $parts)."\n";
                }
            }
            $prompt .= "\n";
        }

        $prompt .= "Rules:\n"
            ."- Provide a concise summary of what you found with key takeaways.\n"
            ."- Include specific details: affected systems, vendors, CVE IDs, recommendations.\n"
            ."- List your sources at the end as a numbered list with URLs when available.\n"
            ."- If you cannot find relevant results, say so honestly.\n"
            ."- Use plain text only. No markdown formatting.\n\n"
            ."Format your response as:\n"
            ."1. Summary of findings\n"
            ."2. Key details and specifics\n"
            .'3. Sources (numbered list with URLs)';

        return $prompt;
    }

    private function buildGeminiSearchPrompt(string $query): string
    {
        return 'Search the web for information about the following topic in the context of incident management, technical risk, or IT operations. '
            ."Focus on: root cause analysis, known issues, best practices, industry standards, or similar incidents reported publicly.\n\n"
            ."Query: {$query}\n\n"
            .'Provide a concise summary of what you found, with key takeaways. '
            .'Include specific details like affected systems, vendors, CVEs, or recommendations when available.';
    }

    private function parseGatewayResponse(array $data, string $query): array
    {
        $content = $data['choices'][0]['message']['content'] ?? '';

        if (blank($content)) {
            return ['results' => [], 'context' => '', 'error' => 'No results'];
        }

        $sources = $this->extractSourcesFromText($content);

        return [
            'results' => $sources,
            'context' => "### Web Search Results for \"{$query}\"\n\n{$content}",
            'summary' => $content,
            'error' => '',
        ];
    }

    private function parseGeminiResponse(array $data): array
    {
        $candidate = $data['candidates'][0] ?? null;
        if (! $candidate) {
            return ['results' => [], 'context' => '', 'error' => 'No results'];
        }

        $summary = $candidate['content']['parts'][0]['text'] ?? '';
        $grounding = $candidate['groundingMetadata'] ?? null;

        $sources = [];
        $maxResults = config('ai.search.max_results', 8);

        if ($grounding && isset($grounding['groundingChunks'])) {
            foreach (array_slice($grounding['groundingChunks'], 0, $maxResults) as $chunk) {
                if (isset($chunk['web'])) {
                    $sources[] = [
                        'title' => $chunk['web']['title'] ?? '',
                        'url' => $chunk['web']['uri'] ?? '',
                    ];
                }
            }
        }

        $context = $summary;
        if (! empty($sources)) {
            $context .= "\n\n### Sources\n";
            foreach ($sources as $i => $source) {
                $context .= ($i + 1).". [{$source['title']}]({$source['url']})\n";
            }
        }

        return [
            'results' => $sources,
            'context' => $context,
            'summary' => $summary,
            'error' => '',
        ];
    }

    private function extractSourcesFromText(string $text): array
    {
        $sources = [];
        preg_match_all('/https?:\/\/[^\s\])\]]+/', $text, $matches);

        foreach ($matches[0] ?? [] as $url) {
            $sources[] = ['title' => parse_url($url, PHP_URL_HOST) ?? $url, 'url' => $url];
        }

        return array_slice($sources, 0, config('ai.search.max_results', 8));
    }

    public function isConfigured(): bool
    {
        $provider = config('ai.search.provider', 'gateway');

        if ($provider === 'gemini') {
            return (bool) config('ai.search.gemini_api_key');
        }

        $apiKey = config('ai.search.gemini_api_key')
            ?: AiSetting::get('api_key', config('ai.api_key', ''));
        $baseUrl = config('ai.search.gemini_base_url')
            ?: AiSetting::get('base_url', config('ai.base_url', ''));

        return (bool) ($apiKey && $baseUrl);
    }

    /**
     * Preview what the sanitization does — useful for debugging/logging.
     */
    public function previewSanitization(string $query): array
    {
        return [
            'original' => $query,
            'sanitized' => $this->sanitizeQuery($query),
        ];
    }
}
