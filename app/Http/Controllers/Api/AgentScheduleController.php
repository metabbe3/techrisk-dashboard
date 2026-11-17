<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AiAgentEngine;
use App\Enums\AiAgentFrequency;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Agent\CancelAgentScheduleRequest;
use App\Http\Requests\Api\V1\Agent\CreateAgentScheduleRequest;
use App\Http\Requests\Api\V1\Agent\ListAgentSchedulesRequest;
use App\Models\AiAgent;
use App\Traits\ApiResponser;
use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * DingTalk chat reminders, stored as AiAgent rows: the Go agent validates the
 * reminder spec and calls these endpoints; the dashboard owns storage and
 * dispatch. Token needs the `agent-schedules` ability.
 */
class AgentScheduleController extends Controller
{
    use ApiResponser;

    private const MAX_PER_CHAT = 20;

    public function store(CreateAgentScheduleRequest $request): JsonResponse
    {
        $data = $request->validated();

        $cron = isset($data['cron']) ? trim((string) $data['cron']) : null;
        $runAt = $data['run_at'] ?? null;

        if ($cron !== null) {
            try {
                CronExpression::factory($cron);
            } catch (\Throwable) {
                return $this->errorResponse('Invalid cron expression.', 422);
            }
        }

        if ($cron !== null) {
            $runAt = null; // cron takes precedence; a one-shot time is meaningless alongside it
        } else {
            // The date rule already guarantees parseability; normalize into app tz.
            $runAt = Carbon::parse($runAt)->setTimezone(config('app.timezone'));
        }

        $existing = AiAgent::query()
            ->where('dingtalk_conversation_id', $data['conversation_id'])
            ->whereNotNull('short_no')
            ->count();

        if ($existing >= self::MAX_PER_CHAT) {
            return $this->errorResponse('Reminder limit reached (max '.self::MAX_PER_CHAT.' per chat). Cancel one first.', 422);
        }

        $base = [
            'name' => 'Reminder: '.Str::limit($data['text'], 60),
            'instructions' => $data['text'],
            'engine' => $data['mode'] === 'task' ? AiAgentEngine::Go : AiAgentEngine::Static,
            'frequency' => $cron !== null ? AiAgentFrequency::Cron : AiAgentFrequency::Once,
            'cron_expression' => $cron,
            'run_at' => $runAt,
            'dingtalk_conversation_type' => $data['conversation_type'],
            'dingtalk_conversation_id' => $data['conversation_id'],
            'dingtalk_staff_id' => $data['staff_id'],
            'enabled' => true,
        ];

        try {
            $agent = $this->createWithNextShortNo($base, $data['conversation_id']);
        } catch (QueryException) {
            return $this->errorResponse('Could not allocate a reminder number — please retry.', 500);
        }

        return $this->successResponse($this->item($agent), 'Reminder saved.');
    }

    public function index(ListAgentSchedulesRequest $request): JsonResponse
    {
        $agents = AiAgent::query()
            ->where('dingtalk_conversation_id', $request->validated('conversation_id'))
            ->whereNotNull('short_no')
            ->where('enabled', true)
            ->get()
            // Active only: cron agents, or one-shots still in the future.
            ->filter(fn (AiAgent $agent) => $agent->frequency === AiAgentFrequency::Cron || $agent->run_at?->isFuture())
            ->sortBy('short_no');

        return $this->successResponse($agents->map(fn (AiAgent $agent) => $this->item($agent))->values()->all());
    }

    public function destroy(int $no, CancelAgentScheduleRequest $request): JsonResponse
    {
        $agent = AiAgent::query()
            ->where('dingtalk_conversation_id', $request->validated('conversation_id'))
            ->where('short_no', $no)
            ->where('enabled', true)
            ->first();

        if (! $agent) {
            return $this->errorResponse("No reminder #{$no} in this chat.", 404);
        }

        // Soft cancel — the row stays for the audit trail; it never fires again.
        $agent->forceFill([
            'enabled' => false,
            'run_at' => null,
            'cron_expression' => null,
        ])->save();

        return $this->successResponse(['no' => $no], "Reminder #{$no} cancelled.");
    }

    /**
     * The wire shape the Go agent renders into chat replies.
     *
     * @return array{no: int, mode: string, text: string, cron: ?string, run_at: ?string, next_run_at: ?string}
     */
    private function item(AiAgent $agent): array
    {
        $next = $agent->frequency === AiAgentFrequency::Once
            ? $agent->run_at
            : ($agent->cron_expression !== null
                ? Carbon::instance(CronExpression::factory($agent->cron_expression)->getNextRunDate(now()))
                : null);

        return [
            'no' => (int) $agent->short_no,
            'mode' => $agent->engine === AiAgentEngine::Static ? 'message' : 'task',
            'text' => (string) $agent->instructions,
            'cron' => $agent->cron_expression,
            'run_at' => $agent->run_at?->toIso8601String(),
            'next_run_at' => $next?->toIso8601String(),
        ];
    }

    /**
     * short_no = max+1 per conversation, with one retry on the unique-index
     * race (two concurrent creates grabbing the same slot).
     */
    private function createWithNextShortNo(array $base, string $conversationId): AiAgent
    {
        try {
            return AiAgent::create($base + ['short_no' => $this->nextShortNo($conversationId)]);
        } catch (QueryException) {
            return AiAgent::create($base + ['short_no' => $this->nextShortNo($conversationId)]);
        }
    }

    private function nextShortNo(string $conversationId): int
    {
        return (int) AiAgent::query()
            ->where('dingtalk_conversation_id', $conversationId)
            ->whereNotNull('short_no')
            ->max('short_no') + 1;
    }
}
