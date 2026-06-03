<?php

namespace App\Services\WarRoom;

use App\Models\Incident;
use App\Services\Ai\WebSearchService;
use App\Services\IncidentStatsService;
use App\Services\Markdown\IncidentMarkdownExporter;
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
        $query = Incident::query()->with(['pic', 'labels']);

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
            $query->where('incident_date', '<=', $args['date_to']);
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

        if ($results->isEmpty()) {
            return 'No incidents found matching the criteria.';
        }

        return $results->map(fn ($inc) => implode(' | ', array_filter([
            $inc->no,
            $inc->severity,
            $inc->incident_status,
            $inc->title ?? 'Untitled',
            $inc->incident_date?->format('Y-m-d'),
            $inc->pic?->name ? "PIC: {$inc->pic->name}" : null,
        ])))->implode("\n");
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
}
