<?php

namespace App\Filament\Widgets;

use App\Models\Incident;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

class TotalIncidentsOnly extends BaseWidget
{
    protected int|string|array $columnSpan = 3;

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected function getStats(): array
    {
        $query = Incident::query()->where('classification', 'Incident');
        $descriptionPeriod = 'this year';

        if ($this->start_date && $this->end_date) {
            $query->whereBetween('incident_date', [$this->start_date, $this->end_date]);
            $descriptionPeriod = 'in the selected period';
        } else {
            $query->whereYear('incident_date', now()->year);
        }

        return [
            Stat::make('Total Incidents', $query->count())
                ->description('Total incidents (Incidents only) '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('primary'),
        ];
    }

    #[On('dashboardFiltersUpdated')]
    public function updateDashboardFilters(array $data): void
    {
        $this->start_date = $data['start_date'];
        $this->end_date = $data['end_date'];
    }
}
