<?php

declare(strict_types=1);

namespace App\Services\Markdown;

use App\Models\Incident;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentMarkdownExporter
{
    private static function formatDate(mixed $value, string $format): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format($format);
        }
        if (is_string($value)) {
            return $value;
        }

        return (string) $value;
    }
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
     * Generate compact markdown — strips empty sections, keeps only data that exists.
     * Used for multi-incident sessions to reduce token usage.
     */
    public function generateCompact(Incident $incident): string
    {
        $incident->load([
            'incidentType',
            'pic',
            'labels',
            'statusUpdates' => fn ($query) => $query->limit(5)->orderBy('created_at', 'desc'),
            'investigationDocuments',
            'actionImprovements',
        ]);

        $lines = [];
        $lines[] = "# {$incident->no}";
        $lines[] = "**Title:** {$incident->title}";
        $lines[] = "**Classification:** {$incident->classification} | **Severity:** {$incident->severity} | **Status:** {$incident->incident_status}";
        $lines[] = "**Type:** " . ($incident->incidentType?->name ?? 'N/A');
        $lines[] = "**PIC:** " . ($incident->pic ? "{$incident->pic->name} ({$incident->pic->email})" : 'N/A');

        if ($incident->summary) {
            $lines[] = "\n## Summary\n{$incident->summary}";
        }

        if ($incident->incident_date || $incident->discovered_at) {
            $lines[] = "\n## Timeline";
            $lines[] = "- Incident Date: " . (self::formatDate($incident->incident_date, 'Y-m-d H:i') ?? 'N/A');
            $lines[] = "- Discovered: " . (self::formatDate($incident->discovered_at, 'Y-m-d H:i') ?? 'N/A');
            if ($incident->stop_bleeding_at) {
                $lines[] = "- Stop Bleeding: " . self::formatDate($incident->stop_bleeding_at, 'Y-m-d H:i');
            }
            if ($incident->entry_date_tech_risk) {
                $lines[] = "- Entry Date: " . self::formatDate($incident->entry_date_tech_risk, 'Y-m-d H:i');
            }
        }

        if ($incident->timeline) {
            $lines[] = "\n## Timeline Details\n{$incident->timeline}";
        }

        if ($incident->root_cause) {
            $lines[] = "\n## Root Cause\n{$incident->root_cause}";
        }

        if ($incident->potential_fund_loss || $incident->recovered_fund || $incident->fund_loss) {
            $lines[] = "\n## Financial Impact";
            if ($incident->fund_status) $lines[] = "- Fund Status: {$incident->fund_status}";
            if ($incident->potential_fund_loss) $lines[] = "- Potential Loss: " . MarkdownFormatter::formatMoney((float) $incident->potential_fund_loss);
            if ($incident->recovered_fund) $lines[] = "- Recovered: " . MarkdownFormatter::formatMoney((float) $incident->recovered_fund);
            if ($incident->fund_loss) $lines[] = "- Actual Loss: " . MarkdownFormatter::formatMoney((float) $incident->fund_loss);
            if ($incident->loss_taken_by) $lines[] = "- Loss Taken By: {$incident->loss_taken_by}";
        }

        if ($incident->mttr || $incident->mtbf) {
            $lines[] = "\n## Metrics";
            if ($incident->mttr) $lines[] = "- MTTR: " . MarkdownFormatter::formatDuration((float) $incident->mttr);
            if ($incident->mtbf) $lines[] = "- MTBF: " . number_format((float) $incident->mtbf, 2) . ' days';
        }

        if ($incident->labels->isNotEmpty()) {
            $lines[] = "\n## Labels";
            foreach ($incident->labels as $label) {
                $lines[] = "- `{$label->name}`";
            }
        }

        if ($incident->actionImprovements->isNotEmpty()) {
            $lines[] = "\n## Action Items";
            foreach ($incident->actionImprovements as $action) {
                $status = $action->is_completed ? 'DONE' : ($action->status ?? 'PENDING');
                $lines[] = "- **{$action->title}** [{$status}]" . ($action->due_date ? " — Due: " . self::formatDate($action->due_date, 'Y-m-d') : '');
            }
        }

        if ($incident->evidence) {
            $lines[] = "\n## Evidence\n{$incident->evidence}";
        }

        if ($incident->remark) {
            $lines[] = "\n## Remarks\n{$incident->remark}";
        }

        if ($incident->investigationDocuments->isNotEmpty()) {
            $lines[] = "\n## Investigation Documents";
            foreach ($incident->investigationDocuments as $doc) {
                $lines[] = "\n### {$doc->original_filename}";
                if ($doc->description) {
                    $lines[] = "Description: {$doc->description}";
                }
                if ($doc->ai_summary) {
                    $lines[] = "\n**AI Summary:**";
                    $lines[] = $doc->ai_summary;
                }
            }
        }

        return implode("\n", $lines);
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
        $date = self::formatDate($incident->incident_date, 'Y-m-d') ?? 'unknown';

        return "{$incident->no}_{$date}_{$safeTitle}.md";
    }

    /**
     * Generate markdown context for multiple incidents.
     * Returns array of labeled markdown strings.
     */
    public function generateForIncidents(Collection $incidents): array
    {
        $contexts = [];
        foreach ($incidents as $incident) {
            $contexts[] = "--- Incident: {$incident->no} ({$incident->severity}) ---";
            $contexts[] = $this->generate($incident);
        }

        return $contexts;
    }
}
