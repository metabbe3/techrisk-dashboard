<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiTextService;
use App\Services\WeeklyDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GenerateWeeklySummaryController extends Controller
{
    public function __construct(
        private readonly AiTextService $aiService,
        private readonly WeeklyDataService $weeklyDataService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2020|max:2030',
            'model' => 'nullable|string',
        ]);

        $year = $validated['year'];
        $weeklyData = $this->weeklyDataService->getWeeklyData($year);

        $totalOpen = collect($weeklyData)->sum('incident_open');
        $totalClosed = collect($weeklyData)->sum('incident_closed');
        $grandTotal = collect($weeklyData)->sum('total');

        if (empty($weeklyData)) {
            return $this->successResponse([
                'success' => false,
                'error' => 'No incident data found for '.$year.'.',
                'summary' => '',
                'key_highlights' => [],
                'areas_of_concern' => [],
                'root_cause_insights' => [],
                'recommendation' => '',
            ]);
        }

        $result = $this->aiService->generateWeeklySummary(
            weeklyData: $weeklyData,
            summaryStats: [
                'year' => $year,
                'totalOpen' => $totalOpen,
                'totalClosed' => $totalClosed,
                'grandTotal' => $grandTotal,
            ],
            model: $validated['model'] ?? null,
        );

        return $this->successResponse([
            'success' => true,
            'summary' => $result['summary'],
            'key_highlights' => $result['key_highlights'],
            'areas_of_concern' => $result['areas_of_concern'],
            'root_cause_insights' => $result['root_cause_insights'] ?? [],
            'recommendation' => $result['recommendation'],
        ]);
    }
}
