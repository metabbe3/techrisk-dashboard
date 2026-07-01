<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\WarRoomSession;
use App\Support\Export;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Parsedown;

class WarRoomExportPdfController extends Controller
{
    public function __invoke(Request $request, string $id)
    {
        $session = WarRoomSession::with(['user:id,name', 'incident:id,no,severity,title'])
            ->accessibleByUser()
            ->findOrFail($id);

        if ($session->status !== 'completed' || blank($session->final_report_html)) {
            return $this->errorResponse('Report not available for export.', 422);
        }

        $parsedown = new Parsedown;
        $reportHtml = $parsedown->text($session->final_report_html);

        $incident = $session->incident;
        $incidentNo = $incident?->no ?? 'unknown';
        $filename = Export::downloadFilename('discussion-forum-'.$incidentNo, 'pdf', now()->format('Y-m-d'));

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
