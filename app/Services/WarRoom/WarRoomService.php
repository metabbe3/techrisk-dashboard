<?php

namespace App\Services\WarRoom;

use App\Events\WarRoomAgentStreaming;
use App\Events\WarRoomMessageUpdated;
use App\Events\WarRoomReportStreaming;
use App\Events\WarRoomRoundCompleted;
use App\Events\WarRoomSessionCompleted;
use App\Jobs\WarRoom\ProcessWarRoomAgent;
use App\Jobs\WarRoom\RunPreAnalysis;
use App\Jobs\WarRoom\StartWarRoomSession;
use App\Jobs\WarRoom\SynthesizeWarRoomReport;
use App\Models\ActionImprovement;
use App\Models\AiSetting;
use App\Models\Incident;
use App\Models\User;
use App\Models\WarRoomAgentConfig;
use App\Models\WarRoomMessage;
use App\Models\WarRoomSession;
use App\Services\Ai\AiUsageLogger;
use App\Services\Ai\Concerns\InteractsWithAiApi;
use App\Services\Ai\ModelRouter;
use App\Services\Ai\SearchPlanningService;
use App\Services\Ai\TokenEstimator;
use App\Services\Ai\ToolRegistryService;
use App\Services\Ai\WebSearchService;
use App\Services\IncidentFormatter;
use App\Services\Markdown\IncidentMarkdownExporter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WarRoomService
{
    use InteractsWithAiApi;

    /** Set by compressContext(); read when persisting context_summarized. */
    private bool $contextWasCompressed = false;

    public function __construct(
        private IncidentMarkdownExporter $markdownExporter,
        private AgentPromptBuilder $promptBuilder,
        private WebSearchService $webSearchService,
        private AiUsageLogger $usageLogger,
        private WarRoomStreamingService $streamingService,
    ) {}

    public function createSession(
        array $incidentIds,
        User $user,
        array $selectedAgents,
        int $maxRounds = 2,
        ?string $model = null,
        ?string $moderatorModel = null,
        bool $enableWebSearch = false,
        bool $deepAnalysis = true,
        ?string $userInstructions = null,
    ): WarRoomSession {
        $incidents = Incident::whereIn('id', $incidentIds)->orderBy('incident_date', 'desc')->get();

        if ($incidents->isEmpty()) {
            throw new \InvalidArgumentException('No valid incidents found for the provided IDs.');
        }

        $this->enforceBudgetLimits($user);

        $primaryIncident = $incidents->first();
        $context = $this->buildIncidentContext($incidents, $deepAnalysis);
        $title = $this->buildSessionTitle($incidents);
        $resolvedModel = $this->resolveModel($model);
        $compressedContext = $this->compressContext($context, $resolvedModel, $incidents);

        $session = WarRoomSession::create([
            'user_id' => $user->id,
            'incident_id' => $primaryIncident->id,
            'title' => $title,
            'status' => 'pending',
            'max_rounds' => $maxRounds,
            'model' => $resolvedModel,
            'moderator_model' => app(ModelRouter::class)->pick('reasoning', $moderatorModel ?? config('ai.war_room.moderator_model') ?? $resolvedModel),
            'enable_web_search' => $enableWebSearch,
            'deep_analysis' => $deepAnalysis,
            'selected_agents' => $selectedAgents,
            'incident_context' => $compressedContext,
            'context_summarized' => $this->contextWasCompressed,
            'user_instructions' => $userInstructions,
        ]);

        $session->incidents()->sync($incidents->pluck('id')->toArray());

        StartWarRoomSession::dispatch($session);

        return $session;
    }

    public function reanalyzeSession(
        WarRoomSession $session,
        ?string $userInstructions = null,
        ?string $model = null,
        ?string $moderatorModel = null,
        ?array $selectedAgents = null,
        ?bool $deepAnalysis = null,
    ): WarRoomSession {
        if (! in_array($session->status, ['completed', 'failed'])) {
            throw new \InvalidArgumentException('Only completed or failed sessions can be re-analyzed.');
        }

        $deepAnalysis = $deepAnalysis ?? $session->deep_analysis;

        // Reset any stuck running messages from previous attempt
        $session->messages()->where('status', 'running')->update([
            'status' => 'failed',
            'error_message' => 'Superseded by re-analysis',
        ]);

        $incidents = $session->incidents()->get();
        $context = $this->buildIncidentContext($incidents, $deepAnalysis);
        $title = $this->buildSessionTitle($incidents);

        $session->messages()->delete();

        $resolvedModel = $model ?? $session->model;
        $compressedContext = $this->compressContext($context, $resolvedModel, $incidents);

        $update = [
            'status' => 'pending',
            'current_round' => 0,
            'incident_context' => $compressedContext,
            'context_summarized' => $this->contextWasCompressed,
            'pre_analysis' => null,
            'user_instructions' => $userInstructions ?? $session->user_instructions,
            'final_report' => null,
            'final_report_html' => null,
            'started_at' => null,
            'completed_at' => null,
            'failed_at' => null,
            'error_message' => null,
            'tokens_used' => 0,
            'deep_analysis' => $deepAnalysis,
            'selected_skills' => null,
            'title' => $title,
        ];

        if ($model !== null) {
            $update['model'] = $model;
        }
        if ($moderatorModel !== null) {
            $update['moderator_model'] = $moderatorModel;
        }
        if ($selectedAgents !== null) {
            $update['selected_agents'] = $selectedAgents;
        }

        $session->update($update);
        $session->refresh();

        StartWarRoomSession::dispatch($session);

        return $session;
    }

    public function startSession(WarRoomSession $session): void
    {
        $session->markRunning();

        app(\App\Services\Skills\SkillRoutingService::class)->selectSkillsForSession($session);

        if (config('ai.war_room.pre_analysis_enabled', true)) {
            RunPreAnalysis::dispatch($session);
        } else {
            $this->dispatchRound($session, 1);
        }
    }

    public function dispatchRound(WarRoomSession $session, int $round): void
    {
        $agents = $session->getAgentRoles();

        foreach ($agents as $role) {
            $message = WarRoomMessage::create([
                'session_id' => $session->id,
                'round' => $round,
                'agent_role' => $role,
                'role' => 'assistant',
                'status' => 'pending',
                'created_at' => now(),
            ]);

            ProcessWarRoomAgent::dispatch($session, $role, $round);
        }
    }

    public function onAgentCompleted(WarRoomSession $session, WarRoomMessage $message): void
    {
        $lock = cache()->lock("warroom:round_complete:{$session->id}:{$message->round}", 30);

        try {
            $lock->block(10, function () use ($session, $message) {
                $session = $session->fresh();

                $pendingCount = WarRoomMessage::where('session_id', $session->id)
                    ->where('round', $message->round)
                    ->whereIn('status', ['pending', 'running'])
                    ->count();

                if ($pendingCount > 0) {
                    return;
                }

                $completedCount = WarRoomMessage::where('session_id', $session->id)
                    ->where('round', $message->round)
                    ->where('status', 'completed')
                    ->count();

                if ($completedCount === 0) {
                    $session->markFailed('All agents failed in round '.$message->round);

                    broadcast(new WarRoomSessionCompleted($session->fresh()));

                    return;
                }

                broadcast(new WarRoomRoundCompleted($session->fresh(), $message->round));

                if ($message->round < $session->max_rounds) {
                    $session->advanceRound();
                    $this->dispatchRound($session, $message->round + 1);
                } else {
                    SynthesizeWarRoomReport::dispatch($session);
                }
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeout $e) {
            Log::warning('[WarRoom] Could not acquire round completion lock', [
                'session_id' => $session->id,
                'round' => $message->round,
            ]);
        }
    }

    public function processAgent(WarRoomSession $session, string $agentRole, int $round): void
    {
        $session->loadMissing('incidents');

        $message = WarRoomMessage::where('session_id', $session->id)
            ->where('agent_role', $agentRole)
            ->where('round', $round)
            ->firstOrFail();

        $message->markRunning();

        broadcast(new WarRoomMessageUpdated($session, $message));

        $config = WarRoomAgentConfig::findByRole($agentRole);
        $model = app(ModelRouter::class)->pick('reasoning', $config?->model_override ?? $session->model);

        $systemPrompt = $this->promptBuilder->buildAgentPrompt($agentRole, $session);
        $userMessage = $this->promptBuilder->buildRoundUserMessage($session, $agentRole, $round);

        if ($session->enable_web_search && ($config?->enable_web_search ?? false)) {
            $searchContext = $this->performWebSearch($session, $agentRole);
            if ($searchContext) {
                $userMessage .= "\n\n## Web Search Results\n\n{$searchContext}";
                $message->update(['web_search_context' => $searchContext]);
            }
        }

        $maxTokens = (int) config('ai.war_room.max_output_tokens', 16384);
        $maxContinuations = (int) config('ai.war_room.max_continuations', 3);

        $startTime = microtime(true);

        try {
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ];

            // Build tool definitions if agent has them enabled
            $toolRegistryService = app(ToolRegistryService::class);
            $enabledTools = $config?->enabled_tools;
            $tools = $enabledTools ? $toolRegistryService->getToolDefinitions($enabledTools) : [];
            $toolCallLog = [];

            $fullContent = '';
            $totalUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
            $finalFinishReason = 'unknown';
            $reasoningContent = null;
            $reasoningTokens = null;
            $toolIterations = 0;
            $maxToolIterations = 5;

            $done = false;
            for ($toolIteration = 0; $toolIteration <= $maxToolIterations && ! $done; $toolIteration++) {
                $iterationHasToolCalls = false;
                $iterationContent = '';

                for ($attempt = 0; $attempt <= $maxContinuations && ! $done; $attempt++) {
                    Log::info("[WarRoom] Agent {$agentRole} prompt size", [
                        'session_id' => $session->id,
                        'round' => $round,
                        'estimated_prompt_tokens' => $this->estimateTokens(
                            collect($messages)->map(fn ($m) => $m['content'] ?? '')->implode("\n")
                        ),
                        'message_count' => count($messages),
                        'tool_iteration' => $toolIteration,
                        'attempt' => $attempt,
                    ]);

                    // Throttled streaming callback
                    $lastBroadcastTime = microtime(true);
                    $accumulatedDelta = '';

                    $streamResult = $this->streamingService->streamCompletion(
                        model: $model,
                        messages: $messages,
                        maxTokens: $maxTokens,
                        tools: $tools,
                        onDelta: function (string $delta, int $contentLength) use (
                            &$lastBroadcastTime, &$accumulatedDelta,
                            $session, $message, $agentRole
                        ) {
                            $accumulatedDelta .= $delta;
                            $elapsed = (microtime(true) - $lastBroadcastTime) * 1000;

                            if (strlen($accumulatedDelta) >= 100 || $elapsed >= 500) {
                                $message->appendContent($accumulatedDelta);
                                broadcast(new WarRoomAgentStreaming(
                                    sessionId: (string) $session->id,
                                    messageId: (string) $message->id,
                                    agentRole: $agentRole,
                                    delta: $accumulatedDelta,
                                    contentLength: $contentLength,
                                ));
                                $accumulatedDelta = '';
                                $lastBroadcastTime = microtime(true);
                            }
                        },
                    );

                    // Flush remaining delta
                    if (! blank($accumulatedDelta)) {
                        $message->appendContent($accumulatedDelta);
                        broadcast(new WarRoomAgentStreaming(
                            sessionId: (string) $session->id,
                            messageId: (string) $message->id,
                            agentRole: $agentRole,
                            delta: $accumulatedDelta,
                            contentLength: strlen($streamResult['content']),
                        ));
                    }

                    $finishReason = $streamResult['finish_reason'];
                    $finalFinishReason = $finishReason;
                    $usage = $streamResult['usage'];

                    foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
                        $totalUsage[$key] += $usage[$key] ?? 0;
                    }

                    if ($streamResult['error'] !== null) {
                        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
                        Log::warning("[WarRoom] Agent {$agentRole} failed", [
                            'session_id' => $session->id,
                            'tool_iteration' => $toolIteration,
                            'attempt' => $attempt,
                            'error' => $streamResult['error'],
                        ]);
                        $message->markFailed($streamResult['error']);
                        $this->logUsage('war_room_agent', $model, false, $totalUsage, $responseTimeMs, $session, $agentRole, $round, $streamResult['error']);
                        $fullContent = '';
                        $done = true;

                        break;
                    }

                    $chunk = $streamResult['content'];
                    $toolCalls = $streamResult['tool_calls'];

                    if ($toolIteration === 0 && $attempt === 0) {
                        $reasoningContent = $streamResult['reasoning_content'];
                        $reasoningTokens = $streamResult['reasoning_tokens'];
                    }

                    // Handle tool calls — execute and loop back for another iteration
                    if (! empty($toolCalls)) {
                        $iterationHasToolCalls = true;

                        if (! blank($chunk)) {
                            $iterationContent .= $chunk;
                        }

                        $assistantMessage = ['role' => 'assistant'];
                        if (! blank($chunk)) {
                            $assistantMessage['content'] = $chunk;
                        }
                        $assistantMessage['tool_calls'] = $toolCalls;
                        $messages[] = $assistantMessage;

                        foreach ($toolCalls as $toolCall) {
                            $toolResult = $toolRegistryService->executeToolCall($toolCall);
                            $messages[] = $toolResult;
                            $toolCallLog[] = [
                                'iteration' => $toolIteration,
                                'name' => $toolCall['function']['name'] ?? 'unknown',
                                'arguments' => $toolCall['function']['arguments'] ?? '{}',
                                'result_length' => strlen($toolResult['content'] ?? ''),
                                'call_id' => $toolCall['id'] ?? '',
                            ];
                        }

                        Log::info("[WarRoom] Agent {$agentRole} tool iteration {$toolIteration}", [
                            'session_id' => $session->id,
                            'round' => $round,
                            'tool_count' => count($toolCalls),
                            'tools' => array_map(fn ($tc) => $tc['function']['name'] ?? 'unknown', $toolCalls),
                        ]);

                        break;
                    }

                    // Handle completely empty response
                    if (blank($chunk) && $attempt === 0 && $toolIteration === 0) {
                        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
                        $message->markFailed('AI returned empty response');
                        $this->logUsage('war_room_agent', $model, false, $totalUsage, $responseTimeMs, $session, $agentRole, $round, 'Empty response');
                        $fullContent = '';
                        $done = true;

                        break;
                    }

                    $iterationContent .= $chunk;

                    // Detect truncation
                    $trimmedChunk = rtrim($chunk);
                    $looksTruncated = ! preg_match('/[.!?)`\n]$/', $trimmedChunk) && strlen($trimmedChunk) > 100;

                    if ($finishReason !== 'length' && ! $looksTruncated) {
                        $done = true;

                        break;
                    }

                    // Truncated — send continuation request
                    Log::info("[WarRoom] Agent {$agentRole} continuation {$attempt}", [
                        'session_id' => $session->id,
                        'round' => $round,
                        'tool_iteration' => $toolIteration,
                        'finish_reason' => $finishReason,
                        'looks_truncated' => $looksTruncated,
                        'completion_tokens_so_far' => $totalUsage['completion_tokens'],
                        'content_length_so_far' => strlen($iterationContent),
                    ]);

                    $messages[] = ['role' => 'assistant', 'content' => $chunk];
                    $messages[] = ['role' => 'user', 'content' => 'Continue your analysis from exactly where you left off. Do not repeat what you already wrote.'];
                }

                $fullContent .= $iterationContent;

                if (! $iterationHasToolCalls) {
                    break; // No tool calls — we're done
                }
            }

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            Log::info("[WarRoom] Agent {$agentRole} final", [
                'session_id' => $session->id,
                'round' => $round,
                'finish_reason' => $finalFinishReason,
                'model' => $model,
                'continuations' => $finalFinishReason === 'length' ? $maxContinuations : 0,
                'total_completion_tokens' => $totalUsage['completion_tokens'],
                'total_prompt_tokens' => $totalUsage['prompt_tokens'],
                'content_length' => strlen($fullContent),
                'response_time_ms' => $responseTimeMs,
                'tool_calls_count' => count($toolCallLog),
            ]);

            if (! blank($fullContent)) {
                $metadata = array_merge($message->metadata ?? [], [
                    'finish_reason' => $finalFinishReason,
                    'model' => $model,
                    'total_completion_tokens' => $totalUsage['completion_tokens'],
                    'tool_iterations' => count($toolCallLog) > 0 ? count(array_unique(array_column($toolCallLog, 'iteration'))) + 1 : 0,
                ]);

                if ($reasoningContent) {
                    $metadata['reasoning_content'] = $reasoningContent;
                }
                if ($reasoningTokens) {
                    $metadata['reasoning_tokens'] = $reasoningTokens;
                }
                if (! empty($toolCallLog)) {
                    $metadata['tool_calls_log'] = $toolCallLog;
                }

                $message->markCompleted($fullContent, $totalUsage, $responseTimeMs, $metadata);

                if ($totalUsage['total_tokens'] > 0) {
                    $session->addTokens($totalUsage['total_tokens']);
                }

                $this->enforceSessionTokenBudget($session);

                $this->logUsage('war_room_agent', $model, true, $totalUsage, $responseTimeMs, $session, $agentRole, $round);
            } else {
                $existingContent = $message->content ?? '';
                if (! blank($existingContent)) {
                    $metadata = array_merge($message->metadata ?? [], [
                        'finish_reason' => $finalFinishReason,
                        'model' => $model,
                    ]);
                    $message->markCompleted($existingContent, $totalUsage, $responseTimeMs, $metadata);
                } else {
                    $message->markFailed('Agent returned empty response after processing');
                    $this->logUsage('war_room_agent', $model, false, $totalUsage, $responseTimeMs, $session, $agentRole, $round, 'Empty response');
                }
            }
        } catch (ConnectionException $e) {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $message->markFailed('Connection failed: '.$e->getMessage());
            $this->logUsage('war_room_agent', $model, false, [], $responseTimeMs, $session, $agentRole, $round, $e->getMessage());
        } catch (\Throwable $e) {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $message->markFailed('Unexpected error: '.$e->getMessage());
            $this->logUsage('war_room_agent', $model, false, [], $responseTimeMs, $session, $agentRole, $round, $e->getMessage());
        }

        broadcast(new WarRoomMessageUpdated($session->fresh(), $message->fresh()));

        try {
            $this->onAgentCompleted($session->fresh(), $message->fresh());
        } catch (\Throwable $e) {
            Log::error('[WarRoom] onAgentCompleted failed', [
                'session_id' => $session->id,
                'agent_role' => $agentRole,
                'round' => $round,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function synthesizeReport(WarRoomSession $session): void
    {
        $model = app(ModelRouter::class)->pick('reasoning', $session->moderator_model);
        $systemPrompt = $this->promptBuilder->buildModeratorPrompt();
        $userMessage = $this->promptBuilder->buildModeratorUserMessage($session);

        $maxTokens = (int) config('ai.war_room.max_output_tokens', 16384);
        $maxContinuations = (int) config('ai.war_room.max_continuations', 3);

        $startTime = microtime(true);

        try {
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ];

            $fullContent = '';
            $totalUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
            $modReasoningContent = null;
            $modReasoningTokens = null;

            for ($attempt = 0; $attempt <= $maxContinuations; $attempt++) {
                $lastBroadcastTime = microtime(true);
                $accumulatedDelta = '';

                $streamResult = $this->streamingService->streamCompletion(
                    model: $model,
                    messages: $messages,
                    maxTokens: $maxTokens,
                    onDelta: function (string $delta, int $contentLength) use (
                        &$lastBroadcastTime, &$accumulatedDelta, $session
                    ) {
                        $accumulatedDelta .= $delta;
                        $elapsed = (microtime(true) - $lastBroadcastTime) * 1000;

                        if (strlen($accumulatedDelta) >= 100 || $elapsed >= 500) {
                            broadcast(new WarRoomReportStreaming(
                                sessionId: (string) $session->id,
                                delta: $accumulatedDelta,
                                contentLength: $contentLength,
                            ));
                            $accumulatedDelta = '';
                            $lastBroadcastTime = microtime(true);
                        }
                    },
                );

                // Flush remaining delta
                if (! blank($accumulatedDelta)) {
                    broadcast(new WarRoomReportStreaming(
                        sessionId: (string) $session->id,
                        delta: $accumulatedDelta,
                        contentLength: strlen($streamResult['content']),
                    ));
                }

                $usage = $streamResult['usage'] ?? [];
                $finishReason = $streamResult['finish_reason'] ?? 'unknown';
                $chunk = $streamResult['content'] ?? '';

                if ($attempt === 0) {
                    $modReasoningContent = $streamResult['reasoning_content'] ?? null;
                    $modReasoningTokens = $streamResult['reasoning_tokens'] ?? null;
                }

                foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
                    $totalUsage[$key] += $usage[$key] ?? 0;
                }

                if ($streamResult['error'] !== null) {
                    $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
                    $session->markFailed('Report synthesis failed: '.$streamResult['error']);
                    $this->logUsage('war_room_moderator', $model, false, $totalUsage, $responseTimeMs, $session, 'moderator', 0, $streamResult['error']);
                    broadcast(new WarRoomSessionCompleted($session->fresh()));

                    return;
                }

                if (blank($chunk) && $attempt === 0) {
                    $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
                    $session->markFailed('Report synthesis returned empty response');
                    $this->logUsage('war_room_moderator', $model, false, $totalUsage, $responseTimeMs, $session, 'moderator', 0, 'Empty response');
                    broadcast(new WarRoomSessionCompleted($session->fresh()));

                    return;
                }

                $fullContent .= $chunk;

                $trimmedChunk = rtrim($chunk);
                $looksTruncated = ! preg_match('/[.!?)`\n]$/', $trimmedChunk) && strlen($trimmedChunk) > 100;

                if ($finishReason !== 'length' && ! $looksTruncated) {
                    break;
                }

                Log::info("[WarRoom] Moderator continuation {$attempt}", [
                    'session_id' => $session->id,
                    'finish_reason' => $finishReason,
                    'looks_truncated' => $looksTruncated,
                    'content_length_so_far' => strlen($fullContent),
                ]);

                $messages[] = ['role' => 'assistant', 'content' => $chunk];
                $messages[] = ['role' => 'user', 'content' => 'Continue the report from exactly where you left off. Do not repeat what you already wrote.'];
            }

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            Log::info('[WarRoom] Moderator final', [
                'session_id' => $session->id,
                'total_completion_tokens' => $totalUsage['completion_tokens'],
                'content_length' => strlen($fullContent),
                'response_time_ms' => $responseTimeMs,
            ]);

            if (blank($fullContent)) {
                $session->markFailed('Report synthesis returned empty content');
                broadcast(new WarRoomSessionCompleted($session->fresh()));

                return;
            }

            if ($totalUsage['total_tokens'] > 0) {
                $session->addTokens($totalUsage['total_tokens']);
            }

            $report = $this->parseReport($fullContent);

            $session->update([
                'final_report' => $report,
                'final_report_html' => $fullContent,
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            broadcast(new WarRoomSessionCompleted($session->fresh()));

            $this->logUsage('war_room_moderator', $model, true, $totalUsage, $responseTimeMs, $session, 'moderator', 0);
        } catch (\Throwable $e) {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $session->markFailed('Report synthesis error: '.$e->getMessage());
            $this->logUsage('war_room_moderator', $model, false, [], $responseTimeMs, $session, 'moderator', 0, $e->getMessage());

            broadcast(new WarRoomSessionCompleted($session->fresh()));
        }
    }

    public function retryFailedAgent(WarRoomMessage $message): void
    {
        $session = $message->session;

        $message->update([
            'status' => 'pending',
            'error_message' => null,
            'retry_count' => $message->retry_count + 1,
        ]);

        if ($session->status === 'failed') {
            $session->update(['status' => 'running', 'error_message' => null]);
        }

        ProcessWarRoomAgent::dispatch($session, $message->agent_role, $message->round);
    }

    public function retryReportSynthesis(WarRoomSession $session): void
    {
        if ($session->status !== 'failed') {
            return;
        }

        $session->update([
            'status' => 'running',
            'error_message' => null,
            'failed_at' => null,
        ]);

        SynthesizeWarRoomReport::dispatch($session);
    }

    public function regenerateReport(WarRoomSession $session): void
    {
        $hasCompleted = WarRoomMessage::where('session_id', $session->id)
            ->where('status', 'completed')
            ->exists();

        if (! $hasCompleted) {
            throw new \InvalidArgumentException('No completed agent data to generate report from.');
        }

        $session->update([
            'status' => 'running',
            'error_message' => null,
            'failed_at' => null,
            'completed_at' => null,
            'final_report' => null,
            'final_report_html' => null,
        ]);

        SynthesizeWarRoomReport::dispatch($session);
    }

    public function markStuckMessages(WarRoomSession $session): int
    {
        if ($session->status !== 'running') {
            return 0;
        }

        $runningTimeout = (int) config('ai.war_room.agent_timeout', 600);
        $pendingTimeout = 120;

        // Re-dispatch pending messages whose jobs were lost (never started)
        $stuckPending = WarRoomMessage::where('session_id', $session->id)
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subSeconds($pendingTimeout))
            ->get();

        foreach ($stuckPending as $message) {
            Log::info("[WarRoom] Re-dispatching stuck pending agent {$message->agent_role}", [
                'session_id' => $session->id,
                'round' => $message->round,
                'age_seconds' => now()->diffInSeconds($message->created_at),
            ]);
            ProcessWarRoomAgent::dispatch($session, $message->agent_role, $message->round);
        }

        // Mark running messages that exceeded the timeout as failed
        $stuckRunning = WarRoomMessage::where('session_id', $session->id)
            ->where('status', 'running')
            ->where('created_at', '<', now()->subSeconds($runningTimeout))
            ->get();

        foreach ($stuckRunning as $message) {
            $message->markFailed("Agent timed out after {$runningTimeout} seconds");
            broadcast(new WarRoomMessageUpdated($session, $message));
        }

        $allStuck = $stuckPending->merge($stuckRunning);
        if ($allStuck->isNotEmpty()) {
            // Process each round that has stuck messages
            $stuckByRound = $allStuck->groupBy('round');
            foreach ($stuckByRound as $round => $roundStuck) {
                $this->onAgentCompleted($session, $roundStuck->first());
            }
        }

        return $allStuck->count();
    }

    public function getSessionData(WarRoomSession $session): array
    {
        $session->load('messages', 'user', 'incidents');

        $agentConfigs = WarRoomAgentConfig::allCached();

        $messagesByRound = $session->messages->groupBy('round')->map(function ($roundMessages) use ($agentConfigs) {
            return $roundMessages->map(function ($msg) use ($agentConfigs) {
                $config = $agentConfigs->get($msg->agent_role);

                return [
                    'id' => $msg->id,
                    'round' => $msg->round,
                    'agent_role' => $msg->agent_role,
                    'agent_name' => $config?->display_name ?? ucfirst($msg->agent_role),
                    'agent_icon' => $config?->icon,
                    'agent_color' => $config?->color,
                    'role' => $msg->role,
                    'content' => $msg->content,
                    'model' => $msg->model,
                    'status' => $msg->status,
                    'response_time_ms' => $msg->response_time_ms,
                    'total_tokens' => $msg->total_tokens,
                    'error_message' => $msg->error_message,
                    'reasoning_content' => $msg->metadata['reasoning_content'] ?? null,
                    'reasoning_tokens' => $msg->metadata['reasoning_tokens'] ?? null,
                    'tool_calls' => $msg->metadata['tool_calls_log'] ?? [],
                    'web_search_context' => $msg->web_search_context,
                    'created_at' => $msg->created_at?->toIso8601String(),
                ];
            })->values();
        });

        $incidentList = $session->incidents->map(fn ($inc) => [
            'id' => $inc->id,
            'no' => $inc->no,
            'title' => $inc->title,
            'severity' => $inc->severity,
            'status' => $inc->incident_status,
        ]);

        $primaryIncident = $session->incidents->first();

        return [
            'id' => $session->id,
            'incident_id' => $session->incident_id,
            'incident' => $primaryIncident ? [
                'id' => $primaryIncident->id,
                'no' => $primaryIncident->no,
                'title' => $primaryIncident->title,
                'severity' => $primaryIncident->severity,
                'status' => $primaryIncident->incident_status,
            ] : null,
            'incidents' => $incidentList,
            'user_name' => $session->user?->name,
            'title' => $session->title,
            'status' => $session->status,
            'current_round' => $session->current_round,
            'max_rounds' => $session->max_rounds,
            'model' => $session->model,
            'moderator_model' => $session->moderator_model,
            'enable_web_search' => $session->enable_web_search,
            'context_summarized' => $session->context_summarized,
            'deep_analysis' => $session->deep_analysis,
            'selected_agents' => $session->selected_agents,
            'user_instructions' => $session->user_instructions,
            'tokens_used' => $session->tokens_used,
            'started_at' => $session->started_at?->toIso8601String(),
            'completed_at' => $session->completed_at?->toIso8601String(),
            'failed_at' => $session->failed_at?->toIso8601String(),
            'error_message' => $session->error_message,
            'pre_analysis' => $session->pre_analysis,
            'messages' => $messagesByRound,
            'final_report' => $session->final_report,
            'final_report_html' => $session->final_report_html,
            'created_at' => $session->created_at?->toIso8601String(),
        ];
    }

    private function performWebSearch(WarRoomSession $session, string $agentRole): ?string
    {
        try {
            $incident = $session->incident;

            // Build incident context for planning
            $incidentContext = [];
            if ($incident) {
                $incidentContext[] = [
                    'root_cause_categories' => $incident->root_cause_category ? (array) $incident->root_cause_category : [],
                    'safe_title_words' => IncidentFormatter::extractSafeTitleWords($incident->title ?? ''),
                    'labels' => $incident->labels->pluck('name')->toArray(),
                    'technical_keywords' => IncidentFormatter::extractTechnicalKeywords(($incident->summary ?? '').'. '.($incident->root_cause ?? '')),
                ];
            }

            // Use AI planning for multi-angle search
            $planner = app(SearchPlanningService::class);
            $baseQuery = ($incident->title ?? $incident->summary ?? 'incident').' troubleshooting root cause analysis';
            $plan = $planner->planSearches($baseQuery, $incidentContext);

            if (! $plan->isEmpty()) {
                $results = $this->webSearchService->searchMulti($plan->getQueries(), $incidentContext);
            } else {
                // Fallback to simple search
                $results = $this->webSearchService->search($baseQuery);
            }

            // Apply relevance filtering
            if (! empty($results['results'])) {
                $results['results'] = $this->webSearchService->filterRelevantResults(
                    $results['results'],
                    $baseQuery,
                    $incidentContext
                );
            }

            if (! empty($results['context'])) {
                return $results['context'];
            }

            return is_string($results) ? $results : null;
        } catch (\Throwable $e) {
            Log::warning("[WarRoom] Web search failed for agent {$agentRole}", ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function parseReport(string $content): array
    {
        $sections = [
            'root_cause_analysis' => $this->extractSection($content, 'Root Cause Analysis'),
            'summary' => $this->extractSection($content, 'Summary'),
            'why_it_happened' => $this->extractSection($content, 'Why It Happened'),
            'how_to_handle' => $this->extractSection($content, 'How to Handle It'),
            'prevention_strategy' => $this->extractSection($content, 'Prevention Strategy'),
            'improvement_recommendations' => $this->extractSection($content, 'Improvement Recommendations'),
        ];

        return $sections;
    }

    private function extractSection(string $content, string $sectionTitle): string
    {
        // Tolerate ## or ### headings and case-insensitive titles so minor model
        // variation (e.g. "Root-Cause Analysis", "### Summary") doesn't blank a
        // section. The raw markdown is always preserved in final_report_html.
        $pattern = '/^#{2,3}\s+'.preg_quote($sectionTitle, '/').'\s*\n(.*?)(?=^#{2,3}\s+|\z)/imsu';

        if (preg_match($pattern, $content, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    private function extractReasoning(array $responseData): array
    {
        $msgData = $responseData['choices'][0]['message'] ?? [];

        return [
            'content' => $msgData['reasoning_content'] ?? $msgData['thinking'] ?? null,
            'tokens' => $responseData['usage']['completion_tokens_details']['reasoning_tokens']
                ?? $responseData['usage']['reasoning_tokens']
                ?? null,
        ];
    }

    protected function getTimeout(): int
    {
        return (int) AiSetting::get('war_room_agent_timeout',
            config('ai.war_room.agent_timeout', 600));
    }

    private function estimateTokens(string $text): int
    {
        return TokenEstimator::estimate($text);
    }

    private function getModelInputLimit(string $model): int
    {
        $limits = config('ai.war_room.model_limits', []);

        return $limits[$model]['input'] ?? config('ai.war_room.default_input_limit', 32000);
    }

    private function compressContext(array $context, string $model, $incidents): array
    {
        $inputLimit = $this->getModelInputLimit($model);
        $targetTokens = (int) ($inputLimit * config('ai.war_room.context_compression_threshold', 0.50));
        $contextText = implode("\n", $context);
        $estimated = $this->estimateTokens($contextText);

        if ($estimated <= $targetTokens) {
            $this->contextWasCompressed = false;

            return $context;
        }

        $this->contextWasCompressed = true;

        Log::info('[WarRoom] Context exceeds model limit, compressing', [
            'model' => $model,
            'estimated_tokens' => $estimated,
            'input_limit' => $inputLimit,
            'target_tokens' => $targetTokens,
        ]);

        // Level 1: Strip investigation doc AI summaries (truncate to 200 chars)
        $context = array_map(function (string $line) {
            return preg_replace_callback(
                '/\*\*AI Summary:\*\*\n(.+)/s',
                fn ($m) => '**AI Summary:** '.Str::limit($m[1], 200),
                $line
            );
        }, $context);

        $estimated = $this->estimateTokens(implode("\n", $context));
        if ($estimated <= $targetTokens) {
            return $context;
        }

        // Level 2: Strip investigation docs and evidence sections entirely
        $context = array_map(function (string $markdown) {
            $markdown = preg_replace('/\n## Investigation Documents\n.*/s', '', $markdown);
            $markdown = preg_replace('/\n## Evidence\n.*/s', '', $markdown);

            return $markdown;
        }, $context);

        $estimated = $this->estimateTokens(implode("\n", $context));
        if ($estimated <= $targetTokens) {
            return $context;
        }

        // Level 3: Use generateMinimal() for each incident
        $minimalContext = [];
        foreach ($incidents as $inc) {
            $minimalContext[] = "--- Incident: {$inc->no} ({$inc->severity->value}) ---";
            $minimalContext[] = $this->markdownExporter->generateMinimal($inc);
        }

        $estimated = $this->estimateTokens(implode("\n", $minimalContext));
        Log::info('[WarRoom] Context compressed (minimal)', [
            'model' => $model,
            'estimated_tokens' => $estimated,
            'original_tokens' => $this->estimateTokens($contextText),
        ]);

        return $minimalContext;
    }

    private function logUsage(
        string $fieldType,
        ?string $model,
        bool $success,
        array $usage,
        int $responseTimeMs,
        WarRoomSession $session,
        string $agentRole,
        int $round,
        ?string $errorMessage = null,
    ): void {
        $this->usageLogger->log(
            fieldType: $fieldType,
            model: $model,
            success: $success,
            usage: $usage,
            responseTimeMs: $responseTimeMs,
            errorMessage: $errorMessage,
            metadata: [
                'session_id' => $session->id,
                'agent_role' => $agentRole,
                'round' => $round,
            ],
        );
    }

    private function enforceBudgetLimits(User $user): void
    {
        $limits = config('ai.war_room.rate_limits');

        $todaySessions = WarRoomSession::where('user_id', $user->id)
            ->whereDate('created_at', today())
            ->count();

        if ($todaySessions >= ($limits['max_sessions_per_user_per_day'] ?? 10)) {
            throw new \RuntimeException(
                "Daily session limit reached ({$limits['max_sessions_per_user_per_day']} sessions per day)."
            );
        }

        $activeSessions = WarRoomSession::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'running'])
            ->count();

        if ($activeSessions >= ($limits['max_active_sessions_per_user'] ?? 3)) {
            throw new \RuntimeException(
                "Active session limit reached ({$limits['max_active_sessions_per_user']} concurrent sessions)."
            );
        }

        $todayTokens = (int) WarRoomSession::where('user_id', $user->id)
            ->whereDate('created_at', today())
            ->sum('tokens_used');

        if ($todayTokens >= ($limits['max_daily_tokens_per_user'] ?? 500000)) {
            throw new \RuntimeException(
                'Daily token budget exhausted ('.number_format($limits['max_daily_tokens_per_user']).' tokens per day).'
            );
        }
    }

    private function enforceSessionTokenBudget(WarRoomSession $session): void
    {
        $maxTokens = config('ai.war_room.rate_limits.max_total_tokens_per_session', 200000);

        $session->refresh();

        if ($session->tokens_used < $maxTokens) {
            return;
        }

        Log::warning('[WarRoom] Session exceeded token budget', [
            'session_id' => $session->id,
            'tokens_used' => $session->tokens_used,
            'max_tokens' => $maxTokens,
        ]);

        WarRoomMessage::where('session_id', $session->id)
            ->whereIn('status', ['pending', 'running'])
            ->each(function (WarRoomMessage $msg) {
                $msg->markFailed('Session token budget exceeded');
                broadcast(new WarRoomMessageUpdated($session->fresh(), $msg->fresh()));
            });

        $session->markFailed('Session token budget exceeded ('.number_format($maxTokens).' tokens).');
        broadcast(new WarRoomSessionCompleted($session->fresh()));
    }

    private function buildIncidentContext(Collection $incidents, bool $deepAnalysis): array
    {
        $generateMethod = $deepAnalysis ? 'generateCompact' : 'generateMinimal';

        if ($incidents->count() <= 1) {
            $incident = $incidents->first();
            $markdown = $incident ? $this->markdownExporter->$generateMethod($incident) : '';

            return [$markdown];
        }

        $context = [];
        foreach ($incidents as $inc) {
            $context[] = "--- Incident: {$inc->no} ({$inc->severity->value}) ---";
            $context[] = $this->markdownExporter->$generateMethod($inc);
        }

        return $context;
    }

    private function buildSessionTitle(Collection $incidents): string
    {
        if ($incidents->count() <= 1) {
            $incident = $incidents->first();

            return "AI Retrospective: {$incident->no}";
        }

        $incidentNos = $incidents->pluck('no')->implode(' vs ');

        return 'AI Retrospective: '.Str::limit($incidentNos, 80);
    }

    private function resolveModel(?string $model): string
    {
        return app(ModelRouter::class)->pick(
            'smart',
            $model ?? config('ai.war_room.default_model') ?? AiSetting::get('default_model', config('ai.default_model')),
        );
    }

    /**
     * Close the loop: turn the report's "Improvement Recommendations" into
     * draft ActionImprovements on the primary incident. Drafts are status
     * 'draft' (not 'pending'), so they stay out of reminder/overdue logic
     * until an admin reviews and promotes them.
     */
    public function draftActionImprovements(WarRoomSession $session): int
    {
        $recommendations = $this->extractRecommendations($session);
        $items = $this->splitRecommendationItems($recommendations);

        if (empty($items) || ! $session->incident_id) {
            return 0;
        }

        $note = 'Drafted from AI Retrospective: '.$session->title;
        $created = 0;

        foreach (array_slice($items, 0, 15) as $item) {
            ActionImprovement::create([
                'incident_id' => $session->incident_id,
                'title' => Str::limit(trim($item), 250),
                'detail' => trim($item)."\n\n_{$note}_",
                'due_date' => now()->addDays(30)->toDateString(),
                'pic_email' => [],
                'reminder' => false,
                'status' => 'draft',
            ]);
            $created++;
        }

        Log::info('[WarRoom] Drafted action improvements', [
            'session_id' => $session->id,
            'incident_id' => $session->incident_id,
            'count' => $created,
        ]);

        return $created;
    }

    private function extractRecommendations(WarRoomSession $session): string
    {
        $report = $session->final_report;
        if (is_array($report) && ! empty($report['improvement_recommendations'])) {
            return (string) $report['improvement_recommendations'];
        }

        // Fall back to extracting the section from the raw report markdown.
        if (is_string($session->final_report_html) && trim($session->final_report_html) !== '') {
            return $this->extractSection($session->final_report_html, 'Improvement Recommendations');
        }

        return '';
    }

    private function splitRecommendationItems(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        // Prefer explicit list items (bullets or numbered).
        if (preg_match_all('/^\s*(?:[-*]|\d+[.)])\s+(.+)/m', $text, $matches)) {
            $items = array_filter(array_map('trim', $matches[1]), fn ($s) => $s !== '');
            if (! empty($items)) {
                return array_values($items);
            }
        }

        // Fall back to sentence splitting for prose recommendations.
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $sentences = array_filter(array_map('trim', $sentences), fn ($s) => strlen($s) > 15);

        return $sentences ? array_values($sentences) : [$text];
    }
}
