<?php

namespace App\Filament\Widgets;

use App\Enums\Severity;
use App\Filament\Concerns\InteractsWithDashboardFilters;
use App\Models\Incident;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class DashboardStatsOverview extends BaseWidget
{
    use InteractsWithDashboardFilters;

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected function getStats(): array
    {
        $cacheKey = 'dashboard_stats_v5_'.md5(json_encode([
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
        $descriptionPeriod = 'this year';

        $incidentDateFilter = function ($query) {
            if ($this->start_date && $this->end_date) {
                $query->whereBetween('incident_date', [$this->start_date, $this->end_date]);
            } else {
                $query->whereYear('incident_date', now()->year);
            }
        };

        if ($this->start_date && $this->end_date) {
            $descriptionPeriod = 'in the selected period';
        }

        $totalIncidentsOnly = Incident::query()
            ->where('classification', 'Incident')
            ->tap($incidentDateFilter)
            ->count();

        $totalIncidents = Incident::query()
            ->tap($incidentDateFilter)
            ->count();

        $fundLossTotal = Incident::query()
            ->tap($incidentDateFilter)
            ->where('incident_status', 'Completed')
            ->sum('fund_loss');

        $recoveredTotal = Incident::query()
            ->tap($incidentDateFilter)
            ->where('recovered_fund', '>', 0)
            ->sum('recovered_fund');

        $lastIncident = Incident::where('classification', 'Incident')
            ->latest('incident_date')
            ->first();

        $days = 0;
        if ($lastIncident && $lastIncident->incident_date) {
            $days = Carbon::parse($lastIncident->incident_date)->startOfDay()->diffInDays(Carbon::now()->startOfDay());
        }

        $lastIncidentLabel = $days === 0 ? 'No recent incident' : $days.' days ago';

        $mttrNonFundLoss = Incident::query()
            ->where('classification', '!=', 'Issue')
            ->whereIn('severity', Severity::METRIC_ELIGIBLE)
            ->tap($incidentDateFilter)
            ->where('mttr', '>=', 0)
            ->average('mttr');

        $mttrFundLoss = abs(Incident::query()
            ->where('classification', '!=', 'Issue')
            ->whereIn('severity', Severity::METRIC_ELIGIBLE)
            ->tap($incidentDateFilter)
            ->where('mttr', '<', 0)
            ->average('mttr') ?? 0);

        $mtbfNonFundLossQuery = Incident::query()
            ->where('classification', '!=', 'Issue')
            ->tap($incidentDateFilter)
            ->whereIn('severity', Severity::METRIC_ELIGIBLE)
            ->where('fund_status', 'Non fundLoss');

        $mtbfNonFundLossCount = $mtbfNonFundLossQuery->count();
        $mtbfNonFundLoss = 0;
        if ($mtbfNonFundLossCount > 1) {
            $minDate = $mtbfNonFundLossQuery->min('incident_date');
            $maxDate = $mtbfNonFundLossQuery->max('incident_date');
            if ($minDate && $maxDate) {
                $mtbfNonFundLoss = Carbon::parse($minDate)->startOfDay()->diffInDays(Carbon::parse($maxDate)->startOfDay()) / ($mtbfNonFundLossCount - 1);
            }
        }

        $mtbfFundLossQuery = Incident::query()
            ->where('classification', '!=', 'Issue')
            ->tap($incidentDateFilter)
            ->whereIn('severity', Severity::METRIC_ELIGIBLE)
            ->whereIn('fund_status', ['Confirmed loss', 'Potential recovery']);

        $mtbfFundLossCount = $mtbfFundLossQuery->count();
        $mtbfFundLoss = 0;
        if ($mtbfFundLossCount > 1) {
            $minDate = $mtbfFundLossQuery->min('incident_date');
            $maxDate = $mtbfFundLossQuery->max('incident_date');
            if ($minDate && $maxDate) {
                $mtbfFundLoss = Carbon::parse($minDate)->startOfDay()->diffInDays(Carbon::parse($maxDate)->startOfDay()) / ($mtbfFundLossCount - 1);
            }
        }

        return [
            Stat::make('Total Incidents', $totalIncidentsOnly)
                ->description('Incidents only '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-chart-bar')
                ->chart([7, 3, 4, 5, 6, 3, 5, 3])
                ->color('primary'),

            Stat::make('Total Issues', $totalIncidents)
                ->description('Incidents + Issues '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->chart([4, 6, 3, 7, 5, 4, 6, 5])
                ->color('success'),

            Stat::make('Fund Loss', 'IDR '.number_format($fundLossTotal, 0, ',', '.'))
                ->description('Total fund loss '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-arrow-trending-down')
                ->chart([3, 5, 8, 4, 6, 2, 7, 3])
                ->color('danger'),

            Stat::make('Recovered', 'IDR '.number_format($recoveredTotal, 0, ',', '.'))
                ->description('Total recovered '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->chart([2, 4, 6, 5, 3, 7, 4, 6])
                ->color('success'),

            Stat::make('Last Incident', $lastIncidentLabel)
                ->description('Days since last incident')
                ->descriptionIcon('heroicon-m-clock')
                ->chart([10, 8, 6, 12, 5, 9, 7, $days])
                ->color('warning'),

            Stat::make('Avg MTTR (Non Fund Loss)', number_format($mttrNonFundLoss, 2).' mins')
                ->description('Avg recovery time (non-fund loss)')
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->chart([5, 3, 7, 4, 6, 2, 8, 3])
                ->color('info'),

            Stat::make('Avg MTTR (Fund Loss)', number_format($mttrFundLoss, 2).' days')
                ->description('Avg recovery time (fund loss)')
                ->descriptionIcon('heroicon-m-clock')
                ->chart([2, 4, 3, 5, 2, 6, 3, 4])
                ->color('danger'),

            Stat::make('MTBF (Non Fund Loss)', number_format($mtbfNonFundLoss, 2).' days')
                ->description('Avg time between failures (non-fund loss)')
                ->descriptionIcon('heroicon-m-shield-check')
                ->chart([8, 6, 9, 7, 5, 10, 8, 6])
                ->color('violet'),

            Stat::make('MTBF (Fund Loss)', number_format($mtbfFundLoss, 2).' days')
                ->description('Avg time between failures (fund loss)')
                ->descriptionIcon('heroicon-m-shield-check')
                ->chart([3, 5, 4, 6, 3, 7, 5, 4])
                ->color('rose'),
        ];
    }
}
