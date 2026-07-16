<?php

namespace App\Http\Controllers\Ai;

use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use App\Support\Export;
use Illuminate\Http\Request;

class WarRoomExportMarkdownController
{
    public function __invoke(Request $request, string $id)
    {
        $session = WarRoomSession::with(['user:id,name', 'incident:id,no,severity,title', 'incidents'])
            ->accessibleByUser()
            ->findOrFail($id);

        $data = app(WarRoomService::class)->getSessionData($session);
        $incident = $session->incident;
        $incidentNo = $incident?->no ?? 'unknown';
        $filename = Export::downloadFilename('discussion-forum-'.$incidentNo, 'md', now()->format('Y-m-d'));

        $markdown = $this->toMarkdown($data);

        return response($markdown)
            ->header('Content-Type', 'text/markdown; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }

    private function toMarkdown(array $data): string
    {
        $lines = [];

        $lines[] = '# '.$data['title'];
        $lines[] = '';
        $lines[] = '**Date:** '.now()->format('d M Y, H:i');
        $lines[] = '**Status:** '.ucfirst($data['status']);
        $lines[] = '**Tokens Used:** '.number_format($data['tokens_used'] ?? 0);

        if ($incident = ($data['incident'] ?? null)) {
            $lines[] = '**Incident:** '.$incident['no'].' — '.$incident['title'].' ('.$incident['severity'].')';
        }

        $lines[] = '';

        if ($rounds = ($data['messages'] ?? [])) {
            foreach ($rounds as $round => $messages) {
                $lines[] = '---';
                $lines[] = '';
                $lines[] = '## Round '.$round;
                $lines[] = '';

                foreach ($messages as $msg) {
                    $name = $msg['agent_name'] ?? ucfirst($msg['agent_role'] ?? 'Agent');
                    $status = strtoupper($msg['status'] ?? '');

                    $lines[] = '### '.$name.' ('.$status.')';
                    $lines[] = '';

                    if (! empty($msg['content'])) {
                        $lines[] = $msg['content'];
                        $lines[] = '';
                    }

                    if (! empty($msg['error_message'])) {
                        $lines[] = '> **Error:** '.$msg['error_message'];
                        $lines[] = '';
                    }
                }
            }
        }

        if (! empty($data['final_report_html'])) {
            $lines[] = '---';
            $lines[] = '';
            $lines[] = '## Final Report';
            $lines[] = '';
            $lines[] = trim($data['final_report_html']);
            $lines[] = '';
        } elseif (! empty($data['final_report'])) {
            // final_report is a parsed array — render its sections instead of
            // casting the array to the literal string "Array".
            $lines[] = '---';
            $lines[] = '';
            $lines[] = '## Final Report';
            $lines[] = '';
            foreach ($data['final_report'] as $section => $body) {
                if (is_string($body) && trim($body) !== '') {
                    $lines[] = '### '.ucwords((string) str_replace('_', ' ', $section));
                    $lines[] = '';
                    $lines[] = $body;
                    $lines[] = '';
                }
            }
        }

        return implode("\n", $lines);
    }
}
