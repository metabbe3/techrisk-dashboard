<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Incident;
use App\Services\Markdown\MarkdownFormatter;
use Illuminate\Support\Str;

/**
 * Formats incident data as text strings for different contexts:
 * - Inline: compact single-line with markdown link (search results, recent list)
 * - Compact: multi-line block (RAG context, chat context)
 * - Full: detailed markdown sections (War Room tool output)
 */
class IncidentFormatter
{
    /**
     * Render a full incident as a markdown document (API export format).
     * Expects the incident to be loaded with Incident::FULL_RELATIONS.
     */
    public static function toMarkdown(Incident $incident): string
    {
        $md = [];

        // Header
        $md[] = "# {$incident->title}";
        $md[] = '';
        $md[] = "**Incident ID:** {$incident->no}";
        $md[] = '';

        // Basic Info
        $md[] = '## Basic Information';
        $md[] = '';
        $md[] = '| Field | Value |';
        $md[] = '|-------|-------|';
        $md[] = "| **Severity** | {$incident->severity->value} |";
        $md[] = "| **Type** | {$incident->incident_type} |";
        $md[] = "| **Source** | {$incident->incident_source} |";
        $md[] = "| **Incident Date** | {$incident->incident_date->format('Y-m-d')} |";
        $md[] = '| **Discovered At** | '.($incident->discovered_at?->format('Y-m-d H:i') ?? 'N/A').' |';
        $md[] = '| **Stop Bleeding At** | '.($incident->stop_bleeding_at?->format('Y-m-d H:i') ?? 'N/A').' |';
        $md[] = '| **Entry Date** | '.($incident->entry_date_tech_risk?->format('Y-m-d') ?? 'N/A').' |';
        $md[] = '';

        // Summary
        $md[] = '## Summary';
        $md[] = '';
        $md[] = $incident->summary ?? 'No summary provided.';
        $md[] = '';

        // Root Cause
        if ($incident->root_cause) {
            $md[] = '## Root Cause';
            $md[] = '';
            $md[] = $incident->root_cause;
            $md[] = '';
        }

        // Financial Impact
        $md[] = '## Financial Impact';
        $md[] = '';
        $md[] = '- **Potential Fund Loss:** '.($incident->potential_fund_loss ? number_format((float) $incident->potential_fund_loss) : 'N/A');
        $md[] = '- **Actual Fund Loss:** '.($incident->fund_loss ? number_format((float) $incident->fund_loss) : 'N/A');
        $md[] = '';

        // PIC
        if ($incident->pic) {
            $md[] = '## Person In Charge';
            $md[] = '';
            $md[] = "- **Name:** {$incident->pic->name}";
            $md[] = '';
        }

        // Third Party
        if ($incident->third_party_client) {
            $md[] = '## Third Party';
            $md[] = '';
            $md[] = $incident->third_party_client;
            $md[] = '';
        }

        // Labels/Tags
        if ($incident->labels && $incident->labels->isNotEmpty()) {
            $md[] = '## Labels';
            $md[] = '';
            foreach ($incident->labels as $label) {
                $md[] = "- `{$label->name}`";
            }
            $md[] = '';
        }

        // Status Updates
        if ($incident->statusUpdates && $incident->statusUpdates->isNotEmpty()) {
            $md[] = '## Status Updates';
            $md[] = '';
            foreach ($incident->statusUpdates as $update) {
                $md[] = "### {$update->updated_at->format('Y-m-d H:i')} - {$update->status}";
                $md[] = '';
                $md[] = $update->notes ?? 'No notes.';
                $md[] = '';
            }
        }

        // Action Improvements
        if ($incident->actionImprovements && $incident->actionImprovements->isNotEmpty()) {
            $md[] = '## Action Improvements';
            $md[] = '';
            foreach ($incident->actionImprovements as $action) {
                $statusIcon = $action->status === 'done' ? '✅' : '🔄';
                $md[] = "### {$statusIcon} {$action->title}";
                $md[] = '';
                $md[] = $action->detail;
                $md[] = '';
                // Safe date handling
                $dueDate = $action->due_date;
                if (is_string($dueDate)) {
                    $md[] = "- **Due Date:** {$dueDate}";
                } elseif ($dueDate && method_exists($dueDate, 'format')) {
                    $md[] = "- **Due Date:** {$dueDate->format('Y-m-d')}";
                } else {
                    $md[] = '- **Due Date:** N/A';
                }
                $md[] = "- **Status:** {$action->status}";
                $md[] = '';
            }
        }

        // Investigation Documents
        if ($incident->investigationDocuments && $incident->investigationDocuments->isNotEmpty()) {
            $md[] = '## Investigation Documents';
            $md[] = '';
            foreach ($incident->investigationDocuments as $doc) {
                $md[] = "### {$doc->title}";
                $md[] = '';
                $md[] = $doc->content ?? 'No content.';
                $md[] = '';
            }
        }

        // Metadata
        $md[] = '---';
        $md[] = '';
        $md[] = '*Reported by: '.($incident->reported_by ?? 'N/A').'*';

        return implode("\n", $md);
    }

    /**
     * Format an incident as a compact single line with markdown link.
     * Used in: getRecentIncidents, formatSmartSearchContext (full detail).
     *
     * Options:
     *  - show_fund_loss (bool): include fund loss field (default true)
     *  - show_potential_loss (bool): include potential loss field (default true)
     *  - show_classification (bool): include classification field (default true)
     *  - show_summary (bool): include summary field (default true)
     *  - show_root_cause (bool): include root cause field (default true)
     *  - show_root_cause_category (bool): include RC categories field (default true)
     *  - show_team (bool): include responsible team field (default true)
     *  - show_business_category (bool): include business category field (default true)
     *  - show_actions (bool): include action improvements field (default true)
     *  - show_docs (bool): include investigation documents field (default true)
     *  - show_match_criteria (bool): include match criteria field (default false)
     *  - compact_fund_label (bool): use "Loss:" instead of "Fund Loss:" (default false)
     */
    public static function formatInline(Incident $incident, array $options = []): string
    {
        $labels = $incident->labels->pluck('name')->implode(', ') ?: 'None';
        $pic = $incident->pic?->name ?? 'Unassigned';
        $date = $incident->incident_date?->format('Y-m-d');

        $parts = [
            "[{$incident->no}](/admin/incidents/{$incident->id})",
            $incident->title,
            "id:{$incident->id}",
        ];

        if ($options['show_classification'] ?? true) {
            $parts[] = $incident->classification->value;
        }

        $parts[] = $incident->incident_type;

        $parts[] = "Severity: {$incident->severity->value}";
        $parts[] = "Status: {$incident->incident_status->value}";
        $parts[] = "PIC: {$pic}";

        if ($options['show_date'] ?? true) {
            $parts[] = "Date: {$date}";
        }

        if ($options['show_fund_loss'] ?? true) {
            if ($incident->fund_loss > 0) {
                $label = ($options['compact_fund_label'] ?? false) ? 'Loss' : 'Fund Loss';
                $parts[] = "{$label}: ".MarkdownFormatter::formatMoney((float) $incident->fund_loss);
            }
        }

        if ($options['show_potential_loss'] ?? true) {
            if ($incident->potential_fund_loss > 0) {
                $parts[] = 'Potential Loss: '.MarkdownFormatter::formatMoney((float) $incident->potential_fund_loss);
            }
        }

        if ($options['show_mttr'] ?? true) {
            $parts[] = "MTTR: {$incident->mttr}";
        }
        if ($options['show_mtbf'] ?? true) {
            $parts[] = "MTBF: {$incident->mtbf}";
        }
        $parts[] = "Labels: {$labels}";

        if (($options['show_summary'] ?? true) && $incident->summary) {
            $parts[] = "Summary: {$incident->summary}";
        }

        if (($options['show_root_cause'] ?? true) && $incident->root_cause) {
            $parts[] = "Root Cause: {$incident->root_cause}";
        }

        if (($options['show_root_cause_category'] ?? true) && $incident->root_cause_category) {
            $parts[] = 'RC Categories: '.implode(', ', $incident->root_cause_category);
        }

        if (($options['show_team'] ?? true) && $incident->responsible_team) {
            $parts[] = 'Team: '.implode(', ', $incident->responsible_team);
        }

        if (($options['show_business_category'] ?? true) && $incident->business_category) {
            $bizCat = is_array($incident->business_category)
                ? implode(', ', $incident->business_category)
                : $incident->business_category;
            $parts[] = "Biz Category: {$bizCat}";
        }

        if (($options['show_actions'] ?? true) && $incident->actionImprovements->isNotEmpty()) {
            $actions = $incident->actionImprovements
                ->map(fn ($a) => "[{$a->status}] {$a->title}".($a->due_date ? ' (due: '.(is_string($a->due_date) ? $a->due_date : $a->due_date->format('Y-m-d')).')' : ''))
                ->implode('; ');
            $parts[] = "Actions: {$actions}";
        }

        if (($options['show_docs'] ?? true) && $incident->investigationDocuments->isNotEmpty()) {
            $docs = $incident->investigationDocuments
                ->map(fn ($d) => "\"{$d->original_filename}\"")
                ->implode(', ');
            $parts[] = "Docs: {$docs}";
        }

        if (($options['show_match_criteria'] ?? false) && ! empty($incident->match_criteria)) {
            $parts[] = 'matched_via: '.implode('+', $incident->match_criteria);
        }

        if (($options['show_discovered'] ?? false) && $incident->discovered_at) {
            $parts[] = 'Discovered: '.$incident->discovered_at->format('Y-m-d H:i');
        }

        if (($options['show_evidence'] ?? false) && ($incident->evidence || $incident->evidence_link)) {
            $parts[] = 'Has Evidence: Yes';
        }

        if (($options['show_recurrence'] ?? false) && ! empty($incident->recurrence_data['is_recurring'])) {
            $parts[] = 'Recurring: Yes';
        }

        if (($options['show_investigation_status'] ?? false) && $incident->investigation_pic_status) {
            $parts[] = 'Investigation: '.$incident->investigation_pic_status;
        }

        return '- '.implode(' | ', $parts);
    }

    /**
     * Format an incident as a multi-line block with bullet points.
     * Used in: RagService buildContextContent.
     */
    public static function formatCompact(Incident $incident): string
    {
        $lines = [];

        $lines[] = "## {$incident->no} - ".($incident->title ?? 'Untitled');
        $meta = collect([
            "Severity: {$incident->severity->value}",
            "Status: {$incident->incident_status->value}",
            "Type: {$incident->incident_type}",
            'Date: '.($incident->incident_date?->format('Y-m-d') ?? 'N/A'),
        ])->implode(' | ');
        $lines[] = "- {$meta}";

        if ($incident->pic) {
            $lines[] = "- PIC: {$incident->pic->name}";
        }

        if ($incident->fund_loss > 0 || $incident->potential_fund_loss > 0) {
            $fundParts = [];
            if ($incident->potential_fund_loss > 0) {
                $fundParts[] = 'Potential: '.MarkdownFormatter::formatMoney((float) $incident->potential_fund_loss);
            }
            if ($incident->fund_loss > 0) {
                $fundParts[] = 'Actual: '.MarkdownFormatter::formatMoney((float) $incident->fund_loss);
            }
            if ($incident->recovered_fund > 0) {
                $fundParts[] = 'Recovered: '.MarkdownFormatter::formatMoney((float) $incident->recovered_fund);
            }
            $lines[] = '- Fund: '.implode(' | ', $fundParts);
        }

        if ($incident->root_cause) {
            $lines[] = '- Root Cause: '.Str::limit($incident->root_cause, 300);
        }

        $actions = $incident->actionImprovements;
        if ($actions && $actions->isNotEmpty()) {
            $actionStr = $actions->take(5)->map(fn ($a) => ($a->is_completed ? '[DONE]' : '[PENDING]')." {$a->title}")->implode('; ');
            $lines[] = "- Actions: {$actionStr}";
        }

        $labels = $incident->labels->pluck('name')->implode(', ');
        if ($labels) {
            $lines[] = "- Labels: {$labels}";
        }

        $categories = collect([$incident->business_category, $incident->root_cause_category, $incident->responsible_team])
            ->filter()->flatten()->unique()->implode(', ');
        if ($categories) {
            $lines[] = "- Categories: {$categories}";
        }

        return implode("\n", $lines);
    }

    /**
     * Format an incident with full detail as markdown sections.
     * Used in: WarRoomToolExecutor getIncidentDetails.
     */
    public static function formatFull(Incident $incident): string
    {
        $parts = [
            "# {$incident->no} - ".($incident->title ?? 'Untitled'),
            "Severity: {$incident->severity->value} | Status: {$incident->incident_status->value}",
            "Date: {$incident->incident_date?->format('Y-m-d')} | PIC: ".($incident->pic?->name ?? 'Unassigned'),
            "Classification: {$incident->classification->value} | Type: {$incident->incident_type}",
        ];

        if ($incident->summary) {
            $parts[] = "\n## Summary\n{$incident->summary}";
        }

        if ($incident->root_cause) {
            $parts[] = "\n## Root Cause\n".Str::limit($incident->root_cause, 2000);
        }

        if ($incident->timeline) {
            $parts[] = "\n## Timeline\n".Str::limit($incident->timeline, 1000);
        }

        $fundFields = array_filter([
            $incident->potential_fund_loss ? 'Potential Loss: '.MarkdownFormatter::formatMoney((float) $incident->potential_fund_loss) : null,
            $incident->fund_loss ? 'Actual Loss: '.MarkdownFormatter::formatMoney((float) $incident->fund_loss) : null,
            $incident->recovered_fund ? 'Recovered: '.MarkdownFormatter::formatMoney((float) $incident->recovered_fund) : null,
        ]);
        if (! empty($fundFields)) {
            $parts[] = "\n## Financial Impact\n".implode(' | ', $fundFields);
        }

        if ($incident->actionImprovements->isNotEmpty()) {
            $actions = $incident->actionImprovements->map(fn ($a) => "- [{$a->status}] {$a->title}".($a->due_date ? " (Due: {$a->due_date?->format('Y-m-d')})" : ''))->implode("\n");
            $parts[] = "\n## Action Items\n{$actions}";
        }

        return implode("\n", $parts);
    }

    public static function extractSafeTitleWords(string $title): array
    {
        $stopWords = ['the', 'a', 'an', 'of', 'in', 'on', 'at', 'to', 'for', 'and', 'or', 'is', 'was', 'by', 'with', 'from', 'due', 'not'];
        $words = preg_split('/[\s\-_:;,]+/', $title);
        $safe = [];

        foreach (array_slice($words, 0, 8) as $word) {
            $word = trim($word);
            if (strlen($word) < 3 || in_array(strtolower($word), $stopWords)) {
                continue;
            }
            if (preg_match('/^[\d_]+$/', $word) || preg_match('/^[A-Z]{2,}[_-]/', $word)) {
                continue;
            }
            $safe[] = $word;
        }

        return array_slice($safe, 0, 4);
    }

    public static function extractTechnicalKeywords(string $text): array
    {
        $keywords = [];

        if (preg_match_all('/\b(?:MySQL|PostgreSQL|MongoDB|Redis|Elasticsearch|MariaDB|Oracle|SQL\s*Server|Cassandra|Couchbase|DynamoDB)\b/i', $text, $matches)) {
            $keywords = array_merge($keywords, $matches[0]);
        }

        if (preg_match_all('/\b(?:Kafka|RabbitMQ|ActiveMQ|SQS|Kinesis|Pub\/Sub|NATS|Pulsar)\b/i', $text, $matches)) {
            $keywords = array_merge($keywords, $matches[0]);
        }

        if (preg_match_all('/\b(?:HTTP|HTTPS|gRPC|WebSocket|TCP|UDP|FTP|SSH|TLS|SSL|DNS|SMTP)\b/i', $text, $matches)) {
            $keywords = array_merge($keywords, array_map('strtoupper', $matches[0]));
        }

        if (preg_match_all('/\b(?:timeout|deadlock|OOM|out\s+of\s+memory|connection\s+(?:pool|refused|reset)|segmentation\s+fault|stack\s+overflow|race\s+condition)\b/i', $text, $matches)) {
            $keywords = array_merge($keywords, $matches[0]);
        }

        if (preg_match_all('/\b(?:Kubernetes|Docker|container|pod|cluster|load\s+balancer|proxy|CDN|firewall|VPN)\b/i', $text, $matches)) {
            $keywords = array_merge($keywords, $matches[0]);
        }

        if (preg_match_all('/\b(?:Java|Python|PHP|Node\.?js|Go|Golang|Ruby|\.NET|Spring|Laravel|Django|Flask|Express|React|Vue)\b/i', $text, $matches)) {
            $keywords = array_merge($keywords, $matches[0]);
        }

        return array_unique(array_filter($keywords));
    }
}
