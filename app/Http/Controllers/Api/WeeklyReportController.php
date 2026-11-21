<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\IncidentListApiResource;
use App\Services\WeeklyDataService;
use App\Traits\ApiResponser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/weekly-report — the DingTalk agent's read over the weekly report.
 * ZERO new metric math: week counts (open/closed/total) come verbatim from
 * WeeklyDataService::getWeeklyData() — the same builder the Filament Weekly
 * Report page and its export show — with count eligibility
 * (classification=Incident, Severity::METRIC_ELIGIBLE, excludedFromCounts)
 * inherited by construction. One-logic rule — the bot cannot drift from the
 * page. Per-week incident rows use the one list projection
 * (IncidentListApiResource); the token user's year access scopes rows for
 * non-admin tokens (stats-endpoint precedent).
 */
class WeeklyReportController extends Controller
{
    use ApiResponser;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            // Last-N elapsed weeks slice (payload sanity for chat); omit = full year.
            'weeks' => ['nullable', 'integer', 'min:1', 'max:53'],
        ]);

        // Query params arrive as strings — the integer rule validates, never casts.
        $year = isset($data['year']) ? (int) $data['year'] : (int) now()->format('Y');

        $weeklyData = app(WeeklyDataService::class)->getWeeklyData($year, $request->user());

        if (isset($data['weeks'])) {
            $weeklyData = array_slice($weeklyData, -$data['weeks']);
        }

        $weeks = array_map(fn ($w) => [
            'week' => $w->week,
            'date_range' => $w->date_range,
            'incident_open' => $w->incident_open,
            'incident_closed' => $w->incident_closed,
            'total' => $w->total,
            'incidents' => IncidentListApiResource::collection($w->incidents)->resolve(),
        ], $weeklyData);

        // Same header sums the weekly export computes.
        $summary = [
            'open' => array_sum(array_column($weeks, 'incident_open')),
            'closed' => array_sum(array_column($weeks, 'incident_closed')),
            'total' => array_sum(array_column($weeks, 'total')),
            'year' => $year,
        ];

        return $this->successResponse(
            ['summary' => $summary, 'weeks' => $weeks],
            'Weekly report retrieved.'
        );
    }
}
