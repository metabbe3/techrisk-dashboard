<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AiAgentRunStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Agent\ReportAgentTurnRequest;
use App\Models\AiAgent;
use App\Models\AiAgentRun;
use App\Services\Ai\AiUsageLogger;
use App\Traits\ApiResponser;
use Illuminate\Http\JsonResponse;

/**
 * DingTalk chat-turn reporting: every turn the Go agent answers is recorded
 * as a run row under the synthetic "DingTalk Bot (chat turns)" agent, so chat
 * usage is visible on the Agent Runs tab. Token needs the `agent-runs` ability.
 */
class AgentRunController extends Controller
{
    use ApiResponser;

    public function store(ReportAgentTurnRequest $request): JsonResponse
    {
        $data = $request->validated();

        $agent = AiAgent::find(AiAgent::CHAT_TURNS_AGENT_ID);
        if (! $agent) {
            // Migration-order safety only — the engine migration inserts it.
            $agent = new AiAgent([
                'name' => 'DingTalk Bot (chat turns)',
                'description' => 'Synthetic agent — every DingTalk chat turn is recorded as a run here. Not runnable.',
                'instructions' => 'Not runnable.',
                'engine' => 'php',
                'frequency' => 'Manual',
                'enabled' => false,
            ]);
            $agent->id = AiAgent::CHAT_TURNS_AGENT_ID;
            $agent->save();
        }

        $error = $data['error'] ?? null;
        $usage = $data['usage'] ?? [];

        $run = AiAgentRun::create([
            'agent_id' => $agent->id,
            'status' => $error !== null ? AiAgentRunStatus::Failed->value : AiAgentRunStatus::Completed->value,
            'input' => $data['user_text'],
            'output' => $error === null ? ($data['assistant_text'] ?? null) : null,
            'model' => $data['model'] ?? 'dingtalk-agent',
            'prompt_tokens' => $usage['prompt_tokens'] ?? null,
            'completion_tokens' => $usage['completion_tokens'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
            'response_time_ms' => $data['response_time_ms'] ?? null,
            'error_message' => $error,
            'requested_at' => now(),
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        // Usage visibility for chat turns too; a logging failure must never
        // fail the report — the run row is already the source of truth.
        try {
            app(AiUsageLogger::class)->log(
                fieldType: 'agent',
                model: $data['model'] ?? 'dingtalk-agent',
                success: $error === null,
                inputLength: strlen($data['user_text']),
                outputLength: isset($data['assistant_text']) ? strlen($data['assistant_text']) : null,
                usage: $usage,
                responseTimeMs: $data['response_time_ms'] ?? null,
                apiRequestId: $run->id,
                errorMessage: $error,
                metadata: [
                    'source' => 'dingtalk-chat',
                    'conversation_id' => $data['conversation_id'],
                    'conversation_type' => $data['conversation_type'] ?? null,
                    'staff_id' => $data['staff_id'] ?? null,
                    'nick' => $data['nick'] ?? null,
                ],
            );
        } catch (\Throwable) {
            // best-effort
        }

        return $this->successResponse(['run_id' => $run->id], 'Turn recorded.', 202);
    }
}
