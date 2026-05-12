<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\Label;
use App\Services\Ai\AiTextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
                'summary' => '',
                'root_cause' => '',
                'remark' => '',
                'categories' => [],
                'contributing_factors' => [],
                'recommendation' => '',
                'labels_matched' => [],
                'labels_suggested' => [],
            ]);
        }

        $availableLabels = Cache::remember('labels', 3600, fn () => Label::pluck('name')->toArray());

        $result = $this->aiService->analyzeRootCause(
            incidentData: $incidentData,
            model: $validated['model'] ?? null,
            availableLabels: $availableLabels,
        );

        return response()->json([
            'success' => true,
            'summary' => $result['summary'],
            'root_cause' => $result['root_cause'],
            'remark' => $result['remark'],
            'categories' => $result['categories'],
            'contributing_factors' => $result['contributing_factors'],
            'recommendation' => $result['recommendation'],
            'labels_matched' => $result['labels_matched'],
            'labels_suggested' => $result['labels_suggested'],
        ]);
    }
}
