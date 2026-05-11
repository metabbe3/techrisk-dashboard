<?php

namespace App\Filament\Widgets;

use App\Enums\Severity;
use App\Filament\Concerns\InteractsWithDashboardFilters;
use App\Models\Incident;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class IncidentStatsOverview extends BaseWidget
{
    use InteractsWithDashboardFilters;

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected function getStats(): array
    {
        $cacheKey = 'incident_stats_v5_'.md5(json_encode([
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'v' => Cache::get('dashboard_cache_version', 0),
        ]));

        return Cache::remember($cacheKey, 300, function () {
            return $this->calculateStats();
        });
    }

    private function calculateStats(): array
    {
        $query = Incident::query()->where('classification', '!=', 'Issue');

        if (! $this->start_date && ! $this->end_date) {
            $query->whereYear('incident_date', now()->year);
            $descriptionPeriod = 'this year';
        } else {
            if ($this->start_date) {
                $query->where('incident_date', '>=', $this->start_date);
            }
            if ($this->end_date) {
                $query->where('incident_date', '<=', $this->end_date);
            }
            $descriptionPeriod = 'in the selected period';
        }

        $fundLossTotal = $query->clone()->where('incident_status', 'Completed')->sum('fund_loss');
        $recoveredTotal = $query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE)->where('recovered_fund', '>', 0)->sum('recovered_fund');
        $mttrNonFundLoss = $query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE)->where('mttr', '>=', 0)->average('mttr');
        $mttrFundLoss = abs($query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE)->where('mttr', '<', 0)->average('mttr') ?? 0);

        $mtbfNonFundLossQuery = $query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE)->where('fund_status', 'Non fundLoss');
        $mtbfNonFundLossCount = $mtbfNonFundLossQuery->count();
        $mtbfNonFundLoss = 0;
        if ($mtbfNonFundLossCount > 1) {
            $minDate = $mtbfNonFundLossQuery->min('incident_date');
            $maxDate = $mtbfNonFundLossQuery->max('incident_date');
            if ($minDate && $maxDate) {
                $mtbfNonFundLoss = Carbon::parse($minDate)->startOfDay()->diffInDays(Carbon::parse($maxDate)->startOfDay()) / ($mtbfNonFundLossCount - 1);
            }
        }

        $mtbfFundLossQuery = $query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE)->where('fund_status', 'Confirmed loss');
        $mtbfFundLossCount = $mtbfFundLossQuery->count();
        $mtbfFundLoss = 0;
        if ($mtbfFundLossCount > 1) {
            $minDate = $mtbfFundLossQuery->min('incident_date');
            $maxDate = $mtbfFundLossQuery->max('incident_date');
            if ($minDate && $maxDate) {
                $mtbfFundLoss = Carbon::parse($minDate)->startOfDay()->diffInDays(Carbon::parse($maxDate)->startOfDay()) / ($mtbfFundLossCount - 1);
            }
        }

        $lastIncident = Incident::where('classification', '!=', 'Issue')
            ->whereIn('severity', Severity::METRIC_ELIGIBLE)
            ->latest('incident_date')
            ->first();
        $lastIncidentDiff = 'N/A';
        if ($lastIncident) {
            $lastIncidentDiff = Carbon::parse($lastIncident->incident_date)->diffInDays(Carbon::now()).' days ago';
        }

        return [
            Stat::make('Last Incident', $lastIncidentDiff)
                ->description('Days since the very last incident')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
            Stat::make('Fund Loss', 'IDR '.number_format($fundLossTotal, 0, ',', '.'))
                ->description('Total fund loss '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-arrow-trending-down')
                ->color('danger'),
            Stat::make('Recovered', 'IDR '.number_format($recoveredTotal, 0, ',', '.'))
                ->description('Total recovered '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),
            Stat::make('Avg MTTR (Non Fund Loss)', number_format($mttrNonFundLoss, 2).' mins')
                ->description('Avg recovery time (non-fund loss) '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->color('info'),

            Stat::make('Avg MTTR (Fund Loss)', number_format($mttrFundLoss, 2).' days')
                ->description('Avg recovery time (fund loss) '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-clock')
                ->color('danger'),
            Stat::make('MTBF (Non Fund Loss)', number_format($mtbfNonFundLoss, 2).' days')
                ->description('Avg time between failures (non-fund loss) '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('violet'),

            Stat::make('MTBF (Fund Loss)', number_format($mtbfFundLoss, 2).' days')
                ->description('Avg time between failures (fund loss) '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('rose'),
        ];
    }
}
