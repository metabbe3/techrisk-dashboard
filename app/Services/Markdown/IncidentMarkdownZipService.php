<?php

declare(strict_types=1);

namespace App\Services\Markdown;

use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the AI-consumption corpus ZIP: one folder per incident with the
 * full incident markdown plus every investigation document converted to
 * markdown (owner rule 2026-10-01). Markdown-only — originals stay out.
 * Content assembly lives in IncidentMarkdownCorpusService (shared with
 * the persistent incident memory).
 *
 * // ponytail: inline build; if the corpus grows slow → queued job + notification
 */
class IncidentMarkdownZipService
{
    public function __construct(private IncidentMarkdownCorpusService $corpus) {}

    /**
     * @return string|null Absolute temp zip path; null when the query matched nothing.
     */
    public function build(Builder $query): ?string
    {
        $incidents = $query->with('investigationDocuments')
            ->orderBy('incident_date')
            ->get();

        if ($incidents->isEmpty()) {
            return null;
        }

        @mkdir(storage_path('app/private/temp'), 0777, true);
        $path = storage_path('app/private/temp/incidents-markdown-'.now()->format('YmdHis').'.zip');

        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('index.md', $this->corpus->index($incidents));

        $usedFolders = [];
        foreach ($incidents as $incident) {
            $folder = $this->corpus->folderFor($incident, $usedFolders);
            foreach ($this->corpus->incidentFiles($incident) as $rel => $content) {
                $zip->addFromString("{$folder}/{$rel}", $content);
            }
        }

        $zip->close();

        return $path;
    }
}
