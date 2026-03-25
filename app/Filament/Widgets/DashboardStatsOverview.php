<?php

namespace App\Filament\Widgets;

use App\Models\ActionImprovement;
use App\Models\Incident;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

class DashboardStatsOverview extends BaseWidget
{
    public ?string $start_date = null;

    public ?string $end_date = null;

    protected function getStats(): array
    {
        $descriptionPeriod = 'this year';

        // Set date filters for queries
        $incidentDateFilter = function ($query) {
            if ($this->start_date && $this->end_date) {
                $query->whereBetween('incident_date', [$this->start_date, $this->end_date]);
            } else {
                $query->whereYear('incident_date', now()->year);
            }
        };

        $actionDateFilter = function ($query) {
            if ($this->start_date && $this->end_date) {
                $query->whereBetween('created_at', [$this->start_date, $this->end_date]);
            } else {
                $query->whereYear('created_at', now()->year);
            }
        };

        if ($this->start_date && $this->end_date) {
            $descriptionPeriod = 'in the selected period';
        }

        // 1. Total Incidents (Incidents only)
        $totalIncidentsOnly = (clone Incident::query())
            ->where('classification', 'Incident')
            ->tap($incidentDateFilter)
            ->count();

        // 2. Total Issues (Incidents + Issues)
        $totalIncidents = (clone Incident::query())
            ->tap($incidentDateFilter)
            ->count();

        // 3. Fund Loss
        $fundLossTotal = (clone Incident::query())
            ->tap($incidentDateFilter)
            ->where('incident_status', 'Completed')
            ->sum('fund_loss');

        // 4. Recovered Fund
        $recoveredTotal = (clone Incident::query())
            ->tap($incidentDateFilter)
            ->where('recovered_fund', '>', 0)
            ->sum('recovered_fund');

        // 5. Last Incident (no date filter - always show most recent)
        $lastIncident = Incident::where('classification', 'Incident')
            ->latest('incident_date')
            ->first();

        $days = 0;
        if ($lastIncident && $lastIncident->incident_date) {
            $incidentDate = Carbon::parse($lastIncident->incident_date)->startOfDay();
            $today = Carbon::now()->startOfDay();
            $days = $incidentDate->diffInDays($today);
        }

        $lastIncidentLabel = $days === 0 ? 'No recent incident' : $days.' days ago';

        // 6. MTTR
        $mttr = (clone Incident::query())
            ->tap($incidentDateFilter)
            ->whereNotNull('mttr')
            ->average('mttr');

        // 7. MTBF (exclude 'Non Incident' and 'G' severities)
        $mtbfQuery = (clone Incident::query())
            ->tap($incidentDateFilter)
            ->whereNotIn('severity', ['Non Incident', 'G']);

        $mtbfCount = $mtbfQuery->count();
        $mtbf = 0;
        if ($mtbfCount > 1) {
            $minDate = $mtbfQuery->min('incident_date');
            $maxDate = $mtbfQuery->max('incident_date');

            if ($minDate && $maxDate) {
                $minDate = Carbon::parse($minDate)->startOfDay();
                $maxDate = Carbon::parse($maxDate)->startOfDay();
                $totalDays = $minDate->diffInDays($maxDate);
                $mtbf = $totalDays / ($mtbfCount - 1);
            }
        }

        // 8. Pending Action
        $pendingCount = (clone ActionImprovement::query())
            ->tap($actionDateFilter)
            ->where('status', 'pending')
            ->count();

        // 9. Done Action
        $doneCount = (clone ActionImprovement::query())
            ->tap($actionDateFilter)
            ->where('status', 'done')
            ->count();

        return [
            // Row 1
            Stat::make('Total Incidents', $totalIncidentsOnly)
                ->description('Total incidents (Incidents only) '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('primary'),

            Stat::make('Total Issues', $totalIncidents)
                ->description('Total issues (Incidents + Issues) '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make('Fund Loss', 'IDR '.number_format($fundLossTotal, 0, ',', '.'))
                ->description('Total fund loss '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-arrow-trending-down')
                ->color('danger'),

            // Row 2
            Stat::make('Recovered', 'IDR '.number_format($recoveredTotal, 0, ',', '.'))
                ->description('Total recovered '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make('Last Incident', $lastIncidentLabel)
                ->description('Days since the last incident')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),

            Stat::make('MTTR', number_format($mttr, 2).' minutes')
                ->description('Avg recovery time '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->color('info'),

            // Row 3
            Stat::make('MTBF', number_format($mtbf, 2).' days')
                ->description('Avg time between failures '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('info'),

            Stat::make('Pending Action', $pendingCount)
                ->description('Pending actions '.$descriptionPeriod)
                ->color('warning'),

            Stat::make('Done Action', $doneCount)
                ->description('Done actions '.$descriptionPeriod)
                ->color('success'),
        ];
    }

    #[On('dashboardFiltersUpdated')]
    public function updateDashboardFilters(array $data): void
    {
        $this->start_date = $data['start_date'];
        $this->end_date = $data['end_date'];
    }
}
