<?php

namespace App\Services\Ai;

use App\Models\Incident;
use App\Models\RagDocument;
use App\Services\IncidentFormatter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RagService
{
    public function indexIncident(Incident $incident): RagDocument
    {
        $incident->load(['pic', 'labels', 'actionImprovements']);

        $searchableContent = $this->buildSearchableContent($incident);
        $contextContent = $this->buildContextContent($incident);

        return RagDocument::updateOrCreate(
            ['incident_id' => $incident->id],
            [
                'incident_no' => $incident->no,
                'severity' => $incident->severity,
                'classification' => $incident->classification,
                'incident_status' => $incident->incident_status,
                'incident_type' => $incident->incident_type,
                'incident_date' => $incident->incident_date,
                'fund_status' => $incident->fund_status,
                'fund_loss' => $incident->fund_loss ?? 0,
                'potential_fund_loss' => $incident->potential_fund_loss ?? 0,
                'pic_id' => $incident->pic_id,
                'business_category' => $incident->business_category,
                'root_cause_category' => $incident->root_cause_category,
                'responsible_team' => $incident->responsible_team,
                'label_names' => $incident->labels->pluck('name')->toArray(),
                'searchable_content' => $searchableContent,
                'context_content' => $contextContent,
                'indexed_at' => now(),
            ]
        );
    }

    public function indexAllIncidents(): int
    {
        $count = 0;
        Incident::chunk(100, function ($incidents) use (&$count) {
            foreach ($incidents as $incident) {
                try {
                    $this->indexIncident($incident);
                    $count++;
                } catch (\Throwable $e) {
                    Log::warning("[RAG] Failed to index incident {$incident->no}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        Log::info("[RAG] Indexed {$count} incidents");

        return $count;
    }

    public function search(string $query, array $filters = [], int $limit = 10): Collection
    {
        $q = RagDocument::query();

        $this->applyFilters($q, $filters);

        if (! empty($query)) {
            $q->selectRaw('*, MATCH(searchable_content) AGAINST(? IN NATURAL LANGUAGE MODE) AS relevance_score', [$query])
                ->whereRaw('MATCH(searchable_content) AGAINST(? IN NATURAL LANGUAGE MODE)', [$query])
                ->orderByDesc('relevance_score');
        } else {
            $q->orderByDesc('incident_date');
        }

        return $q->limit($limit)->get();
    }

    public function getContextForQuery(string $query, array $filters = [], int $maxTokens = 4000): string
    {
        $results = $this->search($query, $filters);

        if ($results->isEmpty()) {
            return '';
        }

        $estimatedChars = $maxTokens * 4;
        $context = '';
        $count = 0;

        foreach ($results as $doc) {
            $entry = $doc->context_content ?? '';
            $entry .= "\n---\n";

            if (strlen($context.$entry) > $estimatedChars) {
                break;
            }

            $context .= $entry;
            $count++;
        }

        if (empty($context)) {
            return '';
        }

        return "## Relevant Incidents ({$count} found)\n\n{$context}";
    }

    public function reindexStale(): int
    {
        $stale = RagDocument::whereHas('incident', function ($q) {
            $q->whereColumn('incidents.updated_at', '>', 'rag_documents.indexed_at')
                ->orWhereNull('rag_documents.indexed_at');
        })->get();

        $count = 0;
        foreach ($stale as $doc) {
            try {
                $this->indexIncident($doc->incident);
                $count++;
            } catch (\Throwable $e) {
                Log::warning("[RAG] Failed to re-index document for incident {$doc->incident_no}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    private function buildSearchableContent(Incident $incident): string
    {
        $parts = [];

        if ($incident->title) {
            $parts[] = "[Title]: {$incident->title}";
        }
        if ($incident->summary) {
            $parts[] = "[Summary]: {$incident->summary}";
        }
        if ($incident->root_cause) {
            $parts[] = "[Root Cause]: {$incident->root_cause}";
        }
        if ($incident->timeline) {
            $parts[] = "[Timeline]: {$incident->timeline}";
        }
        if ($incident->remark) {
            $parts[] = "[Remark]: {$incident->remark}";
        }
        if ($incident->evidence) {
            $parts[] = "[Evidence]: {$incident->evidence}";
        }
        if ($incident->improvements) {
            $parts[] = "[Improvements]: {$incident->improvements}";
        }

        $labels = $incident->labels->pluck('name')->implode(', ');
        if ($labels) {
            $parts[] = "[Labels]: {$labels}";
        }

        $categories = collect([
            $incident->business_category,
            $incident->root_cause_category,
            $incident->responsible_team,
        ])->filter()->flatten()->unique()->implode(', ');
        if ($categories) {
            $parts[] = "[Categories]: {$categories}";
        }

        return implode("\n", $parts);
    }

    private function buildContextContent(Incident $incident): string
    {
        return IncidentFormatter::formatCompact($incident);
    }

    private function applyFilters($query, array $filters): void
    {
        if (! empty($filters['severity'])) {
            $query->bySeverity($filters['severity']);
        }
        if (! empty($filters['status'])) {
            $query->byStatus($filters['status']);
        }
        if (! empty($filters['classification'])) {
            $query->byClassification($filters['classification']);
        }
        if (! empty($filters['date_from'])) {
            $query->where('incident_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('incident_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['fund_loss_min'])) {
            $query->where('fund_loss', '>=', $filters['fund_loss_min']);
        }
        if (! empty($filters['incident_type'])) {
            $query->where('incident_type', $filters['incident_type']);
        }
        if (! empty($filters['label'])) {
            $query->whereJsonContains('label_names', $filters['label']);
        }
        if (! empty($filters['root_cause_category'])) {
            $query->whereJsonContains('root_cause_category', $filters['root_cause_category']);
        }
        if (! empty($filters['business_category'])) {
            $query->whereJsonContains('business_category', $filters['business_category']);
        }
        if (! empty($filters['responsible_team'])) {
            $query->whereJsonContains('responsible_team', $filters['responsible_team']);
        }
    }
}
