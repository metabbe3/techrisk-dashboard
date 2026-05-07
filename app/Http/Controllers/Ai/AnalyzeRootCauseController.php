<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiTextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyzeRootCauseController extends Controller
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
            'title' => 'nullable|string',
            'model' => 'nullable|string',
        ]);

        $incidentData = collect($validated)
            ->except('model')
            ->filter(fn ($v) => filled($v))
            ->toArray();

        if (empty($incidentData)) {
            return response()->json([
                'success' => false,
                'error' => 'No incident data provided. Fill in at least summary or timeline.',
                'root_cause' => '',
                'categories' => [],
                'contributing_factors' => [],
                'recommendation' => '',
            ]);
        }

        $result = $this->aiService->analyzeRootCause(
            incidentData: $incidentData,
            model: $validated['model'] ?? null,
        );

        return response()->json([
            'success' => true,
            'root_cause' => $result['root_cause'],
            'categories' => $result['categories'],
            'contributing_factors' => $result['contributing_factors'],
            'recommendation' => $result['recommendation'],
        ]);
    }
}
