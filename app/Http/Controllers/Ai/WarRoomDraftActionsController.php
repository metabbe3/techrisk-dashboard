<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarRoomDraftActionsController extends Controller
{
    public function __construct(
        private WarRoomService $warRoomService,
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $session = WarRoomSession::accessibleByUser()->findOrFail($id);

        // Only the creator may draft actions from a session; viewing stays open.
        $session->assertModifiable();

        if (! in_array($session->status, ['completed', 'failed']) || blank($session->final_report)) {
            return $this->errorResponse('Session must be completed with a final report first.', 400);
        }

        $count = $this->warRoomService->draftActionImprovements($session);

        return $this->successResponse([
            'message' => $count > 0 ? "Drafted {$count} action improvement(s)." : 'No actionable recommendations found to draft.',
            'drafted_count' => $count,
        ]);
    }
}
