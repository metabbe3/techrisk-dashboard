<?php

namespace App\Filament\Widgets;

use App\Models\Incident;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

class FundLoss extends BaseWidget
{
    protected int|string|array $columnSpan = [
        'md' => 3,
        'xl' => 3,
    ];

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

        $fundLossTotal = $query->where('incident_status', 'Completed')->sum('fund_loss');

        return [
            Stat::make('Fund Loss', 'IDR '.number_format($fundLossTotal, 0, ',', '.'))
                ->description('Total fund loss '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-arrow-trending-down')
                ->color('danger'),
        ];
    }

    #[On('dashboardFiltersUpdated')]
    public function updateDashboardFilters(array $data): void
    {
        $this->start_date = $data['start_date'];
        $this->end_date = $data['end_date'];
    }
}
