<?php

namespace App\Http\Controllers\Ai;

use App\Enums\IncidentClassification;
use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\RagDocument;
use App\Services\Ai\AiTextService;
use App\Services\Ai\RagService;
use App\Services\Ai\SimilarIncidentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DetectSimilarController extends Controller
{
    public function __construct(
        private readonly AiTextService $aiService,
        private readonly RagService $ragService,
        private readonly SimilarIncidentService $similarIncidentService,
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

        if (empty($incidentData)) {
            Log::info('[DetectSimilar] No incident data provided');

            return $this->successResponse([
                'success' => true,
                'similar' => [],
            ]);
        }

        // Use the new 3-phase pipeline when enabled and we have an incident ID
        if (config('ai.similarity.enabled', true) && ($validated['exclude_id'] ?? null)) {
            return $this->detectViaPipeline($validated);
        }

        // Fallback to the legacy single-call method
        return $this->detectViaLegacy($validated, $incidentData);
    }

    private function detectViaPipeline(array $validated): JsonResponse
    {
        $incident = Incident::find($validated['exclude_id']);

        if (! $incident) {
            return $this->successResponse([
                'success' => true,
                'similar' => [],
            ]);
        }

        $this->ensureIndexed($validated);

        Log::info('[DetectSimilar] Using 3-phase pipeline', [
            'incident_id' => $incident->id,
            'incident_no' => $incident->no,
        ]);

        $result = $this->similarIncidentService->analyze($incident);

        if (! $result->success) {
            Log::warning('[DetectSimilar] Pipeline failed, falling back to legacy', [
                'error' => $result->error,
            ]);

            $incidentData = collect($validated)
                ->except(['exclude_id'])
                ->filter(fn ($v) => filled($v))
                ->toArray();

            return $this->detectViaLegacy($validated, $incidentData);
        }

        Log::info('[DetectSimilar] Pipeline result', [
            'verified_count' => $result->verifiedCount,
            'candidate_count' => $result->candidateCount,
        ]);

        return $this->successResponse([
            'success' => true,
            'similar' => $result->toApiResponse(),
        ]);
    }

    private function detectViaLegacy(array $validated, array $incidentData): JsonResponse
    {
        $this->ensureIndexed($validated);

        $recentIncidents = $this->fetchCandidates($validated);

        if (empty($recentIncidents)) {
            Log::info('[DetectSimilar] No candidates found');

            return $this->successResponse([
                'success' => true,
                'similar' => [],
            ]);
        }

        Log::info('[DetectSimilar] Sending to AI (legacy)', [
            'incident_fields' => array_keys($incidentData),
            'candidate_count' => count($recentIncidents),
        ]);

        $result = $this->aiService->detectSimilar(
            incidentData: $incidentData,
            recentIncidents: $recentIncidents,
        );

        Log::info('[DetectSimilar] AI result', [
            'similar_count' => count($result['similar'] ?? []),
        ]);

        return $this->successResponse([
            'success' => true,
            'similar' => $result['similar'],
        ]);
    }

    private function ensureIndexed(array $validated): void
    {
        $excludeId = $validated['exclude_id'] ?? null;
        if (! $excludeId) {
            return;
        }

        $exists = RagDocument::where('incident_id', $excludeId)->exists();
        if (! $exists) {
            $incident = Incident::find($excludeId);
            if ($incident) {
                try {
                    $this->ragService->indexIncident($incident);
                    Log::info('[DetectSimilar] Auto-indexed incident', ['incident_id' => $excludeId]);
                } catch (\Throwable $e) {
                    Log::warning('[DetectSimilar] Failed to auto-index', [
                        'incident_id' => $excludeId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function fetchCandidates(array $validated): array
    {
        $searchQuery = collect([
            $validated['title'] ?? null,
            $validated['summary'] ?? null,
            $validated['root_cause'] ?? null,
        ])->filter()->implode(' ');

        $candidateIds = [];

        if (filled($searchQuery)) {
            $ragResults = $this->ragService->search($searchQuery, [
                'date_from' => now()->subMonths(24)->toDateString(),
            ], limit: 30);

            $candidateIds = $ragResults
                ->when($validated['exclude_id'] ?? null, fn ($c, $id) => $c->where('incident_id', '!=', $id))
                ->pluck('incident_id')
                ->take(30)
                ->toArray();

            Log::info('[DetectSimilar] RAG search', [
                'query_length' => str($searchQuery)->length(),
                'rag_result_count' => $ragResults->count(),
                'candidate_ids_count' => count($candidateIds),
            ]);
        }

        if (! empty($candidateIds)) {
            return Incident::whereIn('id', $candidateIds)
                ->with(['labels:id,name'])
                ->select(Incident::SIMILARITY_COLUMNS)
                ->get()
                ->toArray();
        }

        Log::info('[DetectSimilar] Using DB fallback (no RAG results)');

        return Incident::whereIn('classification', [IncidentClassification::Incident->value, IncidentClassification::Issue->value])
            ->where('incident_date', '>=', now()->subMonths(24))
            ->when($validated['exclude_id'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))
            ->with(['labels:id,name'])
            ->select(Incident::SIMILARITY_COLUMNS)
            ->latest('incident_date')
            ->limit(30)
            ->get()
            ->toArray();
    }
}
