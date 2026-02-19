<?php

namespace App\Filament\Widgets;

use App\Models\Incident;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

class MtbfStat extends BaseWidget
{
    protected int|string|array $columnSpan = 3;

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected function getStats(): array
    {
        $query = Incident::query();
        $descriptionPeriod = 'this year';

        if ($this->start_date && $this->end_date) {
            $query->whereBetween('incident_date', [$this->start_date, $this->end_date]);
            $descriptionPeriod = 'in the selected period';
        } else {
            $query->whereYear('incident_date', now()->year);
        }

        // Calculate MTBF correctly: Total Time Period / Number of Incidents
        // Exclude 'Non Incident' and 'G' severities from MTBF calculation
        $mtbfQuery = $query->clone()->whereNotIn('severity', ['Non Incident', 'G']);
        $mtbfCount = $mtbfQuery->count();
        $mtbf = 0;
        if ($mtbfCount > 0) {
            $minDate = $mtbfQuery->min('incident_date');
            $maxDate = $mtbfQuery->max('incident_date');

            if ($minDate && $maxDate) {
                $minDate = Carbon::parse($minDate)->startOfDay();
                $maxDate = Carbon::parse($maxDate)->startOfDay();
                $totalDays = $minDate->diffInDays($maxDate);
                $mtbf = $mtbfCount > 1 ? $totalDays / ($mtbfCount - 1) : 0;
            }
        }

        return [
            Stat::make('MTBF', number_format($mtbf, 2).' days')
                ->description('Avg time between failures '.$descriptionPeriod)
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
