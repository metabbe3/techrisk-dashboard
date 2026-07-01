<?php

namespace App\Http\Controllers\Ai;

use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use App\Support\Export;
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
        $filename = Export::downloadFilename('discussion-forum-'.$incidentNo, 'json', now()->format('Y-m-d'));

        return response()->json($data)
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }
}
