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
}
