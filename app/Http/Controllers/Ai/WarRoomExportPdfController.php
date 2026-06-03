<?php

namespace App\Http\Controllers\Ai;

use App\Models\WarRoomSession;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Parsedown;

class WarRoomExportPdfController
{
    public function __invoke(Request $request, string $id)
    {
        $session = WarRoomSession::with(['user:id,name', 'incident:id,no,severity,title'])
            ->accessibleByUser()
            ->findOrFail($id);

        if ($session->status !== 'completed' || blank($session->final_report_html)) {
            return response()->json(['message' => 'Report not available for export.'], 422);
        }

        $parsedown = new Parsedown;
        $reportHtml = $parsedown->text($session->final_report_html);

        $incident = $session->incident;
        $incidentNo = $incident?->no ?? 'unknown';
        $filename = 'discussion-forum-'.$incidentNo.'-'.now()->format('Y-m-d').'.pdf';

        $pdf = Pdf::loadView('war-room.pdf-report', [
            'title' => $session->title,
            'report_html' => $reportHtml,
            'incident_no' => $incident?->no,
            'incident_severity' => $incident?->severity,
            'user_name' => $session->user?->name,
            'tokens_used' => $session->tokens_used,
            'generated_at' => now()->format('d M Y, H:i'),
        ]);

        return $pdf->download($filename);
    }
}
