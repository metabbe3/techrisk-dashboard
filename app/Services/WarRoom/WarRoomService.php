<?php

namespace App\Services\WarRoom;

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
use Illuminate\Support\Facades\Log;

class WarRoomService
{
    public function __construct(
        private IncidentMarkdownExporter $markdownExporter,
        private AgentPromptBuilder $promptBuilder,
        private WebSearchService $webSearchService,
    ) {}

    public function createSession(
        Incident $incident,
        User $user,
        array $selectedAgents,
        int $maxRounds = 2,
        ?string $model = null,
        ?string $moderatorModel = null,
        bool $enableWebSearch = false,
        ?string $userInstructions = null,
    ): WarRoomSession {
        $context = $this->markdownExporter->generate($incident);

        $session = WarRoomSession::create([
            'user_id' => $user->id,
            'incident_id' => $incident->id,
            'title' => "Discussion Forum: {$incident->no}",
            'status' => 'pending',
            'max_rounds' => $maxRounds,
            'model' => $model ?? config('ai.war_room.default_model') ?? AiSetting::get('default_model', config('ai.default_model')),
            'moderator_model' => $moderatorModel ?? config('ai.war_room.moderator_model') ?? $model ?? AiSetting::get('default_model', config('ai.default_model')),
            'enable_web_search' => $enableWebSearch,
            'selected_agents' => $selectedAgents,
            'incident_context' => $context,
            'user_instructions' => $userInstructions,
        ]);

        StartWarRoomSession::dispatch($session);

        return $session;
    }

    public function reanalyzeSession(WarRoomSession $session, ?string $userInstructions = null): WarRoomSession
    {
        if (! in_array($session->status, ['completed', 'failed'])) {
            throw new \InvalidArgumentException('Only completed or failed sessions can be re-analyzed.');
        }

        // Refresh incident context with latest data
        $incident = $session->incident;
        $context = $this->markdownExporter->generate($incident);

        // Clear old data
        $session->messages()->delete();

        $session->update([
            'status' => 'pending',
            'current_round' => 0,
            'incident_context' => $context,
            'user_instructions' => $userInstructions ?? $session->user_instructions,
            'final_report' => null,
            'final_report_html' => null,
            'started_at' => null,
            'completed_at' => null,
            'failed_at' => null,
            'error_message' => null,
            'tokens_used' => 0,
            'title' => "Discussion Forum: {$incident->no}",
        ]);

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

                    return;
                }

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

        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout($this->getTimeout())
                ->post($this->buildUrl(), [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                    'max_tokens' => config('ai.war_room.max_output_tokens', 4000),
                ]);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];

            if ($response->failed()) {
                $errorMsg = 'AI service error (HTTP '.$response->status().')';
                Log::warning("[WarRoom] Agent {$agentRole} failed", [
                    'session_id' => $session->id,
                    'status' => $response->status(),
                ]);
                $message->markFailed($errorMsg);
                $this->logUsage('war_room_agent', $model, false, $usage, $responseTimeMs, $session, $agentRole, $round, $errorMsg);
            } else {
                $content = $responseData['choices'][0]['message']['content'] ?? '';

                if (blank($content)) {
                    $message->markFailed('AI returned empty response');
                    $this->logUsage('war_room_agent', $model, false, $usage, $responseTimeMs, $session, $agentRole, $round, 'Empty response');
                } else {
                    $message->markCompleted($content, $usage, $responseTimeMs);

                    if (isset($usage['total_tokens'])) {
                        $session->addTokens($usage['total_tokens']);
                    }

                    $this->logUsage('war_room_agent', $model, true, $usage, $responseTimeMs, $session, $agentRole, $round);
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

        $startTime = microtime(true);

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(config('ai.war_room.moderator_timeout', 180))
                ->post($this->buildUrl(), [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                    'max_tokens' => config('ai.war_room.max_output_tokens', 4000) * 2,
                ]);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $responseData = $response->json();
            $usage = $responseData['usage'] ?? [];

            if ($response->failed()) {
                $session->markFailed('Report synthesis failed: HTTP '.$response->status());
                $this->logUsage('war_room_moderator', $model, false, $usage, $responseTimeMs, $session, 'moderator', 0, 'HTTP '.$response->status());

                return;
            }

            $content = $responseData['choices'][0]['message']['content'] ?? '';

            if (blank($content)) {
                $session->markFailed('Report synthesis returned empty response');
                $this->logUsage('war_room_moderator', $model, false, $usage, $responseTimeMs, $session, 'moderator', 0, 'Empty response');

                return;
            }

            if (isset($usage['total_tokens'])) {
                $session->addTokens($usage['total_tokens']);
            }

            $report = $this->parseReport($content);

            $session->update([
                'final_report' => $report,
                'final_report_html' => $content,
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            $this->logUsage('war_room_moderator', $model, true, $usage, $responseTimeMs, $session, 'moderator', 0);
        } catch (\Throwable $e) {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $session->markFailed('Report synthesis error: '.$e->getMessage());
            $this->logUsage('war_room_moderator', $model, false, [], $responseTimeMs, $session, 'moderator', 0, $e->getMessage());
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
        $session->load('messages', 'user');

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

        return [
            'id' => $session->id,
            'incident_id' => $session->incident_id,
            'user_name' => $session->user?->name,
            'title' => $session->title,
            'status' => $session->status,
            'current_round' => $session->current_round,
            'max_rounds' => $session->max_rounds,
            'model' => $session->model,
            'moderator_model' => $session->moderator_model,
            'enable_web_search' => $session->enable_web_search,
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
        return config('ai.war_room.agent_timeout', 120);
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
