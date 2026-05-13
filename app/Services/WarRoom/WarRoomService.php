<?php

namespace App\Services\WarRoom;

use App\Events\WarRoomMessageUpdated;
use App\Events\WarRoomRoundCompleted;
use App\Events\WarRoomSessionCompleted;
use App\Jobs\WarRoom\ProcessWarRoomAgent;
use App\Jobs\WarRoom\StartWarRoomSession;
use App\Jobs\WarRoom\SynthesizeWarRoomReport;
use App\Models\AiSetting;
use App\Models\AiUsageLog;
use App\Models\Incident;
use App\Models\User;
use App\Models\WarRoomAgentConfig;
use App\Models\WarRoomMessage;
use App\Models\WarRoomSession;
use App\Services\Ai\WebSearchService;
use App\Services\Markdown\IncidentMarkdownExporter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class WarRoomService
{
    public function __construct(
        private IncidentMarkdownExporter $markdownExporter,
        private AgentPromptBuilder $promptBuilder,
        private WebSearchService $webSearchService,
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

        $primaryIncident = $incidents->first();

        $generateMethod = $deepAnalysis ? 'generateCompact' : 'generateMinimal';

        if ($incidents->count() === 1) {
            $markdown = $this->markdownExporter->$generateMethod($primaryIncident);
            $context = [$markdown];
            $title = "Discussion Forum: {$primaryIncident->no}";
        } else {
            $context = [];
            foreach ($incidents as $inc) {
                $context[] = "--- Incident: {$inc->no} ({$inc->severity}) ---";
                $context[] = $this->markdownExporter->$generateMethod($inc);
            }
            $incidentNos = $incidents->pluck('no')->implode(' vs ');
            $title = 'Discussion Forum: '.\Illuminate\Support\Str::limit($incidentNos, 80);
        }

        $session = WarRoomSession::create([
            'user_id' => $user->id,
            'incident_id' => $primaryIncident->id,
            'title' => $title,
            'status' => 'pending',
            'max_rounds' => $maxRounds,
            'model' => $model ?? config('ai.war_room.default_model') ?? AiSetting::get('default_model', config('ai.default_model')),
            'moderator_model' => $moderatorModel ?? config('ai.war_room.moderator_model') ?? $model ?? AiSetting::get('default_model', config('ai.default_model')),
            'enable_web_search' => $enableWebSearch,
            'deep_analysis' => $deepAnalysis,
            'selected_agents' => $selectedAgents,
            'incident_context' => $this->compressContext($context, $model ?? config('ai.war_room.default_model') ?? AiSetting::get('default_model', config('ai.default_model')), $incidents),
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
        $generateMethod = $deepAnalysis ? 'generateCompact' : 'generateMinimal';

        if ($incidents->count() <= 1) {
            $incident = $incidents->first() ?? $session->incident;
            $markdown = $this->markdownExporter->$generateMethod($incident);
            $context = [$markdown];
            $title = "Discussion Forum: {$incident->no}";
        } else {
            $context = [];
            foreach ($incidents as $inc) {
                $context[] = "--- Incident: {$inc->no} ({$inc->severity}) ---";
                $context[] = $this->markdownExporter->$generateMethod($inc);
            }
            $incidentNos = $incidents->pluck('no')->implode(' vs ');
            $title = 'Discussion Forum: '.\Illuminate\Support\Str::limit($incidentNos, 80);
        }

        $session->messages()->delete();

        $resolvedModel = $model ?? $session->model;
        $compressedContext = $this->compressContext($context, $resolvedModel, $incidents);

        $update = [
            'status' => 'pending',
            'current_round' => 0,
            'incident_context' => $compressedContext,
            'context_summarized' => false,
            'user_instructions' => $userInstructions ?? $session->user_instructions,
            'final_report' => null,
            'final_report_html' => null,
            'started_at' => null,
            'completed_at' => null,
            'failed_at' => null,
            'error_message' => null,
            'tokens_used' => 0,
            'deep_analysis' => $deepAnalysis,
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
        $this->dispatchRound($session, 1);
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
        $message = WarRoomMessage::where('session_id', $session->id)
            ->where('agent_role', $agentRole)
            ->where('round', $round)
            ->firstOrFail();

        $message->markRunning();

        broadcast(new WarRoomMessageUpdated($session, $message));

        $config = WarRoomAgentConfig::findByRole($agentRole);
        $model = $config?->model_override ?? $session->model;

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

            $fullContent = '';
            $totalUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
            $finalFinishReason = 'unknown';

            for ($attempt = 0; $attempt <= $maxContinuations; $attempt++) {
                $response = Http::withHeaders($this->buildHeaders())
                    ->timeout($this->getTimeout())
                    ->post($this->buildUrl(), [
                        'model' => $model,
                        'messages' => $messages,
                        'max_tokens' => $maxTokens,
                        'max_completion_tokens' => $maxTokens,
                    ]);

                $responseData = $response->json();
                $usage = $responseData['usage'] ?? [];
                $finishReason = $responseData['choices'][0]['finish_reason'] ?? 'unknown';
                $finalFinishReason = $finishReason;

                foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
                    $totalUsage[$key] += $usage[$key] ?? 0;
                }

                if ($response->failed()) {
                    $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
                    $errorMsg = 'AI service error (HTTP '.$response->status().')';
                    Log::warning("[WarRoom] Agent {$agentRole} failed", [
                        'session_id' => $session->id,
                        'attempt' => $attempt,
                        'status' => $response->status(),
                    ]);
                    $message->markFailed($errorMsg);
                    $this->logUsage('war_room_agent', $model, false, $totalUsage, $responseTimeMs, $session, $agentRole, $round, $errorMsg);
                    $fullContent = '';

                    break;
                }

                $chunk = $responseData['choices'][0]['message']['content'] ?? '';

                if (blank($chunk) && $attempt === 0) {
                    $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
                    $message->markFailed('AI returned empty response');
                    $this->logUsage('war_room_agent', $model, false, $totalUsage, $responseTimeMs, $session, $agentRole, $round, 'Empty response');
                    $fullContent = '';

                    break;
                }

                $fullContent .= $chunk;

                // Detect truncation: explicit (finish_reason=length) or heuristic (doesn't end properly)
                $trimmedChunk = rtrim($chunk);
                $looksTruncated = ! preg_match('/[.!?)`\n]$/', $trimmedChunk) && strlen($trimmedChunk) > 100;

                if ($finishReason !== 'length' && ! $looksTruncated) {
                    break;
                }

                // Truncated — send continuation request
                Log::info("[WarRoom] Agent {$agentRole} continuation {$attempt}", [
                    'session_id' => $session->id,
                    'round' => $round,
                    'finish_reason' => $finishReason,
                    'looks_truncated' => $looksTruncated,
                    'completion_tokens_so_far' => $totalUsage['completion_tokens'],
                    'content_length_so_far' => strlen($fullContent),
                ]);

                $messages[] = ['role' => 'assistant', 'content' => $chunk];
                $messages[] = ['role' => 'user', 'content' => 'Continue your analysis from exactly where you left off. Do not repeat what you already wrote.'];
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
            ]);

            if (! blank($fullContent)) {
                $metadata = array_merge($message->metadata ?? [], [
                    'finish_reason' => $finalFinishReason,
                    'model' => $model,
                    'total_completion_tokens' => $totalUsage['completion_tokens'],
                ]);

                $message->markCompleted($fullContent, $totalUsage, $responseTimeMs, $metadata);

                if ($totalUsage['total_tokens'] > 0) {
                    $session->addTokens($totalUsage['total_tokens']);
                }

                $this->logUsage('war_room_agent', $model, true, $totalUsage, $responseTimeMs, $session, $agentRole, $round);
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
        $model = $session->moderator_model;
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

            for ($attempt = 0; $attempt <= $maxContinuations; $attempt++) {
                $response = Http::withHeaders($this->buildHeaders())
                    ->timeout((int) AiSetting::get('war_room_moderator_timeout',
                        config('ai.war_room.moderator_timeout', 600)))
                    ->post($this->buildUrl(), [
                        'model' => $model,
                        'messages' => $messages,
                        'max_tokens' => $maxTokens,
                        'max_completion_tokens' => $maxTokens,
                    ]);

                $responseData = $response->json();
                $usage = $responseData['usage'] ?? [];
                $finishReason = $responseData['choices'][0]['finish_reason'] ?? 'unknown';

                foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
                    $totalUsage[$key] += $usage[$key] ?? 0;
                }

                if ($response->failed()) {
                    $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
                    $session->markFailed('Report synthesis failed: HTTP '.$response->status());
                    $this->logUsage('war_room_moderator', $model, false, $totalUsage, $responseTimeMs, $session, 'moderator', 0, 'HTTP '.$response->status());
                    broadcast(new WarRoomSessionCompleted($session->fresh()));

                    return;
                }

                $chunk = $responseData['choices'][0]['message']['content'] ?? '';

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

        $message->update(['status' => 'pending', 'error_message' => null]);

        if ($session->status === 'failed') {
            $session->update(['status' => 'running', 'error_message' => null]);
        }

        ProcessWarRoomAgent::dispatch($session, $message->agent_role, $message->round);
    }

    public function getSessionData(WarRoomSession $session): array
    {
        $session->load('messages', 'user', 'incidents');

        $messagesByRound = $session->messages->groupBy('round')->map(function ($roundMessages) {
            return $roundMessages->map(function ($msg) {
                $config = WarRoomAgentConfig::findByRole($msg->agent_role);

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

        return [
            'id' => $session->id,
            'incident_id' => $session->incident_id,
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
            'selected_agents' => $session->selected_agents,
            'user_instructions' => $session->user_instructions,
            'tokens_used' => $session->tokens_used,
            'started_at' => $session->started_at?->toIso8601String(),
            'completed_at' => $session->completed_at?->toIso8601String(),
            'failed_at' => $session->failed_at?->toIso8601String(),
            'error_message' => $session->error_message,
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
            $query = ($incident->title ?? $incident->summary ?? 'incident').' troubleshooting root cause analysis';

            $results = $this->webSearchService->search($query);

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
        $pattern = '/^##\s+'.preg_quote($sectionTitle, '/').'\s*\n(.*?)(?=^##\s+|\z)/msu';

        if (preg_match($pattern, $content, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    private function buildHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->getApiKey(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    private function buildUrl(): string
    {
        return rtrim($this->getBaseUrl(), '/').'/chat/completions';
    }

    private function getApiKey(): ?string
    {
        return AiSetting::get('api_key', config('ai.api_key'));
    }

    private function getBaseUrl(): ?string
    {
        return AiSetting::get('base_url', config('ai.base_url'));
    }

    private function getTimeout(): int
    {
        return (int) AiSetting::get('war_room_agent_timeout',
            config('ai.war_room.agent_timeout', 600));
    }

    private function estimateTokens(string $text): int
    {
        return intdiv(strlen($text), 4);
    }

    private function getModelInputLimit(string $model): int
    {
        $limits = config('ai.war_room.model_limits', []);

        return $limits[$model]['input'] ?? config('ai.war_room.default_input_limit', 32000);
    }

    private function compressContext(array $context, string $model, $incidents): array
    {
        $inputLimit = $this->getModelInputLimit($model);
        $targetTokens = (int) ($inputLimit * 0.75);
        $contextText = implode("\n", $context);
        $estimated = $this->estimateTokens($contextText);

        if ($estimated <= $targetTokens) {
            return $context;
        }

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
                fn ($m) => '**AI Summary:** ' . Str::limit($m[1], 200),
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
            $minimalContext[] = "--- Incident: {$inc->no} ({$inc->severity}) ---";
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
        try {
            AiUsageLog::create([
                'user_id' => $session->user_id,
                'user_email' => $session->user?->email,
                'field_type' => $fieldType,
                'model' => $model,
                'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                'completion_tokens' => $usage['completion_tokens'] ?? null,
                'total_tokens' => $usage['total_tokens'] ?? null,
                'response_time_ms' => $responseTimeMs,
                'success' => $success,
                'error_message' => $errorMessage,
                'metadata' => [
                    'session_id' => $session->id,
                    'agent_role' => $agentRole,
                    'round' => $round,
                ],
                'requested_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write War Room usage log', ['error' => $e->getMessage()]);
        }
    }
}
