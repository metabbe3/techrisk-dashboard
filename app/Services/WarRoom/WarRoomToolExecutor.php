<?php

namespace App\Services\WarRoom;

use App\Models\Incident;
use App\Services\Ai\WebSearchService;
use App\Services\IncidentFormatter;
use App\Services\Markdown\MarkdownFormatter;
use App\Services\RecurrenceDetectionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WarRoomToolExecutor
{
    public function __construct(
        private WebSearchService $webSearch,
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
            ->with(['pic', 'labels', 'actionImprovements', 'statusUpdates'])
            ->first();

        if (! $incident) {
            return "Incident not found: {$args['incident_no']}";
        }

        return IncidentFormatter::formatFull($incident);
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

        $results = $this->webSearch->search($query);

        if (empty($results)) {
            return 'No web search results found.';
        }

        return collect($results)->map(fn ($result) => implode("\n", array_filter([
            "### {$result['title']}",
            $result['url'] ?? null,
            $result['content'] ?? $result['snippet'] ?? null,
        ]))->take(3))->implode("\n\n");
    }

    private function getStats(array $args): string
    {
        $period = $args['period'] ?? 'this_year';

        return match ($period) {
            'this_month' => Cache::remember('warroom_stats_month', 300, fn () => $this->buildStats(now()->startOfMonth(), now())),
            'this_quarter' => Cache::remember('warroom_stats_quarter', 300, fn () => $this->buildStats(now()->startOfQuarter(), now())),
            default => Cache::remember('warroom_stats_year', 300, fn () => $this->buildStats(now()->startOfYear(), now())),
        };
    }

    private function buildStats($from, $to): string
    {
        $excludeQ = fn ($q) => $q->whereNull('fund_status')->orWhereNotIn('fund_status', \App\Enums\FundStatus::EXCLUDED_FROM_COUNTS);

        $total = Incident::where('classification', 'Incident')
            ->whereBetween('incident_date', [$from, $to])
            ->where($excludeQ)
            ->count();

        $open = Incident::where('classification', 'Incident')
            ->whereBetween('incident_date', [$from, $to])
            ->whereNotIn('incident_status', ['Completed'])
            ->where($excludeQ)
            ->count();

        $fundLoss = Incident::whereBetween('incident_date', [$from, $to])
            ->where($excludeQ)
            ->sum('fund_loss');

        $bySeverity = Incident::where('classification', 'Incident')
            ->whereBetween('incident_date', [$from, $to])
            ->where($excludeQ)
            ->selectRaw('severity, COUNT(*) as count')
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();

        $severityBreakdown = collect($bySeverity)->map(fn ($count, $sev) => "{$sev}: {$count}")->implode(', ');

        return "Period: {$from->format('Y-m-d')} to {$to->format('Y-m-d')}\nTotal Incidents: {$total} | Open: {$open}\nTotal Fund Loss: ".MarkdownFormatter::formatMoney((float) $fundLoss)."\nBy Severity: {$severityBreakdown}";
    }
}
