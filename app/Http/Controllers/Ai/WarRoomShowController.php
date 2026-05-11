<?php

namespace App\Http\Controllers\Ai;

use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarRoomShowController
{
    public function __construct(
        private WarRoomService $warRoomService
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $session = WarRoomSession::with('user:id,name')->findOrFail($id);

        return response()->json(
            $this->warRoomService->getSessionData($session)
        );
    }
}
