<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Services\Ai\AiTextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DetectSimilarController extends Controller
{
    public function __construct(
        private readonly AiTextService $aiService
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
            'exclude_id' => 'nullable|integer',
            'model' => 'nullable|string',
        ]);

        $incidentData = collect($validated)
            ->except(['model', 'exclude_id'])
            ->filter(fn ($v) => filled($v))
            ->toArray();

        $recentIncidents = Incident::where('classification', 'Incident')
            ->where('incident_date', '>=', now()->subDays(90))
            ->when($validated['exclude_id'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))
            ->select(['id', 'no', 'summary', 'severity', 'incident_type', 'incident_date', 'incident_status'])
            ->latest('incident_date')
            ->limit(50)
            ->get()
            ->toArray();

        if (empty($incidentData) || empty($recentIncidents)) {
            return response()->json([
                'success' => true,
                'similar' => [],
            ]);
        }

        $result = $this->aiService->detectSimilar(
            incidentData: $incidentData,
            recentIncidents: $recentIncidents,
            model: $validated['model'] ?? null,
        );

        return response()->json([
            'success' => true,
            'similar' => $result['similar'],
        ]);
    }
}
