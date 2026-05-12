<?php

namespace App\Filament\Widgets;

use App\Enums\Severity;
use App\Filament\Concerns\InteractsWithDashboardFilters;
use App\Models\AiSetting;
use App\Models\Incident;
use App\Models\Label;
use App\Services\Ai\AiTextService;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class AiTrendInsights extends Widget
{
    use InteractsWithDashboardFilters;

    protected static string $view = 'filament.widgets.ai-trend-insights';

    protected static ?int $sort = 100;

    protected int|string|array $columnSpan = 'full';

    public ?string $start_date = null;

    public ?string $end_date = null;

    public bool $trLoading = false;

    public ?array $trResults = null;

    public ?string $trError = null;

    public bool $trOpen = false;

    public ?string $trGeneratedAt = null;

    public bool $trCached = false;

    public string $trSelectedModel = '';

    public array $models = [];

    public bool $hasMultipleModels = false;

    public static function canView(): bool
    {
        return app(AiTextService::class)->isAvailable();
    }

    public function mount(): void
    {
        $aiService = app(AiTextService::class);
        $this->models = $aiService->getAvailableModels();
        $this->trSelectedModel = AiSetting::get('default_model', config('ai.default_model', 'SMART-MODEL'));
        $this->hasMultipleModels = count($this->models) > 1;
    }

    #[On('dashboardFiltersUpdated')]
    public function updateDashboardFilters(array $data): void
    {
        $this->start_date = $data['start_date'];
        $this->end_date = $data['end_date'];
        $this->trOpen = false;
        $this->trResults = null;
        $this->trGeneratedAt = null;
        $this->trCached = false;
    }

    public function analyze(?string $model = null, bool $forceRefresh = false): void
    {
        if ($model) {
            $this->trSelectedModel = $model;
        }

        $this->trError = null;

        try {
            $cacheKey = 'ai_trend_insights_'.md5(json_encode([
                'start_date' => $this->start_date,
                'end_date' => $this->end_date,
                'model' => $this->trSelectedModel,
                'v' => Cache::get('dashboard_cache_version', 0),
            ]));

            if ($forceRefresh) {
                Cache::forget($cacheKey);
            }

            $result = Cache::remember($cacheKey, 1800, function () {
                return $this->runAnalysis();
            });

            $hasInsights = ! empty($result['trends'])
                || ! empty($result['recurring_issues'])
                || ! empty($result['anomalies'])
                || ! empty($result['recommendations']);

            if (! $hasInsights) {
                Cache::forget($cacheKey);
                $this->trError = 'AI returned no insights. The AI service may be temporarily unavailable — please try again.';
                $this->trOpen = false;

                Notification::make()
                    ->title('Trend Analysis')
                    ->body($this->trError)
                    ->warning()
                    ->send();

                return;
            }

            $fromCache = ! $forceRefresh && Cache::has($cacheKey);

            $this->trResults = $result;
            $this->trGeneratedAt = $result['generated_at'];
            $this->trCached = $fromCache;
            $this->trOpen = true;

            Notification::make()
                ->title('Trend Analysis')
                ->body($forceRefresh ? 'Analysis refreshed.' : ($fromCache ? 'Loaded from cache.' : 'Trend analysis complete.'))
                ->success()
                ->send();

        } catch (\Exception $e) {
            $this->trError = $e->getMessage() ?: 'Analysis failed.';
            $this->trOpen = false;

            Notification::make()
                ->title('Trend Analysis')
                ->body($this->trError)
                ->warning()
                ->send();
        }
    }

    private function runAnalysis(): array
    {
        $dateFilter = function ($query) {
            if ($this->start_date && $this->end_date) {
                $query->whereBetween('incident_date', [$this->start_date, $this->end_date]);
            } else {
                $query->whereYear('incident_date', now()->year);
            }
        };

        $baseQuery = Incident::where('classification', 'Incident')->tap($dateFilter);

        $monthlyData = (clone $baseQuery)
            ->selectRaw('MONTH(incident_date) as month, COUNT(*) as count')
            ->groupBy(DB::raw('MONTH(incident_date)'))
            ->pluck('count', 'month')
            ->mapWithKeys(fn ($count, $month) => [
                date('F', mktime(0, 0, 0, $month, 1)) => $count,
            ])
            ->toArray();

        $topLabels = Label::withCount(['incidents' => fn ($q) => $q->where('classification', 'Incident')->tap($dateFilter)])
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
            $avgMtbf = round(
                \Carbon\Carbon::parse($mtbfAgg->min_date)->startOfDay()->diffInDays(
                    \Carbon\Carbon::parse($mtbfAgg->max_date)->startOfDay()
                ) / ($mtbfAgg->cnt - 1),
                2
            );
        }

        $fundLoss = (clone $baseQuery)->where('incident_status', 'Completed')->sum('fund_loss');

        $result = app(AiTextService::class)->analyzeTrends(
            monthlyData: $monthlyData,
            topLabels: $topLabels,
            topPics: $topPics,
            stats: [
                'total' => $total,
                'avg_mttr' => round($avgMttr ?? 0, 2),
                'avg_mtbf' => $avgMtbf,
                'fund_loss' => $fundLoss > 0 ? number_format($fundLoss, 0, ',', '.') : null,
            ],
            model: $this->trSelectedModel,
        );

        $result['generated_at'] = now()->toIso8601String();

        return $result;
    }
}
