<?php

declare(strict_types=1);

namespace App\Services\Markdown;

use App\Filament\Actions\ExportActionSchema;
use App\Models\Incident;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The app's long-term incident memory: a persistent corpus at
 * markdown/corpus (index.md + one folder per incident with the full report
 * and converted documents). Rebuilt by one button and an hourly stale
 * check; catalog() feeds it to AI agents (owner rule 2026-10-01).
 *
 * Rendered deterministically from incident data — zero AI calls, zero
 * tokens; always mirrors the DB.
 *
 * // ponytail: inline rebuild; queued job if the corpus grows slow
 */
class IncidentMarkdownCorpusService
{
    public function __construct(
        private IncidentMarkdownExporter $exporter,
        private DocumentConverterService $converter,
    ) {}

    /**
     * The memory's scope: Incidents only, P1–P4/X1–X4 (the AI-facing rule,
     * same source as every export). Fund-status-excluded rows stay — this
     * is a knowledge base, not a metrics count.
     */
    public static function scopedQuery(): Builder
    {
        return ExportActionSchema::baseExportScope(
            Incident::query()->where('classification', 'Incident')
        );
    }

    /**
     * Wipe + rewrite the whole corpus and store the manifest. The folder
     * wipe never touches index.md (it is a file at the corpus root), and
     * the final put() overwrites it in place — so injection keeps serving
     * the OLD catalog until the new one lands, never a half-built one.
     *
     * @return array{built_at: string, incidents: int, version: int}
     */
    public function refresh(): array
    {
        $incidents = $this->scopedQuery()
            ->with('investigationDocuments')
            ->orderBy('incident_date')
            ->get();

        $disk = Storage::disk('local');

        foreach ($disk->directories('markdown/corpus') as $dir) {
            $disk->deleteDirectory($dir);
        }

        $usedFolders = [];
        foreach ($incidents as $incident) {
            $folder = 'markdown/corpus/'.$this->folderFor($incident, $usedFolders);
            foreach ($this->incidentFiles($incident) as $rel => $content) {
                $disk->put("{$folder}/{$rel}", $content);
            }
        }

        $disk->put('markdown/corpus/index.md', $this->index($incidents));

        $manifest = [
            // Second precision to match Eloquent's updated_at serialization;
            // staleness compares >= so a same-second edit reads stale once.
            'built_at' => now()->toDateTimeString(),
            'incidents' => $incidents->count(),
            'version' => (int) Cache::get('dashboard_cache_version', 0),
        ];
        Cache::forever('incident_corpus_manifest', $manifest);

        return $manifest;
    }

    /**
     * The AI-facing catalog: index lines without the H1 (the injection
     * block carries its own header). Null when the corpus was never built.
     */
    public function catalog(): ?string
    {
        $content = Storage::disk('local')->get('markdown/corpus/index.md');

        if ($content === null) {
            return null;
        }

        $lines = explode("\n", $content);
        if (($lines[0] ?? '') === '# Incident corpus') {
            array_shift($lines);
        }

        return trim(implode("\n", $lines));
    }

    /**
     * False only while nothing that feeds the corpus changed: same scoped
     * count (deletes), same metrics version (labels/status edits via
     * dashboard_cache_version), no incident or document row newer than the
     * build (text edits, new docs, conversions — docs carry timestamps).
     */
    public function isStale(): bool
    {
        $manifest = Cache::get('incident_corpus_manifest');

        if (! is_array($manifest) || empty($manifest['built_at'])) {
            return true;
        }

        if ($this->scopedQuery()->toBase()->count() !== (int) ($manifest['incidents'] ?? -1)) {
            return true;
        }

        if ((int) Cache::get('dashboard_cache_version', 0) !== (int) ($manifest['version'] ?? -1)) {
            return true;
        }

        $builtAt = \Illuminate\Support\Carbon::parse($manifest['built_at']);

        // ponytail: unscoped doc check — a doc on an out-of-scope incident
        // triggers a harmless conservative rebuild
        return $this->scopedQuery()->toBase()->where('updated_at', '>=', $builtAt)->exists()
            || \App\Models\InvestigationDocument::query()->where('updated_at', '>=', $builtAt)->exists();
    }

    /** Index.md content: H1 + one line per incident. */
    public function index(Collection $incidents): string
    {
        return "# Incident corpus\n\n".implode("\n", $this->indexLines($incidents))."\n";
    }

    /**
     * @return string[] "- {no} — {title} ({severity}, {status}, {date})"
     */
    public function indexLines(Collection $incidents): array
    {
        $lines = [];
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

        return $lines;
    }

    /** Sanitized, deduped corpus folder name for one incident. */
    public function folderFor(Incident $incident, array &$used): string
    {
        return $this->uniqueName(
            MarkdownFormatter::sanitizeFilename($incident->no ?? (string) $incident->id),
            $used
        );
    }

    /**
     * Every file of one incident: the full markdown report plus its
     * documents as md. Skipped documents are noted in-band (a
     * `## Document conversion notes` appendix) so the corpus is honest
     * about its gaps.
     *
     * @return array<string, string> relative path => content
     */
    public function incidentFiles(Incident $incident): array
    {
        $markdown = $this->exporter->generate($incident);
        $skipped = [];
        $usedNames = [];
        $files = [];

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

            $files["documents/{$name}"] = $content;
        }

        if ($skipped !== []) {
            $markdown .= "\n\n## Document conversion notes\n\n".implode("\n", $skipped)."\n";
        }

        return ['incident.md' => $markdown, ...$files];
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
