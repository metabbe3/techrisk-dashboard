<?php

namespace App\Filament\Widgets;

use App\Models\Incident;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

class RecoveredFund extends BaseWidget
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

        $recoveredTotal = $query->where('recovered_fund', '>', 0)->sum('recovered_fund');

        return [
            Stat::make('Recovered', 'IDR '.number_format($recoveredTotal, 0, ',', '.'))
                ->description('Total recovered '.$descriptionPeriod)
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
