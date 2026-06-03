<?php

namespace App\Http\Controllers\Ai;

use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use Illuminate\Http\Request;

class WarRoomExportJsonController
{
    public function __invoke(Request $request, string $id)
    {
        $session = WarRoomSession::with(['incident:id,no,severity,title'])
            ->accessibleByUser()
            ->findOrFail($id);

        $data = app(WarRoomService::class)->getSessionData($session);
        $incident = $session->incident;
        $incidentNo = $incident?->no ?? 'unknown';
        $filename = 'discussion-forum-'.$incidentNo.'-'.now()->format('Y-m-d').'.json';

        return response()->json($data)
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }
}
