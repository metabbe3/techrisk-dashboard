<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarRoomShowController extends Controller
{
    public function __construct(
        private WarRoomService $warRoomService
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $session = WarRoomSession::accessibleByUser()->with('user:id,name')->findOrFail($id);

        return $this->successResponse(
            $this->warRoomService->getSessionData($session)
        );
    }
}
