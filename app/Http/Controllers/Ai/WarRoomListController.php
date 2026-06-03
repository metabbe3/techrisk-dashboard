<?php

namespace App\Http\Controllers\Ai;

use App\Models\WarRoomSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarRoomListController
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = WarRoomSession::query()
            ->accessibleByUser()
            ->with('incident:id,no,title,summary,severity,incident_status', 'user:id,name')
            ->latestFirst();

        if ($request->filled('incident_id')) {
            $query->where('incident_id', $request->incident_id);
        }

        $sessions = $query->paginate(20);

        return response()->json($sessions);
    }
}
