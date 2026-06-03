<?php

namespace App\Services\Ai\PlanMode;

use App\Jobs\Ai\AnalyzePlanGaps;
use App\Jobs\Ai\SynthesizePlanResults;
use App\Models\AiSetting;
use App\Models\ChatPlanSubtask;
use App\Models\WarRoomAgentConfig;
use App\Services\Ai\AiUsageLogger;
use App\Services\Ai\Concerns\InteractsWithAiApi;
use App\Services\Ai\Concerns\StripsThinkingTags;
use App\Services\Ai\PromptOptimizer;
use App\Services\WarRoom\WarRoomStreamingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class PlanModeService
{
    use InteractsWithAiApi;

    public function __construct(
        private PlanPromptBuilder $promptBuilder,
        private AiUsageLogger $usageLogger,
    ) {}

    public function analyzeQuestionDeep(string $userMessage, array $history, array $referencedIds = [], ?string $userModel = null): ?array
    {
        $model = $userModel ?? config('ai.plan_mode.planning_model', config('ai.reasoning_model'));
        $timeout = config('ai.plan_mode.planning_timeout', 30);
        $maxTokens = 1024;

        $systemPrompt = config('ai.prompts.plan_pre_analysis.system');

        $userParts = [];
        if (! empty($history)) {
            $recentHistory = array_slice($history, -4);
            $historyText = collect($recentHistory)
                ->map(fn ($m) => ucfirst($m['role']).': '.mb_substr($m['content'], 0, 150))
                ->implode("\n");
            $userParts[] = "## Conversation History\n{$historyText}";
        }

        if (! empty($referencedIds)) {
            $incidentContext = $this->buildIncidentContextForPreAnalysis($referencedIds);
            if ($incidentContext) {
                $userParts[] = "## Referenced Incident Data\n{$incidentContext}";
            }
        }

        $userParts[] = "## User's Question\n{$userMessage}";
        $userParts[] = "\nAnalyze this question and return ONLY valid JSON.";

        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout($timeout)
                ->post($this->buildUrl(), [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => implode("\n\n", $userParts)],
                    ],
                    'max_tokens' => $maxTokens,
                ]);

            $responseTimeMs = $this->elapsedMs($startTime);
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];

            if (! $response->successful()) {
                Log::info('Plan pre-analysis API error, skipping', ['status' => $response->status()]);

                return null;
            }

            $content = $responseData['choices'][0]['message']['content'] ?? '';

            if (blank($content)) {
                return null;
            }

            $parsed = $this->extractJson($content);

            $this->usageLogger->log(
                fieldType: 'plan_mode_pre_analysis',
                model: $model,
                success: true,
                outputLength: strlen($content),
                usage: array_filter([
                    'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                    'completion_tokens' => $usage['completion_tokens'] ?? null,
                    'total_tokens' => $usage['total_tokens'] ?? null,
                ]),
                responseTimeMs: $responseTimeMs,
            );

            return is_array($parsed) ? $parsed : null;
        } catch (\Throwable $e) {
            Log::info('Plan pre-analysis failed, skipping', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function buildIncidentContextForPreAnalysis(array $referencedIds): string
    {
        $incidents = \App\Models\Incident::whereIn('no', $referencedIds)
            ->with(\App\Models\Incident::FULL_RELATIONS)
            ->get();

        if ($incidents->isEmpty()) {
            return '';
        }

        $exporter = app(\App\Services\Markdown\IncidentMarkdownExporter::class);
        $parts = [];

        foreach ($incidents->take(3) as $incident) {
            $md = $exporter->generateForContext($incident);
            // Truncate to ~2000 chars to avoid overwhelming the pre-analysis
            if (strlen($md) > 2000) {
                $md = mb_substr($md, 0, 2000)."\n... (truncated)";
            }
            $parts[] = $md;
        }

        return implode("\n\n---\n\n", $parts);
    }

    public function thinkAndPlan(string $userMessage, array $history, array $personaKeys, array $referencedIds = [], ?string $userModel = null, ?array $preAnalysis = null): PlanResult
    {
        $planningModel = $userModel ?? config('ai.plan_mode.planning_model', 'REASONING-MODEL');
        $timeout = config('ai.plan_mode.planning_timeout', 30);
        $maxTokens = config('ai.plan_mode.max_planning_tokens', 4096);

        $systemPrompt = $this->promptBuilder->buildPlannerSystemPrompt($personaKeys);
        $userPrompt = $this->promptBuilder->buildPlannerUserMessage($userMessage, $history, $referencedIds, $preAnalysis);

        if (PromptOptimizer::isEnabled()) {
            $systemPrompt = app(PromptOptimizer::class)->optimize($systemPrompt, 'plan_mode');
        }

        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout($timeout)
                ->post($this->buildUrl(), [
                    'model' => $planningModel,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'max_tokens' => $maxTokens,
                ]);

            $responseTimeMs = $this->elapsedMs($startTime);
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];

            if (! $response->successful()) {
                $errorMsg = $responseData['error']['message'] ?? 'Planning model error (HTTP '.$response->status().')';

                return PlanResult::failure($errorMsg, $planningModel);
            }

            $content = $responseData['choices'][0]['message']['content'] ?? '';
            $thinkingContent = $responseData['choices'][0]['message']['reasoning_content'] ?? null;

            if (blank($content)) {
                return PlanResult::failure('Planning model returned empty response.', $planningModel);
            }

            $parsed = $this->parsePlanJson($content);
            if (! $parsed) {
                return PlanResult::failure('Failed to parse plan from model response.', $planningModel);
            }

            $subtasks = $this->normalizeSubtasks($parsed['subtasks'] ?? [], $personaKeys, $preAnalysis);

            $this->usageLogger->log(
                fieldType: 'plan_mode_planning',
                model: $planningModel,
                success: true,
                outputLength: strlen($content),
                usage: array_filter([
                    'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                    'completion_tokens' => $usage['completion_tokens'] ?? null,
                    'total_tokens' => $usage['total_tokens'] ?? null,
                ]),
                responseTimeMs: $responseTimeMs,
                metadata: ['subtask_count' => count($subtasks)],
            );

            return PlanResult::success(
                planText: $parsed['plan_text'] ?? 'Analyzing your question across multiple specialist perspectives.',
                subtasks: $subtasks,
                model: $planningModel,
                totalTokens: $usage['total_tokens'] ?? null,
                thinkingContent: $thinkingContent,
            );
        } catch (ConnectionException $e) {
            return PlanResult::failure('Cannot connect to planning model. Please try again.', $planningModel);
        } catch (\Throwable $e) {
            Log::warning('Plan mode planning error', ['error' => $e->getMessage()]);

            return PlanResult::failure('Planning error: '.$e->getMessage(), $planningModel);
        }
    }

    public function validatePlan(array $subtasks, ?array $preAnalysis): array
    {
        $issues = [];
        $suggestions = [];

        // 1. Check subtask specificity
        foreach ($subtasks as $i => $task) {
            $desc = $task['description'] ?? '';
            if (strlen($desc) < 20) {
                $issues[] = 'Subtask '.($i + 1).' description is too vague ('.strlen($desc).' chars)';
                $suggestions[] = 'Make subtask '.($i + 1).' more specific — mention what data to analyze and what to produce';
            }
        }

        // 2. MECE independence check
        $dependencyPhrases = ['based on', 'using the results of', 'building on', 'after task', 'after subtask', 'once task', 'depends on'];
        foreach ($subtasks as $i => $task) {
            $desc = strtolower($task['description'] ?? '');
            foreach ($dependencyPhrases as $phrase) {
                if (str_contains($desc, $phrase)) {
                    $issues[] = 'Subtask '.($i + 1)." has dependency phrase \"{$phrase}\" — violates MECE isolation";
                    $suggestions[] = 'Make subtask '.($i + 1)." self-contained without depending on other subtasks' output";
                    break;
                }
            }
        }

        // 3. Check redundancy (simple word overlap)
        for ($i = 0; $i < count($subtasks); $i++) {
            for ($j = $i + 1; $j < count($subtasks); $j++) {
                $words1 = array_unique(str_word_count(strtolower($subtasks[$i]['description'] ?? ''), 1));
                $words2 = array_unique(str_word_count(strtolower($subtasks[$j]['description'] ?? ''), 1));
                $common = count(array_intersect($words1, $words2));
                $total = max(count($words1), count($words2), 1);
                $overlap = $common / $total;
                if ($overlap > 0.7 && $total > 5) {
                    $issues[] = 'Subtasks '.($i + 1).' and '.($j + 1).' have high overlap ('.round($overlap * 100).'%)';
                    $suggestions[] = 'Differentiate subtasks '.($i + 1).' and '.($j + 1).' more clearly';
                }
            }
        }

        // 4. Check domain coverage from pre-analysis
        if ($preAnalysis && ! empty($preAnalysis['required_domains'])) {
            $coveredDomains = [];
            foreach ($subtasks as $task) {
                $domain = $task['domain'] ?? '';
                $desc = strtolower($task['description'] ?? '');
                foreach ($preAnalysis['required_domains'] as $required) {
                    if (str_contains($desc, strtolower($required)) || str_contains(strtolower($domain), strtolower($required))) {
                        $coveredDomains[] = $required;
                    }
                }
            }
            $uncovered = array_diff($preAnalysis['required_domains'], array_unique($coveredDomains));
            if (! empty($uncovered)) {
                $issues[] = 'Domains not covered: '.implode(', ', $uncovered);
                $suggestions[] = 'Add a subtask covering: '.implode(', ', $uncovered);
            }
        }

        // 5. Quick AI validation for deeper analysis (only if basic checks found issues)
        if (! empty($issues)) {
            $score = max(0.1, 1.0 - (count($issues) * 0.15));
        } else {
            // Run AI validation for a quality score
            $score = $this->runAiPlanValidation($subtasks, $preAnalysis);
        }

        $valid = $score >= 0.7;

        return [
            'valid' => $valid,
            'score' => $score,
            'issues' => $issues,
            'suggestions' => $suggestions,
        ];
    }

    private function runAiPlanValidation(array $subtasks, ?array $preAnalysis): float
    {
        try {
            $systemPrompt = config('ai.prompts.plan_validation.system');
            $model = config('ai.plan_mode.planning_model', config('ai.reasoning_model'));

            $taskDescriptions = collect($subtasks)->map(fn ($t, $i) => ($i + 1).". [{$t['persona_key']}] ({$t['domain']}): {$t['description']}")->implode("\n");
            $domains = $preAnalysis ? implode(', ', $preAnalysis['required_domains'] ?? []) : 'N/A';
            $complexity = $preAnalysis['complexity'] ?? 'moderate';

            $userPrompt = "## Pre-Analysis\nDomains: {$domains}\nComplexity: {$complexity}\n\n## Plan Subtasks\n{$taskDescriptions}\n\nEvaluate this plan.";

            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(15)
                ->post($this->buildUrl(), [
                    'model' => config('ai.fast_model', $model),
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'max_tokens' => 512,
                ]);

            if (! $response->successful()) {
                return 0.8; // Default to pass if validation API fails
            }

            $content = $response->json('choices.0.message.content', '');
            $parsed = $this->extractJson($content);

            if (is_array($parsed) && isset($parsed['score'])) {
                return (float) $parsed['score'];
            }

            return 0.8;
        } catch (\Throwable $e) {
            Log::info('AI plan validation failed, defaulting to pass', ['error' => $e->getMessage()]);

            return 0.8;
        }
    }

    public function createSubtasks(string $planId, string $conversationId, array $subtasks): array
    {
        $records = [];
        foreach ($subtasks as $index => $subtask) {
            $records[] = ChatPlanSubtask::create([
                'plan_id' => $planId,
                'conversation_id' => $conversationId,
                'subtask_index' => $index,
                'description' => $subtask['description'],
                'persona_key' => $subtask['persona_key'] ?? null,
                'status' => 'pending',
                'metadata' => [
                    'required_context' => $subtask['required_context'] ?? [],
                    'label' => $subtask['label'] ?? 'General Analysis',
                    'domain' => $subtask['domain'] ?? 'general',
                ],
            ]);
        }

        return $records;
    }

    public function processSubtask(ChatPlanSubtask $subtask, string $userMessage, array $referencedIds, ?string $userModel = null): void
    {
        $subtask->markRunning();

        $isResearch = ($subtask->metadata['type'] ?? null) === 'research';
        $model = $this->resolveSubtaskModel($subtask, $userModel);
        $maxTokens = $this->resolveSubtaskMaxTokens($subtask);
        $timeout = config('ai.plan_mode.subtask_timeout', 300);

        $planText = ChatMessage::where('plan_id', $subtask->plan_id)
            ->where('plan_role', 'plan')
            ->first()?->plan_metadata['plan_text'] ?? '';

        $totalSubtasks = ChatPlanSubtask::where('plan_id', $subtask->plan_id)->count();

        if ($isResearch) {
            $dynamicPrompt = $this->promptBuilder->buildResearchPrompt(
                topic: $subtask->metadata['topic'] ?? $subtask->description,
                reason: $subtask->metadata['reason'] ?? '',
                userMessage: $userMessage,
                referencedIds: $referencedIds,
            );
        } else {
            $requiredContext = $subtask->metadata['required_context'] ?? [];
            $dynamicPrompt = $this->promptBuilder->buildSubtaskAgentPrompt(
                description: $subtask->description,
                personaKey: $subtask->persona_key,
                userMessage: $userMessage,
                referencedIds: $referencedIds,
                requiredContext: $requiredContext,
                planText: $planText,
                totalSubtasks: $totalSubtasks,
            );
        }

        $subtask->update(['dynamic_prompt' => $dynamicPrompt]);

        if (PromptOptimizer::isEnabled()) {
            $dynamicPrompt = app(PromptOptimizer::class)->optimize($dynamicPrompt, 'plan_subtask');
        }

        $messages = [
            ['role' => 'system', 'content' => $dynamicPrompt],
            ['role' => 'user', 'content' => 'Begin your analysis now.'],
        ];

        $startTime = microtime(true);

        try {
            $result = app(WarRoomStreamingService::class)->streamCompletion(
                model: $model,
                messages: $messages,
                maxTokens: $maxTokens,
            );

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            $content = $result['content'] ?? '';
            $usage = $result['usage'] ?? [];

            if (blank($content)) {
                throw new \RuntimeException('Agent returned empty response.');
            }

            $subtask->markCompleted(
                result: $content,
                model: $model,
                tokensUsed: $usage['total_tokens'] ?? 0,
                responseTimeMs: $responseTimeMs,
                metadata: [
                    'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                    'completion_tokens' => $usage['completion_tokens'] ?? null,
                    'reasoning_content' => $result['reasoning_content'] ?? null,
                ],
            );

            $this->usageLogger->log(
                fieldType: 'plan_mode_subtask',
                model: $model,
                success: true,
                outputLength: strlen($content),
                usage: array_filter([
                    'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                    'completion_tokens' => $usage['completion_tokens'] ?? null,
                    'total_tokens' => $usage['total_tokens'] ?? null,
                ]),
                responseTimeMs: $responseTimeMs,
                metadata: ['plan_id' => $subtask->plan_id, 'subtask_index' => $subtask->subtask_index],
            );

            $this->onSubtaskCompleted($subtask->plan_id, $subtask->conversation_id, $userMessage, $referencedIds, $userModel);
        } catch (\Throwable $e) {
            Log::warning('Plan subtask processing error', [
                'plan_id' => $subtask->plan_id,
                'subtask_index' => $subtask->subtask_index,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function onSubtaskCompleted(string $planId, string $conversationId, string $userMessage, array $referencedIds, ?string $userModel = null): void
    {
        $lock = Cache::lock("plan_completion:{$planId}", 10);

        try {
            $lock->block(5);

            $pending = ChatPlanSubtask::where('plan_id', $planId)
                ->whereIn('status', ['pending', 'running'])
                ->count();

            if ($pending === 0) {
                $gapAnalysisDone = Cache::has("plan_gap_analysis_done:{$planId}");

                if (config('ai.plan_mode.gap_analysis_enabled', false) && ! $gapAnalysisDone) {
                    Cache::put("plan_gap_analysis_done:{$planId}", true, now()->addHours(1));
                    AnalyzePlanGaps::dispatch($planId, $conversationId, $userMessage, $referencedIds, $userModel)
                        ->onQueue(config('ai.plan_mode.queue', 'war-room'));
                } else {
                    SynthesizePlanResults::dispatch($planId, $conversationId, $userMessage, $referencedIds, $userModel)
                        ->onQueue(config('ai.plan_mode.queue', 'war-room'));
                }
            }
        } finally {
            optional($lock)->release();
        }
    }

    public function synthesizeResults(string $planId, string $conversationId, string $userMessage, array $referencedIds, ?string $userModel = null): string
    {
        $subtasks = ChatPlanSubtask::where('plan_id', $planId)->orderBy('subtask_index')->get();

        $completedResults = $subtasks->where('status', 'completed')->map(fn ($s) => [
            'persona_key' => $s->persona_key,
            'description' => $s->description,
            'result' => $s->result,
            'is_research' => ($s->metadata['type'] ?? null) === 'research',
        ])->values()->toArray();

        $failedCount = $subtasks->where('status', 'failed')->count();

        if (empty($completedResults)) {
            $errorMessage = 'All subtask agents failed to produce results. Please try again or use normal mode.';
            Cache::put("plan_synthesis:{$planId}", $errorMessage, now()->addHours(1));

            return $errorMessage;
        }

        $planMessage = \App\Models\ChatMessage::where('plan_id', $planId)
            ->where('plan_role', 'plan')
            ->first();
        $planText = $planMessage?->plan_metadata['plan_text'] ?? '';
        $preAnalysis = $planMessage?->plan_metadata['pre_analysis'] ?? null;

        $synthesisModel = config('ai.plan_mode.synthesis_model') ?? $userModel ?? 'REASONING-MODEL';
        $maxTokens = config('ai.plan_mode.synthesis_max_tokens', 8192);
        $timeout = config('ai.plan_mode.synthesis_timeout', 120);

        $synthesisPrompt = $this->promptBuilder->buildSynthesisPrompt($planText, $completedResults, $userMessage, $preAnalysis);

        if ($failedCount > 0) {
            $synthesisPrompt .= "\n\n**Note**: {$failedCount} subtask(s) failed. Synthesize with available results and note any missing perspectives.";
        }

        if (PromptOptimizer::isEnabled()) {
            $synthesisPrompt = app(PromptOptimizer::class)->optimize($synthesisPrompt, 'plan_synthesis');
        }

        $startTime = microtime(true);

        try {
            $result = app(WarRoomStreamingService::class)->streamCompletion(
                model: $synthesisModel,
                messages: [
                    ['role' => 'system', 'content' => $synthesisPrompt],
                    ['role' => 'user', 'content' => 'Produce the synthesized response based on all specialist analyses above.'],
                ],
                maxTokens: $maxTokens,
            );

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $content = $result['content'] ?? '';
            $usage = $result['usage'] ?? [];

            $this->usageLogger->log(
                fieldType: 'plan_mode_synthesis',
                model: $synthesisModel,
                success: ! blank($content),
                outputLength: strlen($content),
                usage: array_filter([
                    'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                    'completion_tokens' => $usage['completion_tokens'] ?? null,
                    'total_tokens' => $usage['total_tokens'] ?? null,
                ]),
                responseTimeMs: $responseTimeMs,
                metadata: ['plan_id' => $planId],
            );

            $finalContent = blank($content)
                ? 'Synthesis failed to produce a response. Individual specialist results are available.'
                : StripsThinkingTags::stripStatic($content);

            Cache::put("plan_synthesis:{$planId}", $finalContent, now()->addHours(1));

            return $finalContent;
        } catch (\Throwable $e) {
            Log::warning('Plan synthesis error', ['plan_id' => $planId, 'error' => $e->getMessage()]);
            $errorMsg = 'Synthesis failed. Individual specialist results may be available.';
            Cache::put("plan_synthesis:{$planId}", $errorMsg, now()->addHours(1));

            return $errorMsg;
        }
    }

    public function getSubtaskStatuses(string $planId): array
    {
        return ChatPlanSubtask::where('plan_id', $planId)
            ->orderBy('subtask_index')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'index' => $s->subtask_index,
                'description' => $s->description,
                'persona_key' => $s->persona_key,
                'label' => $s->metadata['label'] ?? ($s->persona_key ? ucfirst(str_replace('_', ' ', $s->persona_key)) : 'General Analysis'),
                'status' => $s->status,
                'error_message' => $s->error_message,
                'result_preview' => $s->result ? mb_substr($s->result, 0, 200) : null,
                'is_research' => ($s->metadata['type'] ?? null) === 'research',
            ])
            ->values()
            ->toArray();
    }

    public function checkRateLimits(): ?string
    {
        $userId = auth()->id() ?? 'guest';
        $dailyLimit = config('ai.plan_mode.rate_limits.max_plans_per_user_per_day', 20);
        $hourlyLimit = config('ai.plan_mode.rate_limits.max_plans_per_user_per_hour', 5);

        if (! RateLimiter::attempt("plan-mode-daily:{$userId}", $dailyLimit, fn () => true)) {
            return 'Daily plan mode limit reached. Please try again tomorrow.';
        }

        if (! RateLimiter::attempt("plan-mode-hourly:{$userId}", $hourlyLimit, fn () => true)) {
            return 'Hourly plan mode limit reached. Please wait a moment.';
        }

        return null;
    }

    public function analyzeQuestion(string $userMessage, array $history, array $referencedIds = [], ?string $userModel = null): PlanResult
    {
        if (! config('ai.plan_mode.clarification_enabled', false)) {
            return new PlanResult(success: true);
        }

        $model = $userModel ?? AiSetting::get('default_model', config('ai.default_model'));
        $timeout = config('ai.plan_mode.clarification_timeout', 10);
        $maxTokens = config('ai.plan_mode.max_clarification_tokens', 512);

        $prompt = $this->promptBuilder->buildClarificationPrompt($userMessage, $history, $referencedIds);

        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout($timeout)
                ->connectTimeout(5)
                ->post($this->buildUrl(), [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => config('ai.prompts.plan_clarification.system')],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => $maxTokens,
                ]);

            $responseTimeMs = $this->elapsedMs($startTime);
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];

            if (! $response->successful()) {
                Log::info('Plan clarification API returned error, skipping', [
                    'status' => $response->status(),
                    'model' => $model,
                ]);

                return new PlanResult(success: true);
            }

            $content = $responseData['choices'][0]['message']['content'] ?? '';

            if (blank($content)) {
                return new PlanResult(success: true);
            }

            $parsed = $this->parseClarificationJson($content);

            $this->usageLogger->log(
                fieldType: 'plan_mode_clarification',
                model: $model,
                success: true,
                outputLength: strlen($content),
                usage: array_filter([
                    'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                    'completion_tokens' => $usage['completion_tokens'] ?? null,
                    'total_tokens' => $usage['total_tokens'] ?? null,
                ]),
                responseTimeMs: $responseTimeMs,
            );

            if ($parsed['needs_clarification'] ?? false) {
                return PlanResult::needsClarification(
                    questions: $parsed['questions'] ?? [],
                    model: $model,
                    totalTokens: $usage['total_tokens'] ?? null,
                );
            }

            return new PlanResult(success: true);
        } catch (\Throwable $e) {
            Log::info('Plan clarification check failed, skipping', [
                'error' => $e->getMessage(),
                'model' => $model,
            ]);

            return new PlanResult(success: true);
        }
    }

    public function analyzeGaps(string $planId, string $conversationId, string $userMessage, array $referencedIds = [], ?string $userModel = null): PlanResult
    {
        $subtasks = ChatPlanSubtask::where('plan_id', $planId)->orderBy('subtask_index')->get();

        $completedResults = $subtasks->where('status', 'completed')->map(fn ($s) => [
            'persona_key' => $s->persona_key,
            'description' => $s->description,
            'result' => $s->result,
        ])->values()->toArray();

        if (empty($completedResults)) {
            Cache::put("plan_gap_analysis:{$planId}", ['coverage_score' => 0, 'gaps' => [], 'research_needed' => false], now()->addHours(1));

            return new PlanResult(success: true, planText: '', subtasks: []);
        }

        $planText = \App\Models\ChatMessage::where('plan_id', $planId)
            ->where('plan_role', 'plan')
            ->first()?->plan_metadata['plan_text'] ?? '';

        $model = config('ai.plan_mode.gap_analysis_model', 'SMART-MODEL');
        $timeout = config('ai.plan_mode.gap_analysis_timeout', 30);
        $maxTokens = config('ai.plan_mode.max_gap_analysis_tokens', 2048);

        $prompt = $this->promptBuilder->buildGapAnalysisPrompt($planText, $completedResults, $userMessage);

        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout($timeout)
                ->post($this->buildUrl(), [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => config('ai.prompts.plan_gap_analysis.system')],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => $maxTokens,
                ]);

            $responseTimeMs = $this->elapsedMs($startTime);
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];
            $content = $responseData['choices'][0]['message']['content'] ?? '';

            $this->usageLogger->log(
                fieldType: 'plan_mode_gap_analysis',
                model: $model,
                success: true,
                outputLength: strlen($content),
                usage: array_filter([
                    'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                    'completion_tokens' => $usage['completion_tokens'] ?? null,
                    'total_tokens' => $usage['total_tokens'] ?? null,
                ]),
                responseTimeMs: $responseTimeMs,
                metadata: ['plan_id' => $planId],
            );

            $parsed = $this->parseGapAnalysisJson($content);
            $researchNeeded = ($parsed['research_needed'] ?? false)
                && count($parsed['gaps'] ?? []) > 0
                && ($parsed['coverage_score'] ?? 1.0) < config('ai.plan_mode.min_coverage_score', 0.8);

            Cache::put("plan_gap_analysis:{$planId}", $parsed, now()->addHours(1));

            if (! $researchNeeded) {
                return new PlanResult(success: true, planText: $planText);
            }

            $maxTopics = config('ai.plan_mode.max_research_topics', 3);
            $researchTopics = array_slice($parsed['gaps'] ?? [], 0, $maxTopics);

            return PlanResult::needsResearch(
                gapAnalysis: $parsed,
                researchTopics: $researchTopics,
                planText: $planText,
                subtasks: [],
                model: $model,
                totalTokens: $usage['total_tokens'] ?? null,
            );
        } catch (\Throwable $e) {
            Log::warning('Plan gap analysis failed, proceeding to synthesis', ['plan_id' => $planId, 'error' => $e->getMessage()]);
            Cache::put("plan_gap_analysis:{$planId}", ['coverage_score' => 0, 'gaps' => [], 'research_needed' => false], now()->addHours(1));

            return new PlanResult(success: true, planText: $planText);
        }
    }

    public function runDeepResearch(string $planId, string $conversationId, string $userMessage, array $researchTopics, array $referencedIds = [], ?string $userModel = null): void
    {
        $existingCount = ChatPlanSubtask::where('plan_id', $planId)->count();

        foreach ($researchTopics as $i => $topic) {
            $index = $existingCount + $i;
            $description = $topic['suggested_research'] ?? $topic['topic'] ?? 'Research additional context';

            $subtask = ChatPlanSubtask::create([
                'plan_id' => $planId,
                'conversation_id' => $conversationId,
                'subtask_index' => $index,
                'description' => $description,
                'persona_key' => null,
                'status' => 'pending',
                'metadata' => [
                    'type' => 'research',
                    'topic' => $topic['topic'] ?? '',
                    'reason' => $topic['reason'] ?? '',
                ],
            ]);

            \App\Jobs\Ai\ProcessPlanSubtask::dispatch($subtask, $userMessage, $referencedIds, $userModel)
                ->onQueue(config('ai.plan_mode.queue', 'war-room'));
        }
    }

    private function parseClarificationJson(string $content): array
    {
        $parsed = $this->extractJson($content);

        if (! is_array($parsed)) {
            return ['needs_clarification' => false];
        }

        return [
            'needs_clarification' => (bool) ($parsed['needs_clarification'] ?? false),
            'questions' => array_values(array_filter(
                array_map('trim', $parsed['questions'] ?? []),
                fn ($q) => filled($q)
            )),
        ];
    }

    private function parseGapAnalysisJson(string $content): array
    {
        $parsed = $this->extractJson($content);

        if (! is_array($parsed)) {
            return ['coverage_score' => 1.0, 'gaps' => [], 'research_needed' => false];
        }

        return [
            'coverage_score' => (float) ($parsed['coverage_score'] ?? 1.0),
            'gaps' => array_values($parsed['gaps'] ?? []),
            'research_needed' => (bool) ($parsed['research_needed'] ?? false),
        ];
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

    private function parsePlanJson(string $content): ?array
    {
        // Try extracting JSON from markdown code blocks
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/\{[^{]*"plan_text"[\s\S]*?"subtasks"/s', $content, $matches)) {
            $json = $matches[0];
        } else {
            $json = $content;
        }

        $parsed = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Broader extraction: find outermost { } pair
            $jsonStart = strpos($content, '{');
            $jsonEnd = strrpos($content, '}');
            if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
                $candidate = substr($content, $jsonStart, $jsonEnd - $jsonStart + 1);
                $parsed = json_decode($candidate, true);
            }
        }

        if (! is_array($parsed) || ! isset($parsed['subtasks']) || ! is_array($parsed['subtasks'])) {
            return null;
        }

        return $parsed;
    }

    private function normalizeSubtasks(array $rawSubtasks, array $personaKeys, ?array $preAnalysis = null): array
    {
        $max = config('ai.plan_mode.max_subtasks', 5);
        $subtasks = array_slice($rawSubtasks, 0, $max);

        $validPersonaKeys = ! empty($personaKeys) ? array_values($personaKeys) : [];

        // Build domain-to-persona mapping from pre-analysis if available
        $domainPersonaMap = [];
        if ($preAnalysis && ! empty($validPersonaKeys)) {
            $allDomains = array_unique(array_merge(
                $preAnalysis['required_domains'] ?? [],
                $preAnalysis['domain_hints']['from_root_cause'] ?? [],
                $preAnalysis['domain_hints']['from_team'] ?? [],
            ));

            foreach ($validPersonaKeys as $key) {
                $config = WarRoomAgentConfig::findByRole($key);
                if (! $config) {
                    continue;
                }
                $personaData = strtolower(($config->description ?? '').' '.implode(' ', $config->skills ?? []));
                foreach ($allDomains as $domain) {
                    if (str_contains($personaData, strtolower($domain))) {
                        $domainPersonaMap[$domain] = $key;
                    }
                }
            }
        }

        return array_map(function ($task, $index) use ($validPersonaKeys, $preAnalysis, $domainPersonaMap) {
            $personaKey = $task['persona_key'] ?? null;

            // Validate that the AI-assigned persona_key is actually in the selected personas
            if ($personaKey && ! empty($validPersonaKeys) && ! in_array($personaKey, $validPersonaKeys)) {
                $personaKey = null;
            }

            // Domain-matching fallback: use pre-analysis to find the best persona
            if ($personaKey === null && ! empty($validPersonaKeys) && $preAnalysis) {
                $taskDomain = $task['domain'] ?? '';
                $requiredDomains = $preAnalysis['required_domains'] ?? [];

                // Try matching by task domain first, then by required domains
                foreach (array_merge([$taskDomain], $requiredDomains) as $domain) {
                    if (! empty($domain) && isset($domainPersonaMap[$domain])) {
                        $personaKey = $domainPersonaMap[$domain];
                        break;
                    }
                }
            }

            // Final fallback: round-robin only if no domain match found
            if ($personaKey === null && ! empty($validPersonaKeys)) {
                $personaKey = $validPersonaKeys[$index % count($validPersonaKeys)];
            }

            return [
                'id' => $task['id'] ?? 'task_'.($index + 1),
                'description' => $task['description'] ?? 'Analyze the question from a general perspective.',
                'persona_key' => $personaKey,
                'domain' => $task['domain'] ?? 'general',
                'required_context' => $task['required_context'] ?? [],
                'label' => $this->generateSubtaskLabel($task, $personaKey),
            ];
        }, $subtasks, array_keys($subtasks));
    }

    private function generateSubtaskLabel(array $task, ?string $personaKey): string
    {
        if ($personaKey) {
            $config = WarRoomAgentConfig::findByRole($personaKey);
            if ($config) {
                return $config->display_name;
            }
        }

        $domain = $task['domain'] ?? '';
        $description = strtolower($task['description'] ?? '');

        $domainLabels = [
            'infrastructure' => 'Infrastructure Analysis',
            'security' => 'Security Analysis',
            'database' => 'Database Analysis',
            'compliance' => 'Compliance Review',
            'risk' => 'Risk Assessment',
            'data' => 'Data Analysis',
            'pattern' => 'Pattern Analysis',
            'impact' => 'Impact Analysis',
            'root_cause' => 'Root Cause Analysis',
            'trend' => 'Trend Analysis',
            'financial' => 'Financial Analysis',
            'comparison' => 'Comparative Analysis',
            'response' => 'Response Planning',
        ];

        if (! empty($domain) && isset($domainLabels[$domain])) {
            return $domainLabels[$domain];
        }

        $keywordLabels = [
            'Pattern Analysis' => ['pattern', 'recurring', 'repeat', 'frequency', 'correlation'],
            'Impact Analysis' => ['impact', 'severity', 'affected', 'customer impact', 'blast radius', 'negative', 'weakness'],
            'Root Cause Analysis' => ['root cause', 'rca', 'why did', 'caused'],
            'Trend Analysis' => ['trend', 'over time', 'monthly', 'quarterly', 'time-based'],
            'Financial Analysis' => ['fund loss', 'financial', 'cost', 'money', 'loss amount'],
            'Risk Assessment' => ['risk', 'threat', 'vulnerability', 'exposure'],
            'Compliance Review' => ['compliance', 'regulatory', 'audit', 'governance'],
            'Comparative Analysis' => ['compare', 'comparison', 'versus', 'difference', 'contrast'],
            'Strength Assessment' => ['strength', 'positive', 'effective', 'best practice', 'success'],
        ];

        foreach ($keywordLabels as $label => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($description, $keyword)) {
                    return $label;
                }
            }
        }

        if (! empty($domain)) {
            return ucfirst(str_replace('_', ' ', $domain)).' Analysis';
        }

        return 'General Analysis';
    }

    private function resolveSubtaskModel(ChatPlanSubtask $subtask, ?string $userModel = null): string
    {
        $isResearch = ($subtask->metadata['type'] ?? null) === 'research';
        $domain = $subtask->metadata['domain'] ?? $subtask->getRawOriginal('description') ?? '';

        // Determine task type for smart routing
        $taskType = $this->classifySubtaskType($subtask->description, $isResearch);

        // Check routing config for this task type
        $routing = config("ai.plan_mode.subtask_model_routing.{$taskType}");
        if ($routing && ! empty($routing['model'])) {
            return $routing['model'];
        }

        // Global override
        $override = config('ai.plan_mode.subtask_model');
        if ($override) {
            return $override;
        }

        // Per-persona model override
        if ($subtask->persona_key) {
            $config = WarRoomAgentConfig::findByRole($subtask->persona_key);
            if ($config?->model_override) {
                return $config->model_override;
            }
        }

        return $userModel ?? AiSetting::get('default_model', config('ai.default_model'));
    }

    private function resolveSubtaskMaxTokens(ChatPlanSubtask $subtask): int
    {
        $isResearch = ($subtask->metadata['type'] ?? null) === 'research';
        $taskType = $this->classifySubtaskType($subtask->description, $isResearch);

        $routing = config("ai.plan_mode.subtask_model_routing.{$taskType}");
        if ($routing && ! empty($routing['max_tokens'])) {
            return $routing['max_tokens'];
        }

        return $isResearch
            ? config('ai.plan_mode.max_research_tokens', 4096)
            : config('ai.plan_mode.max_subtask_tokens', 8192);
    }

    private function classifySubtaskType(string $description, bool $isResearch): string
    {
        if ($isResearch) {
            return 'research';
        }

        $desc = strtolower($description);

        if (str_contains($desc, 'compar') || str_contains($desc, 'contrast') || str_contains($desc, 'versus') || str_contains($desc, 'difference')) {
            return 'comparison';
        }

        if (str_contains($desc, 'root cause') || str_contains($desc, 'analyz') || str_contains($desc, 'assess') || str_contains($desc, 'investigat') || str_contains($desc, 'security') || str_contains($desc, 'threat') || str_contains($desc, 'vulnerabil')) {
            return 'analysis';
        }

        if (str_contains($desc, 'find') || str_contains($desc, 'list') || str_contains($desc, 'count') || str_contains($desc, 'summar') || str_contains($desc, 'retriev') || str_contains($desc, 'gather')) {
            return 'retrieval';
        }

        return 'analysis';
    }
}
