<?php

namespace App\Services\WarRoom;

use App\Enums\IncidentClassification;
use App\Models\Incident;
use App\Services\Ai\WebSearchService;
use App\Services\IncidentStatsService;
use App\Services\Markdown\IncidentMarkdownExporter;
use App\Services\Markdown\MarkdownFormatter;
use App\Services\RecurrenceDetectionService;
use Illuminate\Support\Facades\Log;

class WarRoomToolExecutor
{
    public function __construct(
        private WebSearchService $webSearch,
        private IncidentStatsService $statsService,
    ) {}

    public function execute(array $toolCall): ?array
    {
        $name = $toolCall['function']['name'] ?? '';
        $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [];
        $callId = $toolCall['id'] ?? '';

        try {
            $result = match ($name) {
                'search_incidents' => $this->searchIncidents($arguments),
                'get_incident_details' => $this->getIncidentDetails($arguments),
                'find_similar_incidents' => $this->findSimilarIncidents($arguments),
                'get_action_items' => $this->getActionItems($arguments),
                'web_search' => $this->webSearch($arguments),
                'get_stats' => $this->getStats($arguments),
                'get_timeline' => $this->getTimeline($arguments),
                'get_metrics' => $this->getMetrics($arguments),
                'search_by_severity' => $this->searchBySeverity($arguments),
                'search_by_date_range' => $this->searchByDateRange($arguments),
                'get_fund_loss' => $this->getFundLoss($arguments),
                'get_root_cause_categories' => $this->getRootCauseCategories($arguments),
                default => "Unknown tool: {$name}",
            };
        } catch (\Throwable $e) {
            Log::warning("[WarRoom Tool] Tool '{$name}' failed", [
                'error' => $e->getMessage(),
                'arguments' => $arguments,
            ]);
            $result = "Tool execution failed: {$e->getMessage()}";
        }

        return [
            'role' => 'tool',
            'tool_call_id' => $callId,
            'content' => is_string($result) ? $result : json_encode($result),
        ];
    }

    private function searchIncidents(array $args): string
    {
        $query = Incident::aiCounts();

        if (! empty($args['severity'])) {
            $query->whereIn('severity', (array) $args['severity']);
        }

        if (! empty($args['status'])) {
            $query->whereIn('incident_status', (array) $args['status']);
        }

        if (! empty($args['date_from'])) {
            $query->where('incident_date', '>=', $args['date_from']);
        }

        if (! empty($args['date_to'])) {
            $query->where('incident_date', '<=', $this->endOfDayBound($args['date_to']));
        }

        if (! empty($args['query'])) {
            $search = $args['query'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('summary', 'LIKE', "%{$search}%")
                    ->orWhere('root_cause', 'LIKE', "%{$search}%");
            });
        }

        $limit = min($args['limit'] ?? 10, 20);

        $results = $query->orderBy('incident_date', 'desc')
            ->limit($limit)
            ->get();

        return $this->formatIncidentList($results) ?: 'No incidents found matching the criteria.';
    }

    private function getIncidentDetails(array $args): string
    {
        $incident = Incident::where('no', $args['incident_no'])
            ->with(Incident::FULL_RELATIONS)
            ->first();

        if (! $incident) {
            return "Incident not found: {$args['incident_no']}";
        }

        return app(IncidentMarkdownExporter::class)->generateForContext($incident);
    }

    private function findSimilarIncidents(array $args): string
    {
        $incident = Incident::where('no', $args['incident_no'])->first();

        if (! $incident) {
            return "Incident not found: {$args['incident_no']}";
        }

        $recurrenceService = app(RecurrenceDetectionService::class);
        $limit = min($args['limit'] ?? 5, 10);

        $recurrenceData = $recurrenceService->detect($incident);

        if (empty($recurrenceData['matches'])) {
            return "No similar incidents found for {$args['incident_no']}.";
        }

        $matches = collect($recurrenceData['matches'])->take($limit);

        return $matches->map(fn ($rec) => implode(' | ', [
            $rec['no'] ?? 'Unknown',
            $rec['severity'] ?? 'N/A',
            'Score: '.($rec['score'] ?? $rec['similarity'] ?? 'N/A'),
            ($rec['reason'] ?? '') ?: ($rec['match_reasons'] ? implode(', ', $rec['match_reasons']) : ''),
        ]))->implode("\n");
    }

    private function getActionItems(array $args): string
    {
        $incident = Incident::where('no', $args['incident_no'])->first();

        if (! $incident) {
            return "Incident not found: {$args['incident_no']}";
        }

        $status = $args['status'] ?? 'all';
        $query = $incident->actionImprovements();

        if ($status === 'pending') {
            $query->where('is_completed', false);
        } elseif ($status === 'done') {
            $query->where('is_completed', true);
        }

        $actions = $query->get();

        if ($actions->isEmpty()) {
            return "No action items found for {$args['incident_no']}.";
        }

        return $actions->map(fn ($a) => implode(' | ', [
            $a->is_completed ? '[DONE]' : '[PENDING]',
            $a->title,
            $a->due_date ? "Due: {$a->due_date?->format('Y-m-d')}" : 'No due date',
        ]))->implode("\n");
    }

    private function webSearch(array $args): string
    {
        $query = $args['query'] ?? '';

        if (empty($query)) {
            return 'No search query provided.';
        }

        $additionalQueries = $args['additional_queries'] ?? [];
        $context = $args['context'] ?? '';

        // Multi-query search when additional queries provided
        if (! empty($additionalQueries)) {
            $allQueries = array_merge([$query], array_slice($additionalQueries, 0, 2));
            $results = $this->webSearch->searchMulti($allQueries, [], null, 3);
        } else {
            $results = $this->webSearch->search($query);
        }

        if (! empty($results['results'])) {
            $results['results'] = $this->webSearch->filterRelevantResults(
                $results['results'],
                $query.' '.$context,
                []
            );
        }

        if (empty($results) || (! empty($results['error']) && empty($results['context']))) {
            return 'No web search results found.';
        }

        // Return formatted context (multi-query results already have sub-headings)
        if (! empty($results['context'])) {
            return $results['context'];
        }

        return collect($results['results'] ?? $results)->map(fn ($result) => implode("\n", array_filter([
            "### {$result['title']}",
            $result['url'] ?? null,
            $result['content'] ?? $result['snippet'] ?? null,
        ]))->take(3))->implode("\n\n");
    }

    private function getStats(array $args): string
    {
        return $this->statsService->getCachedStats($args['period'] ?? 'this_year');
    }

    private function getTimeline(array $args): string
    {
        $incident = Incident::where('no', $args['incident_no'])->first();
        if (! $incident) {
            return "Incident not found: {$args['incident_no']}";
        }

        $lines = [];
        if ($incident->incident_date) {
            $lines[] = 'Incident date: '.$incident->incident_date->format('Y-m-d H:i');
        }
        if ($incident->discovered_at) {
            $lines[] = 'Discovered: '.$incident->discovered_at->format('Y-m-d H:i');
        }
        if ($incident->stop_bleeding_at) {
            $lines[] = 'Stop bleeding: '.$incident->stop_bleeding_at->format('Y-m-d H:i');
        }
        if ($incident->timeline) {
            $lines[] = "Timeline:\n".$incident->timeline;
        }

        return $lines ? implode("\n", $lines) : "No timeline data for {$args['incident_no']}.";
    }

    private function getMetrics(array $args): string
    {
        $incident = Incident::where('no', $args['incident_no'])->first();
        if (! $incident) {
            return "Incident not found: {$args['incident_no']}";
        }

        $lines = [
            $incident->no.' (id:'.$incident->id.') | '.($incident->title ?? 'Untitled'),
            'Severity: '.($incident->severity?->value ?? 'N/A'),
            'MTTR: '.$this->formatMttr($incident->mttr),
            'Fund loss: '.MarkdownFormatter::formatMoney($incident->fund_loss !== null ? (float) $incident->fund_loss : null),
            'Potential fund loss: '.MarkdownFormatter::formatMoney($incident->potential_fund_loss !== null ? (float) $incident->potential_fund_loss : null),
            'Recovered fund: '.MarkdownFormatter::formatMoney($incident->recovered_fund !== null ? (float) $incident->recovered_fund : null),
            'Fund status: '.($incident->fund_status?->value ?? 'N/A'),
        ];

        return implode("\n", $lines);
    }

    private function searchBySeverity(array $args): string
    {
        $severities = (array) ($args['severity'] ?? []);
        if (empty($severities)) {
            return 'No severity levels provided.';
        }

        $results = Incident::aiCounts()
            ->whereIn('severity', $severities)
            ->orderBy('incident_date', 'desc')
            ->limit(min($args['limit'] ?? 10, 20))
            ->get();

        return $this->formatIncidentList($results) ?: 'No incidents found at severity: '.implode(', ', $severities);
    }

    private function searchByDateRange(array $args): string
    {
        $from = $args['date_from'] ?? null;
        if (! $from) {
            return 'date_from is required.';
        }

        $query = Incident::aiCounts()->where('incident_date', '>=', $from);
        if (! empty($args['date_to'])) {
            $query->where('incident_date', '<=', $this->endOfDayBound($args['date_to']));
        }
        $results = $query->orderBy('incident_date', 'desc')->limit(min($args['limit'] ?? 10, 20))->get();

        return $this->formatIncidentList($results) ?: 'No incidents found in that date range.';
    }

    private function getFundLoss(array $args): string
    {
        // Explicit fund_status (even a count-excluded one like "Potential
        // recovery") is an explicit ask: drop the fund-status exclusion but
        // keep the Incident classification so numbers still match Quick Stats.
        $explicitStatus = ! empty($args['fund_status']);
        $base = $explicitStatus
            ? Incident::where('classification', IncidentClassification::Incident->value)
            : Incident::aiCounts();
        $query = $base->where('fund_loss', '>', 0);
        if ($explicitStatus) {
            $query->where('fund_status', $args['fund_status']);
        }
        if (! empty($args['date_from'])) {
            $query->where('incident_date', '>=', $args['date_from']);
        }
        if (! empty($args['date_to'])) {
            $query->where('incident_date', '<=', $this->endOfDayBound($args['date_to']));
        }
        $results = $query->orderByDesc('fund_loss')->limit(min($args['limit'] ?? 10, 20))->get();

        if ($results->isEmpty()) {
            return 'No fund-loss incidents found.';
        }

        return $results->map(fn ($inc) => implode(' | ', array_filter([
            $inc->no,
            "id:{$inc->id}",
            $inc->severity?->value,
            'Loss: '.MarkdownFormatter::formatMoney((float) $inc->fund_loss),
            $inc->recovered_fund ? 'Recovered: '.MarkdownFormatter::formatMoney((float) $inc->recovered_fund) : null,
            $inc->fund_status?->value ? 'Status: '.$inc->fund_status->value : null,
            $inc->incident_date?->format('Y-m-d'),
            $inc->title ?? '',
        ])))->implode("\n");
    }

    private function getRootCauseCategories(array $args): string
    {
        $period = $args['period'] ?? 'this_year';
        $now = now();

        // Only the JSON column is read — select() skips hydrating the ~40 other
        // incident columns (incl. TEXT/JSON fields) per row.
        $query = Incident::query()
            ->select(['id', 'root_cause_category'])
            ->whereNotNull('root_cause_category');

        // The enum advertises month / quarter / year; scope incident_date once so
        // every option works. this_quarter previously fell through to whole-year —
        // a declared option silently doing the wrong thing.
        match ($period) {
            'this_month' => $query->whereMonth('incident_date', $now->month)->whereYear('incident_date', $now->year),
            'this_quarter' => $query->whereBetween('incident_date', [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()]),
            default => $query->whereYear('incident_date', $now->year),
        };

        $counts = $query->get()
            ->flatMap(fn ($inc) => (array) ($inc->root_cause_category ?? []))
            ->countBy()
            ->sortDesc()
            ->take(15);

        if ($counts->isEmpty()) {
            return "No root-cause category data for {$period}.";
        }

        return $counts->map(fn ($count, $category) => "{$category}: {$count}")->implode("\n");
    }

    private function formatIncidentList($results): string
    {
        if ($results->isEmpty()) {
            return '';
        }

        // id is mandatory: the system prompt requires citations as
        // [no — title](/admin/incidents/{id}) — without it tool-grounded
        // answers can't cite.
        return $results->map(fn ($inc) => implode(' | ', array_filter([
            $inc->no,
            "id:{$inc->id}",
            $inc->severity?->value,
            $inc->incident_status?->value,
            $inc->title ?? 'Untitled',
            $inc->fund_loss > 0 ? 'Loss: '.MarkdownFormatter::formatMoney((float) $inc->fund_loss) : null,
            $inc->incident_date?->format('Y-m-d'),
        ])))->implode("\n");
    }

    /**
     * Inclusive upper bound for a date-only string ("2026-05-01" →
     * "2026-05-01 23:59:59") so events later that day aren't dropped.
     * Datetime input passes through unchanged.
     */
    private function endOfDayBound(string $date): string
    {
        return str_contains($date, ':') ? $date : $date.' 23:59:59';
    }

    /**
     * MTTR is stored as minutes for non-fund incidents, negative days for
     * fund-loss incidents (see config/ai.php) — render with the unit so the
     * model doesn't have to guess.
     */
    private function formatMttr(null|string|int|float $mttr): string
    {
        if ($mttr === null || $mttr === '') {
            return 'N/A';
        }

        return (float) $mttr < 0 ? abs((float) $mttr).' days' : (float) $mttr.' minutes';
    }
}
