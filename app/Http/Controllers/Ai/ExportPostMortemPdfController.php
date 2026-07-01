<?php

namespace App\Http\Controllers\Ai;

use App\Models\Incident;
use App\Services\Ai\PostMortemService;
use App\Support\Export;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Parsedown;

class ExportPostMortemPdfController
{
    public function __construct(
        private readonly PostMortemService $postMortemService
    ) {}

    public function __invoke(Request $request, Incident $incident)
    {
        $cacheKey = "postmortem_{$incident->id}_".$incident->updated_at?->timestamp;
        $postMortem = Cache::remember($cacheKey, 3600, fn () => $this->postMortemService->generate($incident));

        $parsedown = new Parsedown;

        $sections = [
            'executive_summary_html' => $parsedown->text($postMortem['executive_summary'] ?? ''),
            'timeline_analysis_html' => $parsedown->text($postMortem['timeline_analysis'] ?? ''),
            'root_cause_deep_dive_html' => $parsedown->text($postMortem['root_cause_deep_dive'] ?? ''),
            'impact_assessment' => $postMortem['impact_assessment'] ?? [],
            'lessons_learned' => $postMortem['lessons_learned'] ?? [],
            'recommendations' => $postMortem['recommendations'] ?? [],
            'severity_assessment' => $postMortem['severity_assessment'] ?? '',
        ];

        $filename = Export::downloadFilename('post-mortem-'.$incident->no, 'pdf');

        $pdf = Pdf::loadView('pdf.post-mortem', [
            'incident' => $incident,
            'sections' => $sections,
            'generated_at' => now()->format('d M Y, H:i'),
        ]);

        return $pdf->download($filename);
    }
}
