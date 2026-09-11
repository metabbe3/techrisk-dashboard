<?php

namespace App\Http\Controllers\Ai;

use App\Enums\Severity;
use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\Label;
use App\Services\Ai\AiTextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AnalyzeTrendsController extends Controller
{
    public function __construct(
        private readonly AiTextService $aiService
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'model' => 'nullable|string',
            'force_refresh' => 'nullable|boolean',
        ]);

        $startDate = $validated['start_date'] ?? null;
        $endDate = $validated['end_date'] ?? null;

        $cacheKey = 'ai_trend_insights_'.md5(json_encode([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'model' => $validated['model'] ?? 'default',
            'v' => Cache::get('dashboard_cache_version', 0),
        ]));

        if ($request->boolean('force_refresh')) {
            Cache::forget($cacheKey);
        }

        // Must be checked BEFORE remember() — after it, the key always exists.
        $fromCache = ! $request->boolean('force_refresh') && Cache::has($cacheKey);

        $result = Cache::remember($cacheKey, 1800, function () use ($startDate, $endDate, $validated) {
            return $this->runAnalysis($startDate, $endDate, $validated['model'] ?? null);
        });

        return $this->successResponse([
            'success' => true,
            'trends' => $result['trends'],
            'recurring_issues' => $result['recurring_issues'],
            'anomalies' => $result['anomalies'],
            'recommendations' => $result['recommendations'],
            'generated_at' => $result['generated_at'],
            'cached' => $fromCache,
        ]);
    }

    private function runAnalysis(?string $startDate, ?string $endDate, ?string $model): array
    {
        $dateFilter = function ($query) use ($startDate, $endDate) {
            if ($startDate && $endDate) {
                // Request dates are midnight-only — include the final day.
                $query->whereBetween('incident_date', [$startDate, \Carbon\Carbon::parse($endDate)->endOfDay()]);
            } else {
                $query->whereYear('incident_date', now()->year);
            }
        };

        // aiCounts(): same scope the chat context uses — fund-status-excluded
        // incidents stay out so trends and chat agree on the same totals.
        $baseQuery = Incident::aiCounts()->tap($dateFilter);

        $monthlyData = (clone $baseQuery)
            ->selectRaw('MONTH(incident_date) as month, COUNT(*) as count')
            ->groupBy(DB::raw('MONTH(incident_date)'))
            ->pluck('count', 'month')
            ->mapWithKeys(fn ($count, $month) => [
                date('F', mktime(0, 0, 0, $month, 1)) => $count,
            ])
            ->toArray();

        $topLabels = Label::withCount(['incidents' => fn ($q) => $q->aiCounts()->tap($dateFilter)])
            ->having('incidents_count', '>', 0)
            ->orderByDesc('incidents_count')
            ->limit(10)
            ->pluck('incidents_count', 'name')
            ->toArray();

        $topPics = (clone $baseQuery)
            ->join('users', 'incidents.pic_id', '=', 'users.id')
            ->selectRaw('users.name, COUNT(*) as count')
            ->groupBy('users.name')
            ->orderByDesc('count')
            ->limit(10)
            ->pluck('count', 'name')
            ->toArray();

        $total = (clone $baseQuery)->count();
        $avgMttr = (clone $baseQuery)->whereIn('severity', Severity::METRIC_ELIGIBLE)->where('mttr', '>=', 0)->avg('mttr');

        $mtbfAgg = (clone $baseQuery)->whereIn('severity', Severity::METRIC_ELIGIBLE)
            ->selectRaw('COUNT(*) as cnt, MIN(incident_date) as min_date, MAX(incident_date) as max_date')
            ->first();
        $avgMtbf = 0;
        if ($mtbfAgg && $mtbfAgg->cnt > 1 && $mtbfAgg->min_date && $mtbfAgg->max_date) {
            $avgMtbf = round(\Carbon\Carbon::parse($mtbfAgg->min_date)->startOfDay()->diffInDays(\Carbon\Carbon::parse($mtbfAgg->max_date)->startOfDay()) / ($mtbfAgg->cnt - 1), 2);
        }

        $fundLoss = (clone $baseQuery)->where('incident_status', 'Completed')->sum('fund_loss');

        $result = $this->aiService->analyzeTrends(
            monthlyData: $monthlyData,
            topLabels: $topLabels,
            topPics: $topPics,
            stats: [
                'total' => $total,
                'avg_mttr' => round($avgMttr ?? 0, 2),
                'avg_mtbf' => $avgMtbf,
                'fund_loss' => $fundLoss > 0 ? number_format($fundLoss, 0, ',', '.') : null,
            ],
            model: $model,
        );

        $result['generated_at'] = now()->toIso8601String();

        return $result;
    }
}
