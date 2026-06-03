<?php

namespace App\Http\Controllers\Ai;

use App\Models\WarRoomSession;
use Illuminate\Http\JsonResponse;

class WarRoomDeleteController
{
    public function __invoke(string $id): JsonResponse
    {
        $session = WarRoomSession::accessibleByUser()->findOrFail($id);

        $session->messages()->delete();
        $session->delete();

        return response()->json(['success' => true]);
    }
}
