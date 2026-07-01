<?php

declare(strict_types=1);

namespace App\Services\Markdown;

use App\Models\Incident;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        $lines[] = "**Classification:** {$incident->classification->value} | **Severity:** {$incident->severity->value} | **Status:** {$incident->incident_status->value}";
        $lines[] = '**Type:** '.($incident->incidentType?->name ?? 'N/A');
        $lines[] = '**PIC:** '.($incident->pic ? "{$incident->pic->name} ({$incident->pic->email})" : 'N/A');

        if ($incident->incident_source) {
            $lines[] = "**Source:** {$incident->incident_source}";
        }
        if ($incident->reported_by) {
            $lines[] = "**Reported By:** {$incident->reported_by}";
        }
        if ($incident->third_party_client) {
            $lines[] = "**Third Party:** {$incident->third_party_client}";
        }

        if ($incident->summary) {
            $lines[] = "\n## Summary\n{$incident->summary}";
        }

        if ($incident->incident_date || $incident->discovered_at) {
            $lines[] = "\n## Timeline";
            $lines[] = '- Incident Date: '.(self::formatDate($incident->incident_date, 'Y-m-d H:i') ?? 'N/A');
            $lines[] = '- Discovered: '.(self::formatDate($incident->discovered_at, 'Y-m-d H:i') ?? 'N/A');
            if ($incident->stop_bleeding_at) {
                $lines[] = '- Stop Bleeding: '.self::formatDate($incident->stop_bleeding_at, 'Y-m-d H:i');
            }
            if ($incident->entry_date_tech_risk) {
                $lines[] = '- Entry Date: '.self::formatDate($incident->entry_date_tech_risk, 'Y-m-d H:i');
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
            if ($incident->fund_status) {
                $lines[] = "- Fund Status: {$incident->fund_status->value}";
            }
            if ($incident->potential_fund_loss) {
                $lines[] = '- Potential Loss: '.MarkdownFormatter::formatMoney((float) $incident->potential_fund_loss);
            }
            if ($incident->recovered_fund) {
                $lines[] = '- Recovered: '.MarkdownFormatter::formatMoney((float) $incident->recovered_fund);
            }
            if ($incident->fund_loss) {
                $lines[] = '- Actual Loss: '.MarkdownFormatter::formatMoney((float) $incident->fund_loss);
            }
            if ($incident->loss_taken_by) {
                $lines[] = "- Loss Taken By: {$incident->loss_taken_by}";
            }
        }

        if ($incident->mttr || $incident->mtbf) {
            $lines[] = "\n## Metrics";
            if ($incident->mttr) {
                $lines[] = '- MTTR: '.MarkdownFormatter::formatDuration((float) $incident->mttr);
            }
            if ($incident->mtbf) {
                $lines[] = '- MTBF: '.number_format((float) $incident->mtbf, 2).' days';
            }
            foreach ([
                'mtbf_completed' => 'MTBF (Completed)', 'mtbf_recovered' => 'MTBF (Recovered)',
                'mtbf_p4' => 'MTBF (P4)', 'mtbf_non_tech' => 'MTBF (Non-Tech)',
                'mtbf_fund_loss' => 'MTBF (Fund Loss)', 'mtbf_non_fund_loss' => 'MTBF (Non-Fund Loss)',
                'mtbf_potential_recovery' => 'MTBF (Potential Recovery)', 'mtbf_fully_recovered' => 'MTBF (Fully Recovered)',
                'mtbf_non_tech_loss' => 'MTBF (Non-Tech Loss)', 'mtbf_non_incident' => 'MTBF (Non-Incident)',
                'mtbf_all' => 'MTBF (All)',
            ] as $col => $label) {
                if ($incident->$col !== null) {
                    $lines[] = "- {$label}: ".number_format((float) $incident->$col, 2).' days';
                }
            }
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
                $pic = is_array($action->pic_email) ? implode(', ', $action->pic_email) : ($action->pic_email ?? '');
                $lines[] = "- **{$action->title}** [{$status}]".($action->due_date ? ' — Due: '.self::formatDate($action->due_date, 'Y-m-d') : '').($pic ? " — PIC: {$pic}" : '');
            }
        }

        if ($incident->statusUpdates->isNotEmpty()) {
            $lines[] = "\n## Status Updates";
            foreach ($incident->statusUpdates as $update) {
                $date = self::formatDate($update->created_at, 'Y-m-d') ?? self::formatDate($update->update_date, 'Y-m-d') ?? '';
                $line = "- [{$date}] {$update->status}";
                if ($update->notes) {
                    $line .= ": {$update->notes}";
                }
                $lines[] = $line;
            }
        }

        if ($incident->evidence) {
            $lines[] = "\n## Evidence\n{$incident->evidence}";
        }

        if ($incident->evidence_link) {
            $lines[] = "\n## Evidence Link\n{$incident->evidence_link}";
        }

        if ($incident->remark) {
            $lines[] = "\n## Remarks\n{$incident->remark}";
        }

        if ($incident->improvements) {
            $lines[] = "\n## Improvements\n{$incident->improvements}";
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
     * Generate AI-optimized markdown for context injection.
     * Used by ChatContextService when incidents are referenced by the user.
     * Includes ALL columns but suppresses null/empty/zero fields to minimize tokens.
     */
    public function generateForContext(Incident $incident, array $sections = [], array $columns = []): string
    {
        $incident->load([
            'incidentType',
            'pic',
            'labels',
            'statusUpdates' => fn ($query) => $query->orderBy('created_at', 'desc')->limit(10),
            'investigationDocuments',
            'actionImprovements',
        ]);

        $colSet = empty($columns) ? [] : array_flip(array_map('strtolower', $columns));
        $has = fn (string $section): bool => empty($sections) || in_array($section, $sections);

        $lines = [];

        // header — always included
        $lines[] = "## Referenced Incident: [{$incident->no}](/admin/incidents/{$incident->id})";
        $lines[] = "ID: {$incident->id}";
        $lines[] = "URL: /admin/incidents/{$incident->id}";
        $lines[] = "**Title:** {$incident->title}";
        $lines[] = "**Severity:** {$incident->severity->value} | **Status:** {$incident->incident_status->value} | **Classification:** {$incident->classification->value}";
        $lines[] = '**Type:** '.($incident->incidentType?->name ?? $incident->incident_type ?? 'N/A');

        if ($incident->incident_source) {
            $lines[] = "**Source:** {$incident->incident_source}";
        }
        if ($incident->incident_category) {
            $lines[] = "**Category:** {$incident->incident_category}";
        }
        if ($incident->glitch_flag) {
            $lines[] = '**Glitch Flag:** Yes';
        }

        // People
        if ($has('people')) {
            $people = [];
            $people[] = 'PIC: '.($incident->pic ? "{$incident->pic->name} ({$incident->pic->email})" : 'N/A');
            if ($incident->reported_by) {
                $people[] = "Reported By: {$incident->reported_by}";
            }
            if ($incident->third_party_client) {
                $people[] = "Third Party/Client: {$incident->third_party_client}";
            }
            if ($incident->checker) {
                $people[] = "Checker: {$incident->checker}";
            }
            if ($incident->maker) {
                $people[] = "Maker: {$incident->maker}";
            }
            $lines[] = implode(' | ', $people);
        }

        // Dates
        if ($has('dates')) {
            $dates = [];
            $dates[] = 'Incident Date: '.(self::formatDate($incident->incident_date, 'Y-m-d H:i') ?? 'N/A');
            if ($incident->discovered_at) {
                $dates[] = 'Discovered: '.self::formatDate($incident->discovered_at, 'Y-m-d H:i');
            }
            if ($incident->stop_bleeding_at) {
                $dates[] = 'Stop Bleeding: '.self::formatDate($incident->stop_bleeding_at, 'Y-m-d H:i');
            }
            if ($incident->entry_date_tech_risk) {
                $dates[] = 'Entry Date: '.self::formatDate($incident->entry_date_tech_risk, 'Y-m-d');
            }
            $lines[] = implode(' | ', $dates);
        }

        // Text fields
        if ($incident->summary && $has('summary')) {
            $lines[] = "\n### Summary\n{$incident->summary}";
        }
        if ($incident->root_cause && $has('root_cause')) {
            $lines[] = "\n### Root Cause\n{$incident->root_cause}";
        }
        if ($incident->timeline && $has('timeline_details')) {
            $lines[] = "\n### Timeline Details\n{$incident->timeline}";
        }
        if ($incident->evidence && $has('evidence')) {
            $lines[] = "\n### Evidence\n{$incident->evidence}";
        }
        if ($incident->evidence_link && $has('evidence_link')) {
            $lines[] = "\n### Evidence Link\n{$incident->evidence_link}";
        }
        if ($incident->remark && $has('remarks')) {
            $lines[] = "\n### Remarks\n{$incident->remark}";
        }
        if ($incident->improvements && $has('improvements')) {
            $lines[] = "\n### Improvements\n{$incident->improvements}";
        }

        // Financial
        if ($has('financial')) {
            $financial = [];
            if ($incident->fund_status) {
                $financial[] = "Fund Status: {$incident->fund_status->value}";
            }
            if ($incident->potential_fund_loss) {
                $financial[] = 'Potential Loss: '.MarkdownFormatter::formatMoney((float) $incident->potential_fund_loss);
            }
            if ($incident->fund_loss) {
                $financial[] = 'Actual Loss: '.MarkdownFormatter::formatMoney((float) $incident->fund_loss);
            }
            if ($incident->recovered_fund) {
                $financial[] = 'Recovered: '.MarkdownFormatter::formatMoney((float) $incident->recovered_fund);
            }
            if ($incident->loss_taken_by) {
                $financial[] = "Loss Taken By: {$incident->loss_taken_by}";
            }
            if (! empty($financial)) {
                $lines[] = "\n### Financial Impact\n".implode(' | ', $financial);
            }
        }

        // Metrics — MTTR + MTBF variants
        if ($has('metrics')) {
            $metrics = [];
            if ($incident->mttr !== null && (empty($colSet) || isset($colSet['mttr']))) {
                $metrics[] = 'MTTR: '.MarkdownFormatter::formatDuration((float) $incident->mttr);
            }
            if ($incident->mtbf !== null && (empty($colSet) || isset($colSet['mtbf']))) {
                $metrics[] = 'MTBF: '.number_format((float) $incident->mtbf, 2).' days';
            }
            $mtbfVariants = [
                'mtbf_completed' => 'MTBF (Completed)',
                'mtbf_recovered' => 'MTBF (Recovered)',
                'mtbf_p4' => 'MTBF (P4)',
                'mtbf_non_tech' => 'MTBF (Non-Tech)',
                'mtbf_fund_loss' => 'MTBF (Fund Loss)',
                'mtbf_non_fund_loss' => 'MTBF (Non-Fund Loss)',
                'mtbf_potential_recovery' => 'MTBF (Potential Recovery)',
                'mtbf_fully_recovered' => 'MTBF (Fully Recovered)',
                'mtbf_non_tech_loss' => 'MTBF (Non-Tech Loss)',
                'mtbf_non_incident' => 'MTBF (Non-Incident)',
                'mtbf_all' => 'MTBF (All)',
            ];
            foreach ($mtbfVariants as $col => $label) {
                if ($incident->$col !== null && (empty($colSet) || isset($colSet[$col]))) {
                    $metrics[] = "{$label}: ".number_format((float) $incident->$col, 2).' days';
                }
            }
            if (! empty($metrics)) {
                $lines[] = "\n### Metrics\n".implode(' | ', $metrics);
            }
        }

        // Categories
        if ($has('categories')) {
            $categories = [];
            if (! empty($incident->business_category) && is_array($incident->business_category)) {
                $categories[] = 'Business: '.implode(', ', $incident->business_category);
            }
            if (! empty($incident->root_cause_category) && is_array($incident->root_cause_category)) {
                $categories[] = 'Root Cause: '.implode(', ', $incident->root_cause_category);
            }
            if (! empty($incident->responsible_team) && is_array($incident->responsible_team)) {
                $categories[] = 'Team: '.implode(', ', $incident->responsible_team);
            }
            if (! empty($categories)) {
                $lines[] = "\n### Categories\n".implode(' | ', $categories);
            }
        }

        // Labels
        if ($incident->labels->isNotEmpty() && $has('labels')) {
            $lines[] = "\n### Labels\n".$incident->labels->pluck('name')->map(fn ($l) => "`{$l}`")->implode(', ');
        }

        // Status updates
        if ($incident->statusUpdates->isNotEmpty() && $has('status_updates')) {
            $lines[] = "\n### Status Updates";
            foreach ($incident->statusUpdates as $update) {
                $date = self::formatDate($update->created_at, 'Y-m-d H:i') ?? self::formatDate($update->update_date, 'Y-m-d H:i') ?? 'N/A';
                $line = "- **{$date}** [{$update->status}]";
                if ($update->notes) {
                    $line .= ": {$update->notes}";
                }
                $lines[] = $line;
            }
        }

        // Investigation documents
        if ($incident->investigationDocuments->isNotEmpty() && $has('investigation_docs')) {
            $lines[] = "\n### Investigation Documents";
            foreach ($incident->investigationDocuments as $doc) {
                $lines[] = "- **{$doc->original_filename}**";
                if ($doc->description) {
                    $lines[] = "  Description: {$doc->description}";
                }
                if ($doc->ai_summary) {
                    $lines[] = "  AI Summary: {$doc->ai_summary}";
                }
            }
        }

        // Action improvements
        if ($incident->actionImprovements->isNotEmpty() && $has('action_improvements')) {
            $lines[] = "\n### Action Improvements";
            foreach ($incident->actionImprovements as $action) {
                $status = $action->is_completed ? 'DONE' : ($action->status ?? 'PENDING');
                $due = $action->due_date ? ' (Due: '.self::formatDate($action->due_date, 'Y-m-d').')' : '';
                $pic = is_array($action->pic_email) ? implode(', ', $action->pic_email) : ($action->pic_email ?? '');
                $lines[] = "- **{$action->title}** [{$status}]{$due}".($pic ? " — PIC: {$pic}" : '');
            }
        }

        // Recurrence data
        if ($has('recurrence') && ! empty($incident->recurrence_data) && is_array($incident->recurrence_data)) {
            if (! empty($incident->recurrence_data['is_recurring'])) {
                $lines[] = "\n### Recurrence Analysis";
                $lines[] = '**This is a recurring incident.**';
                if (! empty($incident->recurrence_data['ai_analysis'])) {
                    $lines[] = $incident->recurrence_data['ai_analysis'];
                }
                if (! empty($incident->recurrence_data['matches'])) {
                    foreach (array_slice($incident->recurrence_data['matches'], 0, 3) as $match) {
                        $no = $match['no'] ?? 'Unknown';
                        $score = $match['score'] ?? 'N/A';
                        $summary = $match['summary'] ?? '';
                        $lines[] = "- Similar: [{$no}] (score: {$score})".($summary ? " — {$summary}" : '');
                    }
                }
            }
        }

        // Investigation PIC status
        if ($incident->investigation_pic_status && $has('investigation_status')) {
            $lines[] = "\n**Investigation PIC Status:** {$incident->investigation_pic_status}";
        }

        return implode("\n", $lines);
    }

    /**
     * Generate minimal markdown — only core fields, truncated text.
     * Used as last-resort compression for models with small context windows.
     */
    public function generateMinimal(Incident $incident): string
    {
        $incident->load([
            'incidentType',
            'pic',
            'labels',
            'actionImprovements',
        ]);

        $lines = [];
        $lines[] = "# {$incident->no}";
        $lines[] = "**Title:** {$incident->title}";
        $lines[] = "**Classification:** {$incident->classification->value} | **Severity:** {$incident->severity->value} | **Status:** {$incident->incident_status->value}";
        $lines[] = '**Type:** '.($incident->incidentType?->name ?? 'N/A');
        $lines[] = '**PIC:** '.($incident->pic ? "{$incident->pic->name}" : 'N/A');

        if ($incident->summary) {
            $lines[] = "\n## Summary\n".Str::limit($incident->summary, 300);
        }

        if ($incident->incident_date || $incident->discovered_at) {
            $lines[] = "\n## Timeline";
            $lines[] = '- Incident Date: '.(self::formatDate($incident->incident_date, 'Y-m-d H:i') ?? 'N/A');
            $lines[] = '- Discovered: '.(self::formatDate($incident->discovered_at, 'Y-m-d H:i') ?? 'N/A');
        }

        if ($incident->root_cause) {
            $lines[] = "\n## Root Cause\n".Str::limit($incident->root_cause, 500);
        }

        if ($incident->potential_fund_loss || $incident->recovered_fund || $incident->fund_loss) {
            $lines[] = "\n## Financial Impact";
            if ($incident->fund_status) {
                $lines[] = "- Fund Status: {$incident->fund_status->value}";
            }
            if ($incident->potential_fund_loss) {
                $lines[] = '- Potential Loss: '.MarkdownFormatter::formatMoney((float) $incident->potential_fund_loss);
            }
            if ($incident->recovered_fund) {
                $lines[] = '- Recovered: '.MarkdownFormatter::formatMoney((float) $incident->recovered_fund);
            }
            if ($incident->fund_loss) {
                $lines[] = '- Actual Loss: '.MarkdownFormatter::formatMoney((float) $incident->fund_loss);
            }
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
                $lines[] = "- **{$action->title}** [{$status}]";
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
            $contexts[] = "--- Incident: {$incident->no} ({$incident->severity->value}) ---";
            $contexts[] = $this->generate($incident);
        }

        return $contexts;
    }
}
