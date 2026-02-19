<?php

declare(strict_types=1);

namespace App\Services\Markdown;

use App\Models\Incident;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentMarkdownExporter
{
    /**
     * Generate markdown content for an incident.
     */
    public function generate(Incident $incident): string
    {
        // Eager load all relationships to prevent N+1 queries
        $incident->load([
            'incidentType',
            'pic',
            'labels',
            'statusUpdates' => fn ($query) => $query->orderBy('created_at', 'desc'),
            'investigationDocuments',
            'actionImprovements',
        ]);

        return view('markdown.incident', [
            'incident' => $incident,
        ])->render();
    }

    /**
     * Save markdown to file.
     */
    public function saveToFile(Incident $incident, ?string $path = null): string
    {
        $markdown = $this->generate($incident);
        $filename = $path ?? "markdown/incidents/{$incident->id}.md";

        Storage::disk('local')->put($filename, $markdown);

        return $filename;
    }

    /**
     * Generate download response for markdown.
     */
    public function download(Incident $incident): StreamedResponse
    {
        $markdown = $this->generate($incident);
        $filename = $this->generateFilename($incident);

        return response()->streamDownload(
            function () use ($markdown) {
                echo $markdown;
            },
            $filename,
            ['Content-Type' => 'text/markdown; charset=utf-8']
        );
    }

    /**
     * Generate a safe filename for the incident.
     */
    public function generateFilename(Incident $incident): string
    {
        $safeTitle = MarkdownFormatter::sanitizeFilename($incident->summary ?? 'incident');
        $date = $incident->incident_date?->format('Y-m-d') ?? 'unknown';

        return "{$incident->no}_{$date}_{$safeTitle}.md";
    }
}
