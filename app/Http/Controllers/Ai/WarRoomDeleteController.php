<?php

namespace App\Http\Controllers\Ai;

use App\Models\WarRoomSession;
use Illuminate\Http\JsonResponse;

class WarRoomDeleteController
{
    public function __invoke(string $id): JsonResponse
    {
        $session = WarRoomSession::accessibleByUser()->findOrFail($id);

        if ((int) $session->user_id !== (int) auth()->id()) {
            abort(403, 'Only the session creator can delete this session.');
        }

        $session->messages()->delete();
        $session->delete();

        return response()->json(['success' => true]);
    }
}
