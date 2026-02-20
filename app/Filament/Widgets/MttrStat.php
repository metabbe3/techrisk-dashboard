<?php

namespace App\Filament\Widgets;

use App\Models\Incident;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

class MttrStat extends BaseWidget
{
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

        $mttr = $query->whereNotNull('mttr')->average('mttr');

        return [
            Stat::make('MTTR', number_format($mttr, 2).' minutes')
                ->description('Avg recovery time '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
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
