<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\WarRoomMessage;
use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarRoomRetryController extends Controller
{
    public function __construct(
        private WarRoomService $warRoomService
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $session = WarRoomSession::accessibleByUser()->findOrFail($id);
        $session->assertModifiable();

        $failedMessages = WarRoomMessage::where('session_id', $session->id)
            ->where('status', 'failed')
            ->get();

        if ($failedMessages->isEmpty()) {
            if ($session->status === 'failed' && $session->final_report === null) {
                $this->warRoomService->retryReportSynthesis($session);

                return $this->successResponse([
                    'message' => 'No failed agents found. Retrying report synthesis instead.',
                ]);
            }

            return $this->errorResponse('No failed agents to retry', 400);
        }

        foreach ($failedMessages as $message) {
            $this->warRoomService->retryFailedAgent($message);
        }

        return $this->successResponse([
            'message' => "Retrying {$failedMessages->count()} failed agents",
            'retried_count' => $failedMessages->count(),
        ]);
    }

    public function retryAgent(Request $request, string $id, string $messageId): JsonResponse
    {
        $session = WarRoomSession::accessibleByUser()->findOrFail($id);
        $session->assertModifiable();

        $message = WarRoomMessage::where('session_id', $session->id)
            ->where('id', $messageId)
            ->firstOrFail();

        if ($message->status !== 'failed') {
            return $this->errorResponse('Agent is not in a failed state', 400);
        }

        $this->warRoomService->retryFailedAgent($message);

        return $this->successResponse([
            'message' => "Retrying agent: {$message->agent_role}",
            'agent_role' => $message->agent_role,
        ]);
    }

    public function retryReport(Request $request, string $id): JsonResponse
    {
        $session = WarRoomSession::accessibleByUser()->findOrFail($id);
        $session->assertModifiable();

        if ($session->status !== 'failed') {
            return $this->errorResponse('Session is not in a failed state', 400);
        }

        $hasFailedAgents = WarRoomMessage::where('session_id', $session->id)
            ->where('status', 'failed')
            ->exists();

        if ($hasFailedAgents) {
            return $this->errorResponse('Session has failed agents. Retry agents first.', 400);
        }

        $this->warRoomService->retryReportSynthesis($session);

        return $this->successResponse([
            'message' => 'Retrying report synthesis',
        ]);
    }

    public function regenerateReport(Request $request, string $id): JsonResponse
    {
        $session = WarRoomSession::accessibleByUser()->findOrFail($id);
        $session->assertModifiable();

        if (! in_array($session->status, ['completed', 'failed'])) {
            return $this->errorResponse('Session must be completed or failed to regenerate report', 400);
        }

        try {
            $this->warRoomService->regenerateReport($session);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }

        return $this->successResponse([
            'message' => 'Regenerating report from available agent data',
        ]);
    }
}
