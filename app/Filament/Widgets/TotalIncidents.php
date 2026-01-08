<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\Incident;
use Carbon\Carbon;
use Livewire\Attributes\On;

class TotalIncidents extends BaseWidget
{
    public ?string $start_date = null;
    public ?string $end_date = null;

    protected function getStats(): array
    {
        $query = Incident::query();

        if ($this->start_date) {
            $query->where('incident_date', '>=', $this->start_date);
        }

        if ($this->end_date) {
            $query->where('incident_date', '<=', $this->end_date);
        }

        return [
            Stat::make('Total Incidents', $query->count())
                ->description('Total incidents in the selected period')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
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