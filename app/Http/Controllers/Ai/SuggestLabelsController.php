<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\Label;
use App\Services\Ai\AiTextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SuggestLabelsController extends Controller
{
    public function __construct(
        private readonly AiTextService $aiService
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'summary' => 'nullable|string',
            'root_cause' => 'nullable|string',
            'timeline' => 'nullable|string',
            'remark' => 'nullable|string',
            'severity' => 'nullable|string',
            'incident_type' => 'nullable|string',
            'business_category' => 'nullable|string',
            'root_cause_category' => 'nullable|string',
            'responsible_team' => 'nullable|string',
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
                'error' => 'No incident data provided. Fill in at least summary or root cause.',
                'matched' => [],
                'suggested' => [],
            ]);
        }

        $availableLabels = Cache::remember('labels', 3600, fn () => Label::pluck('name')->toArray());

        $result = $this->aiService->suggestLabels(
            incidentData: $incidentData,
            availableLabels: $availableLabels,
            model: $validated['model'] ?? null,
        );

        return response()->json([
            'success' => true,
            'matched' => $result['matched'],
            'suggested' => $result['suggested'],
        ]);
    }
}
