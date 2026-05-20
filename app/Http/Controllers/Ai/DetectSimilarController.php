<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Services\Ai\AiTextService;
use App\Services\Ai\RagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DetectSimilarController extends Controller
{
    public function __construct(
        private readonly AiTextService $aiService,
        private readonly RagService $ragService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'summary' => 'nullable|string',
            'timeline' => 'nullable|string',
            'severity' => 'nullable|string',
            'incident_type' => 'nullable|string',
            'business_category' => 'nullable|string',
            'root_cause_category' => 'nullable|string',
            'responsible_team' => 'nullable|string',
            'title' => 'nullable|string',
            'root_cause' => 'nullable|string',
            'improvements' => 'nullable|string',
            'classification' => 'nullable|string',
            'exclude_id' => 'nullable|integer',
        ]);

        $incidentData = collect($validated)
            ->except(['exclude_id'])
            ->filter(fn ($v) => filled($v))
            ->toArray();

        $recentIncidents = $this->fetchCandidates($validated, $incidentData);

        if (empty($incidentData) || empty($recentIncidents)) {
            return response()->json([
                'success' => true,
                'similar' => [],
            ]);
        }

        $result = $this->aiService->detectSimilar(
            incidentData: $incidentData,
            recentIncidents: $recentIncidents,
        );

        return response()->json([
            'success' => true,
            'similar' => $result['similar'],
        ]);
    }

    private function fetchCandidates(array $validated, array $incidentData): array
    {
        $searchQuery = collect([
            $validated['title'] ?? null,
            $validated['summary'] ?? null,
            $validated['root_cause'] ?? null,
        ])->filter()->implode(' ');

        $candidateIds = [];

        if (filled($searchQuery)) {
            $ragResults = $this->ragService->search($searchQuery, [
                'date_from' => now()->subMonths(12)->toDateString(),
            ], limit: 20);

            $candidateIds = $ragResults
                ->when($validated['exclude_id'] ?? null, fn ($c, $id) => $c->where('incident_id', '!=', $id))
                ->pluck('incident_id')
                ->take(20)
                ->toArray();
        }

        if (! empty($candidateIds)) {
            return Incident::whereIn('id', $candidateIds)
                ->with(['labels:id,name'])
                ->select(Incident::SIMILARITY_COLUMNS)
                ->get()
                ->toArray();
        }

        return Incident::whereIn('classification', ['Incident', 'Issue'])
            ->where('incident_date', '>=', now()->subMonths(12))
            ->when($validated['exclude_id'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))
            ->with(['labels:id,name'])
            ->select(Incident::SIMILARITY_COLUMNS)
            ->latest('incident_date')
            ->limit(20)
            ->get()
            ->toArray();
    }
}
