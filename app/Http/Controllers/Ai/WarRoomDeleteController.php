<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\WarRoomSession;
use Illuminate\Http\JsonResponse;

class WarRoomDeleteController extends Controller
{
    public function __invoke(string $id): JsonResponse
    {
        $session = WarRoomSession::accessibleByUser()->findOrFail($id);

        if ((int) $session->user_id !== (int) auth()->id()) {
            abort(403, 'Only the session creator can delete this session.');
        }

        $session->messages()->delete();
        $session->delete();

        return $this->successResponse(['success' => true]);
    }
}
