<?php

namespace App\Services\Ai\PlanMode;

use App\Models\AiSetting;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Ai\AiUsageLogger;
use App\Services\Ai\ChatContextService;
use App\Services\Ai\Concerns\StripsThinkingTags;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlanModeStreamingService
{
    public function __construct(
        private PlanModeService $planService,
        private ChatContextService $contextService,
        private AiUsageLogger $usageLogger,
    ) {}

    public function streamPlanResponse(Request $request): StreamedResponse
    {
        $userMessage = $request->input('message');
        $conversationId = $request->input('conversation_id');
        $referencedIds = $request->input('referenced_incidents', []);
        $personaKeys = $request->input('personas', []);
        $model = $request->input('model');

        $userId = auth()->id() ?? 'guest';

        $rateLimitError = $this->planService->checkRateLimits();
        if ($rateLimitError) {
            return new StreamedResponse(function () use ($rateLimitError) {
                echo 'event: error'."\n".'data: '.json_encode(['error' => $rateLimitError])."\n\n";
            }, 429, ['Content-Type' => 'text/event-stream']);
        }

        $conversation = $conversationId
            ? ChatConversation::where('id', $conversationId)->where('user_id', auth()->id())->firstOrFail()
            : ChatConversation::create([
                'user_id' => auth()->id(),
                'title' => mb_substr($userMessage, 0, 80),
                'model' => $model,
            ]);

        $userMsg = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessage,
            'created_at' => now(),
        ]);

        $history = $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->take(config('ai.chat_max_history', 20))
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();

        if (empty($referencedIds)) {
            $historyText = collect($history)->map(fn ($m) => $m['content'])->implode(' ');
            if (preg_match_all('/\d{4}_(?:IN|IS)_\d{4}/', $historyText, $matches)) {
                $referencedIds = array_unique($matches[0]);
            }
        }

        $planId = (string) Str::uuid();
        $conversationIdStr = (string) $conversation->id;
        $userMsgIdStr = (string) $userMsg->id;
        $isNew = ! $conversationId;
        $pollInterval = config('ai.plan_mode.poll_interval_ms', 500);
        $maxPollTime = config('ai.plan_mode.subtask_timeout', 300) + config('ai.plan_mode.synthesis_timeout', 120);

        return new StreamedResponse(function () use (
            $userMessage, $history, $personaKeys, $referencedIds,
            $planId, $conversationIdStr, $userMsgIdStr, $isNew, $conversation,
            $pollInterval, $maxPollTime, $model
        ) {
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', false);

            $send = function (string $event, array $data) {
                echo "event: {$event}\ndata: ".json_encode($data)."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            };

            $send('setup', [
                'conversation_id' => $conversationIdStr,
                'user_message_id' => $userMsgIdStr,
                'is_new' => $isNew,
                'mode' => 'plan',
            ]);

            // Phase 0: Clarification check (if enabled)
            if (config('ai.plan_mode.clarification_enabled', false)) {
                $send('clarification_check', ['status' => 'checking']);

                try {
                    $clarificationResult = $this->planService->analyzeQuestion(
                        $userMessage, $history, $personaKeys, $referencedIds, $model
                    );

                    if ($clarificationResult->needsClarification) {
                        $send('needs_clarification', [
                            'questions' => $clarificationResult->clarificationQuestions,
                            'plan_id' => $planId,
                            'conversation_id' => $conversationIdStr,
                        ]);

                        Cache::put("plan_clarification:{$planId}", [
                            'userMessage' => $userMessage,
                            'history' => $history,
                            'personaKeys' => $personaKeys,
                            'referencedIds' => $referencedIds,
                            'model' => $model,
                            'conversationId' => $conversationIdStr,
                            'conversationDbId' => $conversation->id,
                        ], now()->addHours(1));

                        $send('done', [
                            'conversation_id' => $conversationIdStr,
                            'awaiting_clarification' => true,
                        ]);

                        return;
                    }
                } catch (\Throwable $e) {
                    Log::info('Clarification check failed in stream, proceeding to planning', ['error' => $e->getMessage()]);
                }
            }

            // Phase 0c: Pre-Analysis (Think Before Planning)
            $send('plan_pre_analysis', ['status' => 'analyzing']);
            $preAnalysis = $this->planService->analyzeQuestionDeep($userMessage, $history, $referencedIds, $model);

            if ($preAnalysis) {
                $send('plan_pre_analysis', [
                    'status' => 'complete',
                    'analysis' => [
                        'question_type' => $preAnalysis['question_type'] ?? 'general',
                        'complexity' => $preAnalysis['complexity'] ?? 'moderate',
                        'required_domains' => $preAnalysis['required_domains'] ?? [],
                        'reasoning' => $preAnalysis['reasoning'] ?? '',
                    ],
                ]);
            }

            // Phase 1: Thinking
            $send('plan_thinking', ['status' => 'thinking']);

            $planResult = $this->planService->thinkAndPlan($userMessage, $history, $personaKeys, $referencedIds, $model, $preAnalysis);

            if (! $planResult->success) {
                $send('plan_fallback', ['reason' => $planResult->error ?? 'Planning failed, using standard mode.']);

                $this->fallbackToStandardStream($userMessage, $history, $referencedIds, $conversation, $model, $personaKeys);

                return;
            }

            // Phase 1.5: Plan Validation
            $validation = $this->planService->validatePlan($planResult->subtasks, $preAnalysis);
            if (! $validation['valid'] && ! empty($validation['issues'])) {
                Log::info('Plan validation found issues', [
                    'score' => $validation['score'],
                    'issues' => $validation['issues'],
                ]);
            }

            // Emit reasoning transparency event
            $send('plan_reasoning', [
                'pre_analysis_reasoning' => $preAnalysis['reasoning'] ?? null,
                'planning_reasoning' => $planResult->thinkingContent,
                'question_type' => $preAnalysis['question_type'] ?? 'general',
                'complexity' => $preAnalysis['complexity'] ?? 'moderate',
                'validation_score' => $validation['score'],
            ]);

            // Phase 2: Plan Ready
            $planMetadata = [
                'plan_text' => $planResult->planText,
                'subtasks' => $planResult->subtasks,
                'thinking_content' => $planResult->thinkingContent,
                'total_tokens' => $planResult->totalTokens,
                'pre_analysis' => $preAnalysis,
                'validation' => $validation,
                'model' => $model,
                'referenced_ids' => $referencedIds,
            ];

            ChatMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $planResult->planText,
                'model' => $planResult->model,
                'tokens_used' => $planResult->totalTokens,
                'plan_id' => $planId,
                'plan_metadata' => $planMetadata,
                'is_plan_message' => true,
                'plan_role' => 'plan',
                'created_at' => now(),
            ]);

            $send('plan_ready', [
                'plan_text' => $planResult->planText,
                'subtasks' => collect($planResult->subtasks)->map(fn ($s, $i) => [
                    'index' => $i,
                    'description' => $s['description'],
                    'persona_key' => $s['persona_key'],
                    'label' => $s['label'] ?? 'General Analysis',
                    'status' => 'pending',
                ])->values()->toArray(),
            ]);

            // Phase 3: Dispatch subtasks and poll
            $subtaskRecords = $this->planService->createSubtasks($planId, $conversationIdStr, $planResult->subtasks);

            foreach ($subtaskRecords as $subtask) {
                \App\Jobs\Ai\ProcessPlanSubtask::dispatch($subtask, $userMessage, $referencedIds, $model)
                    ->onQueue(config('ai.plan_mode.queue', 'war-room'));
            }

            $startTime = microtime(true);
            $lastStatuses = [];

            while (true) {
                $elapsed = (microtime(true) - $startTime);
                if ($elapsed > $maxPollTime) {
                    $send('plan_fallback', ['reason' => 'Agents timed out. Showing partial results.']);
                    break;
                }

                $statuses = $this->planService->getSubtaskStatuses($planId);
                $statusJson = json_encode($statuses);

                if ($statusJson !== json_encode($lastStatuses)) {
                    foreach ($statuses as $status) {
                        if (($status['is_research'] ?? false)) {
                            continue;
                        }
                        $prev = $lastStatuses[$status['index']]['status'] ?? null;
                        if ($prev !== $status['status']) {
                            $send('agent_status', $status);
                        }
                    }
                    $lastStatuses = $statuses;
                }

                $originalStatuses = array_filter($statuses, fn ($s) => ! ($s['is_research'] ?? false));
                $allDone = collect($originalStatuses)->every(fn ($s) => in_array($s['status'], ['completed', 'failed']));
                if ($allDone) {
                    break;
                }

                usleep($pollInterval * 1000);
            }

            // Phase 3b: Gap Analysis + Research (if enabled)
            if (config('ai.plan_mode.gap_analysis_enabled', false)) {
                $send('gap_analysis', ['status' => 'analyzing', 'coverage_score' => null]);

                $gapAnalysis = null;
                $gapStartTime = microtime(true);
                $gapTimeout = config('ai.plan_mode.gap_analysis_timeout', 30) + 30;

                while (true) {
                    $gapAnalysis = Cache::get("plan_gap_analysis:{$planId}");
                    if ($gapAnalysis !== null) {
                        break;
                    }
                    if ((microtime(true) - $gapStartTime) > $gapTimeout) {
                        break;
                    }
                    usleep($pollInterval * 1000);
                }

                if ($gapAnalysis) {
                    $coverageScore = $gapAnalysis['coverage_score'] ?? 0;
                    $gapsFound = count($gapAnalysis['gaps'] ?? []);
                    $researchNeeded = ($gapAnalysis['research_needed'] ?? false) && $gapsFound > 0;

                    $send('gap_analysis', [
                        'status' => 'complete',
                        'coverage_score' => $coverageScore,
                        'gaps_found' => $gapsFound,
                        'research_needed' => $researchNeeded,
                    ]);

                    if ($researchNeeded) {
                        $researchTopics = $gapAnalysis['gaps'] ?? [];
                        $send('research_start', [
                            'topics' => array_map(fn ($g) => $g['topic'] ?? '', $researchTopics),
                            'count' => count($researchTopics),
                        ]);

                        $researchMaxTime = config('ai.plan_mode.research_timeout', 120);
                        $researchStartTime = microtime(true);
                        $lastResearchStatuses = [];

                        while (true) {
                            $elapsed = (microtime(true) - $researchStartTime);
                            if ($elapsed > $researchMaxTime) {
                                break;
                            }

                            $allStatuses = $this->planService->getSubtaskStatuses($planId);
                            $researchStatuses = array_filter($allStatuses, fn ($s) => ($s['is_research'] ?? false));

                            foreach ($researchStatuses as $status) {
                                $prev = $lastResearchStatuses[$status['index']]['status'] ?? null;
                                if ($prev !== $status['status']) {
                                    $send('research_status', $status);
                                }
                            }
                            $lastResearchStatuses = $researchStatuses;

                            $allResearchDone = collect($researchStatuses)->every(
                                fn ($s) => in_array($s['status'], ['completed', 'failed'])
                            );
                            if ($allResearchDone) {
                                break;
                            }

                            usleep($pollInterval * 1000);
                        }
                    }
                }
            }

            // Phase 4: Synthesis
            $send('synthesis_start', [
                'completed_count' => collect($lastStatuses)->where('status', 'completed')->count(),
                'failed_count' => collect($lastStatuses)->where('status', 'failed')->count(),
            ]);

            $synthesisResult = Cache::get("plan_synthesis:{$planId}");

            if (! $synthesisResult) {
                $startTime = microtime(true);
                while (true) {
                    $synthesisResult = Cache::get("plan_synthesis:{$planId}");
                    if ($synthesisResult) {
                        break;
                    }
                    if ((microtime(true) - $startTime) > config('ai.plan_mode.synthesis_timeout', 120)) {
                        $synthesisResult = 'Synthesis timed out. Individual specialist results may be available.';
                        break;
                    }
                    usleep($pollInterval * 1000);
                }
            }

            $fullContent = StripsThinkingTags::stripStatic($synthesisResult ?? 'No results available.');

            // Stream synthesis content in chunks for smooth rendering
            $chunkSize = 8;
            $totalLen = mb_strlen($fullContent);
            for ($i = 0; $i < $totalLen; $i += $chunkSize) {
                $chunk = mb_substr($fullContent, $i, $chunkSize);
                echo 'data: '.json_encode(['delta' => $chunk])."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
                usleep(5000);
            }

            // Save final assistant message
            try {
                ChatMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => $fullContent,
                    'model' => $model ?? config('ai.plan_mode.synthesis_model', 'SMART-MODEL'),
                    'plan_id' => $planId,
                    'plan_metadata' => [
                        'phase' => 'synthesis',
                        'subtask_count' => count($planResult->subtasks),
                    ],
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to save plan mode synthesis message', ['error' => $e->getMessage()]);
            }

            // Generate title for new conversations
            if ($isNew) {
                try {
                    $title = app(\App\Services\Ai\AiChatService::class)
                        ->generateTitle($userMessage, mb_substr($fullContent, 0, 500));
                    if ($title) {
                        $conversation->update(['title' => $title]);
                    }
                } catch (\Throwable $e) {
                    Log::debug('Failed to generate plan mode conversation title', ['error' => $e->getMessage()]);
                }
            }

            // Archive memory
            try {
                app(\App\Services\Ai\ConversationMemoryService::class)->archiveConversation($conversation);
            } catch (\Throwable $e) {
                Log::debug('Failed to archive plan mode conversation memory', ['error' => $e->getMessage()]);
            }

            echo "event: metadata\ndata: ".json_encode([
                'full_content' => $fullContent,
                'model' => $model ?? config('ai.plan_mode.synthesis_model', 'SMART-MODEL'),
                'mode' => 'plan',
                'plan_id' => $planId,
            ])."\n\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();

            echo "event: done\ndata: ".json_encode([
                'conversation_id' => $conversationIdStr,
                'updated_title' => $conversation->fresh()->title,
            ])."\n\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function streamPlanResume(string $planId, array $cachedState, string $augmentedMessage): StreamedResponse
    {
        $history = $cachedState['history'];
        $personaKeys = $cachedState['personaKeys'];
        $referencedIds = $cachedState['referencedIds'];
        $model = $cachedState['model'];
        $conversationIdStr = $cachedState['conversationId'];
        $conversationDbId = $cachedState['conversationDbId'];
        $conversation = ChatConversation::findOrFail($conversationDbId);
        $pollInterval = config('ai.plan_mode.poll_interval_ms', 500);
        $maxPollTime = config('ai.plan_mode.subtask_timeout', 300) + config('ai.plan_mode.synthesis_timeout', 120);

        return new StreamedResponse(function () use (
            $augmentedMessage, $history, $personaKeys, $referencedIds,
            $planId, $conversationIdStr, $conversation,
            $pollInterval, $maxPollTime, $model
        ) {
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', false);

            $send = function (string $event, array $data) {
                echo "event: {$event}\ndata: ".json_encode($data)."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            };

            $send('setup', [
                'conversation_id' => $conversationIdStr,
                'mode' => 'plan',
                'resumed' => true,
            ]);

            // Skip clarification — already resolved. Run pre-analysis then plan.
            $send('plan_pre_analysis', ['status' => 'analyzing']);
            $preAnalysis = $this->planService->analyzeQuestionDeep($augmentedMessage, $history, $referencedIds, $model);

            if ($preAnalysis) {
                $send('plan_pre_analysis', [
                    'status' => 'complete',
                    'analysis' => [
                        'question_type' => $preAnalysis['question_type'] ?? 'general',
                        'complexity' => $preAnalysis['complexity'] ?? 'moderate',
                        'required_domains' => $preAnalysis['required_domains'] ?? [],
                        'reasoning' => $preAnalysis['reasoning'] ?? '',
                    ],
                ]);
            }

            $send('plan_thinking', ['status' => 'thinking']);

            $planResult = $this->planService->thinkAndPlan($augmentedMessage, $history, $personaKeys, $referencedIds, $model, $preAnalysis);

            if (! $planResult->success) {
                $send('plan_fallback', ['reason' => $planResult->error ?? 'Planning failed, using standard mode.']);

                $this->fallbackToStandardStream($augmentedMessage, $history, $referencedIds, $conversation, $model, $personaKeys);

                return;
            }

            // Phase 1.5: Plan Validation
            $validation = $this->planService->validatePlan($planResult->subtasks, $preAnalysis);

            // Emit reasoning transparency event
            $send('plan_reasoning', [
                'pre_analysis_reasoning' => $preAnalysis['reasoning'] ?? null,
                'planning_reasoning' => $planResult->thinkingContent,
                'question_type' => $preAnalysis['question_type'] ?? 'general',
                'complexity' => $preAnalysis['complexity'] ?? 'moderate',
                'validation_score' => $validation['score'],
            ]);

            // Phase 2: Plan Ready
            $planMetadata = [
                'plan_text' => $planResult->planText,
                'subtasks' => $planResult->subtasks,
                'thinking_content' => $planResult->thinkingContent,
                'total_tokens' => $planResult->totalTokens,
                'pre_analysis' => $preAnalysis,
                'validation' => $validation,
                'model' => $model,
                'referenced_ids' => $referencedIds,
            ];

            ChatMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $planResult->planText,
                'model' => $planResult->model,
                'tokens_used' => $planResult->totalTokens,
                'plan_id' => $planId,
                'plan_metadata' => $planMetadata,
                'is_plan_message' => true,
                'plan_role' => 'plan',
                'created_at' => now(),
            ]);

            $send('plan_ready', [
                'plan_text' => $planResult->planText,
                'subtasks' => collect($planResult->subtasks)->map(fn ($s, $i) => [
                    'index' => $i,
                    'description' => $s['description'],
                    'persona_key' => $s['persona_key'],
                    'label' => $s['label'] ?? 'General Analysis',
                    'status' => 'pending',
                ])->values()->toArray(),
            ]);

            // Phase 3: Dispatch subtasks and poll
            $subtaskRecords = $this->planService->createSubtasks($planId, $conversationIdStr, $planResult->subtasks);

            foreach ($subtaskRecords as $subtask) {
                \App\Jobs\Ai\ProcessPlanSubtask::dispatch($subtask, $augmentedMessage, $referencedIds, $model)
                    ->onQueue(config('ai.plan_mode.queue', 'war-room'));
            }

            $startTime = microtime(true);
            $lastStatuses = [];

            while (true) {
                $elapsed = (microtime(true) - $startTime);
                if ($elapsed > $maxPollTime) {
                    $send('plan_fallback', ['reason' => 'Agents timed out. Showing partial results.']);
                    break;
                }

                $statuses = $this->planService->getSubtaskStatuses($planId);
                $statusJson = json_encode($statuses);

                if ($statusJson !== json_encode($lastStatuses)) {
                    foreach ($statuses as $status) {
                        if (($status['is_research'] ?? false)) {
                            continue;
                        }
                        $prev = $lastStatuses[$status['index']]['status'] ?? null;
                        if ($prev !== $status['status']) {
                            $send('agent_status', $status);
                        }
                    }
                    $lastStatuses = $statuses;
                }

                $originalStatuses = array_filter($statuses, fn ($s) => ! ($s['is_research'] ?? false));
                $allDone = collect($originalStatuses)->every(fn ($s) => in_array($s['status'], ['completed', 'failed']));
                if ($allDone) {
                    break;
                }

                usleep($pollInterval * 1000);
            }

            // Phase 3b: Gap Analysis + Research (if enabled)
            if (config('ai.plan_mode.gap_analysis_enabled', false)) {
                $send('gap_analysis', ['status' => 'analyzing', 'coverage_score' => null]);

                $gapAnalysis = null;
                $gapStartTime = microtime(true);
                $gapTimeout = config('ai.plan_mode.gap_analysis_timeout', 30) + 30;

                while (true) {
                    $gapAnalysis = Cache::get("plan_gap_analysis:{$planId}");
                    if ($gapAnalysis !== null) {
                        break;
                    }
                    if ((microtime(true) - $gapStartTime) > $gapTimeout) {
                        break;
                    }
                    usleep($pollInterval * 1000);
                }

                if ($gapAnalysis) {
                    $coverageScore = $gapAnalysis['coverage_score'] ?? 0;
                    $gapsFound = count($gapAnalysis['gaps'] ?? []);
                    $researchNeeded = ($gapAnalysis['research_needed'] ?? false) && $gapsFound > 0;

                    $send('gap_analysis', [
                        'status' => 'complete',
                        'coverage_score' => $coverageScore,
                        'gaps_found' => $gapsFound,
                        'research_needed' => $researchNeeded,
                    ]);

                    if ($researchNeeded) {
                        $researchTopics = $gapAnalysis['gaps'] ?? [];
                        $send('research_start', [
                            'topics' => array_map(fn ($g) => $g['topic'] ?? '', $researchTopics),
                            'count' => count($researchTopics),
                        ]);

                        $researchMaxTime = config('ai.plan_mode.research_timeout', 120);
                        $researchStartTime = microtime(true);
                        $lastResearchStatuses = [];

                        while (true) {
                            $elapsed = (microtime(true) - $researchStartTime);
                            if ($elapsed > $researchMaxTime) {
                                break;
                            }

                            $allStatuses = $this->planService->getSubtaskStatuses($planId);
                            $researchStatuses = array_filter($allStatuses, fn ($s) => ($s['is_research'] ?? false));

                            foreach ($researchStatuses as $status) {
                                $prev = $lastResearchStatuses[$status['index']]['status'] ?? null;
                                if ($prev !== $status['status']) {
                                    $send('research_status', $status);
                                }
                            }
                            $lastResearchStatuses = $researchStatuses;

                            $allResearchDone = collect($researchStatuses)->every(
                                fn ($s) => in_array($s['status'], ['completed', 'failed'])
                            );
                            if ($allResearchDone) {
                                break;
                            }

                            usleep($pollInterval * 1000);
                        }
                    }
                }
            }

            // Phase 4: Synthesis
            $send('synthesis_start', [
                'completed_count' => collect($lastStatuses)->where('status', 'completed')->count(),
                'failed_count' => collect($lastStatuses)->where('status', 'failed')->count(),
            ]);

            $synthesisResult = Cache::get("plan_synthesis:{$planId}");

            if (! $synthesisResult) {
                $startTime = microtime(true);
                while (true) {
                    $synthesisResult = Cache::get("plan_synthesis:{$planId}");
                    if ($synthesisResult) {
                        break;
                    }
                    if ((microtime(true) - $startTime) > config('ai.plan_mode.synthesis_timeout', 120)) {
                        $synthesisResult = 'Synthesis timed out. Individual specialist results may be available.';
                        break;
                    }
                    usleep($pollInterval * 1000);
                }
            }

            $fullContent = StripsThinkingTags::stripStatic($synthesisResult ?? 'No results available.');

            $chunkSize = 8;
            $totalLen = mb_strlen($fullContent);
            for ($i = 0; $i < $totalLen; $i += $chunkSize) {
                $chunk = mb_substr($fullContent, $i, $chunkSize);
                echo 'data: '.json_encode(['delta' => $chunk])."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
                usleep(5000);
            }

            try {
                ChatMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => $fullContent,
                    'model' => $model ?? config('ai.plan_mode.synthesis_model', 'SMART-MODEL'),
                    'plan_id' => $planId,
                    'plan_metadata' => [
                        'phase' => 'synthesis',
                        'subtask_count' => count($planResult->subtasks),
                    ],
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to save plan mode synthesis message', ['error' => $e->getMessage()]);
            }

            try {
                app(\App\Services\Ai\ConversationMemoryService::class)->archiveConversation($conversation);
            } catch (\Throwable $e) {
                Log::debug('Failed to archive plan mode conversation memory', ['error' => $e->getMessage()]);
            }

            echo "event: metadata\ndata: ".json_encode([
                'full_content' => $fullContent,
                'model' => $model ?? config('ai.plan_mode.synthesis_model', 'SMART-MODEL'),
                'mode' => 'plan',
                'plan_id' => $planId,
            ])."\n\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();

            echo "event: done\ndata: ".json_encode([
                'conversation_id' => $conversationIdStr,
                'updated_title' => $conversation->fresh()->title,
            ])."\n\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function fallbackToStandardStream(string $userMessage, array $history, array $referencedIds, ChatConversation $conversation, ?string $model, array $personaKeys = []): void
    {
        $systemPrompt = $this->contextService->buildSystemPrompt($userMessage, $referencedIds);
        $apiMessages = [['role' => 'system', 'content' => $systemPrompt]];

        $maxHistory = config('ai.chat_max_history', 20);
        $historyMessages = array_slice($history, -$maxHistory);
        foreach ($historyMessages as $msg) {
            $apiMessages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        for ($i = count($apiMessages) - 1; $i >= 1; $i--) {
            if (($apiMessages[$i]['role'] ?? '') === 'user') {
                $apiMessages[$i]['content'] = $userMessage;
                break;
            }
        }

        $resolvedModel = $model ?? AiSetting::get('default_model', config('ai.default_model'));
        $baseUrl = rtrim(AiSetting::get('base_url', config('ai.base_url', '')), '/');
        $apiKey = AiSetting::get('api_key', config('ai.api_key', ''));
        $timeout = (int) AiSetting::get('timeout', config('ai.timeout', 60));
        $maxTokens = config('ai.chat_max_tokens', 4000);

        $fullContent = '';
        $usage = [];
        $thinkingFilter = new StripsThinkingTags;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl.'/chat/completions',
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$apiKey,
                'Content-Type: application/json',
                'Accept: text/event-stream',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $resolvedModel,
                'messages' => $apiMessages,
                'max_tokens' => $maxTokens,
                'stream' => true,
            ]),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$fullContent, &$usage, &$thinkingFilter) {
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || ! str_starts_with($line, 'data: ')) {
                        continue;
                    }
                    $json = substr($line, 6);
                    if ($json === '[DONE]') {
                        echo "data: [DONE]\n\n";
                        if (ob_get_level()) {
                            ob_flush();
                        }
                        flush();

                        return strlen($data);
                    }

                    $parsed = json_decode($json, true);
                    if (! $parsed) {
                        continue;
                    }

                    $delta = $parsed['choices'][0]['delta']['content'] ?? '';
                    if ($delta !== '') {
                        $filtered = $thinkingFilter->filter($delta);
                        $fullContent .= $filtered;
                        if ($filtered !== '') {
                            echo 'data: '.json_encode(['delta' => $filtered])."\n\n";
                            if (ob_get_level()) {
                                ob_flush();
                            }
                            flush();
                        }
                    }

                    if (isset($parsed['usage'])) {
                        $usage = $parsed['usage'];
                    }
                }

                return strlen($data);
            },
        ]);

        curl_exec($ch);
        curl_close($ch);

        if (! blank($fullContent)) {
            try {
                ChatMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => $fullContent,
                    'model' => $resolvedModel,
                    'tokens_used' => $usage['total_tokens'] ?? null,
                    'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                    'completion_tokens' => $usage['completion_tokens'] ?? null,
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to save fallback assistant message', ['error' => $e->getMessage()]);
            }
        }

        echo "event: metadata\ndata: ".json_encode([
            'full_content' => $fullContent,
            'usage' => $usage,
            'model' => $resolvedModel,
            'mode' => 'fallback',
        ])."\n\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();

        echo "event: done\ndata: ".json_encode([
            'conversation_id' => $conversation->id,
        ])."\n\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
}
