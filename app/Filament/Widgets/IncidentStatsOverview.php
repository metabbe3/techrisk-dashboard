<?php

namespace App\Filament\Widgets;

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
        $cacheKey = 'incident_stats_v2_'.md5(json_encode([
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
        $recoveredTotal = $query->clone()->where('recovered_fund', '>', 0)->sum('recovered_fund');
        $mttr = $query->clone()->where('mttr', '>=', 0)->average('mttr');

        $mtbfQuery = $query->clone()->whereNotIn('severity', ['Non Incident', 'G']);
        $mtbfCount = $mtbfQuery->count();
        $mtbf = 0;
        if ($mtbfCount > 0) {
            $minDate = $mtbfQuery->min('incident_date');
            $maxDate = $mtbfQuery->max('incident_date');

            if ($minDate && $maxDate) {
                $totalDays = Carbon::parse($minDate)->startOfDay()->diffInDays(Carbon::parse($maxDate)->startOfDay());
                $mtbf = $mtbfCount > 1 ? $totalDays / ($mtbfCount - 1) : 0;
            }
        }

        $lastIncident = Incident::where('classification', '!=', 'Issue')
            ->whereNotIn('severity', ['Non Incident', 'G'])
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
            Stat::make('MTTR', number_format($mttr, 2).' mins')
                ->description('Avg recovery time (non-fund loss) '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->color('info'),
            Stat::make('MTBF', number_format($mtbf, 2).' days')
                ->description('Avg time between failures '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('info'),
        ];
    }
}
