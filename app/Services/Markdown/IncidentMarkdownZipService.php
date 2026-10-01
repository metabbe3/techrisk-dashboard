<?php

declare(strict_types=1);

namespace App\Services\Markdown;

use App\Models\Incident;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Builds the AI-consumption corpus: one folder per incident with the full
 * incident markdown plus every investigation document converted to markdown,
 * zipped (owner rule 2026-10-01). Markdown-only — originals stay out.
 *
 * // ponytail: inline build; if the corpus grows slow → queued job + notification
 */
class IncidentMarkdownZipService
{
    public function __construct(
        private IncidentMarkdownExporter $exporter,
        private DocumentConverterService $converter,
    ) {}

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
        $zip->addFromString('index.md', $this->buildIndex($incidents));

        $usedFolders = [];
        foreach ($incidents as $incident) {
            $folder = $this->uniqueName(
                MarkdownFormatter::sanitizeFilename($incident->no ?? (string) $incident->id),
                $usedFolders
            );
            $zip->addFromString("{$folder}/incident.md", $this->incidentMarkdown($incident, $zip, $folder));
        }

        $zip->close();

        return $path;
    }

    private function buildIndex(Collection $incidents): string
    {
        $lines = ['# Incident corpus', ''];
        foreach ($incidents as $incident) {
            $lines[] = sprintf(
                '- %s — %s (%s, %s, %s)',
                $incident->no,
                $incident->title,
                $incident->severity?->value ?? '-',
                $incident->incident_status?->value ?? '-',
                optional($incident->incident_date)->format('Y-m-d') ?? '-',
            );
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Full incident markdown + its documents as md files; skipped documents
     * are noted in-band so the corpus is honest about its gaps.
     */
    private function incidentMarkdown(Incident $incident, \ZipArchive $zip, string $folder): string
    {
        $markdown = $this->exporter->generate($incident);
        $skipped = [];
        $usedNames = [];

        foreach ($incident->investigationDocuments as $document) {
            $content = $document->getMarkdownContent();

            // Cached-first (ai_summarize precedent): only convert when no
            // cache exists and the record never completed a conversion.
            if (blank($content) && $document->markdown_conversion_status !== 'completed') {
                try {
                    $content = $this->converter->convert($document);
                } catch (\Throwable) {
                    $skipped[] = "`{$document->original_filename}` — skipped (conversion failed)";

                    continue;
                }
            }

            if ($content === null) {
                $skipped[] = "`{$document->original_filename}` — skipped (unsupported type)";

                continue;
            }

            if (blank($content)) {
                $skipped[] = "`{$document->original_filename}` — skipped (no content)";

                continue;
            }

            // Suffix collisions BEFORE the extension so entries keep the
            // readable `{name}_2.{ext}.md` shape (e.g. report_one_2.pdf.md).
            $ext = strtolower(pathinfo($document->original_filename, PATHINFO_EXTENSION));
            $stem = MarkdownFormatter::sanitizeFilename(
                pathinfo($document->original_filename, PATHINFO_FILENAME)
            );
            $name = $this->uniqueName(
                $stem !== '' ? $stem : "document_{$document->id}",
                $usedNames
            ).($ext !== '' ? ".{$ext}" : '').'.md';
            $zip->addFromString("{$folder}/documents/{$name}", $content);
        }

        if ($skipped !== []) {
            $markdown .= "\n\n## Document conversion notes\n\n".implode("\n", $skipped)."\n";
        }

        return $markdown;
    }

    /**
     * Dedupe with a _2/_3 suffix — paranoia for sanitized collisions
     * (`report one.pdf` and `report_one.pdf` both sanitize the same).
     */
    private function uniqueName(string $name, array &$used): string
    {
        $base = $name !== '' ? $name : 'untitled';
        $candidate = $base;
        for ($i = 2; array_key_exists($candidate, $used); $i++) {
            $candidate = "{$base}_{$i}";
        }
        $used[$candidate] = true;

        return $candidate;
    }
}
