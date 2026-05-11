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
        ]);

        $session = WarRoomSession::forUser()->findOrFail($id);

        try {
            $session = $this->warRoomService->reanalyzeSession(
                $session,
                $request->input('user_instructions'),
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
