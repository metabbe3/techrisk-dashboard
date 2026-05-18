<?php

namespace App\Http\Controllers\Ai;

use App\Models\WarRoomMessage;
use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarRoomRetryController
{
    public function __construct(
        private WarRoomService $warRoomService
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $session = WarRoomSession::forUser()->findOrFail($id);

        $failedMessages = WarRoomMessage::where('session_id', $session->id)
            ->where('status', 'failed')
            ->get();

        if ($failedMessages->isEmpty()) {
            if ($session->status === 'failed' && $session->final_report === null) {
                $this->warRoomService->retryReportSynthesis($session);

                return response()->json([
                    'message' => 'No failed agents found. Retrying report synthesis instead.',
                ]);
            }

            return response()->json(['message' => 'No failed agents to retry'], 400);
        }

        foreach ($failedMessages as $message) {
            $this->warRoomService->retryFailedAgent($message);
        }

        return response()->json([
            'message' => "Retrying {$failedMessages->count()} failed agents",
            'retried_count' => $failedMessages->count(),
        ]);
    }

    public function retryAgent(Request $request, string $id, string $messageId): JsonResponse
    {
        $session = WarRoomSession::forUser()->findOrFail($id);

        $message = WarRoomMessage::where('session_id', $session->id)
            ->where('id', $messageId)
            ->firstOrFail();

        if ($message->status !== 'failed') {
            return response()->json(['message' => 'Agent is not in a failed state'], 400);
        }

        $this->warRoomService->retryFailedAgent($message);

        return response()->json([
            'message' => "Retrying agent: {$message->agent_role}",
            'agent_role' => $message->agent_role,
        ]);
    }

    public function retryReport(Request $request, string $id): JsonResponse
    {
        $session = WarRoomSession::forUser()->findOrFail($id);

        if ($session->status !== 'failed') {
            return response()->json(['message' => 'Session is not in a failed state'], 400);
        }

        $hasFailedAgents = WarRoomMessage::where('session_id', $session->id)
            ->where('status', 'failed')
            ->exists();

        if ($hasFailedAgents) {
            return response()->json(['message' => 'Session has failed agents. Retry agents first.'], 400);
        }

        $this->warRoomService->retryReportSynthesis($session);

        return response()->json([
            'message' => 'Retrying report synthesis',
        ]);
    }

    public function regenerateReport(Request $request, string $id): JsonResponse
    {
        $session = WarRoomSession::forUser()->findOrFail($id);

        if (! in_array($session->status, ['completed', 'failed'])) {
            return response()->json(['message' => 'Session must be completed or failed to regenerate report'], 400);
        }

        try {
            $this->warRoomService->regenerateReport($session);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        return response()->json([
            'message' => 'Regenerating report from available agent data',
        ]);
    }
}
