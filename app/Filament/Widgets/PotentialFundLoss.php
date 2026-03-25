<?php

namespace App\Filament\Widgets;

use App\Models\Incident;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

class PotentialFundLoss extends BaseWidget
{
    protected int|string|array $columnSpan = [
        'md' => 4,
        'xl' => 4,
    ];

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected function getStats(): array
    {
        $query = Incident::query()->with(['pic', 'incidentType', 'labels', 'latestStatusUpdate'])->whereHas('latestStatusUpdate', function ($query) {
            $query->whereNotIn('status', ['Closed', 'Resolved', 'Recovered']);
        });

        $descriptionPeriod = 'this year';
        if ($this->start_date && $this->end_date) {
            $query->whereBetween('incident_date', [$this->start_date, $this->end_date]);
            $descriptionPeriod = 'in the selected period';
        } else {
            $query->whereYear('incident_date', now()->year);
        }

        $openCases = $query->sum('potential_fund_loss');

        return [
            Stat::make('Potential Fund Loss', 'IDR '.number_format($openCases, 2, ',', '.'))
                ->description('Total potential fund loss from open cases '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-currency-dollar')
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
