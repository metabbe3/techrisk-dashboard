<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\WarRoomMessage;
use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class WarRoomPollController extends Controller
{
    public function __construct(
        private WarRoomService $warRoomService,
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $session = WarRoomSession::accessibleByUser()->findOrFail($id);

        if ($session->status === 'running') {
            $throttleKey = "warroom_stuck_check:{$session->id}";
            if (Cache::add($throttleKey, true, 30)) {
                $this->warRoomService->markStuckMessages($session);
                $session->refresh();
            }
        }

        $messages = WarRoomMessage::where('session_id', $session->id)
            ->select('id', 'round', 'agent_role', 'status', 'error_message')
            ->get()
            ->groupBy('round')
            ->map(fn ($roundMessages) => $roundMessages->map(fn ($msg) => [
                'id' => $msg->id,
                'round' => $msg->round,
                'agent_role' => $msg->agent_role,
                'status' => $msg->status,
                'error_message' => $msg->error_message,
            ])->values());

        return $this->successResponse([
            'id' => $session->id,
            'status' => $session->status,
            'current_round' => $session->current_round,
            'error_message' => $session->error_message,
            'pre_analysis' => $session->pre_analysis,
            'messages' => $messages,
        ]);
    }
}
