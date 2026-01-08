<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\Incident;
use Carbon\Carbon;
use Livewire\Attributes\On;

class IncidentStatsOverview extends BaseWidget
{

    public ?string $start_date = null;
    public ?string $end_date = null;

    protected function getStats(): array
    {
        $query = Incident::query();

        // If no custom date range is applied, default to this year
        if (!$this->start_date && !$this->end_date) {
            $query->whereYear('incident_date', now()->year);
            $descriptionPeriod = 'this year';
        } else {
            // Apply custom date range if provided
            if ($this->start_date) {
                $query->where('incident_date', '>=', $this->start_date);
            }
            if ($this->end_date) {
                $query->where('incident_date', '<=', $this->end_date);
            }
            $descriptionPeriod = 'in the selected period';
        }

        // Perform calculations on the filtered query
        $totalIncidents = $query->clone()->count();
        $fundLossTotal = $query->clone()->where('incident_status', 'Completed')->sum('fund_loss');
        $recoveredTotal = $query->clone()->where('recovered_fund', '>', 0)->sum('recovered_fund');
        $mttr = $query->clone()->whereNotNull('mttr')->average('mttr');
        $mtbf = $query->clone()->whereNotNull('mtbf')->average('mtbf');

        // Last Incident calculation should always be based on all data, not filtered
        $lastIncident = Incident::latest('incident_date')->first();
        $lastIncidentDiff = 'N/A';
        if ($lastIncident) {
            $lastIncidentDiff = Carbon::parse($lastIncident->incident_date)->diffInDays(Carbon::now()) . ' days ago';
        }

        return [
            Stat::make('Total Incidents', $totalIncidents)
                ->description('Total incidents ' . $descriptionPeriod)
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('primary'),
            Stat::make('Last Incident', $lastIncidentDiff)
                ->description('Days since the very last incident')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
            Stat::make('Fund Loss', 'IDR ' . number_format($fundLossTotal, 0, ',', '.'))
                ->description('Total fund loss ' . $descriptionPeriod)
                ->descriptionIcon('heroicon-m-arrow-trending-down')
                ->color('danger'),
            Stat::make('Recovered', 'IDR ' . number_format($recoveredTotal, 0, ',', '.'))
                ->description('Total recovered ' . $descriptionPeriod)
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),
            Stat::make('MTTR', number_format($mttr, 2) . ' minutes')
                ->description('Avg recovery time ' . $descriptionPeriod)
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->color('info'),
            Stat::make('MTBF', number_format($mtbf, 2) . ' days')
                ->description('Avg time between failures ' . $descriptionPeriod)
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('info'),
        ];
    }

    #[On('dashboardFiltersUpdated')]
    public function updateDashboardFilters(array $data): void
    {
        $this->start_date = $data['start_date'];
        $this->end_date = $data['end_date'];
    }
}