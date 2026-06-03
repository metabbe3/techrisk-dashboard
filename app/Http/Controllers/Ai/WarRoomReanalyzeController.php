<?php

namespace App\Http\Controllers\Ai;

use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarRoomReanalyzeController
{
    public function __construct(
        private WarRoomService $warRoomService,
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'user_instructions' => 'nullable|string|max:2000',
            'model' => 'nullable|string',
            'moderator_model' => 'nullable|string',
            'selected_agents' => 'nullable|array|min:1',
            'selected_agents.*' => 'string',
            'deep_analysis' => 'nullable|boolean',
        ]);

        $session = WarRoomSession::accessibleByUser()->findOrFail($id);

        try {
            $session = $this->warRoomService->reanalyzeSession(
                $session,
                $request->input('user_instructions'),
                $request->input('model'),
                $request->input('moderator_model'),
                $request->input('selected_agents'),
                $request->has('deep_analysis') ? $request->boolean('deep_analysis') : null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $session->id,
            'status' => $session->status,
            'title' => $session->title,
        ]);
    }
}
